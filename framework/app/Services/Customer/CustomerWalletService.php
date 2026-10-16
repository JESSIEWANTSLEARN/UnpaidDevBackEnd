<?php

namespace App\Services\Customer;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CustomerWalletService
{
    public function ready(): bool
    {
        return
            Schema::hasTable('WBO_Wallets') &&
            Schema::hasTable('WBO_WalletTransactions');
    }

    public function summary(int $userId): array
    {
        $this->requireReady();

        $wallet = $this->ensureWallet($userId);

        return [
            'wallet' => [
                'wallet_id' =>
                    (int) $wallet->wallet_id,
                'balance' =>
                    $this->moneyString(
                        $wallet->balance
                    ),
                'status' =>
                    (string) $wallet->status,
                'updated_at' =>
                    $wallet->updated_at,
            ],
            'transactions' =>
                $this->transactionsForWallet(
                    (int) $wallet->wallet_id
                ),
        ];
    }

    public function topUp(
        int $userId,
        string $amount,
        string $paymentMethod,
        string $referenceNumber
    ): array {
        $this->requireReady();

        $amountCents = $this->toCents($amount);

        if ($amountCents < 1000) {
            throw ValidationException::withMessages([
                'amount' => [
                    'Minimum wallet top-up is PHP 10.00.',
                ],
            ]);
        }

        if ($amountCents > 10000000) {
            throw ValidationException::withMessages([
                'amount' => [
                    'Maximum demo wallet top-up is PHP 100,000.00.',
                ],
            ]);
        }

        $paymentMethod =
            strtoupper(trim($paymentMethod));

        $referenceNumber =
            trim($referenceNumber);

        if (
            !in_array(
                $paymentMethod,
                ['GCASH', 'BANK_TRANSFER'],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'payment_method' => [
                    'Choose GCash or Bank / ATM.',
                ],
            ]);
        }

        if ($referenceNumber === '') {
            throw ValidationException::withMessages([
                'reference_number' => [
                    'Enter the demo payment reference number.',
                ],
            ]);
        }

        // Ensure the one-wallet-per-user row exists before we lock it.
        $this->ensureWallet($userId);

        return DB::transaction(
            function () use (
                $userId,
                $amountCents,
                $paymentMethod,
                $referenceNumber
            ) {
                $wallet = DB::table('WBO_Wallets')
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();

                if (!$wallet) {
                    throw new RuntimeException(
                        'Wallet could not be created.'
                    );
                }

                if ($wallet->status !== 'ACTIVE') {
                    throw ValidationException::withMessages([
                        'wallet' => [
                            'This wallet is not currently active.',
                        ],
                    ]);
                }

                $duplicate =
                    DB::table(
                        'WBO_WalletTransactions'
                    )
                        ->where('user_id', $userId)
                        ->where(
                            'payment_method',
                            $paymentMethod
                        )
                        ->where(
                            'reference_number',
                            $referenceNumber
                        )
                        ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'reference_number' => [
                            'This payment reference was already used for a wallet top-up.',
                        ],
                    ]);
                }

                $beforeCents =
                    $this->toCents(
                        (string) $wallet->balance
                    );

                $afterCents =
                    $beforeCents + $amountCents;

                $before =
                    $this->fromCents($beforeCents);

                $after =
                    $this->fromCents($afterCents);

                $amount =
                    $this->fromCents($amountCents);

                DB::table('WBO_Wallets')
                    ->where(
                        'wallet_id',
                        $wallet->wallet_id
                    )
                    ->update([
                        'balance' => $after,
                        'updated_at' => now(),
                    ]);

                $transactionId =
                    DB::table(
                        'WBO_WalletTransactions'
                    )->insertGetId([
                        'wallet_id' =>
                            $wallet->wallet_id,
                        'user_id' =>
                            $userId,
                        'order_id' =>
                            null,
                        'type' =>
                            'TOP_UP',
                        'amount' =>
                            $amount,
                        'balance_before' =>
                            $before,
                        'balance_after' =>
                            $after,
                        'payment_method' =>
                            $paymentMethod,
                        'reference_number' =>
                            $referenceNumber,
                        'status' =>
                            'COMPLETED',
                        'description' =>
                            'Instant demo wallet top-up.',
                        'created_at' =>
                            now(),
                        'updated_at' =>
                            now(),
                    ]);

                return [
                    'wallet_transaction_id' =>
                        (int) $transactionId,
                    'amount' =>
                        $amount,
                    'balance_before' =>
                        $before,
                    'balance_after' =>
                        $after,
                    'payment_method' =>
                        $paymentMethod,
                    'reference_number' =>
                        $referenceNumber,
                    'status' =>
                        'COMPLETED',
                ];
            }
        );
    }


    public function purchase(
        int $userId,
        int $orderId,
        string $amount
    ): array {
        $this->requireReady();

        $amountCents = $this->toCents($amount);

        if ($amountCents <= 0) {
            throw ValidationException::withMessages([
                'wallet' => [
                    'Wallet purchase amount must be greater than zero.',
                ],
            ]);
        }

        // The order checkout already runs inside a DB transaction. This
        // row lock prevents two simultaneous wallet spends from reading the
        // same balance.
        $wallet = DB::table('WBO_Wallets')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw ValidationException::withMessages([
                'wallet' => [
                    'Wallet is not available for this account yet.',
                ],
            ]);
        }

        if ($wallet->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'wallet' => [
                    'This wallet is not currently active.',
                ],
            ]);
        }

        $existing = DB::table('WBO_WalletTransactions')
            ->where('user_id', $userId)
            ->where('order_id', $orderId)
            ->where('type', 'PURCHASE')
            ->where('status', 'COMPLETED')
            ->first();

        if ($existing) {
            return [
                'wallet_transaction_id' =>
                    (int) $existing->wallet_transaction_id,
                'amount' =>
                    $this->moneyString($existing->amount),
                'balance_before' =>
                    $this->moneyString($existing->balance_before),
                'balance_after' =>
                    $this->moneyString($existing->balance_after),
                'status' => 'COMPLETED',
            ];
        }

        $beforeCents = $this->toCents(
            (string) $wallet->balance
        );

        if ($beforeCents < $amountCents) {
            throw ValidationException::withMessages([
                'wallet' => [
                    sprintf(
                        'Insufficient wallet balance. Available: PHP %s. Order total: PHP %s.',
                        $this->fromCents($beforeCents),
                        $this->fromCents($amountCents)
                    ),
                ],
            ]);
        }

        $afterCents = $beforeCents - $amountCents;

        $before = $this->fromCents($beforeCents);
        $after = $this->fromCents($afterCents);
        $purchaseAmount = $this->fromCents($amountCents);

        DB::table('WBO_Wallets')
            ->where('wallet_id', $wallet->wallet_id)
            ->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);

        $transactionId = DB::table('WBO_WalletTransactions')
            ->insertGetId([
                'wallet_id' => $wallet->wallet_id,
                'user_id' => $userId,
                'order_id' => $orderId,
                'type' => 'PURCHASE',
                'amount' => $purchaseAmount,
                'balance_before' => $before,
                'balance_after' => $after,
                'payment_method' => 'WALLET',
                'reference_number' =>
                    'WALLET-ORDER-' . $orderId,
                'status' => 'COMPLETED',
                'description' =>
                    'Wallet payment for order #' . $orderId . '.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'wallet_transaction_id' => (int) $transactionId,
            'amount' => $purchaseAmount,
            'balance_before' => $before,
            'balance_after' => $after,
            'status' => 'COMPLETED',
        ];
    }


    public function refundOrder(
        int $userId,
        int $orderId
    ): array {
        $this->requireReady();

        $purchase = DB::table('WBO_WalletTransactions')
            ->where('user_id', $userId)
            ->where('order_id', $orderId)
            ->where('type', 'PURCHASE')
            ->where('status', 'COMPLETED')
            ->first();

        if (!$purchase) {
            throw ValidationException::withMessages([
                'wallet' => [
                    'The original completed wallet purchase could not be found.',
                ],
            ]);
        }

        $existingRefund = DB::table('WBO_WalletTransactions')
            ->where('user_id', $userId)
            ->where('order_id', $orderId)
            ->where('type', 'REFUND')
            ->where('status', 'COMPLETED')
            ->first();

        if ($existingRefund) {
            return [
                'wallet_transaction_id' =>
                    (int) $existingRefund->wallet_transaction_id,
                'amount' =>
                    $this->moneyString($existingRefund->amount),
                'balance_before' =>
                    $this->moneyString(
                        $existingRefund->balance_before
                    ),
                'balance_after' =>
                    $this->moneyString(
                        $existingRefund->balance_after
                    ),
                'status' => 'COMPLETED',
                'already_refunded' => true,
            ];
        }

        $wallet = DB::table('WBO_Wallets')
            ->where('wallet_id', $purchase->wallet_id)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw ValidationException::withMessages([
                'wallet' => [
                    'Wallet account could not be found for this refund.',
                ],
            ]);
        }

        if ($wallet->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'wallet' => [
                    'This wallet is not currently active.',
                ],
            ]);
        }

        // Re-check after the wallet row is locked. This makes the refund
        // idempotent even if two refund paths are triggered together.
        $existingRefund = DB::table('WBO_WalletTransactions')
            ->where('user_id', $userId)
            ->where('order_id', $orderId)
            ->where('type', 'REFUND')
            ->where('status', 'COMPLETED')
            ->first();

        if ($existingRefund) {
            return [
                'wallet_transaction_id' =>
                    (int) $existingRefund->wallet_transaction_id,
                'amount' =>
                    $this->moneyString($existingRefund->amount),
                'balance_before' =>
                    $this->moneyString(
                        $existingRefund->balance_before
                    ),
                'balance_after' =>
                    $this->moneyString(
                        $existingRefund->balance_after
                    ),
                'status' => 'COMPLETED',
                'already_refunded' => true,
            ];
        }

        $amountCents = $this->toCents(
            (string) $purchase->amount
        );

        $beforeCents = $this->toCents(
            (string) $wallet->balance
        );

        $afterCents =
            $beforeCents + $amountCents;

        $amount =
            $this->fromCents($amountCents);

        $before =
            $this->fromCents($beforeCents);

        $after =
            $this->fromCents($afterCents);

        DB::table('WBO_Wallets')
            ->where('wallet_id', $wallet->wallet_id)
            ->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);

        $transactionId =
            DB::table('WBO_WalletTransactions')
                ->insertGetId([
                    'wallet_id' =>
                        $wallet->wallet_id,
                    'user_id' =>
                        $userId,
                    'order_id' =>
                        $orderId,
                    'type' =>
                        'REFUND',
                    'amount' =>
                        $amount,
                    'balance_before' =>
                        $before,
                    'balance_after' =>
                        $after,
                    'payment_method' =>
                        'WALLET',
                    'reference_number' =>
                        'WALLET-REFUND-ORDER-' . $orderId,
                    'status' =>
                        'COMPLETED',
                    'description' =>
                        'Wallet refund for order #' .
                        $orderId . '.',
                    'created_at' =>
                        now(),
                    'updated_at' =>
                        now(),
                ]);

        return [
            'wallet_transaction_id' =>
                (int) $transactionId,
            'amount' =>
                $amount,
            'balance_before' =>
                $before,
            'balance_after' =>
                $after,
            'status' =>
                'COMPLETED',
            'already_refunded' =>
                false,
        ];
    }

    private function ensureWallet(
        int $userId
    ): object {
        $wallet = DB::table('WBO_Wallets')
            ->where('user_id', $userId)
            ->first();

        if ($wallet) {
            return $wallet;
        }

        DB::table('WBO_Wallets')
            ->updateOrInsert(
                [
                    'user_id' => $userId,
                ],
                [
                    'balance' => '0.00',
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

        $wallet = DB::table('WBO_Wallets')
            ->where('user_id', $userId)
            ->first();

        if (!$wallet) {
            throw new RuntimeException(
                'Wallet could not be initialized.'
            );
        }

        return $wallet;
    }

    private function transactionsForWallet(
        int $walletId
    ): Collection {
        return DB::table(
            'WBO_WalletTransactions'
        )
            ->where('wallet_id', $walletId)
            ->orderByDesc('created_at')
            ->orderByDesc(
                'wallet_transaction_id'
            )
            ->limit(50)
            ->get()
            ->map(fn ($item) => [
                'wallet_transaction_id' =>
                    (int)
                    $item->wallet_transaction_id,
                'order_id' =>
                    $item->order_id === null
                        ? null
                        : (int) $item->order_id,
                'type' =>
                    (string) $item->type,
                'amount' =>
                    $this->moneyString(
                        $item->amount
                    ),
                'balance_before' =>
                    $this->moneyString(
                        $item->balance_before
                    ),
                'balance_after' =>
                    $this->moneyString(
                        $item->balance_after
                    ),
                'payment_method' =>
                    $item->payment_method,
                'reference_number' =>
                    $item->reference_number,
                'status' =>
                    (string) $item->status,
                'description' =>
                    $item->description,
                'created_at' =>
                    $item->created_at,
            ])
            ->values();
    }

    private function requireReady(): void
    {
        if (!$this->ready()) {
            throw ValidationException::withMessages([
                'wallet' => [
                    'Wallet database tables are not installed yet.',
                ],
            ]);
        }
    }

    private function toCents(
        string $amount
    ): int {
        $normalized =
            trim(str_replace(',', '', $amount));

        if (
            !preg_match(
                '/^\d+(?:\.\d{1,2})?$/',
                $normalized
            )
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'Enter a valid amount with up to 2 decimal places.',
                ],
            ]);
        }

        [$whole, $decimal] =
            array_pad(
                explode('.', $normalized, 2),
                2,
                ''
            );

        $decimal =
            str_pad(
                $decimal,
                2,
                '0'
            );

        return
            ((int) $whole * 100) +
            (int) substr($decimal, 0, 2);
    }

    private function fromCents(
        int $cents
    ): string {
        return number_format(
            $cents / 100,
            2,
            '.',
            ''
        );
    }

    private function moneyString(
        mixed $amount
    ): string {
        return number_format(
            (float) $amount,
            2,
            '.',
            ''
        );
    }
}
