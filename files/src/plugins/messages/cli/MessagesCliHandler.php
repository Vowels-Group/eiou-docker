<?php
# Copyright 2025

/**
 * Messages CLI Handler
 *
 * Command-line interface for messaging operations
 *
 * @package Plugins\Messages\Cli
 */

class MessagesCliHandler {
    /**
     * @var MessagesService Messages service
     */
    private MessagesService $service;

    /**
     * @var CliOutputManager Output manager
     */
    private $output;

    /**
     * Constructor
     *
     * @param MessagesService $service Messages service
     */
    public function __construct(MessagesService $service) {
        $this->service = $service;
    }

    /**
     * Handle a messages CLI command
     *
     * @param array $argv Command arguments
     * @param CliOutputManager|null $output Output manager
     * @return int Exit code
     */
    public function handle(array $argv, $output = null): int {
        $this->output = $output ?? new CliOutputManager();

        if (count($argv) < 2) {
            $this->showHelp();
            return 1;
        }

        $subcommand = $argv[1] ?? '';
        $args = array_slice($argv, 2);
        $options = $this->parseOptions($args);

        try {
            return match ($subcommand) {
                'list', 'ls' => $this->listConversations($options),
                'send' => $this->sendMessage($args, $options),
                'read' => $this->readMessages($args, $options),
                'unread' => $this->showUnread(),
                'delete', 'rm' => $this->delete($args, $options),
                'search' => $this->search($args, $options),
                'help', '--help', '-h' => $this->showHelp(),
                default => $this->unknownCommand($subcommand)
            };
        } catch (Exception $e) {
            $this->output->error($e->getMessage());
            return 1;
        }
    }

    /**
     * List conversations
     */
    private function listConversations(array $options): int {
        $includeArchived = isset($options['archived']);

        $result = $this->service->listConversations($includeArchived);

        if (empty($result['conversations'])) {
            $this->output->info('No conversations found.');
            return 0;
        }

        $this->output->info("Conversations ({$result['total_unread']} unread total):\n");

        foreach ($result['conversations'] as $conv) {
            $unread = $conv['unread_count'] > 0 ? " [{$conv['unread_count']} new]" : '';
            $archived = $conv['is_archived'] ? ' (archived)' : '';
            $time = $conv['last_message_time_ago'] ? " - {$conv['last_message_time_ago']}" : '';

            $this->output->writeLine("  {$conv['other_party_name']}{$unread}{$archived}{$time}");
            if ($conv['last_message_preview']) {
                $preview = strlen($conv['last_message_preview']) > 60
                    ? substr($conv['last_message_preview'], 0, 57) . '...'
                    : $conv['last_message_preview'];
                $this->output->writeLine("    \"{$preview}\"");
            }
            $this->output->writeLine("    ID: {$conv['conversation_id']}");
            $this->output->writeLine('');
        }

        return 0;
    }

    /**
     * Send a message
     */
    private function sendMessage(array $args, array $options): int {
        if (count($args) < 2) {
            $this->output->error('Usage: eiou messages send <public-key> <message>');
            return 1;
        }

        $recipientKey = array_shift($args);
        $content = implode(' ', $args);

        if (empty($content)) {
            $this->output->error('Message content required');
            return 1;
        }

        $result = $this->service->sendMessage($recipientKey, $content);

        $this->output->success('Message sent!');
        $this->output->info("Message ID: {$result['message']['message_id']}");
        $this->output->info("Conversation: {$result['conversation_id']}");

        return 0;
    }

    /**
     * Read messages from a conversation
     */
    private function readMessages(array $args, array $options): int {
        if (empty($args[0])) {
            $this->output->error('Conversation ID required');
            return 1;
        }

        $conversationId = $args[0];
        $limit = (int) ($options['limit'] ?? 20);

        $result = $this->service->getMessages($conversationId, $limit);

        $conv = $result['conversation'];
        $this->output->info("Conversation with {$conv['other_party_name']}:\n");

        if (empty($result['messages'])) {
            $this->output->info('No messages in this conversation.');
            return 0;
        }

        foreach ($result['messages'] as $msg) {
            $sender = $msg['is_own_message'] ? 'You' : 'Them';
            $time = $msg['time_ago'];
            $read = !$msg['is_own_message'] ? '' : ($msg['is_read'] ? ' (read)' : ' (unread)');

            $this->output->writeLine("  [{$time}] {$sender}{$read}:");
            $this->output->writeLine("    {$msg['content']}");
            $this->output->writeLine('');
        }

        if ($result['has_more']) {
            $this->output->info("More messages available. Use --limit to see more.");
        }

        return 0;
    }

    /**
     * Show unread count
     */
    private function showUnread(): int {
        $result = $this->service->getUnreadCount();

        $count = $result['unread_count'];
        if ($count === 0) {
            $this->output->info('No unread messages.');
        } else {
            $this->output->info("You have {$count} unread message" . ($count === 1 ? '' : 's') . '.');
        }

        return 0;
    }

    /**
     * Delete a message or conversation
     */
    private function delete(array $args, array $options): int {
        if (empty($args[0])) {
            $this->output->error('ID required');
            return 1;
        }

        $id = $args[0];

        if (isset($options['conversation'])) {
            $result = $this->service->deleteConversation($id);
            $this->output->success('Conversation archived.');
        } else {
            $result = $this->service->deleteMessage($id);
            $this->output->success('Message deleted.');
        }

        return 0;
    }

    /**
     * Search messages
     */
    private function search(array $args, array $options): int {
        if (empty($args)) {
            $this->output->error('Search query required');
            return 1;
        }

        $query = implode(' ', $args);
        $limit = (int) ($options['limit'] ?? 20);

        $result = $this->service->searchMessages($query, $limit);

        if (empty($result['messages'])) {
            $this->output->info("No messages found for \"{$query}\"");
            return 0;
        }

        $this->output->info("Found {$result['count']} message(s) matching \"{$query}\":\n");

        foreach ($result['messages'] as $msg) {
            $direction = $msg['is_own_message'] ? 'to' : 'from';
            $otherKey = $msg['is_own_message'] ? $msg['recipient_public_key'] : $msg['sender_public_key'];
            $shortKey = substr($otherKey, 0, 12) . '...';

            $this->output->writeLine("  [{$msg['time_ago']}] {$direction} {$shortKey}:");
            $this->output->writeLine("    {$msg['content']}");
            $this->output->writeLine('');
        }

        return 0;
    }

    /**
     * Show help
     */
    private function showHelp(): int {
        $this->output->writeLine("Messages Plugin - Send and receive messages");
        $this->output->writeLine("");
        $this->output->writeLine("Usage: eiou messages <command> [options]");
        $this->output->writeLine("");
        $this->output->writeLine("Commands:");
        $this->output->writeLine("  list [--archived]                    List conversations");
        $this->output->writeLine("  send <public-key> <message>          Send a message");
        $this->output->writeLine("  read <conversation-id> [--limit=n]   Read messages");
        $this->output->writeLine("  unread                               Show unread count");
        $this->output->writeLine("  delete <id> [--conversation]         Delete message/conversation");
        $this->output->writeLine("  search <query> [--limit=n]           Search messages");
        $this->output->writeLine("");
        $this->output->writeLine("Examples:");
        $this->output->writeLine("  eiou messages send abc123...xyz \"Hello there!\"");
        $this->output->writeLine("  eiou messages read conv_abc123 --limit=50");
        $this->output->writeLine("  eiou messages search \"meeting\"");

        return 0;
    }

    /**
     * Handle unknown command
     */
    private function unknownCommand(string $command): int {
        $this->output->error("Unknown command: {$command}");
        $this->output->info("Run 'eiou messages help' for usage.");
        return 1;
    }

    /**
     * Parse options from arguments
     */
    private function parseOptions(array &$args): array {
        $options = [];
        $filtered = [];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', substr($arg, 2), 2);
                $options[$parts[0]] = $parts[1] ?? true;
            } else {
                $filtered[] = $arg;
            }
        }

        $args = $filtered;
        return $options;
    }
}
