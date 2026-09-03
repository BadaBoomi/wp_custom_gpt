<?php

namespace WpCustomGpt\Api;

use WP_Error;
use WP_REST_Request;
use WpCustomGpt\Services\OpenAiService;
use WpCustomGpt\Services\SettingsService;

class SettingsController
{
    private const NAMESPACE = 'wp-custom-gpt/v1';

    private SettingsService $settingsService;
    private OpenAiService $openAiService;

    public function __construct(SettingsService $settingsService, OpenAiService $openAiService)
    {
        $this->settingsService = $settingsService;
        $this->openAiService = $openAiService;
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

        register_rest_route(self::NAMESPACE, '/settings/reload-configuration', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'reloadConfiguration'),
                'permission_callback' => array($this, 'canManageSettings'),
            ),
        ));
    }

    public function getSettings(): array
    {
        return $this->settingsService->getSettingsForAdmin();
    }

    public function saveSettings(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('invalid_payload', 'Request-Body muss JSON sein.', array('status' => 400));
        }

        return $this->settingsService->saveSettings($payload);
    }

    public function reloadConfiguration()
    {
        $result = $this->openAiService->readConfiguration();
        if (is_wp_error($result)) {
            return $result;
        }

        $assistantText = (string) ($result['assistant_text'] ?? '');
        $rows = $this->settingsService->parseConfigurationPrompts($assistantText);
        if (empty($rows)) {
            return new WP_Error('configuration_parse_failed', 'Keine Konfigurationszeilen in der Assistenten-Antwort gefunden.', array('status' => 422));
        }

        $this->settingsService->saveConfigurationRows($rows);

        return $this->settingsService->getSettingsForAdmin();
    }

    public function canManageSettings(): bool
    {
        return current_user_can('manage_options');
    }
}
