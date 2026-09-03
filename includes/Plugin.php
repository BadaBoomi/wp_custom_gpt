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

    public static function activate(): void
    {
        MigrationRunner::migrate();
    }

    public function init(): void
    {
        add_action('rest_api_init', array($this, 'registerRestRoutes'));
        add_action('wp_enqueue_scripts', array($this, 'registerAssets'));
        add_shortcode('wp_custom_gpt', array($this, 'renderShortcode'));
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

        wp_localize_script(self::SCRIPT_HANDLE, 'WPCGPT_CONFIG', array(
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
}
