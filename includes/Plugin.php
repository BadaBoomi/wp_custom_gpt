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
    private const CHAT_STYLE_HANDLE = 'wpcgpt-chat-frontend';
    private const COMMON_STYLE_HANDLE = 'wpcgpt-common-frontend';

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

        $settingsController = new SettingsController($settingsService);
        $roomsController = new RoomsController($roomRepository);
        $chatsController = new ChatsController($chatRepository, $roomRepository, $openAiService, $flowSessionRepository, $flowRuntimeService, $settingsService);
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
        $chatScriptPath = WPCGPT_PLUGIN_DIR . 'assets/js/chat.js';
        $chatScriptUrl  = WPCGPT_PLUGIN_URL . 'assets/js/chat.js';

        $chatScriptVersion = file_exists($chatScriptPath)
        ? (string) filemtime($chatScriptPath)
        : WPCGPT_PLUGIN_VERSION;

    wp_register_script(
        self::ROOMS_SCRIPT_HANDLE,
        WPCGPT_PLUGIN_URL . 'assets/js/rooms.js',
        array(),
        WPCGPT_PLUGIN_VERSION,
        array(
            'in_footer' => true,
            'strategy'   => 'defer',
        )
    );

    wp_register_script(
        self::CHATS_SCRIPT_HANDLE,
        WPCGPT_PLUGIN_URL . 'assets/js/chats.js',
        array(),
        WPCGPT_PLUGIN_VERSION,
        array(
            'in_footer' => true,
            'strategy'   => 'defer',
        )
    );

    wp_register_script(
        self::CHAT_SCRIPT_HANDLE,
        $chatScriptUrl,
        array(),
        $chatScriptVersion,
        array(
            'in_footer' => true,
            'strategy'   => 'defer',
        )
    );

    wp_register_style(
        self::CHAT_STYLE_HANDLE,
        WPCGPT_PLUGIN_URL . 'assets/css/chat.css',
        array(),
        WPCGPT_PLUGIN_VERSION
    );

    wp_register_style(
        self::COMMON_STYLE_HANDLE,
        WPCGPT_PLUGIN_URL . 'assets/css/chat.css',
        array(),
        WPCGPT_PLUGIN_VERSION
    );

    wp_register_script(
        self::SETTINGS_SCRIPT_HANDLE,
        WPCGPT_PLUGIN_URL . 'assets/js/settings.js',
        array(),
        WPCGPT_PLUGIN_VERSION,
        array(
            'in_footer' => true,
            'strategy'   => 'defer',
        )
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
        echo '    <p><label><input id="wpcgpt-openai-debug-enabled" type="checkbox" /> OpenAI-Debug-Protokoll aktivieren (Request/Response im Server-Log)</label> <button type="button" id="wpcgpt-openai-debug-save" class="button">Debug-Einstellung speichern</button></p>';
        echo '    <p><button type="button" id="wpcgpt-openai-debug-load" class="button">OpenAI-Debug-Log laden</button></p>';
        echo '    <pre id="wpcgpt-openai-debug-output" style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:260px;overflow:auto;"></pre>';
        echo '    <p><button type="button" id="wpcgpt-table-integrity-verify" class="button">Tabellen-Integritaet nur pruefen</button> <button type="button" id="wpcgpt-table-integrity-check" class="button">Tabellen-Integritaet pruefen und reparieren</button></p>';
        echo '    <pre id="wpcgpt-table-integrity-output" style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:200px;overflow:auto;"></pre>';
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
        $frontendUiConfig = $this->getFrontendUiConfig();
        $themeStyle = $this->buildThemeStyleAttribute($frontendUiConfig['theme']);

        wp_enqueue_script(self::ROOMS_SCRIPT_HANDLE);
        wp_enqueue_style(self::COMMON_STYLE_HANDLE);

        $html = '';
        $html .= '<div id="wpcgpt-rooms-app" class="wpcgpt-app" style="' . esc_attr($themeStyle) . '" data-chats-page="' . esc_attr($chatsPage) . '">';
        $html .= '  <div class="wpcgpt-header">';
        $html .= '    <h2>Raumverwaltung</h2>';
        $html .= '  </div>';
        $html .= '  <div class="wpcgpt-create">';
        $html .= '    <input id="wpcgpt-room-name" type="text" maxlength="120" placeholder="Neuer Raumname" />';
        $html .= '    <button type="button" id="wpcgpt-create-room">Raum erstellen</button>';
        $html .= '  </div>';
        $html .= '  <ul id="wpcgpt-room-list"></ul>';
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
        $configurationEntriesJson = $this->encodeConfigurationEntries();
        $frontendUiConfig = $this->getFrontendUiConfig();
        $themeStyle = $this->buildThemeStyleAttribute($frontendUiConfig['theme']);

        wp_enqueue_script(self::CHATS_SCRIPT_HANDLE);
        wp_enqueue_style(self::COMMON_STYLE_HANDLE);

        $html = '';
        $html .= '<div id="wpcgpt-chats-app" class="wpcgpt-app" style="' . esc_attr($themeStyle) . '" data-room-id="' . esc_attr((string) $roomId) . '" data-chat-page="' . esc_attr($chatPage) . '" data-rooms-page="' . esc_attr($roomsPage) . '" data-configuration-entries="' . esc_attr($configurationEntriesJson) . '">';
        $html .= '  <div class="wpcgpt-header">';
        $html .= '    <h2>Chatverwaltung</h2>';
        $html .= '    <div class="wpcgpt-header-actions">';
        $html .= '      <button type="button" id="wpcgpt-back-rooms">Zurueck zu den Raeumen</button>';
        $html .= '    </div>';
        $html .= '  </div>';
        $html .= '  <p id="wpcgpt-room-label"></p>';
        $html .= '  <div class="wpcgpt-create">';
        $html .= '    <input id="wpcgpt-chat-title" type="text" maxlength="120" placeholder="Neuer Chat-Titel" />';
        $html .= '    <label for="wpcgpt-chat-configuration" class="screen-reader-text">Zweck</label>';
        $html .= '    <select id="wpcgpt-chat-configuration" required></select>';
        $html .= '    <button type="button" id="wpcgpt-create-chat">Chat erstellen</button>';
        $html .= '  </div>';
        $html .= '  <ul id="wpcgpt-chat-list"></ul>';
        $html .= '  <p id="wpcgpt-status" aria-live="polite"></p>';
        $html .= '</div>';

        return $html;
    }

    public function renderChatShortcode($atts = array()): string
    {
        $atts = shortcode_atts(array(
            'chats_page' => '',
            'config_id' => '0',
        ), $atts, 'wp_custom_gpt_chat');

        $settingsService = new SettingsService();
        $transientModeEnabled = $settingsService->isTransientModeEnabled();
        $transientConfigId = absint((string) ($atts['config_id'] ?? '0'));
        $useTransientMode = $transientModeEnabled && $transientConfigId > 0;

        if ($transientConfigId > 0 && !$transientModeEnabled) {
            return '<p>Transient Mode ist deaktiviert. Aktivieren Sie ihn in den Einstellungen oder oeffnen Sie den Chat ueber die Chatverwaltung.</p>';
        }

        if (!$useTransientMode && !is_user_logged_in()) {
            return '<p>Bitte melden Sie sich an, um WP Custom GPT zu nutzen.</p>';
        }

        $chatId = $useTransientMode ? 0 : (isset($_GET['chat_id']) ? absint($_GET['chat_id']) : 0);
        $roomId = $useTransientMode ? 0 : (isset($_GET['room_id']) ? absint($_GET['room_id']) : 0);
        if (!$useTransientMode && $chatId <= 0) {
            return '<p>chat_id fehlt in der URL. Oeffnen Sie diese Seite aus der Chatverwaltung.</p>';
        }

        $chatsPage = $this->resolvePageUrl((string) $atts['chats_page']);
        $frontendUiConfig = $this->getFrontendUiConfig();
        $themeStyle = $this->buildThemeStyleAttribute($frontendUiConfig['theme']);
        $assistantIconUrl = (string) $frontendUiConfig['assistant_icon_url'];
        $transientPrompt = '';

        if ($useTransientMode) {
            $entry = $settingsService->findConfigurationEntryById($transientConfigId);
            if (!$entry) {
                return '<p>Konfigurationseintrag fuer Transient Mode nicht gefunden.</p>';
            }

            if (!empty($entry['iconUrl'])) {
                $assistantIconUrl = esc_url_raw((string) $entry['iconUrl']);
            }

            $transientPrompt = (string) ($entry['prompt'] ?? '');
        }

        if (!$useTransientMode) {
            $chatRepository = new ChatRepository();
            $chat = $chatRepository->getChatForUser($chatId, (int) get_current_user_id());
            if (is_array($chat) && !empty($chat['config_icon_url'])) {
                $assistantIconUrl = esc_url_raw((string) $chat['config_icon_url']);
            }
        }

        wp_enqueue_script(self::CHAT_SCRIPT_HANDLE);
        wp_enqueue_style(self::CHAT_STYLE_HANDLE);

        $html = '';
        $html .= '<div id="wpcgpt-chat-app" class="wpcgpt-app" style="' . esc_attr($themeStyle) . '" data-chat-id="' . esc_attr((string) $chatId) . '" data-room-id="' . esc_attr((string) $roomId) . '" data-chats-page="' . esc_attr($chatsPage) . '" data-assistant-icon-url="' . esc_attr($assistantIconUrl) . '" data-transient-mode="' . ($useTransientMode ? '1' : '0') . '" data-transient-config-id="' . esc_attr((string) $transientConfigId) . '" data-transient-prompt="' . esc_attr($transientPrompt) . '">';
        $html .= '  <div class="wpcgpt-header">';
        $html .= '    <h2>' . ($useTransientMode ? 'Chat (Transient Mode)' : 'Chat') . '</h2>';
        $html .= '    <div class="wpcgpt-header-actions">';
        $html .= '      <button type="button" id="wpcgpt-back-chats">Zurueck zu den Chats</button>';
        $html .= '    </div>';
        $html .= '  </div>';
        $html .= '  <p id="wpcgpt-room-label"' . ($useTransientMode ? ' style="display:none;"' : '') . '></p>';
        $html .= '  <div id="wpcgpt-message-output" class="wpcgpt-chat-window"></div>';
        $html .= '  <div class="wpcgpt-create">';
        $html .= '    <div id="wpcgpt-action-buttons" style="display:flex;flex-wrap:wrap;gap:8px;width:100%;max-width:760px;"></div>';
        $html .= '  </div>';
        $html .= '  <div class="wpcgpt-create">';
        $html .= '    <textarea id="wpcgpt-message-input" rows="4" placeholder="Nachricht eingeben" style="width:100%;max-width:720px;"></textarea>';
        $html .= '  </div>';
        $html .= '  <div class="wpcgpt-create">';
        $html .= '    <button type="button" id="wpcgpt-send-message">Senden</button>';
        $html .= '  </div>';
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
        wp_enqueue_style(self::COMMON_STYLE_HANDLE);
        wp_enqueue_media();

        $settingsService = new SettingsService();
        $fontFamilies = $settingsService->getFontFamilies();

        $fontOptionsHtml = '';
        foreach ($fontFamilies as $fontValue => $fontLabel) {
            $fontOptionsHtml .= '<option value="' . esc_attr((string) $fontValue) . '">' . esc_html((string) $fontLabel) . '</option>';
        }

        $html = '';
        $html .= '<div id="wpcgpt-settings-app" class="wpcgpt-settings-app">';
        $html .= '  <h2>WP Custom GPT Einstellungen</h2>';
        $html .= '  <div class="wpcgpt-settings-tabs" role="tablist" aria-label="Einstellungsbereiche">';
        $html .= '    <button type="button" id="wpcgpt-tab-general" class="wpcgpt-tab-button is-active" data-tab="general" role="tab" aria-selected="true">Allgemein</button>';
        $html .= '    <button type="button" id="wpcgpt-tab-appearance" class="wpcgpt-tab-button" data-tab="appearance" role="tab" aria-selected="false">Farben und Schriften</button>';
        $html .= '  </div>';
        $html .= '  <form id="wpcgpt-settings-form">';
        $html .= '    <section id="wpcgpt-settings-tab-general" class="wpcgpt-settings-tab-panel is-active" data-tab-panel="general" role="tabpanel" aria-labelledby="wpcgpt-tab-general">';
        $html .= '      <p><label for="wpcgpt-api-key">API-Key (leer lassen, um den aktuellen zu behalten)</label><br />';
        $html .= '      <input id="wpcgpt-api-key" type="password" autocomplete="off" style="width:100%;max-width:640px;" /></p>';
        $html .= '      <p id="wpcgpt-api-key-current"></p>';
        $html .= '      <p><label><input id="wpcgpt-transient-mode-enabled" type="checkbox" /> Transient Mode aktivieren</label><br />';
        $html .= '      <span class="description">Wenn aktiv, kann der Chat-Shortcode mit <code>config_id</code> einen nicht-persistenten, nicht benutzer- und nicht raumgebundenen Dialog starten.</span></p>';
        $html .= '      <h3>Konfigurationseintraege</h3>';
        $html .= '      <p>Eintraege fuer die Auswahl beim Erstellen eines neuen Chats. Das Feld <strong>Prompt</strong> definiert die erste Assistenten-Ausgabe (Begruessung) beim Erstellen eines neuen Chats dieses Typs; die Prompt-ID ist optional und wird fuer alle Anfragen des jeweiligen Chats verwendet.</p>';
        $html .= '      <table id="wpcgpt-configuration-table" style="width:100%;max-width:1200px;border-collapse:collapse;">';
        $html .= '        <thead><tr>';
        $html .= '          <th style="text-align:left;">ID</th>';
        $html .= '          <th style="text-align:left;">Zweck</th>';
        $html .= '          <th style="text-align:left;">Prompt</th>';
        $html .= '          <th style="text-align:left;">Max per room</th>';
        $html .= '          <th style="text-align:left;">Prompt-ID</th>';
        $html .= '          <th style="text-align:left;">Icon</th>';
        $html .= '          <th></th>';
        $html .= '        </tr></thead>';
        $html .= '        <tbody id="wpcgpt-configuration-rows"></tbody>';
        $html .= '      </table>';
        $html .= '      <p><button type="button" id="wpcgpt-configuration-add">Eintrag hinzufuegen</button></p>';
        $html .= '    </section>';
        $html .= '    <section id="wpcgpt-settings-tab-appearance" class="wpcgpt-settings-tab-panel" data-tab-panel="appearance" role="tabpanel" aria-labelledby="wpcgpt-tab-appearance" hidden>';
        $html .= '      <h3>Darstellung fuer Chat, Chatverwaltung und Raumverwaltung</h3>';
        $html .= '      <div class="wpcgpt-icon-picker" id="wpcgpt-default-assistant-icon-picker">';
        $html .= '        <label><strong>Standard-Icon Agent</strong></label>';
        $html .= '        <input id="wpcgpt-assistant-icon-id" type="hidden" value="0" />';
        $html .= '        <div id="wpcgpt-assistant-icon-preview" class="wpcgpt-icon-preview" aria-live="polite"></div>';
        $html .= '        <p><button type="button" id="wpcgpt-assistant-icon-upload">Icon hochladen/waehlen</button> <button type="button" id="wpcgpt-assistant-icon-remove">Icon entfernen</button></p>';
        $html .= '        <p class="description">Dieses Icon wird fuer alle Chats genutzt, sofern beim ausgewaehlten Konfigurationseintrag kein eigenes Icon hinterlegt ist.</p>';
        $html .= '      </div>';
        $html .= '      <div class="wpcgpt-theme-grid">';
        $html .= '        <p><label for="wpcgpt-theme-font-family">Schriftfamilie</label><br /><select id="wpcgpt-theme-font-family" style="width:100%;">' . $fontOptionsHtml . '</select></p>';
        $html .= '        <p><label for="wpcgpt-theme-font-size">Basisschriftgroesse</label><br /><input id="wpcgpt-theme-font-size" type="text" placeholder="15px" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-text">Textfarbe</label><br /><input id="wpcgpt-theme-color-text" type="color" value="#1f2933" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-muted">Neben-/Hinweistext</label><br /><input id="wpcgpt-theme-color-muted" type="color" value="#5f6b76" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-background">Seitenhintergrund</label><br /><input id="wpcgpt-theme-color-background" type="color" value="#f3f6fb" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-surface">Panel-Hintergrund</label><br /><input id="wpcgpt-theme-color-surface" type="color" value="#ffffff" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-border">Rahmenfarbe</label><br /><input id="wpcgpt-theme-color-border" type="color" value="#cfd8e3" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-accent">Akzentfarbe (Buttons/Links)</label><br /><input id="wpcgpt-theme-color-accent" type="color" value="#0a58ca" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-accent-text">Akzent-Textfarbe</label><br /><input id="wpcgpt-theme-color-accent-text" type="color" value="#ffffff" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-success">Statusfarbe Erfolg</label><br /><input id="wpcgpt-theme-color-success" type="color" value="#2d6a4f" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-error">Statusfarbe Fehler</label><br /><input id="wpcgpt-theme-color-error" type="color" value="#b00020" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-chat-window">Chatfenster-Hintergrund</label><br /><input id="wpcgpt-theme-color-chat-window" type="color" value="#4c5f7d" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-user-bubble">Bubble Benutzer</label><br /><input id="wpcgpt-theme-color-user-bubble" type="color" value="#e7f0fb" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-assistant-bubble">Bubble Agent</label><br /><input id="wpcgpt-theme-color-assistant-bubble" type="color" value="#cbe3f8" style="width:100%;" /></p>';
        $html .= '        <p><label for="wpcgpt-theme-color-assistant-avatar-bg">Avatar-Hintergrund Agent</label><br /><input id="wpcgpt-theme-color-assistant-avatar-bg" type="color" value="#f3c64b" style="width:100%;" /></p>';
        $html .= '      </div>';
        $html .= '    </section>';
        $html .= '    <p><button type="submit">Einstellungen speichern</button></p>';
        $html .= '  </form>';
        $html .= '  <p id="wpcgpt-settings-status" aria-live="polite"></p>';
        $html .= '</div>';

        return $html;
    }

    private function encodeConfigurationEntries(): string
    {
        $settingsService = new SettingsService();
        $encoded = wp_json_encode($settingsService->getConfigurationEntries());

        return is_string($encoded) ? $encoded : '[]';
    }

    private function getFrontendUiConfig(): array
    {
        $settingsService = new SettingsService();
        $runtime = $settingsService->getRuntimeSettings();

        return array(
            'assistant_icon_url' => esc_url_raw((string) ($runtime['assistant_icon_url'] ?? '')),
            'theme' => $settingsService->getUiThemeSettings(),
        );
    }

    /**
     * @param array<string, string> $theme
     */
    private function buildThemeStyleAttribute(array $theme): string
    {
        $map = array(
            'font_family' => '--wpcgpt-font-family',
            'font_size' => '--wpcgpt-font-size',
            'color_text' => '--wpcgpt-color-text',
            'color_muted' => '--wpcgpt-color-muted',
            'color_background' => '--wpcgpt-color-background',
            'color_surface' => '--wpcgpt-color-surface',
            'color_border' => '--wpcgpt-color-border',
            'color_accent' => '--wpcgpt-color-accent',
            'color_accent_text' => '--wpcgpt-color-accent-text',
            'color_success' => '--wpcgpt-color-success',
            'color_error' => '--wpcgpt-color-error',
            'color_chat_window' => '--wpcgpt-color-chat-window',
            'color_user_bubble' => '--wpcgpt-color-user-bubble',
            'color_assistant_bubble' => '--wpcgpt-color-assistant-bubble',
            'color_assistant_avatar_bg' => '--wpcgpt-color-assistant-avatar-bg',
        );

        $parts = array();
        foreach ($map as $key => $cssVar) {
            $value = isset($theme[$key]) ? trim((string) $theme[$key]) : '';
            if ($value === '') {
                continue;
            }

            $parts[] = $cssVar . ':' . $value;
        }

        return implode(';', $parts);
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
