<?php

namespace WpCustomGpt\Repositories;

use wpdb;

class FlowCodeRepository
{
    private wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'wpcgpt_flow_code';
    }

    public function listAll(): array
    {
        $sql = "SELECT id, flow_type, code_php, version, checksum, is_active, updated_by, created_at, updated_at
                FROM {$this->table}
                ORDER BY flow_type ASC";

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(array($this, 'normalizeRow'), $rows ?: array());
    }

    public function findByFlowType(string $flowType): ?array
    {
        $cleanFlowType = $this->normalizeFlowType($flowType);
        if ($cleanFlowType === '') {
            return null;
        }

        $sql = $this->wpdb->prepare(
            "SELECT id, flow_type, code_php, version, checksum, is_active, updated_by, created_at, updated_at
             FROM {$this->table}
             WHERE flow_type = %s
             LIMIT 1",
            $cleanFlowType
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    public function findActiveByFlowType(string $flowType): ?array
    {
        $cleanFlowType = $this->normalizeFlowType($flowType);
        if ($cleanFlowType === '') {
            return null;
        }

        $sql = $this->wpdb->prepare(
            "SELECT id, flow_type, code_php, version, checksum, is_active, updated_by, created_at, updated_at
             FROM {$this->table}
             WHERE flow_type = %s AND is_active = 1
             LIMIT 1",
            $cleanFlowType
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    public function save(string $flowType, string $codePhp, int $updatedBy): ?array
    {
        $cleanFlowType = $this->normalizeFlowType($flowType);
        if ($cleanFlowType === '') {
            return null;
        }

        $existing = $this->findByFlowType($cleanFlowType);
        $checksum = hash('sha256', $codePhp);
        $now = current_time('mysql', true);

        if ($existing) {
            $this->wpdb->update(
                $this->table,
                array(
                    'code_php' => $codePhp,
                    'version' => ((int) $existing['version']) + 1,
                    'checksum' => $checksum,
                    'is_active' => 1,
                    'updated_by' => $updatedBy,
                    'updated_at' => $now,
                ),
                array('id' => (int) $existing['id']),
                array('%s', '%d', '%s', '%d', '%d', '%s'),
                array('%d')
            );

            return $this->findByFlowType($cleanFlowType);
        }

        $this->wpdb->insert(
            $this->table,
            array(
                'flow_type' => $cleanFlowType,
                'code_php' => $codePhp,
                'version' => 1,
                'checksum' => $checksum,
                'is_active' => 1,
                'updated_by' => $updatedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s')
        );

        return $this->findByFlowType($cleanFlowType);
    }

    public function deactivate(string $flowType, int $updatedBy): bool
    {
        $cleanFlowType = $this->normalizeFlowType($flowType);
        if ($cleanFlowType === '') {
            return false;
        }

        $updated = $this->wpdb->update(
            $this->table,
            array(
                'is_active' => 0,
                'updated_by' => $updatedBy,
                'updated_at' => current_time('mysql', true),
            ),
            array('flow_type' => $cleanFlowType),
            array('%d', '%d', '%s'),
            array('%s')
        );

        return $updated !== false;
    }

    private function normalizeRow(array $row): array
    {
        return array(
            'id' => (int) $row['id'],
            'flow_type' => (string) $row['flow_type'],
            'code_php' => (string) $row['code_php'],
            'version' => (int) $row['version'],
            'checksum' => (string) $row['checksum'],
            'is_active' => ((int) $row['is_active']) === 1,
            'updated_by' => (int) $row['updated_by'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        );
    }

    private function normalizeFlowType(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9_\-]/', '_', $value);

        return is_string($value) ? substr($value, 0, 100) : '';
    }
}
