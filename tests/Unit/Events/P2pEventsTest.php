<?php

declare(strict_types=1);

namespace Eiou\Tests\Events;

use Eiou\Events\P2pEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(P2pEvents::class)]
class P2pEventsTest extends TestCase
{
    public function testConstantsHaveStableStringValues(): void
    {
        $this->assertSame('p2p.received',  P2pEvents::P2P_RECEIVED);
        $this->assertSame('p2p.approved',  P2pEvents::P2P_APPROVED);
        $this->assertSame('p2p.rejected',  P2pEvents::P2P_REJECTED);
        $this->assertSame('p2p.completed', P2pEvents::P2P_COMPLETED);
    }
}
