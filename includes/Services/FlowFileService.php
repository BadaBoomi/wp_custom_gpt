<?php

namespace WpCustomGpt\Services;

use WP_Error;
use WpCustomGpt\Repositories\FlowFileRepository;

class FlowFileService
{
    private const MAX_FILE_BYTES = 10485760;

    private FlowFileRepository $flowFileRepository;

    public function __construct(FlowFileRepository $flowFileRepository)
    {
        $this->flowFileRepository = $flowFileRepository;
    }

    public function listFiles(string $flowType): array|WP_Error
    {
        $cleanFlowType = $this->normalizeFlowType($flowType);
        if ($cleanFlowType === '') {
            return new WP_Error('invalid_flow_type', 'Flow-Typ ist erforderlich.', array('status' => 400));
        }

        $rows = $this->flowFileRepository->listByFlowType($cleanFlowType);
        return array_map(array($this, 'decorateFileRow'), $rows);
    }

    public function saveUpload(string $flowType, array $file, int $uploadedBy): array|WP_Error
    {
        $cleanFlowType = $this->normalizeFlowType($flowType);
        if ($cleanFlowType === '') {
            return new WP_Error('invalid_flow_type', 'Flow-Typ ist erforderlich.', array('status' => 400));
        }

        $validation = $this->validateUpload($file);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $uploadDir = wp_upload_dir();
        if (!is_array($uploadDir) || !isset($uploadDir['basedir'], $uploadDir['baseurl'])) {
            return new WP_Error('upload_dir_error', 'Upload-Verzeichnis konnte nicht ermittelt werden.', array('status' => 500));
        }

        $targetDir = trailingslashit((string) $uploadDir['basedir']) . 'wpcgpt-flow-files/' . $cleanFlowType;
        if (!wp_mkdir_p($targetDir)) {
            return new WP_Error('upload_dir_create_failed', 'Zielordner fuer den Upload konnte nicht erstellt werden.', array('status' => 500));
        }

        $originalName = isset($file['name']) ? (string) $file['name'] : 'file';
        $cleanName = sanitize_file_name($originalName);
        if ($cleanName === '') {
            $cleanName = 'file';
        }

        $storedName = wp_unique_filename($targetDir, $cleanName);
        $tmpName = (string) ($file['tmp_name'] ?? '');

        if (!is_uploaded_file($tmpName)) {
            return new WP_Error('upload_invalid_tmp', 'Upload-Quelle ist ungueltig.', array('status' => 400));
        }

        $targetPath = trailingslashit($targetDir) . $storedName;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            return new WP_Error('upload_move_failed', 'Hochgeladene Datei konnte nicht verschoben werden.', array('status' => 500));
        }

        $check = wp_check_filetype_and_ext($targetPath, $storedName);
        $mimeType = isset($check['type']) && is_string($check['type']) ? $check['type'] : '';
        if ($mimeType === '') {
            $mimeType = isset($file['type']) ? sanitize_text_field((string) $file['type']) : 'application/octet-stream';
        }

        $relativePath = 'wpcgpt-flow-files/' . $cleanFlowType . '/' . $storedName;
        $sizeBytes = file_exists($targetPath) ? (int) filesize($targetPath) : 0;

        $created = $this->flowFileRepository->create(
            $cleanFlowType,
            $originalName,
            $storedName,
            $relativePath,
            $mimeType,
            $sizeBytes,
            $uploadedBy
        );

        if (!$created) {
            @unlink($targetPath);
            return new WP_Error('file_metadata_save_failed', 'Datei-Metadaten konnten nicht gespeichert werden.', array('status' => 500));
        }

        return $this->decorateFileRow($created);
    }

    public function deleteFile(string $flowType, int $fileId): bool
    {
        $cleanFlowType = $this->normalizeFlowType($flowType);
        if ($cleanFlowType === '') {
            return false;
        }

        $row = $this->flowFileRepository->findById($fileId);
        if (!$row || (string) $row['flow_type'] !== $cleanFlowType) {
            return false;
        }

        $uploadDir = wp_upload_dir();
        $baseDir = is_array($uploadDir) && isset($uploadDir['basedir']) ? (string) $uploadDir['basedir'] : '';
        if ($baseDir !== '') {
            $absolutePath = trailingslashit($baseDir) . ltrim((string) $row['relative_path'], '/');
            if (file_exists($absolutePath)) {
                @unlink($absolutePath);
            }
        }

        return $this->flowFileRepository->deleteByIdAndFlowType($fileId, $cleanFlowType);
    }

    private function validateUpload(array $file): true|WP_Error
    {
        if (empty($file) || !isset($file['tmp_name'])) {
            return new WP_Error('upload_missing_file', 'Es wurde keine Datei hochgeladen.', array('status' => 400));
        }

        $errorCode = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_OK;
        if ($errorCode !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', 'Upload fehlgeschlagen mit Fehlercode ' . $errorCode . '.', array('status' => 400));
        }

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0) {
            return new WP_Error('upload_empty_file', 'Die hochgeladene Datei ist leer.', array('status' => 400));
        }

        if ($size > self::MAX_FILE_BYTES) {
            return new WP_Error('upload_too_large', 'Die hochgeladene Datei ueberschreitet das Limit von 10 MB.', array('status' => 400));
        }

        $name = isset($file['name']) ? (string) $file['name'] : '';
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $allowed = array('xlsx', 'xls', 'csv', 'ods', 'tsv', 'txt', 'json');

        if (!in_array($ext, $allowed, true)) {
            return new WP_Error('upload_type_not_allowed', 'Erlaubte Dateitypen: xlsx, xls, csv, ods, tsv, txt, json.', array('status' => 400));
        }

        return true;
    }

    private function decorateFileRow(array $row): array
    {
        $uploadDir = wp_upload_dir();
        $baseDir = is_array($uploadDir) && isset($uploadDir['basedir']) ? (string) $uploadDir['basedir'] : '';
        $baseUrl = is_array($uploadDir) && isset($uploadDir['baseurl']) ? (string) $uploadDir['baseurl'] : '';

        $relativePath = ltrim((string) $row['relative_path'], '/');
        $url = $baseUrl !== '' ? trailingslashit($baseUrl) . $relativePath : '';
        $absolutePath = $baseDir !== '' ? trailingslashit($baseDir) . $relativePath : '';

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
            'url' => $url,
            'absolute_path' => $absolutePath,
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
