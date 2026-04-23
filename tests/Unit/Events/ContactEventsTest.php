<?php

declare(strict_types=1);

namespace Eiou\Tests\Events;

use Eiou\Events\ContactEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactEvents::class)]
class ContactEventsTest extends TestCase
{
    public function testConstantsHaveStableStringValues(): void
    {
        $this->assertSame('contact.added',    ContactEvents::CONTACT_ADDED);
        $this->assertSame('contact.accepted', ContactEvents::CONTACT_ACCEPTED);
        $this->assertSame('contact.rejected', ContactEvents::CONTACT_REJECTED);
        $this->assertSame('contact.blocked',  ContactEvents::CONTACT_BLOCKED);
    }
}
