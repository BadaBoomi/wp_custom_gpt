<?php

namespace WpCustomGpt\Services;

class SettingsService
{
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
            $this->saveConfigurationRows($this->normalizeConfigurationEntries($payload['configuration_entries']));
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
     * @param array<int, mixed> $entries
     * @return array<int, array{id: int, label: string, prompt: string, promptId: string, iconId: int}>
     */
    public function normalizeConfigurationEntries(array $entries): array
    {
        $normalized = array();
        $maxId = 0;

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
            $promptId = trim(sanitize_text_field((string) ($entry['promptId'] ?? '')));
            $iconId = max(0, (int) ($entry['iconId'] ?? 0));
            $maxPerRoomRaw = $entry['maxPerRoom'] ?? $entry['max_per_room'] ?? 0;
            $maxPerRoom = max(0, (int) $maxPerRoomRaw);

            if ($id === 0) {
                $maxId++;
                $id = $maxId;
            }

            if ($label === '' && $prompt === '' && $promptId === '' && $iconId === 0 && $maxPerRoom === 0) {
                continue;
            }

            $normalized[] = array(
                'id' => $id,
                'label' => $label !== '' ? $label : 'Ohne Bezeichnung',
                'prompt' => $prompt,
                'promptId' => $promptId,
                'iconId' => $iconId,
                'maxPerRoom' => $maxPerRoom,
            );
        }

        return $normalized;
    }

    public function saveConfigurationRows(array $rows): void
    {
        update_option(self::OPTION_CONFIGURATION_ENTRIES, $rows, false);

        // Keep legacy markdown option synchronized for compatibility.
        $header = '| Zweck | Prompt | Prompt-ID |';
        $separator = '|---|---|---|';
        $body = array();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $prompt = trim((string) ($row['prompt'] ?? ''));
            $promptId = trim((string) ($row['promptId'] ?? ''));

            $safeLabel = str_replace('|', '/', $label);
            $safePrompt = str_replace('|', '/', $prompt);
            $safePromptId = str_replace('|', '/', $promptId);

            $body[] = sprintf('| %s | %s | %s |', $safeLabel, $safePrompt, $safePromptId);
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
                'promptId' => $parts[2] ?? '',
                'iconId' => 0,
            );
        }

        return $entries;
    }

    /**
     * @param array<int, array{id?: int, label: string, prompt: string, promptId: string, iconId?: int}> $entries
     * @return array<int, array{id: int, label: string, prompt: string, promptId: string, iconId: int, iconUrl: string}>
     */
    private function hydrateConfigurationEntries(array $entries): array
    {
        $result = array();
        foreach ($entries as $entry) {
            $id = max(0, (int) ($entry['id'] ?? 0));
            $iconId = max(0, (int) ($entry['iconId'] ?? 0));
            $maxPerRoom = max(0, (int) ($entry['maxPerRoom'] ?? $entry['max_per_room'] ?? 0));
            $result[] = array(
                'id' => $id,
                'label' => (string) ($entry['label'] ?? ''),
                'prompt' => (string) ($entry['prompt'] ?? ''),
                'promptId' => (string) ($entry['promptId'] ?? ''),
                'iconId' => $iconId,
                'maxPerRoom' => $maxPerRoom,
                'iconUrl' => $this->resolveAttachmentUrl($iconId),
            );
        }

        return $result;
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
