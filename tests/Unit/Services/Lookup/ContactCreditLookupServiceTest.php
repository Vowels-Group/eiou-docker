<?php
namespace Eiou\Tests\Services\Lookup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Contracts\PluginCallable;
use Eiou\Core\SplitAmount;
use Eiou\Database\ContactCreditRepository;
use Eiou\Services\Lookup\ContactCreditLookupService;
use ReflectionMethod;

#[CoversClass(ContactCreditLookupService::class)]
class ContactCreditLookupServiceTest extends TestCase
{
    private $repo;
    private ContactCreditLookupService $svc;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(ContactCreditRepository::class);
        $this->svc = new ContactCreditLookupService($this->repo);
    }

    // =========================================================================
    // Currency-specific path
    // =========================================================================

    public function testGetCreditStateWithCurrencyReturnsProjectedRow(): void
    {
        $this->repo->expects($this->once())
            ->method('getAvailableCredit')
            ->with('hash-x', 'USD')
            ->willReturn([
                'available_credit' => new SplitAmount(500, 0),
                'currency' => 'USD',
            ]);

        $r = $this->svc->getCreditState('hash-x', 'USD');
        $this->assertSame('USD', $r['currency']);
        $this->assertSame(500, $r['available_credit']['whole']);
        $this->assertSame(50000000000, $r['available_credit']['minor_units']);
    }

    public function testGetCreditStateWithCurrencyReturnsNullOnNoRow(): void
    {
        $this->repo->expects($this->once())
            ->method('getAvailableCredit')
            ->with('h', 'USD')
            ->willReturn(null);
        $this->assertNull($this->svc->getCreditState('h', 'USD'));
    }

    public function testGetCreditStateWithEmptyCurrencyReturnsNull(): void
    {
        $this->repo->expects($this->never())->method('getAvailableCredit');
        $this->assertNull($this->svc->getCreditState('h', ''));
        $this->assertNull($this->svc->getCreditState('h', '   '));
    }

    // =========================================================================
    // All-currencies path
    // =========================================================================

    public function testGetCreditStateWithoutCurrencyReturnsList(): void
    {
        $this->repo->expects($this->once())
            ->method('getAvailableCreditAllCurrencies')
            ->with('h')
            ->willReturn([
                ['available_credit' => new SplitAmount(100, 0), 'currency' => 'USD'],
                ['available_credit' => new SplitAmount(50, 0), 'currency' => 'EUR'],
            ]);

        $r = $this->svc->getCreditState('h');
        $this->assertCount(2, $r);
        $this->assertSame('USD', $r[0]['currency']);
        $this->assertSame('EUR', $r[1]['currency']);
    }

    public function testGetCreditStateWithoutCurrencyReturnsEmptyOnNoRows(): void
    {
        $this->repo->expects($this->once())
            ->method('getAvailableCreditAllCurrencies')
            ->willReturn([]);
        $this->assertSame([], $this->svc->getCreditState('h'));
    }

    public function testGetCreditStateWithoutCurrencyDropsMalformedRows(): void
    {
        $this->repo->method('getAvailableCreditAllCurrencies')->willReturn([
            ['available_credit' => new SplitAmount(1, 0), 'currency' => 'USD'],
            ['available_credit' => 'not split amount', 'currency' => 'BAD'],
            ['available_credit' => new SplitAmount(2, 0)], // missing currency
        ]);
        $r = $this->svc->getCreditState('h');
        $this->assertCount(1, $r);
        $this->assertSame('USD', $r[0]['currency']);
    }

    // =========================================================================
    // Empty pubkey_hash short-circuits
    // =========================================================================

    public function testEmptyPubkeyHashShortCircuitsWithCurrency(): void
    {
        $this->repo->expects($this->never())->method('getAvailableCredit');
        $this->assertNull($this->svc->getCreditState('', 'USD'));
    }

    public function testEmptyPubkeyHashShortCircuitsWithoutCurrency(): void
    {
        $this->repo->expects($this->never())->method('getAvailableCreditAllCurrencies');
        $this->assertSame([], $this->svc->getCreditState(''));
    }

    public function testNormalisesPubkeyHashCase(): void
    {
        $this->repo->expects($this->once())
            ->method('getAvailableCredit')
            ->with('abc', 'USD')
            ->willReturn(['available_credit' => SplitAmount::zero(), 'currency' => 'USD']);
        $this->svc->getCreditState(' ABC ', 'USD');
    }

    // =========================================================================
    // Permission-gate annotation
    // =========================================================================

    public function testRequiresContactCreditReadPermission(): void
    {
        $reflection = new ReflectionMethod(ContactCreditLookupService::class, 'getCreditState');
        $instance = $reflection->getAttributes(PluginCallable::class)[0]->newInstance();
        $this->assertSame('contact_credit_read', $instance->permission);
    }
}
