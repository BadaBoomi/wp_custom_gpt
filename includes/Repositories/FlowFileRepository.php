<?php

namespace WpCustomGpt\Repositories;

use wpdb;

class FlowFileRepository
{
    private wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'wpcgpt_flow_files';
    }

    public function listByFlowType(string $flowType): array
    {
        $cleanFlowType = $this->normalizeFlowType($flowType);
        if ($cleanFlowType === '') {
            return array();
        }

        $sql = $this->wpdb->prepare(
            "SELECT id, flow_type, original_name, stored_name, relative_path, mime_type, size_bytes, uploaded_by, created_at, updated_at
             FROM {$this->table}
             WHERE flow_type = %s
             ORDER BY created_at DESC",
            $cleanFlowType
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(array($this, 'normalizeRow'), $rows ?: array());
    }

    public function findById(int $fileId): ?array
    {
        $sql = $this->wpdb->prepare(
            "SELECT id, flow_type, original_name, stored_name, relative_path, mime_type, size_bytes, uploaded_by, created_at, updated_at
             FROM {$this->table}
             WHERE id = %d
             LIMIT 1",
            $fileId
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    public function create(
        string $flowType,
        string $originalName,
        string $storedName,
        string $relativePath,
        string $mimeType,
        int $sizeBytes,
        int $uploadedBy
    ): ?array {
        $cleanFlowType = $this->normalizeFlowType($flowType);
        if ($cleanFlowType === '') {
            return null;
        }

        $now = current_time('mysql', true);

        $this->wpdb->insert(
            $this->table,
            array(
                'flow_type' => $cleanFlowType,
                'original_name' => sanitize_file_name($originalName),
                'stored_name' => sanitize_file_name($storedName),
                'relative_path' => ltrim(str_replace('\\', '/', $relativePath), '/'),
                'mime_type' => sanitize_text_field($mimeType),
                'size_bytes' => max(0, $sizeBytes),
                'uploaded_by' => $uploadedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s')
        );

        $fileId = (int) $this->wpdb->insert_id;

        return $this->findById($fileId);
    }

    public function deleteByIdAndFlowType(int $fileId, string $flowType): bool
    {
        $cleanFlowType = $this->normalizeFlowType($flowType);
        if ($cleanFlowType === '') {
            return false;
        }

        $deleted = $this->wpdb->delete(
            $this->table,
            array(
                'id' => $fileId,
                'flow_type' => $cleanFlowType,
            ),
            array('%d', '%s')
        );

        return $deleted !== false && $deleted > 0;
    }

    private function normalizeRow(array $row): array
    {
        return array(
            'id' => (int) $row['id'],
            'flow_type' => (string) $row['flow_type'],
            'original_name' => (string) $row['original_name'],
            'stored_name' => (string) $row['stored_name'],
            'relative_path' => (string) $row['relative_path'],
            'mime_type' => (string) $row['mime_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'uploaded_by' => (int) $row['uploaded_by'],
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
