<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\ReverseTransactionRequest;
use App\Http\Requests\TransactionIndexRequest;
use App\Http\Requests\TransferRequest;
use App\Models\Transaction;
use App\Services\WalletService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function __construct(private readonly WalletService $walletService)
    {
    }

    /**
     * Create a deposit transaction.
     */
    public function deposit(DepositRequest $request): JsonResponse
    {
        $authUser = auth()->user();
        $amount = (float) $request->validated('amount');

        try {
            $result = $this->walletService->deposit(
                $authUser,
                $amount,
                $request->validated('description')
            );

            return response()->json([
                'message' => 'Deposit created successfully.',
                'transaction' => $result['transaction']->load('user'),
                'new_balance' => $result['current_user']->balance,
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
            $result = $this->walletService->transfer(
                $authUser,
                $recipientId,
                $amount,
                $request->validated('description')
            );

            return response()->json([
                'message' => 'Transfer created successfully.',
                'transaction' => $result['transaction']->load(['user', 'recipient']),
                'your_new_balance' => $result['user']->balance,
                'recipient_new_balance' => $result['recipient']->balance,
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

        try {
            $result = $this->walletService->reverse($user, $transactionId, $reason);

            return response()->json([
                'message' => 'Transaction reversed successfully.',
                'original_transaction' => $result['transaction'],
                'reversal_transaction' => $result['reversal_transaction'],
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
