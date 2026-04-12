<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\ReverseTransactionRequest;
use App\Http\Requests\TransferRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    /**
     * Create a deposit transaction.
     */
    public function deposit(DepositRequest $request): JsonResponse
    {
        $user = auth()->user();
        $amount = (float) $request->validated('amount');

        try {
            DB::beginTransaction();

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'type' => TransactionType::DEPOSIT,
                'amount' => $amount,
                'description' => $request->validated('description'),
                'status' => TransactionStatus::COMPLETED,
            ]);

            $user->update([
                'balance' => $user->balance + $amount,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Deposit created successfully.',
                'transaction' => $transaction->load('user'),
                'new_balance' => $user->fresh()->balance,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create deposit.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a transfer transaction.
     */
    public function transfer(TransferRequest $request): JsonResponse
    {
        $user = auth()->user();
        $recipient = User::findOrFail($request->validated('recipient_id'));
        $amount = (float) $request->validated('amount');

        // Check if user has sufficient balance
        if ($user->balance < $amount) {
            return response()->json([
                'message' => 'Insufficient balance for this transfer.',
                'available_balance' => $user->balance,
                'required_amount' => $amount,
            ], 422);
        }

        try {
            DB::beginTransaction();

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'type' => TransactionType::TRANSFER,
                'amount' => $amount,
                'description' => $request->validated('description'),
                'recipient_user_id' => $recipient->id,
                'status' => TransactionStatus::COMPLETED,
            ]);

            // Debit from sender
            $user->update([
                'balance' => $user->balance - $amount,
            ]);

            // Credit to recipient
            $recipient->update([
                'balance' => $recipient->balance + $amount,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Transfer created successfully.',
                'transaction' => $transaction->load(['user', 'recipient']),
                'your_new_balance' => $user->fresh()->balance,
                'recipient_new_balance' => $recipient->fresh()->balance,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create transfer.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reverse a transaction.
     */
    public function reverse(ReverseTransactionRequest $request): JsonResponse
    {
        $user = auth()->user();
        $transaction = Transaction::findOrFail($request->validated('transaction_id'));
        $reason = $request->validated('reason');

        // Only admins or the transaction owner can reverse
        if ($user->user_type->value !== 'admin' && $transaction->user_id !== $user->id) {
            return response()->json([
                'message' => 'You are not authorized to reverse this transaction.',
            ], 403);
        }

        // Cannot reverse already reversed transactions
        if ($transaction->status === TransactionStatus::REVERSED) {
            return response()->json([
                'message' => 'This transaction has already been reversed.',
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Create reversal transaction
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

            // Update original transaction status
            $transaction->update([
                'status' => TransactionStatus::REVERSED,
            ]);

            if ($transaction->type === TransactionType::DEPOSIT) {
                // Reverse deposit: debit from user
                $transaction->user->update([
                    'balance' => $transaction->user->balance - $transaction->amount,
                ]);
            } else {
                // Reverse transfer: credit back to sender, debit from recipient
                $transaction->user->update([
                    'balance' => $transaction->user->balance + $transaction->amount,
                ]);
                $transaction->recipient->update([
                    'balance' => $transaction->recipient->balance - $transaction->amount,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Transaction reversed successfully.',
                'original_transaction' => $transaction,
                'reversal_transaction' => $reversalTransaction,
                'reason' => $reason,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to reverse transaction.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user transactions.
     */
    public function getUserTransactions(): JsonResponse
    {
        $user = auth()->user();
        $transactions = $user->transactions()
            ->with(['user', 'recipient', 'originalTransaction'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'User transactions retrieved successfully.',
            'data' => $transactions,
        ], 200);
    }

    /**
     * Get transaction details.
     */
    public function show(Transaction $transaction): JsonResponse
    {
        $user = auth()->user();

        // Only allow viewing own transactions or admins
        if ($transaction->user_id !== $user->id && $transaction->recipient_user_id !== $user->id && $user->user_type->value !== 'admin') {
            return response()->json([
                'message' => 'You are not authorized to view this transaction.',
            ], 403);
        }

        return response()->json([
            'message' => 'Transaction retrieved successfully.',
            'data' => $transaction->load(['user', 'recipient', 'originalTransaction']),
        ], 200);
    }
}
