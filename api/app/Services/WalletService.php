<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UserType;
use App\Models\Transaction;
use App\Models\User;
use App\Support\WalletRules;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService
{
    public function deposit(User $authUser, float $amount, ?string $description): array
    {
        $transaction = DB::transaction(function () use ($authUser, $amount, $description) {
            $user = User::query()->lockForUpdate()->findOrFail($authUser->id);

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'type' => TransactionType::DEPOSIT,
                'amount' => $amount,
                'description' => $description,
                'status' => TransactionStatus::COMPLETED,
            ]);

            $user->update([
                'balance' => $user->balance + $amount,
            ]);

            return $transaction;
        });

        $currentUser = User::findOrFail($authUser->id);

        Log::info('wallet.deposit.completed', [
            'actor_user_id' => $authUser->id,
            'transaction_id' => $transaction->id,
            'amount' => $amount,
            'status' => TransactionStatus::COMPLETED->value,
            'new_balance' => (float) $currentUser->balance,
        ]);

        return [
            'transaction' => $transaction,
            'current_user' => $currentUser,
        ];
    }

    public function transfer(User $authUser, int $recipientId, float $amount, ?string $description): array
    {
        $transaction = DB::transaction(function () use ($authUser, $recipientId, $amount, $description) {
            $orderedIds = [$authUser->id, $recipientId];
            sort($orderedIds);

            $lockedUsers = User::query()
                ->whereIn('id', $orderedIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $user = $lockedUsers->get($authUser->id);
            $recipient = $lockedUsers->get($recipientId);

            if (! $user || ! $recipient) {
                Log::warning('wallet.transfer.rejected.user_not_found', [
                    'actor_user_id' => $authUser->id,
                    'recipient_user_id' => $recipientId,
                    'amount' => $amount,
                ]);

                abort(404, 'User not found.');
            }

            if (! WalletRules::hasSufficientBalance((float) $user->balance, $amount)) {
                Log::warning('wallet.transfer.rejected.insufficient_balance', [
                    'actor_user_id' => $user->id,
                    'recipient_user_id' => $recipient->id,
                    'available_balance' => (float) $user->balance,
                    'required_amount' => $amount,
                ]);

                throw new HttpResponseException(response()->json([
                    'message' => 'Insufficient balance for this transfer.',
                    'available_balance' => $user->balance,
                    'required_amount' => $amount,
                ], 422));
            }

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'type' => TransactionType::TRANSFER,
                'amount' => $amount,
                'description' => $description,
                'recipient_user_id' => $recipient->id,
                'status' => TransactionStatus::COMPLETED,
            ]);

            $user->update([
                'balance' => $user->balance - $amount,
            ]);

            $recipient->update([
                'balance' => $recipient->balance + $amount,
            ]);

            return $transaction;
        });

        $user = User::findOrFail($authUser->id);
        $recipient = User::findOrFail($recipientId);

        Log::info('wallet.transfer.completed', [
            'actor_user_id' => $authUser->id,
            'recipient_user_id' => $recipient->id,
            'transaction_id' => $transaction->id,
            'amount' => $amount,
            'status' => TransactionStatus::COMPLETED->value,
            'actor_new_balance' => (float) $user->balance,
            'recipient_new_balance' => (float) $recipient->balance,
        ]);

        return [
            'transaction' => $transaction,
            'user' => $user,
            'recipient' => $recipient,
        ];
    }

    public function reverse(User $actor, int $transactionId, string $reason): array
    {
        $isAdmin = $actor->user_type === UserType::ADMIN;
        $transaction = Transaction::findOrFail($transactionId);

        if (! $isAdmin && $transaction->user_id !== $actor->id) {
            Log::warning('wallet.reverse.rejected.unauthorized', [
                'actor_user_id' => $actor->id,
                'transaction_id' => $transactionId,
            ]);

            throw new HttpResponseException(response()->json([
                'message' => 'You are not authorized to reverse this transaction.',
            ], 403));
        }

        if ($transaction->status === TransactionStatus::REVERSED) {
            Log::warning('wallet.reverse.rejected.already_reversed', [
                'actor_user_id' => $actor->id,
                'transaction_id' => $transactionId,
            ]);

            throw new HttpResponseException(response()->json([
                'message' => 'This transaction has already been reversed.',
            ], 422));
        }

        $actorUserId = $actor->id;

        $reversalTransaction = DB::transaction(function () use ($transactionId, $reason, $actorUserId) {
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($transactionId);

            if ($transaction->status === TransactionStatus::REVERSED) {
                Log::warning('wallet.reverse.rejected.already_reversed', [
                    'actor_user_id' => $actorUserId,
                    'transaction_id' => $transactionId,
                ]);

                throw new HttpResponseException(response()->json([
                    'message' => 'This transaction has already been reversed.',
                ], 422));
            }

            $owner = User::query()->lockForUpdate()->findOrFail($transaction->user_id);
            $recipient = null;

            if ($transaction->recipient_user_id) {
                $recipient = User::query()->lockForUpdate()->findOrFail($transaction->recipient_user_id);
            }

            if ($transaction->type === TransactionType::DEPOSIT && ! WalletRules::canReverseDeposit((float) $owner->balance, (float) $transaction->amount)) {
                Log::warning('wallet.reverse.rejected.deposit_negative_balance', [
                    'actor_user_id' => $actorUserId,
                    'transaction_id' => $transactionId,
                    'owner_user_id' => $owner->id,
                    'owner_balance' => (float) $owner->balance,
                    'amount' => (float) $transaction->amount,
                ]);

                throw new HttpResponseException(response()->json([
                    'message' => 'Cannot reverse this deposit because it would create a negative balance.',
                ], 422));
            }

            if ($transaction->type === TransactionType::TRANSFER && $recipient && ! WalletRules::canReverseTransfer((float) $recipient->balance, (float) $transaction->amount)) {
                Log::warning('wallet.reverse.rejected.transfer_recipient_insufficient', [
                    'actor_user_id' => $actorUserId,
                    'transaction_id' => $transactionId,
                    'recipient_user_id' => $recipient->id,
                    'recipient_balance' => (float) $recipient->balance,
                    'amount' => (float) $transaction->amount,
                ]);

                throw new HttpResponseException(response()->json([
                    'message' => 'Cannot reverse this transfer because recipient balance is insufficient.',
                ], 422));
            }

            $reversalTransaction = Transaction::create([
                'user_id' => $transaction->user_id,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
                'description' => 'Reversal of transaction #' . $transaction->id,
                'recipient_user_id' => $transaction->recipient_user_id,
                'original_transaction_id' => $transaction->id,
                'reversal_reason' => $reason,
                'status' => TransactionStatus::REVERSED,
            ]);

            $transaction->update([
                'status' => TransactionStatus::REVERSED,
            ]);

            if ($transaction->type === TransactionType::DEPOSIT) {
                $owner->update([
                    'balance' => $owner->balance - $transaction->amount,
                ]);
            } elseif ($recipient) {
                $owner->update([
                    'balance' => $owner->balance + $transaction->amount,
                ]);
                $recipient->update([
                    'balance' => $recipient->balance - $transaction->amount,
                ]);
            }

            return $reversalTransaction;
        });

        $transaction = Transaction::findOrFail($transactionId);

        Log::info('wallet.reverse.completed', [
            'actor_user_id' => $actor->id,
            'transaction_id' => $transactionId,
            'reversal_transaction_id' => $reversalTransaction->id,
            'reason' => $reason,
            'status' => TransactionStatus::REVERSED->value,
        ]);

        return [
            'transaction' => $transaction,
            'reversal_transaction' => $reversalTransaction,
        ];
    }
}
