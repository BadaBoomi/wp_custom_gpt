<?php

namespace WpCustomGpt;

use WpCustomGpt\Api\ChatsController;
use WpCustomGpt\Api\RoomsController;
use WpCustomGpt\Api\SettingsController;
use WpCustomGpt\Database\MigrationRunner;
use WpCustomGpt\Repositories\ChatRepository;
use WpCustomGpt\Repositories\RoomRepository;
use WpCustomGpt\Services\SettingsService;

class Plugin
{
    private const SCRIPT_HANDLE = 'wpcgpt-frontend';
    private const SETTINGS_SCRIPT_HANDLE = 'wpcgpt-settings-frontend';

    public static function activate(): void
    {
        MigrationRunner::migrate();
    }

    public function init(): void
    {
        add_action('rest_api_init', array($this, 'registerRestRoutes'));
        add_action('wp_enqueue_scripts', array($this, 'registerAssets'));
        add_shortcode('wp_custom_gpt', array($this, 'renderShortcode'));
        add_shortcode('wp_custom_gpt_settings', array($this, 'renderSettingsShortcode'));
    }

    public function registerRestRoutes(): void
    {
        $settingsService = new SettingsService();
        $roomRepository = new RoomRepository();
        $chatRepository = new ChatRepository();

        $settingsController = new SettingsController($settingsService);
        $roomsController = new RoomsController($roomRepository);
        $chatsController = new ChatsController($chatRepository);

        $settingsController->registerRoutes();
        $roomsController->registerRoutes();
        $chatsController->registerRoutes();

        register_rest_route('wp-custom-gpt/v1', '/health', array(
            'methods' => 'GET',
            'callback' => function () {
                return rest_ensure_response(array(
                    'ok' => true,
                    'version' => WPCGPT_PLUGIN_VERSION,
                ));
            },
            'permission_callback' => '__return_true',
        ));
    }

    public function registerAssets(): void
    {
        wp_register_script(
            self::SCRIPT_HANDLE,
            WPCGPT_PLUGIN_URL . 'assets/js/app.js',
            array(),
            WPCGPT_PLUGIN_VERSION,
            true
        );

        wp_register_script(
            self::SETTINGS_SCRIPT_HANDLE,
            WPCGPT_PLUGIN_URL . 'assets/js/settings.js',
            array(),
            WPCGPT_PLUGIN_VERSION,
            true
        );

        wp_localize_script(self::SCRIPT_HANDLE, 'WPCGPT_CONFIG', array(
            'restBase' => esc_url_raw(rest_url('wp-custom-gpt/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        ));

        wp_localize_script(self::SETTINGS_SCRIPT_HANDLE, 'WPCGPT_SETTINGS_CONFIG', array(
            'restBase' => esc_url_raw(rest_url('wp-custom-gpt/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        ));
    }

    public function renderShortcode(): string
    {
        if (!is_user_logged_in()) {
            return '<p>Please log in to use WP Custom GPT.</p>';
        }

        wp_enqueue_script(self::SCRIPT_HANDLE);

        $html = '';
        $html .= '<div id="wpcgpt-app" class="wpcgpt-app">';
        $html .= '  <div class="wpcgpt-header">';
        $html .= '    <h2>WP Custom GPT</h2>';
        $html .= '    <button type="button" id="wpcgpt-refresh">Refresh Rooms</button>';
        $html .= '  </div>';
        $html .= '  <div class="wpcgpt-create">';
        $html .= '    <input id="wpcgpt-room-name" type="text" maxlength="120" placeholder="New room name" />';
        $html .= '    <button type="button" id="wpcgpt-create-room">Create Room</button>';
        $html .= '  </div>';
        $html .= '  <ul id="wpcgpt-room-list"></ul>';
        $html .= '  <h3>Chats</h3>';
        $html .= '  <div class="wpcgpt-create">';
        $html .= '    <input id="wpcgpt-chat-title" type="text" maxlength="120" placeholder="New chat title" />';
        $html .= '    <button type="button" id="wpcgpt-create-chat">Create Chat</button>';
        $html .= '  </div>';
        $html .= '  <ul id="wpcgpt-chat-list"></ul>';
        $html .= '  <p id="wpcgpt-status" aria-live="polite"></p>';
        $html .= '</div>';

        return $html;
    }

    public function renderSettingsShortcode(): string
    {
        if (!current_user_can('manage_options')) {
            return '<p>You do not have permission to manage WP Custom GPT settings.</p>';
        }

        wp_enqueue_script(self::SETTINGS_SCRIPT_HANDLE);

        $html = '';
        $html .= '<div id="wpcgpt-settings-app" class="wpcgpt-settings-app">';
        $html .= '  <h2>WP Custom GPT Settings</h2>';
        $html .= '  <form id="wpcgpt-settings-form">';
        $html .= '    <p><label for="wpcgpt-api-key">API Key (leave empty to keep current)</label><br />';
        $html .= '    <input id="wpcgpt-api-key" type="password" autocomplete="off" style="width:100%;max-width:640px;" /></p>';
        $html .= '    <p id="wpcgpt-api-key-current"></p>';
        $html .= '    <p><label for="wpcgpt-prompt-id">Prompt ID</label><br />';
        $html .= '    <input id="wpcgpt-prompt-id" type="text" maxlength="191" style="width:100%;max-width:640px;" /></p>';
        $html .= '    <p><label for="wpcgpt-vector-store-ids">Vector Store IDs (comma separated)</label><br />';
        $html .= '    <input id="wpcgpt-vector-store-ids" type="text" style="width:100%;max-width:640px;" /></p>';
        $html .= '    <p><label for="wpcgpt-user-email">User Email</label><br />';
        $html .= '    <input id="wpcgpt-user-email" type="email" style="width:100%;max-width:640px;" /></p>';
        $html .= '    <p><button type="submit">Save Settings</button></p>';
        $html .= '  </form>';
        $html .= '  <p id="wpcgpt-settings-status" aria-live="polite"></p>';
        $html .= '</div>';

        return $html;
    }
}
