<?php
# Copyright 2025

/**
 * Messages Service Tests
 *
 * Unit tests for the Messages plugin service layer
 *
 * @package Plugins\Messages\Tests
 */

require_once __DIR__ . '/../models/Message.php';
require_once __DIR__ . '/../models/Conversation.php';

class MessagesServiceTest {
    /**
     * @var array Test results
     */
    private array $results = [];

    /**
     * @var int Total tests run
     */
    private int $total = 0;

    /**
     * @var int Passed tests
     */
    private int $passed = 0;

    /**
     * Run all tests
     *
     * @return array Test results
     */
    public function runAll(): array {
        $this->testMessageIdGeneration();
        $this->testConversationIdGeneration();
        $this->testConversationIdDeterministic();
        $this->testMessageValidation();
        $this->testMessageValidationFailures();
        $this->testMessageToArray();
        $this->testMessageToApiArray();
        $this->testConversationCreate();
        $this->testConversationIsParticipant();
        $this->testConversationToApiArray();
        $this->testMessageTypes();
        $this->testTimeAgoFormatting();

        return [
            'results' => $this->results,
            'total' => $this->total,
            'passed' => $this->passed,
            'failed' => $this->total - $this->passed
        ];
    }

    /**
     * Test message ID generation
     */
    private function testMessageIdGeneration(): void {
        $id1 = Message::generateMessageId();
        $id2 = Message::generateMessageId();

        $this->assert(
            'Message ID has correct prefix',
            str_starts_with($id1, 'msg_')
        );

        $this->assert(
            'Message ID has correct length',
            strlen($id1) === 36 // msg_ (4) + 32 hex chars
        );

        $this->assert(
            'Message IDs are unique',
            $id1 !== $id2
        );
    }

    /**
     * Test conversation ID generation
     */
    private function testConversationIdGeneration(): void {
        $key1 = 'abc123publickey';
        $key2 = 'xyz789publickey';

        $convId = Message::generateConversationId($key1, $key2);

        $this->assert(
            'Conversation ID has correct prefix',
            str_starts_with($convId, 'conv_')
        );

        $this->assert(
            'Conversation ID has correct length',
            strlen($convId) === 21 // conv_ (5) + 16 hex chars
        );
    }

    /**
     * Test conversation ID is deterministic regardless of key order
     */
    private function testConversationIdDeterministic(): void {
        $key1 = 'first_public_key';
        $key2 = 'second_public_key';

        $convId1 = Message::generateConversationId($key1, $key2);
        $convId2 = Message::generateConversationId($key2, $key1);

        $this->assert(
            'Conversation ID is same regardless of key order',
            $convId1 === $convId2
        );
    }

    /**
     * Test message validation passes for valid message
     */
    private function testMessageValidation(): void {
        $message = new Message();
        $message->messageId = Message::generateMessageId();
        $message->conversationId = 'conv_abc123';
        $message->senderPublicKey = 'sender_key_123';
        $message->recipientPublicKey = 'recipient_key_456';
        $message->content = 'Hello, world!';
        $message->messageType = Message::TYPE_TEXT;

        $errors = $message->validate();

        $this->assert(
            'Valid message has no validation errors',
            empty($errors)
        );
    }

    /**
     * Test message validation fails for invalid messages
     */
    private function testMessageValidationFailures(): void {
        // Missing message ID
        $message = new Message();
        $message->conversationId = 'conv_abc123';
        $message->senderPublicKey = 'sender_key';
        $message->recipientPublicKey = 'recipient_key';
        $message->content = 'Test';

        $errors = $message->validate();
        $this->assert(
            'Missing message ID causes validation error',
            in_array('message_id is required', $errors)
        );

        // Missing content
        $message2 = new Message();
        $message2->messageId = Message::generateMessageId();
        $message2->conversationId = 'conv_abc123';
        $message2->senderPublicKey = 'sender_key';
        $message2->recipientPublicKey = 'recipient_key';
        $message2->content = '';

        $errors2 = $message2->validate();
        $this->assert(
            'Empty content causes validation error',
            in_array('content is required', $errors2)
        );

        // Same sender and recipient
        $message3 = new Message();
        $message3->messageId = Message::generateMessageId();
        $message3->conversationId = 'conv_abc123';
        $message3->senderPublicKey = 'same_key';
        $message3->recipientPublicKey = 'same_key';
        $message3->content = 'Test';

        $errors3 = $message3->validate();
        $this->assert(
            'Same sender and recipient causes validation error',
            in_array('sender and recipient cannot be the same', $errors3)
        );
    }

    /**
     * Test message toArray
     */
    private function testMessageToArray(): void {
        $message = new Message();
        $message->messageId = 'msg_test123';
        $message->conversationId = 'conv_abc123';
        $message->senderPublicKey = 'sender_key';
        $message->recipientPublicKey = 'recipient_key';
        $message->content = 'Test message';
        $message->messageType = Message::TYPE_TEXT;
        $message->isEncrypted = false;
        $message->isRead = false;

        $array = $message->toArray();

        $this->assert(
            'toArray includes message_id',
            $array['message_id'] === 'msg_test123'
        );

        $this->assert(
            'toArray includes content',
            $array['content'] === 'Test message'
        );

        $this->assert(
            'toArray includes conversation_id',
            $array['conversation_id'] === 'conv_abc123'
        );
    }

    /**
     * Test message toApiArray
     */
    private function testMessageToApiArray(): void {
        $message = new Message();
        $message->messageId = 'msg_test123';
        $message->conversationId = 'conv_abc123';
        $message->senderPublicKey = 'sender_key';
        $message->recipientPublicKey = 'recipient_key';
        $message->content = 'Test message';
        $message->messageType = Message::TYPE_TEXT;
        $message->isRead = false;
        $message->createdAt = date('Y-m-d H:i:s');

        // From sender's perspective
        $senderView = $message->toApiArray('sender_key');
        $this->assert(
            'is_own_message true for sender',
            $senderView['is_own_message'] === true
        );

        // From recipient's perspective
        $recipientView = $message->toApiArray('recipient_key');
        $this->assert(
            'is_own_message false for recipient',
            $recipientView['is_own_message'] === false
        );
    }

    /**
     * Test conversation create
     */
    private function testConversationCreate(): void {
        $conv = Conversation::create('key_a', 'key_b');

        $this->assert(
            'Conversation has conversation_id',
            !empty($conv->conversationId)
        );

        $this->assert(
            'Conversation has participant1',
            !empty($conv->participant1PublicKey)
        );

        $this->assert(
            'Conversation has participant2',
            !empty($conv->participant2PublicKey)
        );

        $this->assert(
            'Conversation unread counts start at 0',
            $conv->unreadCount1 === 0 && $conv->unreadCount2 === 0
        );
    }

    /**
     * Test conversation isParticipant
     */
    private function testConversationIsParticipant(): void {
        $conv = new Conversation();
        $conv->participant1PublicKey = 'alice_key';
        $conv->participant2PublicKey = 'bob_key';

        $this->assert(
            'isParticipant returns true for participant1',
            $conv->isParticipant('alice_key') === true
        );

        $this->assert(
            'isParticipant returns true for participant2',
            $conv->isParticipant('bob_key') === true
        );

        $this->assert(
            'isParticipant returns false for non-participant',
            $conv->isParticipant('charlie_key') === false
        );
    }

    /**
     * Test conversation toApiArray
     */
    private function testConversationToApiArray(): void {
        $conv = new Conversation();
        $conv->conversationId = 'conv_test123';
        $conv->participant1PublicKey = 'alice_key';
        $conv->participant2PublicKey = 'bob_key';
        $conv->unreadCount1 = 5;
        $conv->unreadCount2 = 0;
        $conv->archivedBy1 = false;
        $conv->archivedBy2 = true;
        $conv->lastMessagePreview = 'Hello!';

        // From Alice's perspective
        $aliceView = $conv->toApiArray('alice_key');
        $this->assert(
            'Alice sees Bob as other party',
            $aliceView['other_party_public_key'] === 'bob_key'
        );
        $this->assert(
            'Alice sees her unread count',
            $aliceView['unread_count'] === 5
        );
        $this->assert(
            'Alice is not archived',
            $aliceView['is_archived'] === false
        );

        // From Bob's perspective
        $bobView = $conv->toApiArray('bob_key');
        $this->assert(
            'Bob sees Alice as other party',
            $bobView['other_party_public_key'] === 'alice_key'
        );
        $this->assert(
            'Bob sees his unread count',
            $bobView['unread_count'] === 0
        );
        $this->assert(
            'Bob is archived',
            $bobView['is_archived'] === true
        );
    }

    /**
     * Test message types
     */
    private function testMessageTypes(): void {
        $this->assert(
            'TYPE_TEXT constant exists',
            Message::TYPE_TEXT === 'text'
        );

        $this->assert(
            'TYPE_IMAGE constant exists',
            Message::TYPE_IMAGE === 'image'
        );

        $this->assert(
            'TYPE_FILE constant exists',
            Message::TYPE_FILE === 'file'
        );

        $this->assert(
            'TYPE_SYSTEM constant exists',
            Message::TYPE_SYSTEM === 'system'
        );
    }

    /**
     * Test time ago formatting
     */
    private function testTimeAgoFormatting(): void {
        $message = new Message();
        $message->messageId = 'msg_test';
        $message->conversationId = 'conv_test';
        $message->senderPublicKey = 'sender';
        $message->recipientPublicKey = 'recipient';
        $message->content = 'Test';

        // Just now
        $message->createdAt = date('Y-m-d H:i:s');
        $api = $message->toApiArray('sender');
        $this->assert(
            'Recent message shows time ago',
            !empty($api['time_ago'])
        );

        // 1 hour ago
        $message->createdAt = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $api = $message->toApiArray('sender');
        $this->assert(
            'Hour old message shows time ago',
            str_contains($api['time_ago'], 'hour') || str_contains($api['time_ago'], 'minute')
        );
    }

    /**
     * Assert a condition
     *
     * @param string $description Test description
     * @param bool $condition Condition to test
     */
    private function assert(string $description, bool $condition): void {
        $this->total++;

        if ($condition) {
            $this->passed++;
            $this->results[] = [
                'test' => $description,
                'status' => 'PASS'
            ];
        } else {
            $this->results[] = [
                'test' => $description,
                'status' => 'FAIL'
            ];
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $test = new MessagesServiceTest();
    $results = $test->runAll();

    echo "\n=== Messages Plugin Tests ===\n\n";

    foreach ($results['results'] as $result) {
        $status = $result['status'] === 'PASS' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
        echo "[$status] {$result['test']}\n";
    }

    echo "\n----------------------------\n";
    echo "Total: {$results['total']} | Passed: {$results['passed']} | Failed: {$results['failed']}\n\n";

    exit($results['failed'] > 0 ? 1 : 0);
}
