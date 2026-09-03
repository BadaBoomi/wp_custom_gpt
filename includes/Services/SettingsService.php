<?php

namespace WpCustomGpt\Services;

class SettingsService
{
    private const OPTION_API_KEY = 'wpcgpt_api_key';
    private const OPTION_PROMPT_ID = 'wpcgpt_prompt_id';
    private const OPTION_VECTOR_STORE_IDS = 'wpcgpt_vector_store_ids';
    private const OPTION_USER_EMAIL = 'wpcgpt_user_email';

    public function getSettingsForAdmin(): array
    {
        $apiKey = (string) get_option(self::OPTION_API_KEY, '');

        return array(
            'prompt_id' => (string) get_option(self::OPTION_PROMPT_ID, ''),
            'vector_store_ids' => (string) get_option(self::OPTION_VECTOR_STORE_IDS, ''),
            'user_email' => (string) get_option(self::OPTION_USER_EMAIL, ''),
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

        return $this->getSettingsForAdmin();
    }

    public function getRuntimeSettings(): array
    {
        return array(
            'api_key' => (string) get_option(self::OPTION_API_KEY, ''),
            'prompt_id' => (string) get_option(self::OPTION_PROMPT_ID, ''),
            'vector_store_ids' => (string) get_option(self::OPTION_VECTOR_STORE_IDS, ''),
            'user_email' => (string) get_option(self::OPTION_USER_EMAIL, ''),
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
}
