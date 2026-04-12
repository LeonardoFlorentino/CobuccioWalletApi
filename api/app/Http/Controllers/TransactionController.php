<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UserType;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\ReverseTransactionRequest;
use App\Http\Requests\TransactionIndexRequest;
use App\Http\Requests\TransferRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\WalletRules;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    /**
     * Create a deposit transaction.
     */
    public function deposit(DepositRequest $request): JsonResponse
    {
        $authUser = auth()->user();
        $amount = (float) $request->validated('amount');

        try {
            $transaction = DB::transaction(function () use ($authUser, $amount, $request) {
                $user = User::query()->lockForUpdate()->findOrFail($authUser->id);

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

                return $transaction;
            });

            $currentUser = User::findOrFail($authUser->id);

            return response()->json([
                'message' => 'Deposit created successfully.',
                'transaction' => $transaction->load('user'),
                'new_balance' => $currentUser->balance,
            ], 201);
        } catch (\Exception $e) {
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
        $authUser = auth()->user();
        $recipientId = (int) $request->validated('recipient_id');
        $amount = (float) $request->validated('amount');

        try {
            $transaction = DB::transaction(function () use ($authUser, $recipientId, $amount, $request) {
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
                    abort(404, 'User not found.');
                }

                if (! WalletRules::hasSufficientBalance((float) $user->balance, $amount)) {
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
                    'description' => $request->validated('description'),
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

            return response()->json([
                'message' => 'Transfer created successfully.',
                'transaction' => $transaction->load(['user', 'recipient']),
                'your_new_balance' => $user->balance,
                'recipient_new_balance' => $recipient->balance,
            ], 201);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (\Exception $e) {
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
        $transactionId = (int) $request->validated('transaction_id');
        $reason = $request->validated('reason');

        $isAdmin = $user->user_type === UserType::ADMIN;

        $transaction = Transaction::findOrFail($transactionId);

        // Only admins or the transaction owner can reverse
        if (! $isAdmin && $transaction->user_id !== $user->id) {
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
            $reversalTransaction = DB::transaction(function () use ($transactionId, $reason) {
                $transaction = Transaction::query()->lockForUpdate()->findOrFail($transactionId);

                if ($transaction->status === TransactionStatus::REVERSED) {
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
                    throw new HttpResponseException(response()->json([
                        'message' => 'Cannot reverse this deposit because it would create a negative balance.',
                    ], 422));
                }

                if ($transaction->type === TransactionType::TRANSFER && $recipient && ! WalletRules::canReverseTransfer((float) $recipient->balance, (float) $transaction->amount)) {
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

            return response()->json([
                'message' => 'Transaction reversed successfully.',
                'original_transaction' => $transaction,
                'reversal_transaction' => $reversalTransaction,
                'reason' => $reason,
            ], 200);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to reverse transaction.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user transactions.
     */
    public function getUserTransactions(TransactionIndexRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 20);

        $transactions = $user->transactions()
            ->with(['user', 'recipient', 'originalTransaction'])
            ->when(isset($validated['type']), function ($query) use ($validated) {
                $query->where('type', $validated['type']);
            })
            ->when(isset($validated['status']), function ($query) use ($validated) {
                $query->where('status', $validated['status']);
            })
            ->when(isset($validated['from']), function ($query) use ($validated) {
                $query->whereDate('created_at', '>=', $validated['from']);
            })
            ->when(isset($validated['to']), function ($query) use ($validated) {
                $query->whereDate('created_at', '<=', $validated['to']);
            })
            ->latest()
            ->paginate($perPage)
            ->appends($request->query());

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
        $isAdmin = $user->user_type === UserType::ADMIN;

        // Only allow viewing own transactions or admins
        if ($transaction->user_id !== $user->id && $transaction->recipient_user_id !== $user->id && ! $isAdmin) {
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
