<?php

namespace WpCustomGpt\Services;

class SettingsService
{
    private const CONFIGURATION_LOG_TABLE_SUFFIX = 'wpcgpt_configuration_log';
    private const OPTION_API_KEY = 'wpcgpt_api_key';
    private const OPTION_STARTERS = 'wpcgpt_starters';
    private const OPTION_OPENAI_DEBUG_ENABLED = 'wpcgpt_openai_debug_enabled';
    private const OPTION_ASSISTANT_ICON_URL = 'wpcgpt_assistant_icon_url';
    private const OPTION_ASSISTANT_ICON_ID = 'wpcgpt_assistant_icon_id';
    private const OPTION_CONFIGURATION_ENTRIES = 'wpcgpt_configuration_entries';
    private const OPTION_ROOM_ATTRIBUTES = 'wpcgpt_room_attributes';
    private const OPTION_TRANSIENT_MODE_ENABLED = 'wpcgpt_transient_mode_enabled';
    private const OPTION_UI_THEME = 'wpcgpt_ui_theme';

    /**
     * @return array<string, string>
     */
    public function getFontFamilies(): array
    {
        return array(
            'Segoe UI, Tahoma, sans-serif' => 'Segoe UI',
            'Arial, Helvetica, sans-serif' => 'Arial',
            'Verdana, Geneva, sans-serif' => 'Verdana',
            'Trebuchet MS, Helvetica, sans-serif' => 'Trebuchet MS',
            'Georgia, Times New Roman, serif' => 'Georgia',
            'Times New Roman, Times, serif' => 'Times New Roman',
            'Garamond, Baskerville, serif' => 'Garamond',
            'Courier New, Courier, monospace' => 'Courier New',
            'Consolas, Monaco, monospace' => 'Consolas',
            'Lucida Console, Monaco, monospace' => 'Lucida Console',
            'system-ui, -apple-system, BlinkMacSystemFont, sans-serif' => 'System UI',
        );
    }

    /**
     * @return array<string, string>
     */
    public function getDefaultUiTheme(): array
    {
        return array(
            'font_family' => 'Segoe UI, Tahoma, sans-serif',
            'font_size' => '15px',
            'color_text' => '#1f2933',
            'color_muted' => '#5f6b76',
            'color_background' => '#f3f6fb',
            'color_surface' => '#ffffff',
            'color_border' => '#cfd8e3',
            'color_accent' => '#0a58ca',
            'color_accent_text' => '#ffffff',
            'color_success' => '#2d6a4f',
            'color_error' => '#b00020',
            'color_chat_window' => '#4c5f7d',
            'color_user_bubble' => '#e7f0fb',
            'color_assistant_bubble' => '#cbe3f8',
            'color_assistant_avatar_bg' => '#f3c64b',
        );
    }

    public function getSettingsForAdmin(): array
    {
        $apiKey = (string) get_option(self::OPTION_API_KEY, '');
        $assistantIconId = (int) get_option(self::OPTION_ASSISTANT_ICON_ID, 0);
        $assistantIconUrl = $this->resolveAttachmentUrl($assistantIconId);
        if ($assistantIconUrl === '') {
            // Backward compatibility with previous URL-based setting.
            $assistantIconUrl = esc_url_raw((string) get_option(self::OPTION_ASSISTANT_ICON_URL, ''));
        }

        return array(
            'starters' => (string) get_option(self::OPTION_STARTERS, ''),
            'openai_debug_enabled' => $this->toBool(get_option(self::OPTION_OPENAI_DEBUG_ENABLED, '0')),
            'transient_mode_enabled' => $this->isTransientModeEnabled(),
            'configuration_entries' => $this->getConfigurationEntries(),
            'room_attributes' => $this->getRoomAttributes(),
            'assistant_icon_id' => $assistantIconId,
            'assistant_icon_url' => esc_url_raw($assistantIconUrl),
            'ui_theme' => $this->getUiThemeSettings(),
            'font_families' => $this->getFontFamilies(),
            'has_api_key' => $apiKey !== '',
            'api_key_masked' => $this->maskApiKey($apiKey),
        );
    }

    public function saveSettings(array $payload): array
    {
        if (array_key_exists('api_key', $payload)) {
            $apiKey = trim((string) $payload['api_key']);
            if ($apiKey !== '') {
                update_option(self::OPTION_API_KEY, $apiKey, false);
            }
        }

        if (array_key_exists('configuration_entries', $payload) && is_array($payload['configuration_entries'])) {
            $normalizedEntries = $this->normalizeConfigurationEntries($payload['configuration_entries']);
            $oldEntries = $this->normalizeConfigurationEntries($this->getConfigurationEntries());
            $this->logConfigurationEntryChanges($oldEntries, $normalizedEntries, (int) get_current_user_id());
            $this->saveConfigurationRows($normalizedEntries);
        }

        if (array_key_exists('room_attributes', $payload) && is_array($payload['room_attributes'])) {
            $this->saveRoomAttributes($this->normalizeRoomAttributes($payload['room_attributes']));
        }

        if (array_key_exists('openai_debug_enabled', $payload)) {
            $enabled = $this->toBool($payload['openai_debug_enabled']) ? '1' : '0';
            update_option(self::OPTION_OPENAI_DEBUG_ENABLED, $enabled, false);
        }

        if (array_key_exists('transient_mode_enabled', $payload)) {
            $enabled = $this->toBool($payload['transient_mode_enabled']) ? '1' : '0';
            update_option(self::OPTION_TRANSIENT_MODE_ENABLED, $enabled, false);
        }

        if (array_key_exists('assistant_icon_id', $payload)) {
            $assistantIconId = max(0, (int) $payload['assistant_icon_id']);
            update_option(self::OPTION_ASSISTANT_ICON_ID, $assistantIconId, false);
            // Keep URL option aligned for smooth backward compatibility.
            update_option(self::OPTION_ASSISTANT_ICON_URL, $this->resolveAttachmentUrl($assistantIconId), false);
        } elseif (array_key_exists('assistant_icon_url', $payload)) {
            // Graceful fallback if an older client still sends a URL.
            $assistantIconUrl = esc_url_raw(trim((string) $payload['assistant_icon_url']));
            update_option(self::OPTION_ASSISTANT_ICON_URL, $assistantIconUrl, false);
            update_option(self::OPTION_ASSISTANT_ICON_ID, 0, false);
        }

        if (array_key_exists('ui_theme', $payload) && is_array($payload['ui_theme'])) {
            update_option(self::OPTION_UI_THEME, $this->normalizeUiThemeSettings($payload['ui_theme']), false);
        }

        return $this->getSettingsForAdmin();
    }

    public function getRuntimeSettings(): array
    {
        $assistantIconId = (int) get_option(self::OPTION_ASSISTANT_ICON_ID, 0);
        $assistantIconUrl = $this->resolveAttachmentUrl($assistantIconId);
        if ($assistantIconUrl === '') {
            $assistantIconUrl = esc_url_raw((string) get_option(self::OPTION_ASSISTANT_ICON_URL, ''));
        }

        return array(
            'api_key' => (string) get_option(self::OPTION_API_KEY, ''),
            'starters' => (string) get_option(self::OPTION_STARTERS, ''),
            'openai_debug_enabled' => $this->toBool(get_option(self::OPTION_OPENAI_DEBUG_ENABLED, '0')),
            'transient_mode_enabled' => $this->isTransientModeEnabled(),
            'assistant_icon_id' => $assistantIconId,
            'assistant_icon_url' => esc_url_raw($assistantIconUrl),
            'ui_theme' => $this->getUiThemeSettings(),
        );
    }

    public function isTransientModeEnabled(): bool
    {
        return $this->toBool(get_option(self::OPTION_TRANSIENT_MODE_ENABLED, '0'));
    }

    /**
     * @return array<string, string>
     */
    public function getUiThemeSettings(): array
    {
        $defaults = $this->getDefaultUiTheme();
        $stored = get_option(self::OPTION_UI_THEME, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        $normalized = $this->normalizeUiThemeSettings($stored);

        return array_merge($defaults, $normalized);
    }

    /**
     * @param array<string, mixed> $theme
     * @return array<string, string>
     */
    public function normalizeUiThemeSettings(array $theme): array
    {
        $defaults = $this->getDefaultUiTheme();
        $normalized = array();

        foreach ($defaults as $key => $defaultValue) {
            $raw = array_key_exists($key, $theme) ? (string) $theme[$key] : $defaultValue;
            $normalized[$key] = $this->sanitizeCssValue($raw, $defaultValue);
        }

        return $normalized;
    }

    public function getConfigurationEntries(): array
    {
        $storedEntries = get_option(self::OPTION_CONFIGURATION_ENTRIES, array());
        if (is_array($storedEntries) && !empty($storedEntries)) {
            return $this->hydrateConfigurationEntries($this->normalizeConfigurationEntries($storedEntries));
        }

        $starters = (string) get_option(self::OPTION_STARTERS, '');
        return $this->hydrateConfigurationEntries($this->parseStarterPromptsMarkdown($starters));
    }

    public function getConfigurationAuditLog(array $filters = array()): array
    {
        global $wpdb;

        $table = $wpdb->prefix . self::CONFIGURATION_LOG_TABLE_SUFFIX;
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ((string) $tableExists !== $table) {
            return array(
                'entries' => array(),
                'users' => array(),
            );
        }

        $usersTable = $wpdb->users;
        $where = array();
        $params = array();

        $userId = max(0, (int) ($filters['user_id'] ?? 0));
        if ($userId > 0) {
            $where[] = 'l.user_id = %d';
            $params[] = $userId;
        }

        $fieldName = sanitize_key((string) ($filters['field_name'] ?? ''));
        if ($fieldName !== '') {
            $where[] = 'l.field_name = %s';
            $params[] = $fieldName;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $fromTimestamp = strtotime($dateFrom . ' 00:00:00');
            if ($fromTimestamp !== false) {
                $where[] = 'l.changed_at >= %s';
                $params[] = gmdate('Y-m-d H:i:s', $fromTimestamp);
            }
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $toTimestamp = strtotime($dateTo . ' 23:59:59');
            if ($toTimestamp !== false) {
                $where[] = 'l.changed_at <= %s';
                $params[] = gmdate('Y-m-d H:i:s', $toTimestamp);
            }
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 200)));

        $query = "SELECT l.id, l.user_id, COALESCE(u.display_name, u.user_login, '') AS user_name, l.config_entry_id, l.field_name, l.old_value, l.changed_at
            FROM {$table} l
            LEFT JOIN {$usersTable} u ON u.ID = l.user_id";

        if (!empty($where)) {
            $query .= ' WHERE ' . implode(' AND ', $where);
        }

        $query .= ' ORDER BY l.changed_at DESC, l.id DESC LIMIT ' . $limit;

        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }

        $rows = $wpdb->get_results($query, ARRAY_A);
        if (!is_array($rows)) {
            $rows = array();
        }

        $usersQuery = "SELECT DISTINCT l.user_id, COALESCE(u.display_name, u.user_login, '') AS user_name
            FROM {$table} l
            LEFT JOIN {$usersTable} u ON u.ID = l.user_id
            WHERE l.user_id > 0
            ORDER BY user_name ASC, l.user_id ASC
            LIMIT 500";

        $users = $wpdb->get_results($usersQuery, ARRAY_A);
        if (!is_array($users)) {
            $users = array();
        }

        return array(
            'entries' => array_values($rows),
            'users' => array_values($users),
        );
    }

    /**
     * @return string[]
     */
    public function getRoomAttributes(): array
    {
        $stored = get_option(self::OPTION_ROOM_ATTRIBUTES, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        return $this->normalizeRoomAttributes($stored);
    }

    /**
     * @param array<int, mixed> $values
     * @return string[]
     */
    public function normalizeRoomAttributes(array $values): array
    {
        $names = array();
        $seen = array();

        foreach ($values as $value) {
            $name = strtolower(trim((string) sanitize_text_field((string) $value)));
            if ($name === '') {
                continue;
            }

            $name = preg_replace('/[^a-z0-9 _\-]/', '', $name);
            $name = is_string($name) ? trim($name) : '';
            if ($name === '' || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param string[] $values
     */
    public function saveRoomAttributes(array $values): void
    {
        update_option(self::OPTION_ROOM_ATTRIBUTES, $values, false);
    }

    public function findConfigurationEntryByLabel(string $label): ?array
    {
        $needle = strtolower(trim($label));
        if ($needle === '') {
            return null;
        }

        foreach ($this->getConfigurationEntries() as $entry) {
            if (strtolower(trim((string) ($entry['label'] ?? ''))) === $needle) {
                return $entry;
            }
        }

        return null;
    }

    public function findConfigurationEntryById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        foreach ($this->getConfigurationEntries() as $entry) {
            if ((int) ($entry['id'] ?? 0) === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $values
     * @return string[]
     */
    private function normalizeAttributeList($values, array $allowedValues = array()): array
    {
        if (!is_array($values)) {
            $values = array($values);
        }

        $allowedMap = array();
        foreach ($allowedValues as $allowedValue) {
            $normalized = strtolower(trim((string) $allowedValue));
            $normalized = preg_replace('/[^a-z0-9 _\-]/', '', $normalized);
            if ($normalized !== '') {
                $allowedMap[$normalized] = true;
            }
        }

        $result = array();
        $seen = array();

        foreach ($values as $value) {
            $normalized = strtolower(trim((string) $value));
            $normalized = preg_replace('/[^a-z0-9 _\-]/', '', $normalized);
            $normalized = is_string($normalized) ? trim($normalized) : '';

            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            if (!empty($allowedMap) && !isset($allowedMap[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $result[] = $normalized;
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $entries
     * @return array<int, array{id: int, label: string, prompt: string, agentPrompt: string, vectorStoreId: string, model: string, iconId: int, maxPerRoom: int, attrPrereq: string[], attrProv: string[]}>
     */
    public function normalizeConfigurationEntries(array $entries): array
    {
        $normalized = array();
        $maxId = 0;
        $allowedRoomAttributes = $this->getRoomAttributes();

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $existingId = max(0, (int) ($entry['id'] ?? 0));
            if ($existingId > $maxId) {
                $maxId = $existingId;
            }
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = max(0, (int) ($entry['id'] ?? 0));
            $label = trim(sanitize_text_field((string) ($entry['label'] ?? '')));
            $prompt = trim((string) ($entry['prompt'] ?? ''));
            $agentPrompt = trim((string) ($entry['agentPrompt'] ?? $entry['agent_prompt'] ?? ''));
            $vectorStoreId = trim(sanitize_text_field((string) ($entry['vectorStoreId'] ?? $entry['vector_store_id'] ?? '')));
            $model = trim(sanitize_text_field((string) ($entry['model'] ?? '')));
            $iconId = max(0, (int) ($entry['iconId'] ?? 0));
            $maxPerRoomRaw = $entry['maxPerRoom'] ?? $entry['max_per_room'] ?? 0;
            $maxPerRoom = max(0, (int) $maxPerRoomRaw);
            $attrPrereq = $this->normalizeAttributeList($entry['attrPrereq'] ?? $entry['attr_prereq'] ?? array(), $allowedRoomAttributes);
            $attrProv = $this->normalizeAttributeList($entry['attrProv'] ?? $entry['attr_prov'] ?? array(), $allowedRoomAttributes);

            if ($id === 0) {
                $maxId++;
                $id = $maxId;
            }

            if ($label === '' && $prompt === '' && $agentPrompt === '' && $vectorStoreId === '' && $model === '' && $iconId === 0 && $maxPerRoom === 0 && empty($attrPrereq) && empty($attrProv)) {
                continue;
            }

            $normalized[] = array(
                'id' => $id,
                'label' => $label !== '' ? $label : 'Ohne Bezeichnung',
                'prompt' => $prompt,
                'agentPrompt' => $agentPrompt,
                'vectorStoreId' => $vectorStoreId,
                'model' => $model,
                'iconId' => $iconId,
                'maxPerRoom' => $maxPerRoom,
                'attrPrereq' => $attrPrereq,
                'attrProv' => $attrProv,
            );
        }

        return $normalized;
    }

    public function saveConfigurationRows(array $rows): void
    {
        update_option(self::OPTION_CONFIGURATION_ENTRIES, $rows, false);

        // Keep legacy markdown option synchronized for compatibility.
        $header = '| Zweck | Prompt | Agent Prompt | Vector-Store-ID | Modell |';
        $separator = '|---|---|---|---|---|';
        $body = array();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $prompt = trim((string) ($row['prompt'] ?? ''));
            $agentPrompt = trim((string) ($row['agentPrompt'] ?? ''));
            $vectorStoreId = trim((string) ($row['vectorStoreId'] ?? ''));
            $model = trim((string) ($row['model'] ?? ''));

            $safeLabel = str_replace('|', '/', $label);
            $safePrompt = str_replace('|', '/', $prompt);
            $safeAgentPrompt = str_replace('|', '/', $agentPrompt);
            $safeVectorStoreId = str_replace('|', '/', $vectorStoreId);
            $safeModel = str_replace('|', '/', $model);

            $body[] = sprintf('| %s | %s | %s | %s | %s |', $safeLabel, $safePrompt, $safeAgentPrompt, $safeVectorStoreId, $safeModel);
        }

        $markdown = implode("\n", array_merge(array($header, $separator), $body));
        update_option(self::OPTION_STARTERS, $markdown, false);
    }

    private function parseStarterPromptsMarkdown(string $startersMd): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $startersMd) ?: array();
        if (count($lines) < 3) {
            return array();
        }

        $entries = array();
        foreach (array_slice($lines, 2) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', trim($trimmed, '|')));
            if (count($parts) < 2) {
                continue;
            }

            $label = $parts[0];
            $prompt = $parts[1];
            if ($label === '' && $prompt === '') {
                continue;
            }

            $entries[] = array(
                'id' => count($entries) + 1,
                'label' => $label,
                'prompt' => $prompt,
                'agentPrompt' => isset($parts[4]) ? (string) $parts[2] : '',
                'vectorStoreId' => isset($parts[4])
                    ? (string) ($parts[3] ?? '')
                    : (isset($parts[2]) && strpos((string) $parts[2], 'pmpt_') !== 0 ? (string) $parts[2] : ''),
                'model' => isset($parts[4]) ? (string) ($parts[4] ?? '') : (string) ($parts[3] ?? ''),
                'iconId' => 0,
            );
        }

        return $entries;
    }

    /**
     * @param array<int, array{id?: int, label: string, prompt: string, agentPrompt: string, vectorStoreId: string, model: string, iconId?: int}> $entries
     * @return array<int, array{id: int, label: string, prompt: string, agentPrompt: string, vectorStoreId: string, model: string, iconId: int, iconUrl: string}>
     */
    private function hydrateConfigurationEntries(array $entries): array
    {
        $allowedRoomAttributes = $this->getRoomAttributes();
        $result = array();
        foreach ($entries as $entry) {
            $id = max(0, (int) ($entry['id'] ?? 0));
            $iconId = max(0, (int) ($entry['iconId'] ?? 0));
            $maxPerRoom = max(0, (int) ($entry['maxPerRoom'] ?? $entry['max_per_room'] ?? 0));
            $attrPrereq = $this->normalizeAttributeList($entry['attrPrereq'] ?? $entry['attr_prereq'] ?? array(), $allowedRoomAttributes);
            $attrProv = $this->normalizeAttributeList($entry['attrProv'] ?? $entry['attr_prov'] ?? array(), $allowedRoomAttributes);
            $result[] = array(
                'id' => $id,
                'label' => (string) ($entry['label'] ?? ''),
                'prompt' => (string) ($entry['prompt'] ?? ''),
                'agentPrompt' => (string) ($entry['agentPrompt'] ?? ''),
                'vectorStoreId' => (string) ($entry['vectorStoreId'] ?? ''),
                'model' => (string) ($entry['model'] ?? ''),
                'iconId' => $iconId,
                'maxPerRoom' => $maxPerRoom,
                'attrPrereq' => $attrPrereq,
                'attrProv' => $attrProv,
                'iconUrl' => $this->resolveAttachmentUrl($iconId),
            );
        }

        return $result;
    }

    /**
     * @param array<int, array{id: int, label: string, prompt: string, agentPrompt: string, vectorStoreId: string, model: string, iconId: int, maxPerRoom: int, attrPrereq: string[], attrProv: string[]}> $oldEntries
     * @param array<int, array{id: int, label: string, prompt: string, agentPrompt: string, vectorStoreId: string, model: string, iconId: int, maxPerRoom: int, attrPrereq: string[], attrProv: string[]}> $newEntries
     */
    private function logConfigurationEntryChanges(array $oldEntries, array $newEntries, int $userId): void
    {
        global $wpdb;

        $table = $wpdb->prefix . self::CONFIGURATION_LOG_TABLE_SUFFIX;
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ((string) $tableExists !== $table) {
            return;
        }

        $oldById = array();
        foreach ($oldEntries as $entry) {
            $entryId = max(0, (int) ($entry['id'] ?? 0));
            if ($entryId > 0) {
                $oldById[$entryId] = $entry;
            }
        }

        $newById = array();
        foreach ($newEntries as $entry) {
            $entryId = max(0, (int) ($entry['id'] ?? 0));
            if ($entryId > 0) {
                $newById[$entryId] = $entry;
            }
        }

        $trackedFields = array('label', 'prompt', 'agentPrompt', 'vectorStoreId', 'model', 'iconId', 'maxPerRoom', 'attrPrereq', 'attrProv');

        foreach ($oldById as $entryId => $oldEntry) {
            if (!isset($newById[$entryId])) {
                foreach ($trackedFields as $field) {
                    $oldValue = array_key_exists($field, $oldEntry) ? $oldEntry[$field] : '';
                    if ($this->isAuditValueEmpty($oldValue)) {
                        continue;
                    }
                    $this->insertConfigurationAuditLog($table, $userId, $entryId, $field, $oldValue);
                }
                continue;
            }

            $newEntry = $newById[$entryId];
            foreach ($trackedFields as $field) {
                $oldValue = array_key_exists($field, $oldEntry) ? $oldEntry[$field] : '';
                $newValue = array_key_exists($field, $newEntry) ? $newEntry[$field] : '';
                if ($this->serializeAuditValue($oldValue) === $this->serializeAuditValue($newValue)) {
                    continue;
                }

                $this->insertConfigurationAuditLog($table, $userId, $entryId, $field, $oldValue);
            }
        }
    }

    private function isAuditValueEmpty($value): bool
    {
        if (is_array($value)) {
            return empty($value);
        }

        return trim((string) $value) === '';
    }

    private function serializeAuditValue($value): string
    {
        if (is_array($value)) {
            $encoded = wp_json_encode(array_values($value));
            return is_string($encoded) ? $encoded : '';
        }

        return (string) $value;
    }

    private function insertConfigurationAuditLog(string $table, int $userId, int $configEntryId, string $fieldName, $oldValue): void
    {
        global $wpdb;

        $wpdb->insert(
            $table,
            array(
                'user_id' => max(0, $userId),
                'config_entry_id' => max(0, $configEntryId),
                'field_name' => sanitize_key($fieldName),
                'old_value' => $this->serializeAuditValue($oldValue),
                'changed_at' => current_time('mysql', true),
            ),
            array('%d', '%d', '%s', '%s', '%s')
        );
    }

    private function maskApiKey(string $apiKey): string
    {
        if ($apiKey === '') {
            return '';
        }

        if (strlen($apiKey) <= 8) {
            return str_repeat('*', strlen($apiKey));
        }

        $prefix = substr($apiKey, 0, 4);
        $suffix = substr($apiKey, -4);

        return $prefix . str_repeat('*', max(0, strlen($apiKey) - 8)) . $suffix;
    }

    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return $normalized === '1' || $normalized === 'true' || $normalized === 'yes' || $normalized === 'on';
    }

    private function sanitizeCssValue(string $value, string $fallback): string
    {
        $clean = trim(wp_strip_all_tags($value));
        if ($clean === '') {
            return $fallback;
        }

        // Prevent CSS/script injection via style variables.
        if (preg_match('/[{};]/', $clean) === 1 || stripos($clean, 'url(') !== false || stripos($clean, 'expression(') !== false) {
            return $fallback;
        }

        return $clean;
    }

    private function resolveAttachmentUrl(int $attachmentId): string
    {
        if ($attachmentId <= 0) {
            return '';
        }

        $url = wp_get_attachment_image_url($attachmentId, 'full');
        if (!is_string($url) || trim($url) === '') {
            return '';
        }

        return esc_url_raw($url);
    }
}
