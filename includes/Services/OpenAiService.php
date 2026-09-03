<?php

namespace WpCustomGpt\Services;

use WP_Error;

class OpenAiService
{
    private const API_BASE = 'https://api.openai.com/v1';

    private SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    public function createAssistantReply(array $messages, ?string $promptIdOverride = null): array|WP_Error
    {
        $runtime = $this->settingsService->getRuntimeSettings();
        $apiKey = (string) ($runtime['api_key'] ?? '');

        if ($apiKey === '') {
            return new WP_Error('missing_api_key', 'OpenAI API-Key ist nicht konfiguriert.', array('status' => 400));
        }

        $payload = array(
            'input' => $this->mapMessagesToInput($messages),
        );

        $promptId = $promptIdOverride !== null && trim($promptIdOverride) !== ''
            ? trim($promptIdOverride)
            : (string) ($runtime['prompt_id'] ?? '');
        if ($promptId !== '') {
            $payload['prompt'] = array('id' => $promptId);
        } else {
            // Fallback model when no prompt is configured.
            $payload['model'] = 'gpt-4.1-mini';
        }

        $vectorStoreIds = $this->parseVectorStoreIds((string) ($runtime['vector_store_ids'] ?? ''));
        if (!empty($vectorStoreIds)) {
            $payload['tools'] = array(
                array(
                    'type' => 'file_search',
                    'vector_store_ids' => $vectorStoreIds,
                ),
            );
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        );

        $userEmail = (string) ($runtime['user_email'] ?? '');
        if ($userEmail !== '') {
            $headers['user-id'] = $userEmail;
        }

        $response = wp_remote_post(self::API_BASE . '/responses', array(
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => 45,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('openai_request_failed', $response->get_error_message(), array('status' => 502));
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($statusCode >= 400) {
            $message = 'OpenAI-Anfrage fehlgeschlagen.';
            if (is_array($body) && isset($body['error']['message'])) {
                $message = (string) $body['error']['message'];
            }

            return new WP_Error('openai_http_error', $message, array('status' => $statusCode));
        }

        $assistantText = $this->extractAssistantText($body);
        if ($assistantText === '') {
            return new WP_Error('openai_empty_response', 'OpenAI hat keinen Assistenten-Text zurueckgegeben.', array('status' => 502));
        }

        return array(
            'assistant_text' => $assistantText,
            'raw' => $body,
        );
    }

    public function readConfiguration(): array|WP_Error
    {
        $runtime = $this->settingsService->getRuntimeSettings();
        $promptId = trim((string) ($runtime['prompt_id'] ?? ''));

        if ($promptId === '') {
            return new WP_Error('missing_prompt_id', 'Prompt-ID ist zum Neuladen der Konfiguration erforderlich.', array('status' => 400));
        }

        $userEmail = trim((string) ($runtime['user_email'] ?? ''));
        $content = '[user-id: ' . $userEmail . '] GET_CONFIGURATION';

        $response = $this->requestResponsesApi(array(
            'prompt' => array('id' => $promptId),
            'input' => array(
                array(
                    'role' => 'user',
                    'content' => $content,
                ),
            ),
            'tools' => $this->buildTools((string) ($runtime['vector_store_ids'] ?? '')),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $assistantText = $this->extractAssistantText($response);
        if ($assistantText === '') {
            return new WP_Error('openai_empty_response', 'OpenAI hat keinen Assistenten-Text zurueckgegeben.', array('status' => 502));
        }

        return array(
            'assistant_text' => $assistantText,
            'raw' => $response,
        );
    }

    private function mapMessagesToInput(array $messages): array
    {
        $input = array();

        foreach ($messages as $message) {
            $role = isset($message['role']) ? (string) $message['role'] : 'user';
            $content = isset($message['content']) ? trim((string) $message['content']) : '';

            if ($content === '') {
                continue;
            }

            $input[] = array(
                'role' => $role,
                'content' => $content,
            );
        }

        return $input;
    }

    private function buildTools(string $vectorStoreIdsValue): array
    {
        $vectorStoreIds = $this->parseVectorStoreIds($vectorStoreIdsValue);
        if (empty($vectorStoreIds)) {
            return array();
        }

        return array(
            array(
                'type' => 'file_search',
                'vector_store_ids' => $vectorStoreIds,
            ),
        );
    }

    private function requestResponsesApi(array $payload): array|WP_Error
    {
        $runtime = $this->settingsService->getRuntimeSettings();
        $apiKey = (string) ($runtime['api_key'] ?? '');

        if ($apiKey === '') {
            return new WP_Error('missing_api_key', 'OpenAI API-Key ist nicht konfiguriert.', array('status' => 400));
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        );

        $userEmail = (string) ($runtime['user_email'] ?? '');
        if ($userEmail !== '') {
            $headers['user-id'] = $userEmail;
        }

        $response = wp_remote_post(self::API_BASE . '/responses', array(
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => 45,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('openai_request_failed', $response->get_error_message(), array('status' => 502));
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($statusCode >= 400) {
            $message = 'OpenAI-Anfrage fehlgeschlagen.';
            if (is_array($body) && isset($body['error']['message'])) {
                $message = (string) $body['error']['message'];
            }

            return new WP_Error('openai_http_error', $message, array('status' => $statusCode));
        }

        return is_array($body) ? $body : array();
    }

    private function parseVectorStoreIds(string $value): array
    {
        if ($value === '') {
            return array();
        }

        $parts = array_map('trim', explode(',', $value));
        $parts = array_filter($parts, function ($entry) {
            return $entry !== '';
        });

        return array_values($parts);
    }

    private function extractAssistantText($body): string
    {
        if (!is_array($body)) {
            return '';
        }

        if (isset($body['output_text']) && is_string($body['output_text']) && trim($body['output_text']) !== '') {
            return trim($body['output_text']);
        }

        if (!isset($body['output']) || !is_array($body['output'])) {
            return '';
        }

        $chunks = array();

        foreach ($body['output'] as $outputItem) {
            if (!is_array($outputItem) || !isset($outputItem['content']) || !is_array($outputItem['content'])) {
                continue;
            }

            foreach ($outputItem['content'] as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }

                if (isset($contentItem['text']) && is_string($contentItem['text']) && trim($contentItem['text']) !== '') {
                    $chunks[] = trim($contentItem['text']);
                }
            }
        }

        return trim(implode("\n\n", $chunks));
    }
}
