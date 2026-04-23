<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Eiou\Contracts\P2pServiceInterface;
use Eiou\Contracts\P2pTransactionSenderInterface;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Events\EventDispatcher;
use Eiou\Events\P2pEvents;
use Eiou\Services\P2pApprovalService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(P2pApprovalService::class)]
class P2pApprovalServiceTest extends TestCase
{
    private MockObject|P2pRepository $p2pRepo;
    private MockObject|Rp2pRepository $rp2pRepo;
    private MockObject|Rp2pCandidateRepository $candidateRepo;
    private MockObject|P2pTransactionSenderInterface $sender;
    private MockObject|P2pServiceInterface $p2pService;
    private P2pApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        EventDispatcher::resetInstance();

        $this->p2pRepo = $this->createMock(P2pRepository::class);
        $this->rp2pRepo = $this->createMock(Rp2pRepository::class);
        $this->candidateRepo = $this->createMock(Rp2pCandidateRepository::class);
        $this->sender = $this->createMock(P2pTransactionSenderInterface::class);
        $this->p2pService = $this->createMock(P2pServiceInterface::class);

        $this->service = new P2pApprovalService(
            $this->p2pRepo,
            $this->rp2pRepo,
            $this->candidateRepo,
            $this->sender,
            $this->p2pService,
        );
    }

    protected function tearDown(): void
    {
        EventDispatcher::resetInstance();
        parent::tearDown();
    }

    // -- Gate failures -----------------------------------------------------

    public function testApproveNotFoundWhenHashUnknown(): void
    {
        $this->p2pRepo->method('getAwaitingApproval')->willReturn(null);

        $result = $this->service->approve('deadbeef');

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['code']);
        $this->assertSame(404, $result['status']);
    }

    public function testApproveNotOriginatorWhenDestinationMissing(): void
    {
        $this->p2pRepo->method('getAwaitingApproval')
            ->willReturn(['destination_address' => '']); // relay, not originator

        $result = $this->service->approve('deadbeef');

        $this->assertFalse($result['success']);
        $this->assertSame('not_originator', $result['code']);
        $this->assertSame(403, $result['status']);
    }

    // -- Approve happy paths ----------------------------------------------

    public function testApproveByCandidateIdFiresEventAndSends(): void
    {
        $hash = 'abc123';
        $this->p2pRepo->method('getAwaitingApproval')
            ->willReturn(['destination_address' => 'http://example.com']);
        $this->candidateRepo->expects($this->once())
            ->method('getCandidateById')
            ->with(42)
            ->willReturn([
                'hash' => $hash,
                'time' => 123,
                'amount' => '10',
                'currency' => 'USD',
                'sender_public_key' => 'pk',
                'sender_address' => 'http://relay.example',
                'sender_signature' => 'sig',
            ]);
        $this->rp2pRepo->expects($this->once())->method('insertRp2pRequest');
        $this->p2pRepo->expects($this->once())->method('updateStatus')->with($hash, 'found');
        $this->sender->expects($this->once())->method('sendP2pEiou');
        $this->candidateRepo->expects($this->once())->method('deleteCandidatesByHash')->with($hash);

        $fired = null;
        EventDispatcher::getInstance()->subscribe(P2pEvents::P2P_APPROVED, function ($data) use (&$fired) {
            $fired = $data;
        });

        $result = $this->service->approve($hash, null, 42);

        $this->assertTrue($result['success']);
        $this->assertSame('candidate', $result['mode']);
        $this->assertSame('USD', $fired['currency']);
        $this->assertSame($hash, $fired['p2p_id']);
    }

    public function testApproveByCandidateIdRejectsMismatch(): void
    {
        $this->p2pRepo->method('getAwaitingApproval')
            ->willReturn(['destination_address' => 'http://example.com']);
        $this->candidateRepo->method('getCandidateById')
            ->willReturn(['hash' => 'OTHER_HASH']);

        $result = $this->service->approve('abc123', null, 42);

        $this->assertFalse($result['success']);
        $this->assertSame('candidate_mismatch', $result['code']);
        $this->assertSame(400, $result['status']);
    }

    public function testApproveByCandidateIndex(): void
    {
        $this->p2pRepo->method('getAwaitingApproval')
            ->willReturn(['destination_address' => 'http://example.com']);
        $this->candidateRepo->method('getCandidatesByHash')->willReturn([
            ['hash' => 'abc', 'time' => 1, 'amount' => '1', 'currency' => 'USD',
             'sender_public_key' => 'a', 'sender_address' => 'a', 'sender_signature' => 's'],
            ['hash' => 'abc', 'time' => 2, 'amount' => '2', 'currency' => 'USD',
             'sender_public_key' => 'b', 'sender_address' => 'b', 'sender_signature' => 's'],
        ]);
        $this->rp2pRepo->expects($this->once())->method('insertRp2pRequest');

        $result = $this->service->approve('abc', 2);

        $this->assertTrue($result['success']);
        $this->assertSame('b', $result['sender_address']);
    }

    public function testApproveFastModeUsesSingleRp2p(): void
    {
        $this->p2pRepo->method('getAwaitingApproval')
            ->willReturn(['destination_address' => 'http://example.com']);
        $this->candidateRepo->method('getCandidatesByHash')->willReturn([]);
        $this->rp2pRepo->method('getByHash')->willReturn([
            'hash' => 'abc', 'time' => 1, 'amount' => '1', 'currency' => 'USD',
            'sender_public_key' => 'p', 'sender_address' => 'a', 'sender_signature' => 's',
        ]);
        // Fast mode does NOT insert rp2p — the row already exists.
        $this->rp2pRepo->expects($this->never())->method('insertRp2pRequest');
        $this->sender->expects($this->once())->method('sendP2pEiou');

        $result = $this->service->approve('abc');

        $this->assertTrue($result['success']);
        $this->assertSame('fast', $result['mode']);
    }

    public function testApproveMultipleCandidatesRequiresSelection(): void
    {
        $this->p2pRepo->method('getAwaitingApproval')
            ->willReturn(['destination_address' => 'http://example.com']);
        $this->candidateRepo->method('getCandidatesByHash')->willReturn([
            ['hash' => 'a'], ['hash' => 'a'],
        ]);

        $result = $this->service->approve('abc');

        $this->assertFalse($result['success']);
        $this->assertSame('candidate_selection_required', $result['code']);
    }

    public function testApproveNoRoute(): void
    {
        $this->p2pRepo->method('getAwaitingApproval')
            ->willReturn(['destination_address' => 'http://example.com']);
        $this->candidateRepo->method('getCandidatesByHash')->willReturn([]);
        $this->rp2pRepo->method('getByHash')->willReturn(null);

        $result = $this->service->approve('abc');

        $this->assertFalse($result['success']);
        $this->assertSame('no_route', $result['code']);
    }

    // -- Reject -----------------------------------------------------------

    public function testRejectFiresEventAndBroadcastsFullCancel(): void
    {
        $this->p2pRepo->method('getAwaitingApproval')
            ->willReturn(['destination_address' => 'http://example.com']);
        $this->p2pRepo->expects($this->once())->method('updateStatus');
        // Reject MUST use broadcastFullCancelForHash — the API and GUI used
        // to call sendCancelNotificationForHash here, which short-circuits
        // for originators (does nothing). The shared service fixes that.
        $this->p2pService->expects($this->once())->method('broadcastFullCancelForHash')->with('abc');
        $this->p2pService->expects($this->never())->method('sendCancelNotificationForHash');
        $this->candidateRepo->expects($this->once())->method('deleteCandidatesByHash')->with('abc');

        $fired = null;
        EventDispatcher::getInstance()->subscribe(P2pEvents::P2P_REJECTED, function ($data) use (&$fired) {
            $fired = $data;
        });

        $result = $this->service->reject('abc');

        $this->assertTrue($result['success']);
        $this->assertSame('abc', $fired['p2p_id']);
    }

    public function testRejectNotFound(): void
    {
        $this->p2pRepo->method('getAwaitingApproval')->willReturn(null);

        $result = $this->service->reject('abc');

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['code']);
    }

    public function testRejectNotOriginator(): void
    {
        $this->p2pRepo->method('getAwaitingApproval')
            ->willReturn(['destination_address' => '']);

        $result = $this->service->reject('abc');

        $this->assertFalse($result['success']);
        $this->assertSame('not_originator', $result['code']);
    }
}
