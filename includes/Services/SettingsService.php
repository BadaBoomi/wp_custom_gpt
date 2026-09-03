<?php

namespace WpCustomGpt\Services;

class SettingsService
{
    private const OPTION_API_KEY = 'wpcgpt_api_key';
    private const OPTION_PROMPT_ID = 'wpcgpt_prompt_id';
    private const OPTION_VECTOR_STORE_IDS = 'wpcgpt_vector_store_ids';
    private const OPTION_USER_EMAIL = 'wpcgpt_user_email';
    private const OPTION_STARTERS = 'wpcgpt_starters';

    public function getSettingsForAdmin(): array
    {
        $apiKey = (string) get_option(self::OPTION_API_KEY, '');

        return array(
            'prompt_id' => (string) get_option(self::OPTION_PROMPT_ID, ''),
            'vector_store_ids' => (string) get_option(self::OPTION_VECTOR_STORE_IDS, ''),
            'user_email' => (string) get_option(self::OPTION_USER_EMAIL, ''),
            'starters' => (string) get_option(self::OPTION_STARTERS, ''),
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
        );
    }

    public function getConfigurationEntries(): array
    {
        $starters = (string) get_option(self::OPTION_STARTERS, '');
        return $this->parseStarterPromptsMarkdown($starters);
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

    private function normalizeConfigRowsFromUnknown($value): array
    {
        if (!is_array($value)) {
            return array();
        }

        $labelRaw = $value['Zweck'] ?? $value['Desc'] ?? $value['Description'] ?? $value['label'] ?? $value['name'] ?? $value['title'] ?? '';
        $promptRaw = $value['Prompt'] ?? $value['prompt'] ?? $value['text'] ?? $value['value'] ?? '';
        $promptIdRaw = $value['Pmpt-ID'] ?? $value['Prompt-ID'] ?? $value['promptId'] ?? $value['pmptId'] ?? '';

        $label = trim((string) $labelRaw);
        $prompt = trim((string) $promptRaw);
        $promptId = trim((string) $promptIdRaw);

        if ($label === '' && $prompt === '') {
            return array();
        }

        return array(
            array(
                'label' => $label !== '' ? $label : 'Ohne Bezeichnung',
                'prompt' => $prompt,
                'promptId' => $promptId,
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

            $parts = array_values(array_filter(array_map('trim', explode('|', $trimmed)), function ($value) {
                return $value !== '';
            }));

            if (count($parts) < 2) {
                continue;
            }

            $entries[] = array(
                'label' => $parts[0],
                'prompt' => $parts[1],
                'promptId' => $parts[2] ?? '',
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
}
