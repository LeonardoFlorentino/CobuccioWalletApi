<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_deposit(): void
    {
        $user = User::factory()->create([
            'balance' => 100.00,
            'user_type' => 'regular',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/transactions/deposit', [
            'amount' => 50.25,
            'description' => 'Wallet top-up',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Deposit created successfully.');

        $this->assertSame(150.25, (float) $response->json('new_balance'));

        $user->refresh();

        $this->assertSame(150.25, (float) $user->balance);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'deposit',
            'status' => 'completed',
            'description' => 'Wallet top-up',
        ]);
    }

    public function test_authenticated_user_can_transfer_with_sufficient_balance(): void
    {
        $sender = User::factory()->create([
            'balance' => 100.00,
            'user_type' => 'regular',
        ]);

        $recipient = User::factory()->create([
            'balance' => 20.00,
            'user_type' => 'regular',
        ]);

        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/transactions/transfer', [
            'recipient_id' => $recipient->id,
            'amount' => 40.00,
            'description' => 'Transfer to friend',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Transfer created successfully.');

        $this->assertSame(60.00, (float) $response->json('your_new_balance'));
        $this->assertSame(60.00, (float) $response->json('recipient_new_balance'));

        $sender->refresh();
        $recipient->refresh();

        $this->assertSame(60.00, (float) $sender->balance);
        $this->assertSame(60.00, (float) $recipient->balance);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'type' => 'transfer',
            'status' => 'completed',
            'description' => 'Transfer to friend',
        ]);
    }

    public function test_transfer_fails_when_balance_is_insufficient(): void
    {
        $sender = User::factory()->create([
            'balance' => 10.00,
            'user_type' => 'regular',
        ]);

        $recipient = User::factory()->create([
            'balance' => 0.00,
            'user_type' => 'regular',
        ]);

        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/transactions/transfer', [
            'recipient_id' => $recipient->id,
            'amount' => 50.00,
            'description' => 'Should fail',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient balance for this transfer.');

        $sender->refresh();
        $recipient->refresh();

        $this->assertSame(10.00, (float) $sender->balance);
        $this->assertSame(0.00, (float) $recipient->balance);

        $this->assertDatabaseMissing('transactions', [
            'user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'description' => 'Should fail',
        ]);
    }

    public function test_owner_can_reverse_a_transfer_transaction(): void
    {
        $sender = User::factory()->create([
            'balance' => 60.00,
            'user_type' => 'regular',
        ]);

        $recipient = User::factory()->create([
            'balance' => 40.00,
            'user_type' => 'regular',
        ]);

        $transaction = Transaction::create([
            'user_id' => $sender->id,
            'type' => TransactionType::TRANSFER,
            'amount' => 40.00,
            'description' => 'Original transfer',
            'status' => TransactionStatus::COMPLETED,
            'recipient_user_id' => $recipient->id,
        ]);

        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/transactions/reverse', [
            'transaction_id' => $transaction->id,
            'reason' => 'Requested by user',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Transaction reversed successfully.');

        $transaction->refresh();
        $sender->refresh();
        $recipient->refresh();

        $this->assertSame('reversed', $transaction->status->value);
        $this->assertSame(100.00, (float) $sender->balance);
        $this->assertSame(0.00, (float) $recipient->balance);

        $this->assertDatabaseHas('transactions', [
            'original_transaction_id' => $transaction->id,
            'reversal_reason' => 'Requested by user',
            'status' => 'reversed',
        ]);
    }

    public function test_reverse_transfer_fails_when_recipient_balance_is_insufficient(): void
    {
        $sender = User::factory()->create([
            'balance' => 10.00,
            'user_type' => 'regular',
        ]);

        $recipient = User::factory()->create([
            'balance' => 5.00,
            'user_type' => 'regular',
        ]);

        $transaction = Transaction::create([
            'user_id' => $sender->id,
            'type' => TransactionType::TRANSFER,
            'amount' => 40.00,
            'description' => 'Historical transfer',
            'status' => TransactionStatus::COMPLETED,
            'recipient_user_id' => $recipient->id,
        ]);

        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/transactions/reverse', [
            'transaction_id' => $transaction->id,
            'reason' => 'Try to reverse with low recipient balance',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot reverse this transfer because recipient balance is insufficient.');

        $transaction->refresh();
        $sender->refresh();
        $recipient->refresh();

        $this->assertSame('completed', $transaction->status->value);
        $this->assertSame(10.00, (float) $sender->balance);
        $this->assertSame(5.00, (float) $recipient->balance);

        $this->assertDatabaseMissing('transactions', [
            'original_transaction_id' => $transaction->id,
        ]);
    }

    public function test_reverse_deposit_fails_when_it_would_create_negative_balance(): void
    {
        $owner = User::factory()->create([
            'balance' => 5.00,
            'user_type' => 'regular',
        ]);

        $deposit = Transaction::create([
            'user_id' => $owner->id,
            'type' => TransactionType::DEPOSIT,
            'amount' => 20.00,
            'description' => 'Historical deposit',
            'status' => TransactionStatus::COMPLETED,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/transactions/reverse', [
            'transaction_id' => $deposit->id,
            'reason' => 'Would go negative',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot reverse this deposit because it would create a negative balance.');

        $deposit->refresh();
        $owner->refresh();

        $this->assertSame('completed', $deposit->status->value);
        $this->assertSame(5.00, (float) $owner->balance);

        $this->assertDatabaseMissing('transactions', [
            'original_transaction_id' => $deposit->id,
        ]);
    }

    public function test_user_can_filter_transactions_by_type_status_and_date_range(): void
    {
        $user = User::factory()->create([
            'user_type' => 'regular',
        ]);

        $recipient = User::factory()->create([
            'user_type' => 'regular',
        ]);

        $oldDeposit = Transaction::create([
            'user_id' => $user->id,
            'type' => TransactionType::DEPOSIT,
            'amount' => 20.00,
            'description' => 'Old deposit',
            'status' => TransactionStatus::COMPLETED,
        ]);
        $oldDeposit->forceFill([
            'created_at' => Carbon::now()->subDays(5),
            'updated_at' => Carbon::now()->subDays(5),
        ])->save();

        $targetTransfer = Transaction::create([
            'user_id' => $user->id,
            'type' => TransactionType::TRANSFER,
            'amount' => 30.00,
            'description' => 'Target transfer',
            'status' => TransactionStatus::COMPLETED,
            'recipient_user_id' => $recipient->id,
        ]);
        $targetTransfer->forceFill([
            'created_at' => Carbon::now()->subDays(3),
            'updated_at' => Carbon::now()->subDays(3),
        ])->save();

        $reversedTransfer = Transaction::create([
            'user_id' => $user->id,
            'type' => TransactionType::TRANSFER,
            'amount' => 15.00,
            'description' => 'Reversed transfer',
            'status' => TransactionStatus::REVERSED,
            'recipient_user_id' => $recipient->id,
        ]);
        $reversedTransfer->forceFill([
            'created_at' => Carbon::now()->subDay(),
            'updated_at' => Carbon::now()->subDay(),
        ])->save();

        $otherUserTransaction = Transaction::create([
            'user_id' => $recipient->id,
            'type' => TransactionType::TRANSFER,
            'amount' => 999.00,
            'description' => 'Another user transaction',
            'status' => TransactionStatus::COMPLETED,
            'recipient_user_id' => $user->id,
        ]);
        $otherUserTransaction->forceFill([
            'created_at' => Carbon::now()->subDays(3),
            'updated_at' => Carbon::now()->subDays(3),
        ])->save();

        Sanctum::actingAs($user);

        $from = Carbon::now()->subDays(4)->toDateString();
        $to = Carbon::now()->subDays(2)->toDateString();

        $response = $this->getJson("/api/transactions?type=transfer&status=completed&from={$from}&to={$to}");

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.description', 'Target transfer')
            ->assertJsonPath('data.data.0.type', 'transfer')
            ->assertJsonPath('data.data.0.status', 'completed');
    }

    public function test_user_can_define_transactions_per_page(): void
    {
        $user = User::factory()->create([
            'user_type' => 'regular',
        ]);

        foreach (range(1, 15) as $index) {
            Transaction::create([
                'user_id' => $user->id,
                'type' => TransactionType::DEPOSIT,
                'amount' => 5.00 + $index,
                'description' => 'Deposit #' . $index,
                'status' => TransactionStatus::COMPLETED,
            ]);
        }

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/transactions?per_page=5');

        $response->assertOk()
            ->assertJsonPath('data.per_page', 5)
            ->assertJsonCount(5, 'data.data');
    }

    public function test_transactions_filter_validation_returns_422_for_invalid_type(): void
    {
        $user = User::factory()->create([
            'user_type' => 'regular',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/transactions?type=invalid-type');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }
}
