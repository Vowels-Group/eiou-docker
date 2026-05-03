<?php

declare(strict_types=1);

namespace Eiou\Tests\Events;

use Eiou\Events\TransactionEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransactionEvents::class)]
class TransactionEventsTest extends TestCase
{
    public function testConstantsHaveStableStringValues(): void
    {
        $this->assertSame('transaction.created',  TransactionEvents::TRANSACTION_CREATED);
        $this->assertSame('transaction.sent',     TransactionEvents::TRANSACTION_SENT);
        $this->assertSame('transaction.received', TransactionEvents::TRANSACTION_RECEIVED);
        $this->assertSame('transaction.failed',   TransactionEvents::TRANSACTION_FAILED);
    }
}
