<?php

namespace Tests\Unit;

use App\Support\WalletRules;
use PHPUnit\Framework\TestCase;

class WalletRulesTest extends TestCase
{
    public function test_has_sufficient_balance_returns_true_when_available_equals_required(): void
    {
        $this->assertTrue(WalletRules::hasSufficientBalance(100.00, 100.00));
    }

    public function test_has_sufficient_balance_returns_true_when_available_is_greater(): void
    {
        $this->assertTrue(WalletRules::hasSufficientBalance(120.50, 100.00));
    }

    public function test_has_sufficient_balance_returns_false_when_available_is_lower(): void
    {
        $this->assertFalse(WalletRules::hasSufficientBalance(99.99, 100.00));
    }

    public function test_can_reverse_deposit_uses_same_balance_rule(): void
    {
        $this->assertTrue(WalletRules::canReverseDeposit(50.00, 49.99));
        $this->assertFalse(WalletRules::canReverseDeposit(50.00, 50.01));
    }

    public function test_can_reverse_transfer_uses_same_balance_rule(): void
    {
        $this->assertTrue(WalletRules::canReverseTransfer(75.00, 75.00));
        $this->assertFalse(WalletRules::canReverseTransfer(10.00, 10.01));
    }
}
