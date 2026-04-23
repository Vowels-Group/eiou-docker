<?php

declare(strict_types=1);

namespace Eiou\Tests\Events;

use Eiou\Events\PluginEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PluginEvents::class)]
class PluginEventsTest extends TestCase
{
    public function testConstantsHaveStableStringValues(): void
    {
        $this->assertSame('plugin.registered', PluginEvents::PLUGIN_REGISTERED);
        $this->assertSame('plugin.booted',     PluginEvents::PLUGIN_BOOTED);
        $this->assertSame('plugin.failed',     PluginEvents::PLUGIN_FAILED);
    }
}
