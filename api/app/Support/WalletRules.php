<?php

namespace App\Support;

class WalletRules
{
    public static function hasSufficientBalance(float $available, float $required): bool
    {
        return $available >= $required;
    }

    public static function canReverseDeposit(float $ownerBalance, float $amount): bool
    {
        return self::hasSufficientBalance($ownerBalance, $amount);
    }

    public static function canReverseTransfer(float $recipientBalance, float $amount): bool
    {
        return self::hasSufficientBalance($recipientBalance, $amount);
    }
}
