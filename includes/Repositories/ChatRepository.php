<?php

namespace WpCustomGpt\Repositories;

use wpdb;

class ChatRepository
{
    private wpdb $wpdb;
    private string $roomsTable;
    private string $chatsTable;
    private string $messagesTable;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->roomsTable = $wpdb->prefix . 'wpcgpt_rooms';
        $this->chatsTable = $wpdb->prefix . 'wpcgpt_chats';
        $this->messagesTable = $wpdb->prefix . 'wpcgpt_messages';
    }

    public function listChatsForRoom(int $roomId, int $userId): array
    {
        if (!$this->roomExistsForUser($roomId, $userId)) {
            return array();
        }

        $sql = $this->wpdb->prepare(
            "SELECT id, room_id, title, conversation_id, created_at, updated_at
             FROM {$this->chatsTable}
             WHERE user_id = %d AND room_id = %d
             ORDER BY created_at DESC",
            $userId,
            $roomId
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(array($this, 'normalizeChat'), $rows ?: array());
    }

    public function createChat(int $roomId, int $userId, string $title): ?array
    {
        if (!$this->roomExistsForUser($roomId, $userId)) {
            return null;
        }

        $now = current_time('mysql', true);

        $this->wpdb->insert(
            $this->chatsTable,
            array(
                'user_id' => $userId,
                'room_id' => $roomId,
                'conversation_id' => null,
                'title' => sanitize_text_field($title),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s')
        );

        $chatId = (int) $this->wpdb->insert_id;

        return $this->findChatForUser($chatId, $userId);
    }

    public function listMessagesForChat(int $chatId, int $userId): array
    {
        if (!$this->chatExistsForUser($chatId, $userId)) {
            return array();
        }

        $sql = $this->wpdb->prepare(
            "SELECT id, chat_id, role, content, meta_json, created_at
             FROM {$this->messagesTable}
             WHERE user_id = %d AND chat_id = %d
             ORDER BY created_at ASC",
            $userId,
            $chatId
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(array($this, 'normalizeMessage'), $rows ?: array());
    }

    public function addMessage(int $chatId, int $userId, string $role, string $content): ?array
    {
        if (!$this->chatExistsForUser($chatId, $userId)) {
            return null;
        }

        $now = current_time('mysql', true);

        $this->wpdb->insert(
            $this->messagesTable,
            array(
                'user_id' => $userId,
                'chat_id' => $chatId,
                'role' => sanitize_text_field($role),
                'content' => wp_kses_post($content),
                'meta_json' => null,
                'created_at' => $now,
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s')
        );

        $messageId = (int) $this->wpdb->insert_id;

        $sql = $this->wpdb->prepare(
            "SELECT id, chat_id, role, content, meta_json, created_at
             FROM {$this->messagesTable}
             WHERE id = %d AND user_id = %d
             LIMIT 1",
            $messageId,
            $userId
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return $row ? $this->normalizeMessage($row) : null;
    }

    private function roomExistsForUser(int $roomId, int $userId): bool
    {
        $sql = $this->wpdb->prepare(
            "SELECT id FROM {$this->roomsTable} WHERE id = %d AND user_id = %d LIMIT 1",
            $roomId,
            $userId
        );

        return (bool) $this->wpdb->get_var($sql);
    }

    private function chatExistsForUser(int $chatId, int $userId): bool
    {
        $sql = $this->wpdb->prepare(
            "SELECT id FROM {$this->chatsTable} WHERE id = %d AND user_id = %d LIMIT 1",
            $chatId,
            $userId
        );

        return (bool) $this->wpdb->get_var($sql);
    }

    private function findChatForUser(int $chatId, int $userId): ?array
    {
        $sql = $this->wpdb->prepare(
            "SELECT id, room_id, title, conversation_id, created_at, updated_at
             FROM {$this->chatsTable}
             WHERE id = %d AND user_id = %d
             LIMIT 1",
            $chatId,
            $userId
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return $row ? $this->normalizeChat($row) : null;
    }

    private function normalizeChat(array $row): array
    {
        return array(
            'id' => (int) $row['id'],
            'room_id' => (int) $row['room_id'],
            'title' => (string) $row['title'],
            'conversation_id' => (string) ($row['conversation_id'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        );
    }

    private function normalizeMessage(array $row): array
    {
        return array(
            'id' => (int) $row['id'],
            'chat_id' => (int) $row['chat_id'],
            'role' => (string) $row['role'],
            'content' => (string) $row['content'],
            'meta_json' => (string) ($row['meta_json'] ?? ''),
            'created_at' => (string) $row['created_at'],
        );
    }
}
