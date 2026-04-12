<?php
/**
 * Unit Tests for RouteCancellationService
 *
 * Tests route cancellation service functionality including:
 * - Cancelling unselected routes with messaging and audit trail
 * - Handling incoming cancellation messages
 * - Generating randomized hop budgets
 * - Decrementing hop budgets
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\RouteCancellationService;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Database\CapacityReservationRepository;
use Eiou\Database\RouteCancellationRepository;
use Eiou\Database\RepositoryFactory;
use Eiou\Database\P2pRepository;
use Eiou\Core\Constants;

#[CoversClass(RouteCancellationService::class)]
class RouteCancellationServiceTest extends TestCase
{
    private RouteCancellationService $service;
    private MockObject|P2pServiceInterface $p2pService;
    private MockObject|CapacityReservationRepository $capacityReservationRepository;
    private MockObject|RouteCancellationRepository $routeCancellationRepository;
    private MockObject|P2pRepository $p2pRepository;

    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_SELECTED_ID = '1';
    private const TEST_SENDER_ADDRESS = 'http://sender.example.com';
    private const TEST_SENDER_PUBKEY = 'test-public-key-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        $this->p2pService = $this->createMock(P2pServiceInterface::class);
        $this->capacityReservationRepository = $this->createMock(CapacityReservationRepository::class);
        $this->routeCancellationRepository = $this->createMock(RouteCancellationRepository::class);
        $this->p2pRepository = $this->createMock(P2pRepository::class);

        $repoFactory = $this->createMock(RepositoryFactory::class);
        $repoFactory->method('get')
            ->willReturnCallback(function (string $class) {
                if ($class === CapacityReservationRepository::class) return $this->capacityReservationRepository;
                if ($class === RouteCancellationRepository::class) return $this->routeCancellationRepository;
                if ($class === P2pRepository::class) return $this->p2pRepository;
                return null;
            });

        $this->service = new RouteCancellationService($repoFactory);
    }

    // =========================================================================
    // cancelUnselectedRoutes() Tests
    // =========================================================================

    /**
     * Test cancelUnselectedRoutes returns zero count when candidates array is empty
     */
    public function testCancelUnselectedRoutesReturnsZeroWhenEmpty(): void
    {
        $this->service->setP2pService($this->p2pService);

        $result = $this->service->cancelUnselectedRoutes(self::TEST_HASH, self::TEST_SELECTED_ID, []);

        $this->assertEquals(0, $result['cancelled_count']);
        $this->assertEmpty($result['routes']);
    }

    /**
     * Test cancelUnselectedRoutes sends a P2P message to each unselected candidate
     */
    public function testCancelUnselectedRoutesSendsMessageToEachCandidate(): void
    {
        $this->service->setP2pService($this->p2pService);

        $unselectedCandidates = [
            ['id' => 2, 'sender_address' => 'http://node2.test', 'sender_public_key' => 'key2'],
            ['id' => 3, 'sender_address' => 'http://node3.test', 'sender_public_key' => 'key3'],
            ['id' => 4, 'sender_address' => 'http://node4.test', 'sender_public_key' => 'key4'],
        ];

        $this->p2pService->expects($this->exactly(3))
            ->method('sendCancelToAddress')
            ->willReturnCallback(function (string $hash, string $address) {
                $this->assertEquals(self::TEST_HASH, $hash);
                $this->assertNotEmpty($address);
            });

        $result = $this->service->cancelUnselectedRoutes(self::TEST_HASH, self::TEST_SELECTED_ID, $unselectedCandidates);

        $this->assertEquals(3, $result['cancelled_count']);
        $this->assertCount(3, $result['routes']);
        foreach ($result['routes'] as $route) {
            $this->assertEquals('sent', $route['status']);
        }
    }

    /**
     * Test cancelUnselectedRoutes records audit trail via RouteCancellationRepository
     */
    public function testCancelUnselectedRoutesRecordsAuditTrail(): void
    {
        $this->service->setP2pService($this->p2pService);

        $unselectedCandidates = [
            ['id' => 2, 'sender_address' => 'http://node2.test', 'sender_public_key' => 'key2'],
            ['id' => 3, 'sender_address' => 'http://node3.test', 'sender_public_key' => 'key3'],
        ];

        $this->routeCancellationRepository->expects($this->exactly(2))
            ->method('insertCancellation');

        $this->service->cancelUnselectedRoutes(self::TEST_HASH, self::TEST_SELECTED_ID, $unselectedCandidates);
    }

    /**
     * Test cancelUnselectedRoutes releases capacity reservations for each unselected candidate
     */
    public function testCancelUnselectedRoutesReleasesCapacityReservations(): void
    {
        $this->service->setP2pService($this->p2pService);

        $unselectedCandidates = [
            ['id' => 2, 'sender_address' => 'http://node2.test', 'sender_public_key' => 'key2'],
            ['id' => 3, 'sender_address' => 'http://node3.test', 'sender_public_key' => 'key3'],
        ];

        $this->capacityReservationRepository->expects($this->exactly(2))
            ->method('releaseByHashAndContact');

        $this->service->cancelUnselectedRoutes(self::TEST_HASH, self::TEST_SELECTED_ID, $unselectedCandidates);
    }

    /**
     * Test cancelUnselectedRoutes handles missing P2P service gracefully
     *
     * When p2pService is not set, cancellation messages cannot be sent but the
     * method should still log and return without crashing.
     */
    public function testCancelUnselectedRoutesHandlesMissingP2pService(): void
    {
        // Do NOT call setP2pService - leave it null

        $unselectedCandidates = [
            ['id' => 2, 'sender_address' => 'http://node2.test', 'sender_public_key' => 'key2'],
        ];

        $result = $this->service->cancelUnselectedRoutes(self::TEST_HASH, self::TEST_SELECTED_ID, $unselectedCandidates);

        $this->assertEquals(0, $result['cancelled_count']);
        $this->assertCount(1, $result['routes']);
        $this->assertEquals('failed', $result['routes'][0]['status']);
    }

    // =========================================================================
    // handleIncomingCancellation() Tests — Regular route_cancel (no full_cancel)
    // =========================================================================

    /**
     * Test regular route_cancel just acknowledges without cancelling P2P
     *
     * Multi-route safety: this node may still be part of the selected route
     * in a diamond topology, so we must NOT cancel the P2P or release reservation.
     */
    public function testRegularRouteCancelJustAcknowledges(): void
    {
        $this->p2pRepository->expects($this->never())
            ->method('getByHash');
        $this->p2pRepository->expects($this->never())
            ->method('updateStatus');
        $this->capacityReservationRepository->expects($this->never())
            ->method('releaseByHash');

        ob_start();
        $this->service->handleIncomingCancellation(['hash' => self::TEST_HASH]);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertEquals('acknowledged', $decoded['status']);
        $this->assertStringContainsString('acknowledged', $decoded['message']);
    }

    /**
     * Test regular route_cancel does not propagate downstream
     */
    public function testRegularRouteCancelDoesNotPropagate(): void
    {
        $this->service->setP2pService($this->p2pService);

        $this->p2pService->expects($this->never())
            ->method('broadcastFullCancelForHash');

        ob_start();
        $this->service->handleIncomingCancellation(['hash' => self::TEST_HASH]);
        ob_get_clean();
    }

    // =========================================================================
    // handleIncomingCancellation() Tests — Full cancel (full_cancel=true)
    // =========================================================================

    /**
     * Test full cancel marks local P2P as cancelled
     */
    public function testFullCancelMarksP2pCancelled(): void
    {
        $this->service->setP2pService($this->p2pService);

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn(['hash' => self::TEST_HASH, 'status' => 'pending']);

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_CANCELLED);

        ob_start();
        $this->service->handleIncomingCancellation(['hash' => self::TEST_HASH, 'full_cancel' => true]);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertEquals('acknowledged', $decoded['status']);
        $this->assertStringContainsString('Full cancel', $decoded['message']);
    }

    /**
     * Test full cancel releases capacity reservation
     */
    public function testFullCancelReleasesReservation(): void
    {
        $this->service->setP2pService($this->p2pService);

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn(['hash' => self::TEST_HASH, 'status' => 'pending']);

        $this->capacityReservationRepository->expects($this->once())
            ->method('releaseByHash')
            ->with(self::TEST_HASH, 'cancelled');

        ob_start();
        $this->service->handleIncomingCancellation(['hash' => self::TEST_HASH, 'full_cancel' => true]);
        ob_get_clean();
    }

    /**
     * Test full cancel propagates downstream via broadcastFullCancelForHash
     */
    public function testFullCancelPropagatesDownstream(): void
    {
        $this->service->setP2pService($this->p2pService);

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn(['hash' => self::TEST_HASH, 'status' => 'pending']);

        $this->p2pService->expects($this->once())
            ->method('broadcastFullCancelForHash')
            ->with(self::TEST_HASH);

        ob_start();
        $this->service->handleIncomingCancellation(['hash' => self::TEST_HASH, 'full_cancel' => true]);
        ob_get_clean();
    }

    /**
     * Test full cancel skips status update for terminal statuses
     */
    public function testFullCancelSkipsTerminalStatuses(): void
    {
        $terminalStatuses = [
            Constants::STATUS_COMPLETED,
            Constants::STATUS_CANCELLED,
            Constants::STATUS_EXPIRED,
        ];

        foreach ($terminalStatuses as $terminalStatus) {
            $p2pRepo = $this->createMock(P2pRepository::class);
            $capacityRepo = $this->createMock(CapacityReservationRepository::class);
            $p2pMock = $this->createMock(P2pServiceInterface::class);
            $cancelRepo = $this->createMock(RouteCancellationRepository::class);
            $repoFactory = $this->createMock(RepositoryFactory::class);
            $repoFactory->method('get')
                ->willReturnCallback(function (string $class) use ($capacityRepo, $cancelRepo, $p2pRepo) {
                    if ($class === CapacityReservationRepository::class) return $capacityRepo;
                    if ($class === RouteCancellationRepository::class) return $cancelRepo;
                    if ($class === P2pRepository::class) return $p2pRepo;
                    return null;
                });
            $service = new RouteCancellationService($repoFactory);
            $service->setP2pService($p2pMock);

            $p2pRepo->method('getByHash')
                ->with(self::TEST_HASH)
                ->willReturn(['hash' => self::TEST_HASH, 'status' => $terminalStatus]);

            $p2pRepo->expects($this->never())
                ->method('updateStatus');

            ob_start();
            $service->handleIncomingCancellation(['hash' => self::TEST_HASH, 'full_cancel' => true]);
            ob_get_clean();
        }
    }

    /**
     * Test handleIncomingCancellation handles missing hash in request
     */
    public function testHandleIncomingCancellationHandlesMissingHash(): void
    {
        $this->p2pRepository->expects($this->never())
            ->method('getByHash');

        ob_start();
        $this->service->handleIncomingCancellation([]);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertEquals('rejected', $decoded['status']);
        $this->assertStringContainsString('Missing hash', $decoded['message']);
    }

    /**
     * Test full cancel handles P2P not found in repository
     */
    public function testFullCancelHandlesMissingP2p(): void
    {
        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn(null);

        $this->p2pRepository->expects($this->never())
            ->method('updateStatus');

        ob_start();
        $this->service->handleIncomingCancellation(['hash' => self::TEST_HASH, 'full_cancel' => true]);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertEquals('acknowledged', $decoded['status']);
        $this->assertStringContainsString('No local P2P found', $decoded['message']);
    }

    // =========================================================================
    // generateRandomizedHopBudget() / computeHopBudget() Tests
    // =========================================================================

    /**
     * Test generateRandomizedHopBudget always returns within bounds (randomized mode)
     *
     * Run 100 times to verify statistical property: all results must be within [min, max].
     */
    public function testGenerateRandomizedHopBudgetReturnsWithinBounds(): void
    {
        // Ensure randomization is enabled for this test
        putenv('EIOU_HOP_BUDGET_RANDOMIZED=true');

        $minHops = 2;
        $maxHops = 8;

        for ($i = 0; $i < 100; $i++) {
            $result = $this->service->generateRandomizedHopBudget($minHops, $maxHops);
            $this->assertGreaterThanOrEqual($minHops, $result, "Iteration $i: result $result below min $minHops");
            $this->assertLessThanOrEqual($maxHops, $result, "Iteration $i: result $result above max $maxHops");
        }

        putenv('EIOU_HOP_BUDGET_RANDOMIZED');
    }

    /**
     * Test generateRandomizedHopBudget clamps negative min to zero
     */
    public function testGenerateRandomizedHopBudgetClampsNegativeMin(): void
    {
        putenv('EIOU_HOP_BUDGET_RANDOMIZED=true');

        for ($i = 0; $i < 50; $i++) {
            $result = $this->service->generateRandomizedHopBudget(-5, 3);
            $this->assertGreaterThanOrEqual(0, $result, "Result $result should not be negative");
            $this->assertLessThanOrEqual(3, $result, "Result $result exceeds max of 3");
        }

        putenv('EIOU_HOP_BUDGET_RANDOMIZED');
    }

    /**
     * Test generateRandomizedHopBudget handles max less than min
     *
     * When maxHops < minHops, maxHops should be clamped to minHops.
     */
    public function testGenerateRandomizedHopBudgetHandlesMaxLessThanMin(): void
    {
        $result = $this->service->generateRandomizedHopBudget(5, 2);

        // maxHops is clamped to minHops (5), so result should be exactly 5
        $this->assertEquals(5, $result);
    }

    // =========================================================================
    // EIOU_HOP_BUDGET_RANDOMIZED env var Tests
    // =========================================================================

    /**
     * Test computeHopBudget returns maxHops when randomization is disabled
     *
     * When EIOU_HOP_BUDGET_RANDOMIZED=false, the hop budget should always
     * equal maxHops for deterministic test behavior.
     */
    public function testComputeHopBudgetReturnsDeterministicWhenDisabled(): void
    {
        putenv('EIOU_HOP_BUDGET_RANDOMIZED=false');

        for ($i = 0; $i < 50; $i++) {
            $result = RouteCancellationService::computeHopBudget(1, 6);
            $this->assertEquals(6, $result, "Iteration $i: deterministic mode should always return maxHops");
        }

        putenv('EIOU_HOP_BUDGET_RANDOMIZED');
    }

    /**
     * Test computeHopBudget returns varied results when randomization is enabled
     *
     * With enough iterations the geometric distribution (30% stop probability)
     * should produce at least two distinct values over 200 samples.
     */
    public function testComputeHopBudgetReturnsVariedWhenEnabled(): void
    {
        putenv('EIOU_HOP_BUDGET_RANDOMIZED=true');

        $results = [];
        for ($i = 0; $i < 200; $i++) {
            $results[] = RouteCancellationService::computeHopBudget(1, 10);
        }

        $unique = array_unique($results);
        $this->assertGreaterThan(1, count($unique), 'Randomized mode should produce varied results over 200 iterations');

        putenv('EIOU_HOP_BUDGET_RANDOMIZED');
    }

    /**
     * Test generateRandomizedHopBudget delegates to computeHopBudget correctly
     *
     * The instance method should respect the env var just like the static method.
     */
    public function testInstanceMethodRespectsEnvVar(): void
    {
        putenv('EIOU_HOP_BUDGET_RANDOMIZED=false');

        $result = $this->service->generateRandomizedHopBudget(1, 8);
        $this->assertEquals(8, $result, 'Instance method should return maxHops when randomization disabled');

        putenv('EIOU_HOP_BUDGET_RANDOMIZED');
    }

    /**
     * Test computeHopBudget deterministic mode with edge cases
     */
    public function testComputeHopBudgetDeterministicEdgeCases(): void
    {
        putenv('EIOU_HOP_BUDGET_RANDOMIZED=false');

        // min=0, max=0: should return 0
        $this->assertEquals(0, RouteCancellationService::computeHopBudget(0, 0));

        // min=max: should return that value
        $this->assertEquals(5, RouteCancellationService::computeHopBudget(5, 5));

        // max < min: clamped to min
        $this->assertEquals(5, RouteCancellationService::computeHopBudget(5, 2));

        // negative min clamped to 0
        $this->assertEquals(3, RouteCancellationService::computeHopBudget(-5, 3));

        putenv('EIOU_HOP_BUDGET_RANDOMIZED');
    }

    /**
     * Test computeHopBudget defaults to randomized (constant is true)
     *
     * Without the env var set, the constant HOP_BUDGET_RANDOMIZED=true applies.
     * We verify the method still returns within bounds (randomized behavior).
     */
    public function testComputeHopBudgetDefaultIsRandomized(): void
    {
        // Clear env var to fall back on constant
        putenv('EIOU_HOP_BUDGET_RANDOMIZED');

        for ($i = 0; $i < 100; $i++) {
            $result = RouteCancellationService::computeHopBudget(1, 6);
            $this->assertGreaterThanOrEqual(1, $result);
            $this->assertLessThanOrEqual(6, $result);
        }
    }

    // =========================================================================
    // computeHopBudget() $randomized parameter Tests
    // =========================================================================

    /**
     * Test computeHopBudget with explicit $randomized=false returns maxHops
     * regardless of the env var setting.
     */
    public function testComputeHopBudgetExplicitFalseReturnsDeterministic(): void
    {
        // Even with env set to true, explicit false should override
        putenv('EIOU_HOP_BUDGET_RANDOMIZED=true');

        for ($i = 0; $i < 50; $i++) {
            $result = RouteCancellationService::computeHopBudget(1, 6, false);
            $this->assertEquals(6, $result, "Explicit randomized=false should always return maxHops");
        }

        putenv('EIOU_HOP_BUDGET_RANDOMIZED');
    }

    /**
     * Test computeHopBudget with explicit $randomized=true produces varied results
     * regardless of the env var setting.
     */
    public function testComputeHopBudgetExplicitTrueReturnsVaried(): void
    {
        // Even with env set to false, explicit true should randomize
        putenv('EIOU_HOP_BUDGET_RANDOMIZED=false');

        $results = [];
        for ($i = 0; $i < 200; $i++) {
            $results[] = RouteCancellationService::computeHopBudget(1, 10, true);
        }

        $unique = array_unique($results);
        $this->assertGreaterThan(1, count($unique), 'Explicit randomized=true should produce varied results');

        putenv('EIOU_HOP_BUDGET_RANDOMIZED');
    }

    /**
     * Test computeHopBudget with $randomized=null falls back to Constants
     */
    public function testComputeHopBudgetNullFallsBackToConstant(): void
    {
        putenv('EIOU_HOP_BUDGET_RANDOMIZED=false');

        // null should behave like the env var (false → deterministic)
        for ($i = 0; $i < 50; $i++) {
            $result = RouteCancellationService::computeHopBudget(1, 6, null);
            $this->assertEquals(6, $result, "null randomized should fall back to env/constant");
        }

        putenv('EIOU_HOP_BUDGET_RANDOMIZED');
    }

    // =========================================================================
    // decrementHopBudget() Tests
    // =========================================================================

    /**
     * Test decrementHopBudget decrements by one
     */
    public function testDecrementHopBudgetDecrementsCorrectly(): void
    {
        $this->assertEquals(4, $this->service->decrementHopBudget(5));
        $this->assertEquals(0, $this->service->decrementHopBudget(1));
        $this->assertEquals(9, $this->service->decrementHopBudget(10));
    }

    /**
     * Test decrementHopBudget never goes below zero
     */
    public function testDecrementHopBudgetNeverGoesBelowZero(): void
    {
        $this->assertEquals(0, $this->service->decrementHopBudget(0));
        $this->assertEquals(0, $this->service->decrementHopBudget(-1));
        $this->assertEquals(0, $this->service->decrementHopBudget(-100));
    }
}
