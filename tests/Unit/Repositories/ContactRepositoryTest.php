<?php
/**
 * Unit Tests for ContactRepository
 *
 * Tests contact repository database operations including CRUD operations,
 * status management, and lookup functions with mocked PDO.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Eiou\Database\ContactRepository;
use Eiou\Core\Constants;
use PDO;
use PDOStatement;

#[CoversClass(ContactRepository::class)]
class ContactRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private ContactRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new ContactRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor sets table name correctly
     */
    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('contacts', $this->repository->getTableName());
    }

    /**
     * Test constructor accepts PDO dependency injection
     */
    public function testConstructorAcceptsPdoDependencyInjection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repository = new ContactRepository($pdo);

        $this->assertSame($pdo, $repository->getPdo());
    }

    // =========================================================================
    // acceptContact() Tests
    // =========================================================================

    /**
     * Test acceptContact updates contact status to accepted
     */
    public function testAcceptContactUpdatesStatusToAccepted(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->acceptContact(
            'sender-pubkey-123',
            'Contact Name',
            1.5,
            1000.0,
            'USD'
        );

        $this->assertTrue($result);
    }

    /**
     * Test acceptContact returns false when no rows affected
     */
    public function testAcceptContactReturnsFalseWhenNoRowsAffected(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->acceptContact(
            'nonexistent-pubkey',
            'Contact Name',
            1.5,
            1000.0,
            'USD'
        );

        $this->assertFalse($result);
    }

    // =========================================================================
    // addPendingContact() Tests
    // =========================================================================

    /**
     * Test addPendingContact inserts contact with pending status
     */
    public function testAddPendingContactInsertsPendingContact(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->addPendingContact('sender-pubkey-123');

        $this->assertEquals('1', $result);
    }

    // =========================================================================
    // getPublicKeyFromAddress() Tests
    // =========================================================================

    /**
     * Test getPublicKeyFromAddress returns pubkey for valid transport
     */
    public function testGetPublicKeyFromAddressReturnsPubkeyForValidTransport(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn('contact-pubkey-abc');

        $result = $this->repository->getPublicKeyFromAddress('http', 'http://example.com');

        $this->assertEquals('contact-pubkey-abc', $result);
    }

    /**
     * Test getPublicKeyFromAddress returns null for invalid transport index
     */
    public function testGetPublicKeyFromAddressReturnsNullForInvalidTransport(): void
    {
        $result = $this->repository->getPublicKeyFromAddress('invalid', 'http://example.com');

        $this->assertNull($result);
    }

    /**
     * Test getPublicKeyFromAddress returns null when not found
     */
    public function testGetPublicKeyFromAddressReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(false);

        $result = $this->repository->getPublicKeyFromAddress('http', 'http://nonexistent.com');

        $this->assertNull($result);
    }

    // =========================================================================
    // blockContact() / unblockContact() Tests
    // =========================================================================

    /**
     * Test blockContact updates status to blocked
     */
    public function testBlockContactUpdatesStatusToBlocked(): void
    {
        // First call: getPublicKeyFromAddress
        // Second call: update status
        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn('contact-pubkey-123');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->blockContact('http', 'http://example.com');

        $this->assertTrue($result);
    }

    /**
     * Test unblockContact updates status to accepted
     */
    public function testUnblockContactUpdatesStatusToAccepted(): void
    {
        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn('contact-pubkey-123');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->unblockContact('http', 'http://example.com');

        $this->assertTrue($result);
    }

    // =========================================================================
    // deleteContact() Tests
    // =========================================================================

    /**
     * Test deleteContact removes contact by pubkey
     */
    public function testDeleteContactRemovesContactByPubkey(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->deleteContact('contact-pubkey-123');

        $this->assertTrue($result);
    }

    /**
     * Test deleteContact returns false when contact not found
     */
    public function testDeleteContactReturnsFalseWhenContactNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->deleteContact('nonexistent-pubkey');

        $this->assertFalse($result);
    }

    // =========================================================================
    // updateContactStatus() Tests
    // =========================================================================

    /**
     * Test updateContactStatus updates status successfully
     */
    public function testUpdateContactStatusUpdatesStatusSuccessfully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->updateContactStatus('contact-pubkey-123', 'accepted');

        $this->assertTrue($result);
    }

    /**
     * Test updateContactStatus returns false on failure
     */
    public function testUpdateContactStatusReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Database error'));

        $result = $this->repository->updateContactStatus('contact-pubkey-123', 'accepted');

        $this->assertFalse($result);
    }

    // =========================================================================
    // isAcceptedContactPubkey() Tests
    // =========================================================================

    /**
     * Test isAcceptedContactPubkey returns true for accepted contact
     */
    public function testIsAcceptedContactPubkeyReturnsTrueForAcceptedContact(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(1);

        $result = $this->repository->isAcceptedContactPubkey('accepted-pubkey');

        $this->assertTrue($result);
    }

    /**
     * Test isAcceptedContactPubkey returns false for non-accepted contact
     */
    public function testIsAcceptedContactPubkeyReturnsFalseForNonAcceptedContact(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(0);

        $result = $this->repository->isAcceptedContactPubkey('pending-pubkey');

        $this->assertFalse($result);
    }

    // =========================================================================
    // isAcceptedContactAddress() Tests
    // =========================================================================

    /**
     * Test isAcceptedContactAddress returns false for invalid transport
     */
    public function testIsAcceptedContactAddressReturnsFalseForInvalidTransport(): void
    {
        $result = $this->repository->isAcceptedContactAddress('invalid', 'http://example.com');

        $this->assertFalse($result);
    }

    // =========================================================================
    // countAcceptedContacts() Tests
    // =========================================================================

    /**
     * Test countAcceptedContacts returns count of accepted contacts
     */
    public function testCountAcceptedContactsReturnsCount(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(5);

        $result = $this->repository->countAcceptedContacts();

        $this->assertEquals(5, $result);
    }

    /**
     * Test countAcceptedContacts returns zero when query fails
     */
    public function testCountAcceptedContactsReturnsZeroOnQueryFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->countAcceptedContacts();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // contactExistsPubkey() Tests
    // =========================================================================

    /**
     * Test contactExistsPubkey returns true when contact exists
     */
    public function testContactExistsPubkeyReturnsTrueWhenExists(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 1]);

        $result = $this->repository->contactExistsPubkey('existing-pubkey');

        $this->assertTrue($result);
    }

    /**
     * Test contactExistsPubkey returns false when contact does not exist
     */
    public function testContactExistsPubkeyReturnsFalseWhenNotExists(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 0]);

        $result = $this->repository->contactExistsPubkey('nonexistent-pubkey');

        $this->assertFalse($result);
    }

    // =========================================================================
    // checkForNewContactRequests() Tests
    // =========================================================================

    /**
     * Test checkForNewContactRequests returns true when new requests exist
     */
    public function testCheckForNewContactRequestsReturnsTrueWhenRequestsExist(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 2]);

        $result = $this->repository->checkForNewContactRequests(time() - 3600);

        $this->assertTrue($result);
    }

    /**
     * Test checkForNewContactRequests returns false when no new requests
     */
    public function testCheckForNewContactRequestsReturnsFalseWhenNoRequests(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 0]);

        $result = $this->repository->checkForNewContactRequests(time() - 3600);

        $this->assertFalse($result);
    }

    // =========================================================================
    // isNotBlocked() Tests
    // =========================================================================

    /**
     * Test isNotBlocked returns true when contact is not blocked
     */
    public function testIsNotBlockedReturnsTrueWhenNotBlocked(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 0]);

        $result = $this->repository->isNotBlocked('not-blocked-pubkey');

        $this->assertTrue($result);
    }

    /**
     * Test isNotBlocked returns false when contact is blocked
     */
    public function testIsNotBlockedReturnsFalseWhenBlocked(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 1]);

        $result = $this->repository->isNotBlocked('blocked-pubkey');

        $this->assertFalse($result);
    }

    /**
     * Test isNotBlocked returns true when query fails (fail open)
     */
    public function testIsNotBlockedReturnsTrueWhenQueryFails(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->isNotBlocked('any-pubkey');

        $this->assertTrue($result);
    }

    // =========================================================================
    // hasPendingContact() Tests
    // =========================================================================

    /**
     * Test hasPendingContact returns true when pending contact exists
     */
    public function testHasPendingContactReturnsTrueWhenExists(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 1]);

        $result = $this->repository->hasPendingContact('pending-pubkey');

        $this->assertTrue($result);
    }

    /**
     * Test hasPendingContact returns false when no pending contact
     */
    public function testHasPendingContactReturnsFalseWhenNotExists(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 0]);

        $result = $this->repository->hasPendingContact('no-pending-pubkey');

        $this->assertFalse($result);
    }

    // =========================================================================
    // getCreditLimit() Tests
    // =========================================================================

    /**
     * Test getCreditLimit returns credit limit for contact
     */
    public function testGetCreditLimitReturnsCreditLimit(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['credit_limit' => 5000]);

        $result = $this->repository->getCreditLimit('contact-pubkey');

        $this->assertEquals(5000.0, $result);
    }

    /**
     * Test getCreditLimit returns zero when not found
     */
    public function testGetCreditLimitReturnsZeroWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(null);

        $result = $this->repository->getCreditLimit('nonexistent-pubkey');

        $this->assertEquals(0.0, $result);
    }

    // =========================================================================
    // insertContact() Tests
    // =========================================================================

    /**
     * Test insertContact creates new contact
     */
    public function testInsertContactCreatesNewContact(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->insertContact(
            'new-contact-pubkey',
            'New Contact',
            0.1,
            1000.0,
            'USD'
        );

        $this->assertTrue($result);
    }

    /**
     * Test insertContact returns false on failure
     */
    public function testInsertContactReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->insertContact(
            'new-contact-pubkey',
            'New Contact',
            0.1,
            1000.0,
            'USD'
        );

        $this->assertFalse($result);
    }

    // =========================================================================
    // lookupAllByName() Tests
    // =========================================================================

    /**
     * Test lookupAllByName returns all matching contacts
     */
    public function testLookupAllByNameReturnsAllMatches(): void
    {
        $contacts = [
            ['pubkey' => 'key1', 'name' => 'John', 'http' => 'http://node1.example.com'],
            ['pubkey' => 'key2', 'name' => 'John', 'http' => 'http://node2.example.com'],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($contacts);

        $result = $this->repository->lookupAllByName('John');

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('key1', $result[0]['pubkey']);
        $this->assertEquals('key2', $result[1]['pubkey']);
    }

    /**
     * Test lookupAllByName returns empty array when no matches
     */
    public function testLookupAllByNameReturnsEmptyWhenNoMatches(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->lookupAllByName('Nonexistent');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test lookupAllByName returns single match as array with one element
     */
    public function testLookupAllByNameReturnsSingleMatchAsArray(): void
    {
        $contacts = [
            ['pubkey' => 'key1', 'name' => 'Alice', 'http' => 'http://alice.example.com'],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($contacts);

        $result = $this->repository->lookupAllByName('Alice');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    /**
     * Test lookupAllByName returns empty array on query failure
     */
    public function testLookupAllByNameReturnsEmptyOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->lookupAllByName('Test');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // lookupByName() Tests
    // =========================================================================

    /**
     * Test lookupByName returns contact data
     */
    public function testLookupByNameReturnsContactData(): void
    {
        $contactData = [
            'pubkey' => 'test-pubkey',
            'name' => 'Test Contact',
            'status' => 'accepted',
            'http' => 'http://test.example.com'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($contactData);

        $result = $this->repository->lookupByName('Test Contact');

        $this->assertEquals($contactData, $result);
    }

    /**
     * Test lookupByName returns null when not found
     */
    public function testLookupByNameReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->lookupByName('Nonexistent Contact');

        $this->assertNull($result);
    }

    // =========================================================================
    // lookupByAddress() Tests
    // =========================================================================

    /**
     * Test lookupByAddress returns null for invalid transport
     */
    public function testLookupByAddressReturnsNullForInvalidTransport(): void
    {
        $result = $this->repository->lookupByAddress('invalid', 'http://example.com');

        $this->assertNull($result);
    }

    /**
     * Test lookupByAddress returns contact data
     */
    public function testLookupByAddressReturnsContactData(): void
    {
        $contactData = [
            'pubkey' => 'test-pubkey',
            'name' => 'Test Contact',
            'status' => 'accepted',
            'http' => 'http://test.example.com'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($contactData);

        $result = $this->repository->lookupByAddress('http', 'http://test.example.com');

        $this->assertEquals($contactData, $result);
    }

    // =========================================================================
    // getPendingContactRequests() Tests
    // =========================================================================

    /**
     * Test getPendingContactRequests returns array of pending contacts
     */
    public function testGetPendingContactRequestsReturnsArray(): void
    {
        $pendingContacts = [
            ['pubkey' => 'pending-1', 'status' => 'pending', 'name' => null],
            ['pubkey' => 'pending-2', 'status' => 'pending', 'name' => null]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($pendingContacts);

        $result = $this->repository->getPendingContactRequests();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * Test getPendingContactRequests returns empty array on failure
     */
    public function testGetPendingContactRequestsReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->getPendingContactRequests();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getContactsByStatus() Tests
    // =========================================================================

    /**
     * Test getContactsByStatus returns contacts with specified status
     */
    public function testGetContactsByStatusReturnsContactsWithStatus(): void
    {
        $contacts = [
            ['pubkey' => 'blocked-1', 'status' => 'blocked'],
            ['pubkey' => 'blocked-2', 'status' => 'blocked']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($contacts);

        $result = $this->repository->getContactsByStatus('blocked');

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    // =========================================================================
    // getAllContacts() Tests
    // =========================================================================

    /**
     * Test getAllContacts returns all contacts
     */
    public function testGetAllContactsReturnsAllContacts(): void
    {
        $contacts = [
            ['pubkey' => 'contact-1', 'name' => 'Contact One'],
            ['pubkey' => 'contact-2', 'name' => 'Contact Two']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($contacts);

        $result = $this->repository->getAllContacts();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * Test getAllContacts returns empty array when no contacts
     */
    public function testGetAllContactsReturnsEmptyArrayWhenNoContacts(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->getAllContacts();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getRecentContacts() Tests
    // =========================================================================

    /**
     * Test getRecentContacts returns limited recent contacts
     */
    public function testGetRecentContactsReturnsLimitedContacts(): void
    {
        $contacts = [
            ['pubkey' => 'recent-1', 'name' => 'Recent One'],
            ['pubkey' => 'recent-2', 'name' => 'Recent Two']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($contacts);

        $result = $this->repository->getRecentContacts(5);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    // =========================================================================
    // updateContactFields() Tests
    // =========================================================================

    /**
     * Test updateContactFields returns false for empty fields
     */
    public function testUpdateContactFieldsReturnsFalseForEmptyFields(): void
    {
        $result = $this->repository->updateContactFields('pubkey-123', []);

        $this->assertFalse($result);
    }

    /**
     * Test updateContactFields updates fields successfully
     */
    public function testUpdateContactFieldsUpdatesSuccessfully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateContactFields('pubkey-123', ['name' => 'New Name']);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Valid Transport Index Tests
    // =========================================================================

    /**
     * Provide valid transport indices for testing
     */
    public static function validTransportIndicesProvider(): array
    {
        return [
            'http' => ['http'],
            'https' => ['https'],
            'tor' => ['tor'],
            'HTTP uppercase' => ['HTTP'],
            'HTTPS uppercase' => ['HTTPS'],
            'TOR uppercase' => ['TOR'],
        ];
    }

    /**
     * Provide invalid transport indices for testing
     */
    public static function invalidTransportIndicesProvider(): array
    {
        return [
            'ftp' => ['ftp'],
            'ssh' => ['ssh'],
            'empty' => [''],
            'numeric' => ['123'],
            'special chars' => ['http://'],
        ];
    }

    /**
     * Test lookupNameByAddress returns null for null transport index
     */
    public function testLookupNameByAddressReturnsNullForNullTransport(): void
    {
        $result = $this->repository->lookupNameByAddress(null, 'http://example.com');

        $this->assertNull($result);
    }

    /**
     * Test getAllSingleAcceptedAddresses returns empty for invalid transport
     */
    public function testGetAllSingleAcceptedAddressesReturnsEmptyForInvalidTransport(): void
    {
        $result = $this->repository->getAllSingleAcceptedAddresses('invalid');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
