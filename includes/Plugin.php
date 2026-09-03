<?php

namespace WpCustomGpt;

use WpCustomGpt\Api\ChatsController;
use WpCustomGpt\Api\FlowCodeController;
use WpCustomGpt\Api\RoomsController;
use WpCustomGpt\Api\SettingsController;
use WpCustomGpt\Database\MigrationRunner;
use WpCustomGpt\Repositories\ChatRepository;
use WpCustomGpt\Repositories\FlowCodeRepository;
use WpCustomGpt\Repositories\FlowFileRepository;
use WpCustomGpt\Repositories\FlowSessionRepository;
use WpCustomGpt\Repositories\RoomRepository;
use WpCustomGpt\Services\FlowFileService;
use WpCustomGpt\Services\FlowRuntimeService;
use WpCustomGpt\Services\OpenAiService;
use WpCustomGpt\Services\SettingsService;

class Plugin
{
    private const ROOMS_SCRIPT_HANDLE = 'wpcgpt-rooms-frontend';
    private const CHATS_SCRIPT_HANDLE = 'wpcgpt-chats-frontend';
    private const CHAT_SCRIPT_HANDLE = 'wpcgpt-chat-frontend';
    private const SETTINGS_SCRIPT_HANDLE = 'wpcgpt-settings-frontend';
    private const FLOWS_ADMIN_SCRIPT_HANDLE = 'wpcgpt-flows-admin';

    public static function activate(): void
    {
        MigrationRunner::migrate();
    }

    public function init(): void
    {
        MigrationRunner::maybeMigrate();

        add_action('rest_api_init', array($this, 'registerRestRoutes'));
        add_action('wp_enqueue_scripts', array($this, 'registerAssets'));
        add_action('admin_menu', array($this, 'registerAdminPages'));
        add_action('admin_enqueue_scripts', array($this, 'registerAdminAssets'));
        add_shortcode('wp_custom_gpt', array($this, 'renderRoomsShortcode'));
        add_shortcode('wp_custom_gpt_rooms', array($this, 'renderRoomsShortcode'));
        add_shortcode('wp_custom_gpt_chats', array($this, 'renderChatsShortcode'));
        add_shortcode('wp_custom_gpt_chat', array($this, 'renderChatShortcode'));
        add_shortcode('wp_custom_gpt_settings', array($this, 'renderSettingsShortcode'));
    }

    public function registerRestRoutes(): void
    {
        $settingsService = new SettingsService();
        $openAiService = new OpenAiService($settingsService);
        $roomRepository = new RoomRepository();
        $chatRepository = new ChatRepository();
        $flowCodeRepository = new FlowCodeRepository();
        $flowFileRepository = new FlowFileRepository();
        $flowSessionRepository = new FlowSessionRepository();
        $flowFileService = new FlowFileService($flowFileRepository);
        $flowRuntimeService = new FlowRuntimeService($flowCodeRepository, $flowFileService);

        $settingsController = new SettingsController($settingsService, $openAiService);
        $roomsController = new RoomsController($roomRepository);
        $chatsController = new ChatsController($chatRepository, $roomRepository, $openAiService, $flowSessionRepository, $flowRuntimeService);
        $flowCodeController = new FlowCodeController($flowRuntimeService, $flowFileService);

        $settingsController->registerRoutes();
        $roomsController->registerRoutes();
        $chatsController->registerRoutes();
        $flowCodeController->registerRoutes();

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
            self::ROOMS_SCRIPT_HANDLE,
            WPCGPT_PLUGIN_URL . 'assets/js/rooms.js',
            array(),
            WPCGPT_PLUGIN_VERSION,
            true
        );

        wp_register_script(
            self::CHATS_SCRIPT_HANDLE,
            WPCGPT_PLUGIN_URL . 'assets/js/chats.js',
            array(),
            WPCGPT_PLUGIN_VERSION,
            true
        );

        wp_register_script(
            self::CHAT_SCRIPT_HANDLE,
            WPCGPT_PLUGIN_URL . 'assets/js/chat.js',
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

        wp_localize_script(self::ROOMS_SCRIPT_HANDLE, 'WPCGPT_ROOMS_CONFIG', array(
            'restBase' => esc_url_raw(rest_url('wp-custom-gpt/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        ));

        wp_localize_script(self::CHATS_SCRIPT_HANDLE, 'WPCGPT_CHATS_CONFIG', array(
            'restBase' => esc_url_raw(rest_url('wp-custom-gpt/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        ));

        wp_localize_script(self::CHAT_SCRIPT_HANDLE, 'WPCGPT_CHAT_CONFIG', array(
            'restBase' => esc_url_raw(rest_url('wp-custom-gpt/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        ));

        wp_localize_script(self::SETTINGS_SCRIPT_HANDLE, 'WPCGPT_SETTINGS_CONFIG', array(
            'restBase' => esc_url_raw(rest_url('wp-custom-gpt/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        ));
    }

    public function registerAdminPages(): void
    {
        add_menu_page(
            'WP Custom GPT Flows',
            'Custom GPT Flows',
            'manage_options',
            'wpcgpt-flows',
            array($this, 'renderFlowsAdminPage'),
            'dashicons-editor-code',
            65
        );
    }

    public function registerAdminAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'toplevel_page_wpcgpt-flows') {
            return;
        }

        wp_register_script(
            self::FLOWS_ADMIN_SCRIPT_HANDLE,
            WPCGPT_PLUGIN_URL . 'assets/js/flow-admin.js',
            array(),
            WPCGPT_PLUGIN_VERSION,
            true
        );

        wp_localize_script(self::FLOWS_ADMIN_SCRIPT_HANDLE, 'WPCGPT_FLOW_ADMIN_CONFIG', array(
            'restBase' => esc_url_raw(rest_url('wp-custom-gpt/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'defaultFlowCode' => $this->getDefaultFlowTemplate(),
        ));

        wp_enqueue_script(self::FLOWS_ADMIN_SCRIPT_HANDLE);
    }

    public function renderFlowsAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            echo '<div class="wrap"><p>Sie haben keine Berechtigung, Flows zu verwalten.</p></div>';
            return;
        }

        echo '<div class="wrap">';
        echo '  <h1>WP Custom GPT Flow-Verwaltung</h1>';
        echo '  <p>Verwalten Sie serverseitige Flow-Handler nach Flow-Typ. Der Code wird in der WordPress-Datenbank gespeichert und fuer laufende Flow-Sitzungen ausgefuehrt.</p>';
        echo '  <div id="wpcgpt-flow-admin-app" style="max-width:1100px;">';
        echo '    <p><label for="wpcgpt-flow-type"><strong>Flow-Typ</strong></label><br />';
        echo '    <input id="wpcgpt-flow-type" type="text" placeholder="collect_contact" style="width:100%;max-width:360px;" /></p>';
        echo '    <p><button type="button" id="wpcgpt-flow-load" class="button">Laden</button> <button type="button" id="wpcgpt-flow-list" class="button">Auflisten</button> <button type="button" id="wpcgpt-flow-template" class="button">Vorlage einfuegen</button></p>';
        echo '    <p><label for="wpcgpt-flow-code"><strong>Flow-PHP-Code (Funktionsinhalt, ohne &lt;?php-Tag)</strong></label><br />';
        echo '    <textarea id="wpcgpt-flow-code" rows="20" style="width:100%;font-family:Consolas,Monaco,monospace;"></textarea></p>';
        echo '    <p><button type="button" id="wpcgpt-flow-validate" class="button button-secondary">Validieren</button> <button type="button" id="wpcgpt-flow-save" class="button button-primary">Aktive Version speichern</button> <button type="button" id="wpcgpt-flow-deactivate" class="button">Deaktivieren</button></p>';
        echo '    <hr />';
        echo '    <h2>Flow-Dateien</h2>';
        echo '    <p>Dateien fuer diesen Flow-Typ hochladen (xlsx, xls, csv, ods, tsv, txt, json; max. 10 MB).</p>';
        echo '    <p><input id="wpcgpt-flow-file-input" type="file" /> <button type="button" id="wpcgpt-flow-file-upload" class="button button-secondary">Datei hochladen</button> <button type="button" id="wpcgpt-flow-file-refresh" class="button">Dateiliste aktualisieren</button></p>';
        echo '    <p><label for="wpcgpt-flow-file-delete-id">Zu loeschende Datei-ID</label> <input id="wpcgpt-flow-file-delete-id" type="number" min="1" style="width:100px;" /> <button type="button" id="wpcgpt-flow-file-delete" class="button">Datei loeschen</button></p>';
        echo '    <pre id="wpcgpt-flow-files-output" style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:240px;overflow:auto;"></pre>';
        echo '    <pre id="wpcgpt-flow-list-output" style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:240px;overflow:auto;"></pre>';
        echo '    <p id="wpcgpt-flow-status" aria-live="polite"></p>';
        echo '  </div>';
        echo '</div>';
    }

    private function getDefaultFlowTemplate(): string
    {
        return implode("\n", array(
            '$mode = isset($context[\'mode\']) ? (string) $context[\'mode\'] : \'turn\';',
            '$state = isset($context[\'session\'][\'state\']) && is_array($context[\'session\'][\'state\']) ? $context[\'session\'][\'state\'] : array();',
            '',
            'if ($mode === \'initial\') {',
            '    return array(',
            '        \'initial_prompt\' => \'Willkommen. Wie kann ich helfen?\',',
            '        \'status\' => \'running\',',
            '        \'state\' => $state,',
            '    );',
            '}',
            '',
            '$userInput = isset($context[\'user_input\']) ? trim((string) $context[\'user_input\']) : \'\';',
            '',
            'return array(',
            '    \'assistant_reply\' => \'Du hast gesagt: \'. $userInput,',
            '    \'status\' => \'completed\',',
            '    \'state\' => $state,',
            ');',
        ));
    }

    public function renderRoomsShortcode($atts = array()): string
    {
        if (!is_user_logged_in()) {
            return '<p>Bitte melden Sie sich an, um WP Custom GPT zu nutzen.</p>';
        }

        $atts = shortcode_atts(array(
            'chats_page' => '',
        ), $atts, 'wp_custom_gpt_rooms');

        $chatsPage = $this->resolvePageUrl((string) $atts['chats_page']);

        wp_enqueue_script(self::ROOMS_SCRIPT_HANDLE);

        $html = '';
        $html .= '<div id="wpcgpt-rooms-app" class="wpcgpt-app" data-chats-page="' . esc_attr($chatsPage) . '">';
        $html .= '  <div class="wpcgpt-header">';
        $html .= '    <h2>Raumverwaltung</h2>';
        $html .= '    <button type="button" id="wpcgpt-refresh">Raeume aktualisieren</button>';
        $html .= '  </div>';
        $html .= '  <div class="wpcgpt-create">';
        $html .= '    <input id="wpcgpt-room-name" type="text" maxlength="120" placeholder="Neuer Raumname" />';
        $html .= '    <button type="button" id="wpcgpt-create-room">Raum erstellen</button>';
        $html .= '  </div>';
        $html .= '  <ul id="wpcgpt-room-list"></ul>';
        $html .= '  <p id="wpcgpt-room-hint">Mit dem Shortcode-Attribut chats_page legen Sie fest, wohin Nutzer beim Betreten eines Raums weitergeleitet werden.</p>';
        $html .= '  <p id="wpcgpt-status" aria-live="polite"></p>';
        $html .= '</div>';

        return $html;
    }

    public function renderChatsShortcode($atts = array()): string
    {
        if (!is_user_logged_in()) {
            return '<p>Bitte melden Sie sich an, um WP Custom GPT zu nutzen.</p>';
        }

        $roomId = isset($_GET['room_id']) ? absint($_GET['room_id']) : 0;
        if ($roomId <= 0) {
            return '<p>room_id fehlt in der URL. Oeffnen Sie diese Seite aus der Raumverwaltung.</p>';
        }

        $atts = shortcode_atts(array(
            'rooms_page' => '',
            'chat_page' => '',
        ), $atts, 'wp_custom_gpt_chats');

        $roomsPage = $this->resolvePageUrl((string) $atts['rooms_page']);
        $chatPage = $this->resolvePageUrl((string) $atts['chat_page']);

        wp_enqueue_script(self::CHATS_SCRIPT_HANDLE);

        $html = '';
        $html .= '<div id="wpcgpt-chats-app" class="wpcgpt-app" data-room-id="' . esc_attr((string) $roomId) . '" data-chat-page="' . esc_attr($chatPage) . '" data-rooms-page="' . esc_attr($roomsPage) . '">';
        $html .= '  <div class="wpcgpt-header">';
        $html .= '    <h2>Chatverwaltung</h2>';
        $html .= '    <button type="button" id="wpcgpt-refresh-chats">Chats aktualisieren</button>';
        $html .= '  </div>';
        $html .= '  <p id="wpcgpt-room-label"></p>';
        $html .= '  <div class="wpcgpt-create">';
        $html .= '    <input id="wpcgpt-chat-title" type="text" maxlength="120" placeholder="Neuer Chat-Titel" />';
        $html .= '    <button type="button" id="wpcgpt-create-chat">Chat erstellen</button>';
        $html .= '  </div>';
        $html .= '  <ul id="wpcgpt-chat-list"></ul>';
        $html .= '  <p><a id="wpcgpt-back-rooms" href="#">Zurueck zu den Raeumen</a></p>';
        $html .= '  <p id="wpcgpt-status" aria-live="polite"></p>';
        $html .= '</div>';

        return $html;
    }

    public function renderChatShortcode($atts = array()): string
    {
        if (!is_user_logged_in()) {
            return '<p>Bitte melden Sie sich an, um WP Custom GPT zu nutzen.</p>';
        }

        $chatId = isset($_GET['chat_id']) ? absint($_GET['chat_id']) : 0;
        $roomId = isset($_GET['room_id']) ? absint($_GET['room_id']) : 0;
        if ($chatId <= 0) {
            return '<p>chat_id fehlt in der URL. Oeffnen Sie diese Seite aus der Chatverwaltung.</p>';
        }

        $atts = shortcode_atts(array(
            'chats_page' => '',
        ), $atts, 'wp_custom_gpt_chat');

        $chatsPage = $this->resolvePageUrl((string) $atts['chats_page']);
        $settingsService = new SettingsService();
        $configurationEntries = $settingsService->getConfigurationEntries();
        $configurationEntriesJson = wp_json_encode($configurationEntries);
        if ($configurationEntriesJson === false) {
            $configurationEntriesJson = '[]';
        }

        wp_enqueue_script(self::CHAT_SCRIPT_HANDLE);

        $html = '';
        $html .= '<div id="wpcgpt-chat-app" class="wpcgpt-app" data-chat-id="' . esc_attr((string) $chatId) . '" data-room-id="' . esc_attr((string) $roomId) . '" data-chats-page="' . esc_attr($chatsPage) . '" data-configuration-entries="' . esc_attr($configurationEntriesJson) . '">';
        $html .= '  <div class="wpcgpt-header">';
        $html .= '    <h2>Chat</h2>';
        $html .= '    <button type="button" id="wpcgpt-refresh-messages">Nachrichten aktualisieren</button>';
        $html .= '  </div>';
        $html .= '  <p id="wpcgpt-room-label"></p>';
        $html .= '  <div id="wpcgpt-message-output" style="width:100%;max-width:760px;height:360px;overflow-y:auto;border:1px solid #d0d7de;border-radius:8px;padding:12px;background:#ffffff;"></div>';
        $html .= '  <div class="wpcgpt-create">';
        $html .= '    <div id="wpcgpt-action-buttons" style="display:flex;flex-wrap:wrap;gap:8px;width:100%;max-width:760px;"></div>';
        $html .= '  </div>';
        $html .= '  <div class="wpcgpt-create">';
        $html .= '    <textarea id="wpcgpt-message-input" rows="4" placeholder="Nachricht eingeben" style="width:100%;max-width:720px;"></textarea>';
        $html .= '  </div>';
        $html .= '  <div class="wpcgpt-create">';
        $html .= '    <button type="button" id="wpcgpt-send-message">Senden</button>';
        $html .= '  </div>';
        $html .= '  <p><a id="wpcgpt-back-chats" href="#">Zurueck zu den Chats</a></p>';
        $html .= '  <p id="wpcgpt-status" aria-live="polite"></p>';
        $html .= '</div>';

        return $html;
    }

    public function renderSettingsShortcode(): string
    {
        if (!current_user_can('manage_options')) {
            return '<p>Sie haben keine Berechtigung, die WP Custom GPT Einstellungen zu verwalten.</p>';
        }

        wp_enqueue_script(self::SETTINGS_SCRIPT_HANDLE);

        $html = '';
        $html .= '<div id="wpcgpt-settings-app" class="wpcgpt-settings-app">';
        $html .= '  <h2>WP Custom GPT Einstellungen</h2>';
        $html .= '  <form id="wpcgpt-settings-form">';
        $html .= '    <p><label for="wpcgpt-api-key">API-Key (leer lassen, um den aktuellen zu behalten)</label><br />';
        $html .= '    <input id="wpcgpt-api-key" type="password" autocomplete="off" style="width:100%;max-width:640px;" /></p>';
        $html .= '    <p id="wpcgpt-api-key-current"></p>';
        $html .= '    <p><label for="wpcgpt-prompt-id">Prompt-ID</label><br />';
        $html .= '    <input id="wpcgpt-prompt-id" type="text" maxlength="191" style="width:100%;max-width:640px;" /></p>';
        $html .= '    <p><label for="wpcgpt-vector-store-ids">Vector-Store-IDs (durch Komma getrennt)</label><br />';
        $html .= '    <input id="wpcgpt-vector-store-ids" type="text" style="width:100%;max-width:640px;" /></p>';
        $html .= '    <p><label for="wpcgpt-user-email">Benutzer-E-Mail</label><br />';
        $html .= '    <input id="wpcgpt-user-email" type="email" style="width:100%;max-width:640px;" /></p>';
        $html .= '    <p><label for="wpcgpt-starters">Starter (Markdown-Tabelle)</label><br />';
        $html .= '    <textarea id="wpcgpt-starters" rows="8" style="width:100%;max-width:760px;"></textarea></p>';
        $html .= '    <p><button type="submit">Einstellungen speichern</button></p>';
        $html .= '    <p><button type="button" id="wpcgpt-reload-configuration">Konfiguration neu laden (GET_CONFIGURATION)</button></p>';
        $html .= '  </form>';
        $html .= '  <p id="wpcgpt-settings-status" aria-live="polite"></p>';
        $html .= '</div>';

        return $html;
    }

    private function resolvePageUrl(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return '';
        }

        if (ctype_digit($target)) {
            $url = get_permalink((int) $target);
            return $url ? esc_url_raw($url) : '';
        }

        if (filter_var($target, FILTER_VALIDATE_URL)) {
            return esc_url_raw($target);
        }

        $path = trim($target, "/ \t\n\r\0\x0B");

        if ($path !== '') {
            $page = get_page_by_path($path, OBJECT, 'page');
            if ($page && isset($page->ID)) {
                $url = get_permalink((int) $page->ID);
                if ($url) {
                    return esc_url_raw($url);
                }
            }
        }

        if ($target[0] !== '/') {
            $target = '/' . $target;
        }

        return esc_url_raw(home_url($target));
    }
}
