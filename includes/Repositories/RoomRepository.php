<?php

namespace WpCustomGpt\Repositories;

use wpdb;

class RoomRepository
{
    private wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'wpcgpt_rooms';
    }

    public function listByUser(int $userId): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT id, name, custom_attributes, created_at, updated_at
             FROM {$this->table}
             WHERE user_id = %d
             ORDER BY created_at DESC",
            $userId
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(array($this, 'normalizeRoom'), $rows ?: array());
    }

    public function create(int $userId, string $name): array
    {
        $now = current_time('mysql', true);
        $cleanName = sanitize_text_field($name);

        $this->wpdb->insert(
            $this->table,
            array(
                'user_id' => $userId,
                'name' => $cleanName,
                'custom_attributes' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );

        $roomId = (int) $this->wpdb->insert_id;

        return $this->findByIdForUser($roomId, $userId) ?: array();
    }

    public function rename(int $roomId, int $userId, string $name): ?array
    {
        $updated = $this->wpdb->update(
            $this->table,
            array(
                'name' => sanitize_text_field($name),
                'updated_at' => current_time('mysql', true),
            ),
            array(
                'id' => $roomId,
                'user_id' => $userId,
            ),
            array('%s', '%s'),
            array('%d', '%d')
        );

        if ($updated === false) {
            return null;
        }

        return $this->findByIdForUser($roomId, $userId);
    }

    public function delete(int $roomId, int $userId): bool
    {
        $deleted = $this->wpdb->delete(
            $this->table,
            array(
                'id' => $roomId,
                'user_id' => $userId,
            ),
            array('%d', '%d')
        );

        return $deleted !== false && $deleted > 0;
    }

    private function findByIdForUser(int $roomId, int $userId): ?array
    {
        $sql = $this->wpdb->prepare(
            "SELECT id, name, custom_attributes, created_at, updated_at
             FROM {$this->table}
             WHERE id = %d AND user_id = %d
             LIMIT 1",
            $roomId,
            $userId
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            return null;
        }

        return $this->normalizeRoom($row);
    }

    private function normalizeRoom(array $row): array
    {
        return array(
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'custom_attributes' => (string) $row['custom_attributes'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        );
    }
}
