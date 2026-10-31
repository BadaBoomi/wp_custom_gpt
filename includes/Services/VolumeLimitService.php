<?php

namespace WpCustomGpt\Services;

use WP_Error;

class VolumeLimitService
{
    public function getAvailableRoles(): array
    {
        $roles = array();

        if (function_exists('wp_roles') && isset($GLOBALS['wp_roles']) && $GLOBALS['wp_roles'] instanceof \WP_Roles) {
            foreach ($GLOBALS['wp_roles']->roles as $roleKey => $roleConfig) {
                $roleName = isset($roleConfig['name']) ? (string) $roleConfig['name'] : $roleKey;
                $roles[$roleKey] = $roleName;
            }
        }

        foreach ($this->getRoleQuotas() as $roleKey => $unusedQuota) {
            $roles[$roleKey] = $roles[$roleKey] ?? ucfirst(str_replace(array('-', '_'), ' ', $roleKey));
        }

        ksort($roles);

        return $roles;
    }

    public function getRoleQuotas(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcgpt_volume_role_quotas';
        $rows = $wpdb->get_results('SELECT role_name, monthly_tokens_millions FROM ' . $table . ' ORDER BY role_name ASC', ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }

        $result = array();
        foreach ($rows as $row) {
            $roleName = trim((string) ($row['role_name'] ?? ''));
            if ($roleName === '') {
                continue;
            }

            $result[$roleName] = max(0, (int) ($row['monthly_tokens_millions'] ?? 0));
        }

        return $result;
    }

    public function saveRoleQuota(string $role, int $monthlyTokensMillions): bool
    {
        $roleName = trim((string) $role);
        if ($roleName === '') {
            return false;
        }

        $roleName = sanitize_key($roleName);
        if ($roleName === '') {
            return false;
        }

        $monthlyTokensMillions = max(0, (int) $monthlyTokensMillions);
        $oldValue = $this->getRoleQuota($roleName);

        global $wpdb;
        $table = $wpdb->prefix . 'wpcgpt_volume_role_quotas';
        $inserted = $wpdb->replace(
            $table,
            array(
                'role_name' => $roleName,
                'monthly_tokens_millions' => $monthlyTokensMillions,
                'updated_at' => current_time('mysql'),
            ),
            array('%s', '%d', '%s')
        );

        if ($inserted === false) {
            return false;
        }

        $eventType = ($oldValue > 0 || $this->getRoleQuota($roleName) > 0) ? 'role_volume_changed' : 'role_volume_added';
        $this->addAuditLog($eventType, 0, $roleName, (string) $oldValue, (string) $monthlyTokensMillions, null);

        return true;
    }

    public function getRoleQuota(string $role): int
    {
        $roleName = trim((string) $role);
        if ($roleName === '') {
            return 0;
        }

        return max(0, (int) ($this->getRoleQuotas()[$roleName] ?? 0));
    }

    public function assignUserRole(int $userId, string $role): bool
    {
        $userId = max(0, (int) $userId);
        $roleName = trim((string) $role);
        if ($userId <= 0 || $roleName === '') {
            return false;
        }

        $roleName = sanitize_key($roleName);
        if ($roleName === '' || !isset($this->getAvailableRoles()[$roleName])) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpcgpt_volume_user_roles';
        $oldRole = $this->getAssignedUserRole($userId);
        $result = $wpdb->replace(
            $table,
            array(
                'user_id' => $userId,
                'role_name' => $roleName,
                'updated_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s')
        );

        if ($result === false) {
            return false;
        }

        $this->addAuditLog(($oldRole === '') ? 'user_volume_role_assigned' : 'user_volume_role_changed', $userId, $roleName, $oldRole, $roleName, null);

        return true;
    }

    public function getAssignedUserRole(int $userId): string
    {
        $userId = max(0, (int) $userId);
        if ($userId <= 0) {
            return '';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpcgpt_volume_user_roles';
        $row = $wpdb->get_row('SELECT role_name FROM ' . $table . ' WHERE user_id = ' . (int) $userId . ' LIMIT 1', ARRAY_A);

        if (!is_array($row)) {
            return '';
        }

        return trim((string) ($row['role_name'] ?? ''));
    }

    public function getUserOverview(?string $month = null): array
    {
        $monthKey = $this->normalizeMonth($month);
        $users = get_users(array('fields' => array('ID', 'display_name', 'user_login')));
        $overview = array();

        foreach ($users as $user) {
            $overview[] = $this->getUserUsageSummary((int) $user->ID, $monthKey);
        }

        usort(
            $overview,
            static function (array $left, array $right): int {
                $nameA = strtolower((string) ($left['display_name'] ?? ''));
                $nameB = strtolower((string) ($right['display_name'] ?? ''));
                return strcmp($nameA, $nameB);
            }
        );

        return $overview;
    }

    public function getUserUsageSummary(int $userId, ?string $month = null): array
    {
        $monthKey = $this->normalizeMonth($month);
        $usage = $this->getMonthlyUsage($userId, $monthKey);
        $role = $this->getAssignedUserRole($userId);
        $quotaTokens = $this->getRoleQuotaTokensForRole($role);
        $remaining = $quotaTokens - $usage['total_tokens'];

        $user = get_userdata($userId);

        return array(
            'user_id' => $userId,
            'user_login' => $user ? $user->user_login : '',
            'display_name' => $user ? $user->display_name : '',
            'assigned_role' => $role,
            'role_quota_millions' => $this->getRoleQuota($role),
            'quota_tokens' => $quotaTokens,
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'used_tokens' => $usage['total_tokens'],
            'remaining_tokens' => $remaining,
            'month' => $monthKey,
            'blocked' => $this->isUserBlocked($userId, $monthKey),
        );
    }

    public function getRoleQuotaTokensForRole(string $role): int
    {
        return $this->getRoleQuota($role) * 1000000;
    }

    public function getRoleQuotaTokensForUser(int $userId): int
    {
        return $this->getRoleQuotaTokensForRole($this->getAssignedUserRole($userId));
    }

    public function getMonthlyUsage(int $userId, ?string $month = null): array
    {
        $userId = max(0, (int) $userId);
        $monthKey = $this->normalizeMonth($month);

        if ($userId <= 0) {
            return array(
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'month' => $monthKey,
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpcgpt_volume_usage';
        $row = $wpdb->get_row('SELECT input_tokens, output_tokens FROM ' . $table . ' WHERE user_id = ' . (int) $userId . ' AND month = \'' . esc_sql($monthKey) . '\' LIMIT 1', ARRAY_A);

        if (!is_array($row)) {
            return array(
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'month' => $monthKey,
            );
        }

        $inputTokens = max(0, (int) ($row['input_tokens'] ?? 0));
        $outputTokens = max(0, (int) ($row['output_tokens'] ?? 0));

        return array(
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'month' => $monthKey,
        );
    }

    public function registerOpenAiUsage(int $userId, int $inputTokens = 0, int $outputTokens = 0, ?string $month = null): array
    {
        $userId = max(0, (int) $userId);
        $monthKey = $this->normalizeMonth($month);
        if ($userId <= 0) {
            return array(
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'month' => $monthKey,
            );
        }

        $existing = $this->getMonthlyUsage($userId, $monthKey);
        $newInput = $existing['input_tokens'] + max(0, (int) $inputTokens);
        $newOutput = $existing['output_tokens'] + max(0, (int) $outputTokens);

        global $wpdb;
        $table = $wpdb->prefix . 'wpcgpt_volume_usage';
        $wpdb->replace(
            $table,
            array(
                'user_id' => $userId,
                'month' => $monthKey,
                'input_tokens' => $newInput,
                'output_tokens' => $newOutput,
                'updated_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%d', '%s')
        );

        return array(
            'input_tokens' => $newInput,
            'output_tokens' => $newOutput,
            'total_tokens' => $newInput + $newOutput,
            'month' => $monthKey,
        );
    }

    public function canUseOpenAiForUser(int $userId, ?string $month = null): bool|WP_Error
    {
        $userId = max(0, (int) $userId);
        if ($userId <= 0) {
            return true;
        }

        $role = $this->getAssignedUserRole($userId);
        $configuredRoles = $this->getAvailableRoles();
        if ($role === '') {
            if (!empty($configuredRoles)) {
                return new WP_Error('volume_role_missing', 'Dem Benutzer ist noch keine Volumenrolle zugewiesen.', array('status' => 403));
            }

            return true;
        }

        $quotaTokens = $this->getRoleQuotaTokensForRole($role);
        if ($quotaTokens <= 0) {
            return new WP_Error('volume_role_missing', 'Der zugewiesenen Volumenrolle wurde kein Token-Volumen zugewiesen.', array('status' => 403));
        }

        if ($this->isUserBlocked($userId, $month)) {
            return new WP_Error('volume_limit_exceeded', 'Das monatliche OpenAI-Volumen für diesen Benutzer ist erreicht.', array('status' => 403));
        }

        return true;
    }

    public function isUserBlocked(int $userId, ?string $month = null): bool
    {
        $quotaTokens = $this->getRoleQuotaTokensForUser($userId);
        if ($quotaTokens <= 0) {
            return false;
        }

        return $this->getMonthlyUsage($userId, $month)['total_tokens'] >= $quotaTokens;
    }

    public function getRemainingTokensForUser(int $userId, ?string $month = null): int
    {
        $usage = $this->getMonthlyUsage($userId, $month);
        return $this->getRoleQuotaTokensForUser($userId) - $usage['total_tokens'];
    }

    public function recordUsageFromOpenAiResponse(int $userId, array $responseBody, ?string $month = null): array
    {
        $usage = $this->extractUsageTotals($responseBody);
        return $this->registerOpenAiUsage($userId, $usage['input_tokens'], $usage['output_tokens'], $month);
    }

    public function extractUsageTotals(array $responseBody): array
    {
        $input = 0;
        $output = 0;

        if (isset($responseBody['usage']) && is_array($responseBody['usage'])) {
            $input = max(0, (int) ($responseBody['usage']['input_tokens'] ?? 0));
            $output = max(0, (int) ($responseBody['usage']['output_tokens'] ?? 0));
        }

        if ($input === 0 && $output === 0 && isset($responseBody['usage']['total_tokens'])) {
            $total = max(0, (int) $responseBody['usage']['total_tokens']);
            $input = $total;
            $output = 0;
        }

        return array(
            'input_tokens' => $input,
            'output_tokens' => $output,
        );
    }

    public function getAuditLog(?string $month = null, int $limit = 200): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcgpt_volume_log';
        $monthKey = $this->normalizeMonth($month);
        $query = 'SELECT id, event_type, user_id, role_name, month, old_value, new_value, created_at FROM ' . $table;
        $params = array();
        if ($monthKey !== '') {
            $query .= ' WHERE month = %s';
            $params[] = $monthKey;
        }
        $query .= ' ORDER BY created_at DESC LIMIT ' . max(1, min(500, (int) $limit));

        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }

        $rows = $wpdb->get_results($query, ARRAY_A);
        return is_array($rows) ? array_values($rows) : array();
    }

    public function normalizeMonth(?string $month = null): string
    {
        $candidate = trim((string) ($month ?? ''));
        if ($candidate === '') {
            return gmdate('Y-m');
        }

        if (preg_match('/^\d{4}-\d{2}$/', $candidate) === 1) {
            return $candidate;
        }

        $timestamp = strtotime($candidate);
        if ($timestamp === false) {
            return gmdate('Y-m');
        }

        return gmdate('Y-m', $timestamp);
    }

    private function addAuditLog(string $eventType, int $userId, ?string $roleName, string $oldValue, string $newValue, ?string $month = null): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcgpt_volume_log';
        $wpdb->insert(
            $table,
            array(
                'event_type' => sanitize_key($eventType),
                'user_id' => max(0, $userId),
                'role_name' => $roleName !== null ? sanitize_key((string) $roleName) : '',
                'month' => $this->normalizeMonth($month),
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s')
        );
    }
}
