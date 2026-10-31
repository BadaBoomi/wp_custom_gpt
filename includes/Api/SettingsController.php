<?php

namespace WpCustomGpt\Api;

use WP_Error;
use WP_REST_Request;
use WpCustomGpt\Database\MigrationRunner;
use WpCustomGpt\Services\SettingsService;
use WpCustomGpt\Services\VolumeLimitService;

class SettingsController
{
    private const NAMESPACE = 'wp-custom-gpt/v1';

    private SettingsService $settingsService;
    private VolumeLimitService $volumeLimitService;

    public function __construct(SettingsService $settingsService, ?VolumeLimitService $volumeLimitService = null)
    {
        $this->settingsService = $settingsService;
        $this->volumeLimitService = $volumeLimitService ?? new VolumeLimitService();
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/settings', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'getSettings'),
                'permission_callback' => array($this, 'canManageSettings'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'saveSettings'),
                'permission_callback' => array($this, 'canManageSettings'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/settings/openai-debug-log', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'getOpenAiDebugLog'),
                'permission_callback' => array($this, 'canManageSettings'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/settings/table-integrity-check', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'runTableIntegrityCheck'),
                'permission_callback' => array($this, 'canManageSettings'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/settings/table-integrity-verify', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'runTableIntegrityVerify'),
                'permission_callback' => array($this, 'canManageSettings'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/volume/roles', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'getVolumeRoles'),
                'permission_callback' => array($this, 'canManageSettings'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'saveVolumeRole'),
                'permission_callback' => array($this, 'canManageSettings'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/volume/users', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'getVolumeUsers'),
                'permission_callback' => array($this, 'canManageSettings'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/volume/users/(?P<userId>\d+)', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'saveVolumeUserRole'),
                'permission_callback' => array($this, 'canManageSettings'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/volume/log', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'getVolumeLog'),
                'permission_callback' => array($this, 'canManageSettings'),
            ),
        ));
    }

    public function getSettings(): array
    {
        return $this->settingsService->getSettingsForAdmin();
    }

    public function getVolumeRoles(WP_REST_Request $request)
    {
        $month = trim((string) $request->get_param('month'));
        return rest_ensure_response(array(
            'roles' => $this->volumeLimitService->getAvailableRoles(),
            'quotas' => $this->volumeLimitService->getRoleQuotas(),
            'month' => $this->volumeLimitService->normalizeMonth($month),
        ));
    }

    public function saveVolumeRole(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('invalid_payload', 'Request-Body muss JSON sein.', array('status' => 400));
        }

        $role = trim((string) ($payload['role'] ?? ''));
        $monthlyTokens = (int) ($payload['monthly_tokens_millions'] ?? 0);

        if ($role === '') {
            return new WP_Error('invalid_role', 'Rolle ist erforderlich.', array('status' => 400));
        }

        $saved = $this->volumeLimitService->saveRoleQuota($role, $monthlyTokens);
        if (!$saved) {
            return new WP_Error('volume_role_not_saved', 'Role quota konnte nicht gespeichert werden.', array('status' => 500));
        }

        return rest_ensure_response(array(
            'role' => $role,
            'quota' => $this->volumeLimitService->getRoleQuota($role),
            'roles' => $this->volumeLimitService->getAvailableRoles(),
            'quotas' => $this->volumeLimitService->getRoleQuotas(),
        ));
    }

    public function getVolumeUsers(WP_REST_Request $request)
    {
        $month = trim((string) $request->get_param('month'));
        return rest_ensure_response(array(
            'users' => $this->volumeLimitService->getUserOverview($month),
            'roles' => $this->volumeLimitService->getAvailableRoles(),
            'month' => $this->volumeLimitService->normalizeMonth($month),
        ));
    }

    public function saveVolumeUserRole(WP_REST_Request $request)
    {
        $userId = (int) $request->get_param('userId');
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('invalid_payload', 'Request-Body muss JSON sein.', array('status' => 400));
        }

        $role = trim((string) ($payload['role'] ?? ''));
        if ($userId <= 0 || $role === '') {
            return new WP_Error('invalid_user_or_role', 'Benutzer und Rolle sind erforderlich.', array('status' => 400));
        }

        $saved = $this->volumeLimitService->assignUserRole($userId, $role);
        if (!$saved) {
            return new WP_Error('volume_user_role_not_saved', 'Benutzerrolle konnte nicht gespeichert werden.', array('status' => 500));
        }

        return rest_ensure_response(array(
            'user' => $this->volumeLimitService->getUserUsageSummary($userId),
            'roles' => $this->volumeLimitService->getAvailableRoles(),
        ));
    }

    public function getVolumeLog(WP_REST_Request $request)
    {
        $month = trim((string) $request->get_param('month'));
        $limit = max(1, min(500, (int) $request->get_param('limit')));
        return rest_ensure_response(array(
            'entries' => $this->volumeLimitService->getAuditLog($month, $limit),
            'month' => $this->volumeLimitService->normalizeMonth($month),
        ));
    }

    public function saveSettings(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('invalid_payload', 'Request-Body muss JSON sein.', array('status' => 400));
        }

        return $this->settingsService->saveSettings($payload);
    }

    public function getOpenAiDebugLog(WP_REST_Request $request)
    {
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = 200;
        }
        if ($limit > 2000) {
            $limit = 2000;
        }

        $candidates = $this->resolveDebugLogCandidates();
        $logPath = $this->resolveReadableDebugLogPath($candidates);
        if ($logPath === '') {
            return rest_ensure_response(array(
                'path' => '',
                'candidates' => $candidates,
                'lines' => array(),
                'message' => 'Keine lesbare Log-Datei gefunden. Der Logger schreibt zusaetzlich nach uploads/custom_gpt/openai-debug.log, sobald neue OpenAI-Requests ausgefuehrt werden.',
            ));
        }

        $tail = $this->readTailLines($logPath, 5000);
        $filtered = array_values(array_filter($tail, static function (string $line): bool {
            return strpos($line, 'WPCGPT OpenAI Debug [') !== false;
        }));

        if (count($filtered) > $limit) {
            $filtered = array_slice($filtered, -$limit);
        }

        return rest_ensure_response(array(
            'path' => $logPath,
            'candidates' => $candidates,
            'lines' => $filtered,
            'total' => count($filtered),
        ));
    }

    public function runTableIntegrityCheck()
    {
        $result = MigrationRunner::checkAndRepairIntegrity();

        return $this->formatTableIntegrityResponse($result);
    }

    public function runTableIntegrityVerify()
    {
        $result = MigrationRunner::checkIntegrityOnly();

        return $this->formatTableIntegrityResponse($result);
    }

    private function formatTableIntegrityResponse(array $result)
    {
        return rest_ensure_response(array(
            'ok' => (bool) ($result['ok'] ?? false),
            'repaired' => (bool) ($result['repaired'] ?? false),
            'checked_tables' => (int) ($result['checked_tables'] ?? 0),
            'missing_before' => isset($result['missing_before']) && is_array($result['missing_before']) ? $result['missing_before'] : array(),
            'missing_after' => isset($result['missing_after']) && is_array($result['missing_after']) ? $result['missing_after'] : array(),
            'schema_version_expected' => (string) ($result['schema_version_expected'] ?? ''),
            'schema_version_stored' => (string) ($result['schema_version_stored'] ?? ''),
        ));
    }

    private function resolveDebugLogCandidates(): array
    {
        $candidates = array();

        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            $baseDir = isset($uploads['basedir']) && is_string($uploads['basedir']) ? trim($uploads['basedir']) : '';
            if ($baseDir !== '') {
                $candidates[] = rtrim($baseDir, '/\\') . '/custom_gpt/openai-debug.log';
            }
        }

        if (defined('WP_CONTENT_DIR')) {
            $candidates[] = rtrim((string) WP_CONTENT_DIR, '/\\') . '/uploads/custom_gpt/openai-debug.log';
        }

        if (defined('WP_DEBUG_LOG')) {
            if (is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
                $candidates[] = WP_DEBUG_LOG;
            }

            if (WP_DEBUG_LOG === true && defined('WP_CONTENT_DIR')) {
                $candidates[] = rtrim((string) WP_CONTENT_DIR, '/\\') . '/debug.log';
            }
        }

        $phpErrorLog = ini_get('error_log');
        if (is_string($phpErrorLog) && trim($phpErrorLog) !== '') {
            $candidates[] = trim($phpErrorLog);
        }

        $normalized = array_values(array_unique(array_filter(array_map('trim', $candidates), static function (string $path): bool {
            return $path !== '';
        })));

        return $normalized;
    }

    private function resolveReadableDebugLogPath(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function readTailLines(string $path, int $maxLines): array
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return array();
        }

        $buffer = '';
        $chunkSize = 8192;
        $position = -1;
        $lineCount = 0;

        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);
        if (!is_int($fileSize) || $fileSize <= 0) {
            fclose($handle);
            return array();
        }

        while ($lineCount <= $maxLines && (-$position) < $fileSize) {
            $seek = min($chunkSize, $fileSize + $position + 1);
            fseek($handle, $position - $seek + 1, SEEK_END);
            $chunk = fread($handle, $seek);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }

            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
            $position -= $seek;
        }

        fclose($handle);

        $lines = preg_split('/\r\n|\r|\n/', $buffer);
        if (!is_array($lines)) {
            return array();
        }

        $lines = array_values(array_filter(array_map('trim', $lines), static function (string $line): bool {
            return $line !== '';
        }));

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return $lines;
    }

    public function canManageSettings(): bool
    {
        return current_user_can('manage_options');
    }
}
