<?php

namespace WpCustomGpt\Services;

class SettingsService
{
    private const OPTION_API_KEY = 'wpcgpt_api_key';
    private const OPTION_PROMPT_ID = 'wpcgpt_prompt_id';
    private const OPTION_VECTOR_STORE_IDS = 'wpcgpt_vector_store_ids';
    private const OPTION_USER_EMAIL = 'wpcgpt_user_email';
    private const OPTION_STARTERS = 'wpcgpt_starters';
    private const OPTION_OPENAI_DEBUG_ENABLED = 'wpcgpt_openai_debug_enabled';

    public function getSettingsForAdmin(): array
    {
        $apiKey = (string) get_option(self::OPTION_API_KEY, '');

        return array(
            'prompt_id' => (string) get_option(self::OPTION_PROMPT_ID, ''),
            'vector_store_ids' => (string) get_option(self::OPTION_VECTOR_STORE_IDS, ''),
            'user_email' => (string) get_option(self::OPTION_USER_EMAIL, ''),
            'starters' => (string) get_option(self::OPTION_STARTERS, ''),
            'openai_debug_enabled' => $this->toBool(get_option(self::OPTION_OPENAI_DEBUG_ENABLED, '0')),
            'configuration_entries' => $this->getConfigurationEntries(),
            'has_api_key' => $apiKey !== '',
            'api_key_masked' => $this->maskApiKey($apiKey),
        );
    }

    public function saveSettings(array $payload): array
    {
        if (array_key_exists('prompt_id', $payload)) {
            update_option(self::OPTION_PROMPT_ID, sanitize_text_field((string) $payload['prompt_id']), false);
        }

        if (array_key_exists('vector_store_ids', $payload)) {
            update_option(self::OPTION_VECTOR_STORE_IDS, sanitize_text_field((string) $payload['vector_store_ids']), false);
        }

        if (array_key_exists('user_email', $payload)) {
            $email = sanitize_email((string) $payload['user_email']);
            update_option(self::OPTION_USER_EMAIL, $email, false);
        }

        if (array_key_exists('api_key', $payload)) {
            $apiKey = trim((string) $payload['api_key']);
            if ($apiKey !== '') {
                update_option(self::OPTION_API_KEY, $apiKey, false);
            }
        }

        if (array_key_exists('starters', $payload)) {
            update_option(self::OPTION_STARTERS, (string) $payload['starters'], false);
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
            'prompt_id' => (string) get_option(self::OPTION_PROMPT_ID, ''),
            'vector_store_ids' => (string) get_option(self::OPTION_VECTOR_STORE_IDS, ''),
            'user_email' => (string) get_option(self::OPTION_USER_EMAIL, ''),
            'starters' => (string) get_option(self::OPTION_STARTERS, ''),
            'openai_debug_enabled' => $this->toBool(get_option(self::OPTION_OPENAI_DEBUG_ENABLED, '0')),
        );
    }

    public function getConfigurationEntries(): array
    {
        $starters = (string) get_option(self::OPTION_STARTERS, '');
        return $this->parseStarterPromptsMarkdown($starters);
    }

    /**
     * @param array<int, mixed> $entries
     * @return array<int, array{label: string, prompt: string, promptId: string, vectorStoreId: string}>
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
            $vectorStoreId = trim(sanitize_text_field((string) ($entry['vectorStoreId'] ?? '')));

            if ($label === '' && $prompt === '' && $promptId === '' && $vectorStoreId === '') {
                continue;
            }

            $normalized[] = array(
                'label' => $label !== '' ? $label : 'Ohne Bezeichnung',
                'prompt' => $prompt,
                'promptId' => $promptId,
                'vectorStoreId' => $vectorStoreId,
            );
        }

        return $normalized;
    }

    public function parseConfigurationPrompts(string $rawText): array
    {
        $trimmed = trim($rawText);
        if ($trimmed === '') {
            return array();
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $trimmed, $matches)) {
            $jsonCandidate = trim((string) ($matches[1] ?? ''));
        } else {
            $jsonCandidate = $trimmed;
        }

        $decoded = json_decode($jsonCandidate, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded) && $this->isListArray($decoded)) {
                $rows = array();
                foreach ($decoded as $item) {
                    foreach ($this->normalizeConfigRowsFromUnknown($item) as $row) {
                        $rows[] = $row;
                    }
                }
                if (!empty($rows)) {
                    return $rows;
                }
            }

            if (is_array($decoded)) {
                $listCandidate = $decoded['configuration'] ?? $decoded['prompts'] ?? $decoded['starters'] ?? $decoded['data'] ?? null;
                if (is_array($listCandidate)) {
                    $rows = array();
                    foreach ($listCandidate as $item) {
                        foreach ($this->normalizeConfigRowsFromUnknown($item) as $row) {
                            $rows[] = $row;
                        }
                    }
                    if (!empty($rows)) {
                        return $rows;
                    }
                }
            }
        }

        return $this->parseStarterPromptsMarkdown($rawText);
    }

    public function saveConfigurationRows(array $rows): void
    {
        $header = '| Zweck | Prompt | Prompt-ID | Vector-Store-ID |';
        $separator = '|---|---|---|---|';
        $body = array();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $prompt = trim((string) ($row['prompt'] ?? ''));
            $promptId = trim((string) ($row['promptId'] ?? ''));
            $vectorStoreId = trim((string) ($row['vectorStoreId'] ?? ''));

            $safeLabel = str_replace('|', '/', $label);
            $safePrompt = str_replace('|', '/', $prompt);
            $safePromptId = str_replace('|', '/', $promptId);
            $safeVectorStoreId = str_replace('|', '/', $vectorStoreId);

            $body[] = sprintf('| %s | %s | %s | %s |', $safeLabel, $safePrompt, $safePromptId, $safeVectorStoreId);
        }

        $markdown = implode("\n", array_merge(array($header, $separator), $body));
        update_option(self::OPTION_STARTERS, $markdown, false);
    }

    private function normalizeConfigRowsFromUnknown($value): array
    {
        if (!is_array($value)) {
            return array();
        }

        $labelRaw = $value['Zweck'] ?? $value['Desc'] ?? $value['Description'] ?? $value['label'] ?? $value['name'] ?? $value['title'] ?? '';
        $promptRaw = $value['Prompt'] ?? $value['prompt'] ?? $value['text'] ?? $value['value'] ?? '';
        $promptIdRaw = $value['Pmpt-ID'] ?? $value['Prompt-ID'] ?? $value['promptId'] ?? $value['pmptId'] ?? '';
        $vectorStoreIdRaw = $value['Vector-Store-ID'] ?? $value['VectorStore-ID'] ?? $value['vectorStoreId'] ?? $value['vector_store_id'] ?? '';

        $label = trim((string) $labelRaw);
        $prompt = trim((string) $promptRaw);
        $promptId = trim((string) $promptIdRaw);
        $vectorStoreId = is_array($vectorStoreIdRaw)
            ? implode(',', array_map('strval', $vectorStoreIdRaw))
            : trim((string) $vectorStoreIdRaw);

        if ($label === '' && $prompt === '') {
            return array();
        }

        return array(
            array(
                'label' => $label !== '' ? $label : 'Ohne Bezeichnung',
                'prompt' => $prompt,
                'promptId' => $promptId,
                'vectorStoreId' => $vectorStoreId,
            ),
        );
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
                'vectorStoreId' => $parts[3] ?? '',
            );
        }

        return $entries;
    }

    private function isListArray(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $_) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }
        return true;
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
