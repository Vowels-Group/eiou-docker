<?php
/**
 * Balance Model Unit Tests
 *
 * Copyright 2025
 */

namespace Eiou\Tests\Unit\Gui\Models;

use PHPUnit\Framework\TestCase;
use Eiou\Gui\Models\Balance;

/**
 * Test Balance Model
 */
class BalanceTest extends TestCase
{
    private $serviceContainerMock;
    private Balance $balanceModel;

    protected function setUp(): void
    {
        // Mock ServiceContainer
        $this->serviceContainerMock = $this->createMock(\ServiceContainer::class);

        // Create Balance model with mocked container
        $this->balanceModel = new Balance($this->serviceContainerMock);
    }

    /**
     * Test getBalance returns correct value
     */
    public function testGetBalanceReturnsCorrectValue(): void
    {
        $walletServiceMock = $this->createMock(\WalletService::class);
        $walletServiceMock->method('getBalance')
            ->with('USD')
            ->willReturn(1234.56);

        $this->serviceContainerMock->method('getWalletService')
            ->willReturn($walletServiceMock);

        $balance = $this->balanceModel->getBalance('USD');

        $this->assertEquals(1234.56, $balance);
    }

    /**
     * Test getFormattedBalance returns correctly formatted string
     */
    public function testGetFormattedBalanceReturnsFormattedString(): void
    {
        $walletServiceMock = $this->createMock(\WalletService::class);
        $walletServiceMock->method('getBalance')
            ->with('USD')
            ->willReturn(1234.56);

        $this->serviceContainerMock->method('getWalletService')
            ->willReturn($walletServiceMock);

        $formatted = $this->balanceModel->getFormattedBalance('USD');

        $this->assertEquals('1,234.56', $formatted);
    }

    /**
     * Test hasSufficientBalance returns true when balance is sufficient
     */
    public function testHasSufficientBalanceReturnsTrueWhenSufficient(): void
    {
        $walletServiceMock = $this->createMock(\WalletService::class);
        $walletServiceMock->method('getBalance')
            ->with('USD')
            ->willReturn(1000.00);

        $this->serviceContainerMock->method('getWalletService')
            ->willReturn($walletServiceMock);

        $this->assertTrue($this->balanceModel->hasSufficientBalance(500.00, 'USD'));
    }

    /**
     * Test hasSufficientBalance returns false when balance is insufficient
     */
    public function testHasSufficientBalanceReturnsFalseWhenInsufficient(): void
    {
        $walletServiceMock = $this->createMock(\WalletService::class);
        $walletServiceMock->method('getBalance')
            ->with('USD')
            ->willReturn(100.00);

        $this->serviceContainerMock->method('getWalletService')
            ->willReturn($walletServiceMock);

        $this->assertFalse($this->balanceModel->hasSufficientBalance(500.00, 'USD'));
    }

    /**
     * Test getAllBalances returns array of balances
     */
    public function testGetAllBalancesReturnsArray(): void
    {
        $expectedBalances = [
            'USD' => 1234.56,
            'EUR' => 987.65
        ];

        $walletServiceMock = $this->createMock(\WalletService::class);
        $walletServiceMock->method('getAllBalances')
            ->willReturn($expectedBalances);

        $this->serviceContainerMock->method('getWalletService')
            ->willReturn($walletServiceMock);

        $balances = $this->balanceModel->getAllBalances();

        $this->assertEquals($expectedBalances, $balances);
    }

    /**
     * Test clearCache clears cached balance data
     */
    public function testClearCacheClearsCachedData(): void
    {
        $walletServiceMock = $this->createMock(\WalletService::class);
        $walletServiceMock->expects($this->exactly(2))
            ->method('getBalance')
            ->with('USD')
            ->willReturn(100.00);

        $this->serviceContainerMock->method('getWalletService')
            ->willReturn($walletServiceMock);

        // First call - should cache
        $this->balanceModel->getBalance('USD');

        // Clear cache
        $this->balanceModel->clearCache();

        // Second call - should fetch again after cache clear
        $this->balanceModel->getBalance('USD');

        // The test passes if getBalance is called exactly twice
        $this->assertTrue(true);
    }
}
