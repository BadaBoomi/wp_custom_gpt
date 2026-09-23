<?php

namespace WpCustomGpt\Services;

class SettingsService
{
    private const OPTION_API_KEY = 'wpcgpt_api_key';
    private const OPTION_STARTERS = 'wpcgpt_starters';
    private const OPTION_OPENAI_DEBUG_ENABLED = 'wpcgpt_openai_debug_enabled';

    public function getSettingsForAdmin(): array
    {
        $apiKey = (string) get_option(self::OPTION_API_KEY, '');

        return array(
            'starters' => (string) get_option(self::OPTION_STARTERS, ''),
            'openai_debug_enabled' => $this->toBool(get_option(self::OPTION_OPENAI_DEBUG_ENABLED, '0')),
            'configuration_entries' => $this->getConfigurationEntries(),
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

        if (array_key_exists('openai_debug_enabled', $payload)) {
            $enabled = $this->toBool($payload['openai_debug_enabled']) ? '1' : '0';
            update_option(self::OPTION_OPENAI_DEBUG_ENABLED, $enabled, false);
        }

        return $this->getSettingsForAdmin();
    }

    public function getRuntimeSettings(): array
    {
        return array(
            'api_key' => (string) get_option(self::OPTION_API_KEY, ''),
            'starters' => (string) get_option(self::OPTION_STARTERS, ''),
            'openai_debug_enabled' => $this->toBool(get_option(self::OPTION_OPENAI_DEBUG_ENABLED, '0')),
        );
    }

    public function getConfigurationEntries(): array
    {
        $starters = (string) get_option(self::OPTION_STARTERS, '');
        return $this->parseStarterPromptsMarkdown($starters);
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

    /**
     * @param array<int, mixed> $entries
     * @return array<int, array{label: string, prompt: string, promptId: string}>
     */
    public function normalizeConfigurationEntries(array $entries): array
    {
        $normalized = array();

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = trim(sanitize_text_field((string) ($entry['label'] ?? '')));
            $prompt = trim((string) ($entry['prompt'] ?? ''));
            $promptId = trim(sanitize_text_field((string) ($entry['promptId'] ?? '')));

            if ($label === '' && $prompt === '' && $promptId === '') {
                continue;
            }

            $normalized[] = array(
                'label' => $label !== '' ? $label : 'Ohne Bezeichnung',
                'prompt' => $prompt,
                'promptId' => $promptId,
            );
        }

        return $normalized;
    }

    public function saveConfigurationRows(array $rows): void
    {
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
                'label' => $label,
                'prompt' => $prompt,
                'promptId' => $parts[2] ?? '',
            );
        }

        return $entries;
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
}
