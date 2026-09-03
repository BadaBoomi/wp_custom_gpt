<?php

namespace WpCustomGpt\Api;

use WP_Error;
use WP_REST_Request;
use WpCustomGpt\Services\SettingsService;

class SettingsController
{
    private const NAMESPACE = 'wp-custom-gpt/v1';

    private SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
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
    }

    public function getSettings(): array
    {
        return $this->settingsService->getSettingsForAdmin();
    }

    public function saveSettings(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('invalid_payload', 'Request body must be JSON.', array('status' => 400));
        }

        return $this->settingsService->saveSettings($payload);
    }

    public function canManageSettings(): bool
    {
        return current_user_can('manage_options');
    }
}
