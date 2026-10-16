<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\WBOUser;
use App\Services\Customer\CustomerWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerWalletController extends Controller
{
    public function show(
        Request $request,
        CustomerWalletService $wallets
    ): JsonResponse {
        $user =
            $this->currentUser($request);

        $summary =
            $wallets->summary(
                (int) $user->user_id
            );

        return response()->json([
            'success' => true,
            ...$summary,
        ]);
    }

    public function topUp(
        Request $request,
        CustomerWalletService $wallets
    ): JsonResponse {
        $user =
            $this->currentUser($request);

        $validated =
            $request->validate([
                'amount' => [
                    'required',
                    'regex:/^\d+(?:\.\d{1,2})?$/',
                ],
                'payment_method' => [
                    'required',
                    'string',
                    'in:GCASH,BANK_TRANSFER',
                ],
                'reference_number' => [
                    'required',
                    'string',
                    'max:100',
                ],
            ]);

        $transaction =
            $wallets->topUp(
                (int) $user->user_id,
                (string) $validated['amount'],
                (string)
                    $validated['payment_method'],
                (string)
                    $validated['reference_number']
            );

        $this->audit(
            $request,
            (int) $user->user_id,
            'WALLET_TOP_UP',
            sprintf(
                'Customer completed wallet top-up of PHP %s using %s. Wallet transaction #%d.',
                $transaction['amount'],
                $transaction['payment_method'],
                $transaction[
                    'wallet_transaction_id'
                ]
            )
        );

        $this->notifyTopUp(
            (int) $user->user_id,
            $transaction
        );

        return response()->json([
            'success' => true,
            'message' =>
                'Wallet top-up completed successfully.',
            'transaction' =>
                $transaction,
        ]);
    }

    private function currentUser(
        Request $request
    ): WBOUser {
        if (
            !$request->session()->get(
                'logged_in'
            ) ||
            !$request->session()->get(
                'user_id'
            )
        ) {
            abort(
                401,
                'Please log in first.'
            );
        }

        if (
            $request->session()->get('role') !==
            'System_User'
        ) {
            abort(
                403,
                'This page is only available to System Users.'
            );
        }

        $user =
            WBOUser::find(
                $request->session()->get(
                    'user_id'
                )
            );

        if (
            !$user ||
            $user->account_status !== 'active'
        ) {
            $request->session()->invalidate();

            abort(
                401,
                'Your account is unavailable.'
            );
        }

        return $user;
    }

    private function audit(
        Request $request,
        int $userId,
        string $action,
        string $description
    ): void {
        if (
            !Schema::hasTable('WBO_AuditLogs')
        ) {
            return;
        }

        DB::table('WBO_AuditLogs')
            ->insert([
                'user_id' =>
                    $userId,
                'action' =>
                    $action,
                'description' =>
                    $description,
                'ip_address' =>
                    $request->ip(),
                'created_at' =>
                    now(),
            ]);
    }

    private function notifyTopUp(
        int $userId,
        array $transaction
    ): void {
        if (
            !Schema::hasTable(
                'WBO_Notifications'
            )
        ) {
            return;
        }

        DB::table('WBO_Notifications')
            ->insert([
                'alert_tier' =>
                    'Yellow',
                'title' =>
                    'Wallet top-up completed',
                'message' =>
                    sprintf(
                        'PHP %s was added to your wallet. New balance: PHP %s.',
                        $transaction['amount'],
                        $transaction[
                            'balance_after'
                        ]
                    ),
                'related_product_id' =>
                    null,
                'related_batch_id' =>
                    null,
                'recipient_user_id' =>
                    $userId,
                'triggered_at' =>
                    now(),
                'status' =>
                    'UNREAD',
                'acknowledged_at' =>
                    null,
                'resolved_at' =>
                    null,
            ]);
    }
}
