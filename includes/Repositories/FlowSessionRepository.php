<?php

namespace WpCustomGpt\Repositories;

use wpdb;

class FlowSessionRepository
{
    private wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'wpcgpt_flow_sessions';
    }

    public function getActiveForChat(int $chatId, int $userId): ?array
    {
        $sql = $this->wpdb->prepare(
            "SELECT id, user_id, chat_id, flow_type, status, state_json, created_at, updated_at
             FROM {$this->table}
             WHERE chat_id = %d AND user_id = %d AND status = %s
             LIMIT 1",
            $chatId,
            $userId,
            'running'
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    public function createOrReplace(int $chatId, int $userId, string $flowType, array $state = array()): ?array
    {
        $now = current_time('mysql', true);
        $stateJson = wp_json_encode($state);
        if (!is_string($stateJson)) {
            $stateJson = '{}';
        }

        $existing = $this->findByChatForUser($chatId, $userId);

        if ($existing) {
            $this->wpdb->update(
                $this->table,
                array(
                    'flow_type' => sanitize_text_field($flowType),
                    'status' => 'running',
                    'state_json' => $stateJson,
                    'updated_at' => $now,
                ),
                array(
                    'id' => (int) $existing['id'],
                    'user_id' => $userId,
                ),
                array('%s', '%s', '%s', '%s'),
                array('%d', '%d')
            );
        } else {
            $this->wpdb->insert(
                $this->table,
                array(
                    'user_id' => $userId,
                    'chat_id' => $chatId,
                    'flow_type' => sanitize_text_field($flowType),
                    'status' => 'running',
                    'state_json' => $stateJson,
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s')
            );
        }

        return $this->findByChatForUser($chatId, $userId);
    }

    public function complete(int $sessionId, int $userId, array $state = array()): bool
    {
        return $this->setStatus($sessionId, $userId, 'completed', $state);
    }

    public function abort(int $sessionId, int $userId, array $state = array()): bool
    {
        return $this->setStatus($sessionId, $userId, 'aborted', $state);
    }

    public function updateRunningState(int $sessionId, int $userId, array $state): bool
    {
        return $this->setStatus($sessionId, $userId, 'running', $state);
    }

    private function setStatus(int $sessionId, int $userId, string $status, array $state): bool
    {
        $stateJson = wp_json_encode($state);
        if (!is_string($stateJson)) {
            $stateJson = '{}';
        }

        $updated = $this->wpdb->update(
            $this->table,
            array(
                'status' => $status,
                'state_json' => $stateJson,
                'updated_at' => current_time('mysql', true),
            ),
            array(
                'id' => $sessionId,
                'user_id' => $userId,
            ),
            array('%s', '%s', '%s'),
            array('%d', '%d')
        );

        return $updated !== false;
    }

    private function findByChatForUser(int $chatId, int $userId): ?array
    {
        $sql = $this->wpdb->prepare(
            "SELECT id, user_id, chat_id, flow_type, status, state_json, created_at, updated_at
             FROM {$this->table}
             WHERE chat_id = %d AND user_id = %d
             LIMIT 1",
            $chatId,
            $userId
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    private function normalizeRow(array $row): array
    {
        $decoded = json_decode((string) ($row['state_json'] ?? ''), true);
        if (!is_array($decoded)) {
            $decoded = array();
        }

        return array(
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'chat_id' => (int) $row['chat_id'],
            'flow_type' => (string) $row['flow_type'],
            'status' => (string) $row['status'],
            'state' => $decoded,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        );
    }
}
