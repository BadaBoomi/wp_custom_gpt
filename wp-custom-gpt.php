<?php
/**
 * Plugin Name: WP Custom GPT
 * Description: Brings core features from pwa_custom_gpt into WordPress.
 * Version: 0.9.5
 * Author: Heiko
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WPCGPT_PLUGIN_VERSION')) {
    define('WPCGPT_PLUGIN_VERSION', '0.9.5');
}

if (!defined('WPCGPT_PLUGIN_FILE')) {
    define('WPCGPT_PLUGIN_FILE', __FILE__);
}

if (!defined('WPCGPT_PLUGIN_DIR')) {
    define('WPCGPT_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('WPCGPT_PLUGIN_URL')) {
    define('WPCGPT_PLUGIN_URL', plugin_dir_url(__FILE__));
}
function wpcgpt_enqueue_frontend_assets() {
    // Optional: Nur auf der Seite laden, auf der der Chat verwendet wird.
    // Diese Bedingung musst du an deine Website anpassen.
    //
    // if (!is_page('chat')) {
    //     return;
    // }

    $script_path = WPCGPT_PLUGIN_DIR . 'assets/js/chat.js';
    $script_url  = WPCGPT_PLUGIN_URL . 'assets/js/chat.js';

    // Versionsnummer automatisch aus dem Änderungsdatum der Datei erzeugen.
    $script_version = file_exists($script_path)
        ? filemtime($script_path)
        : WPCGPT_PLUGIN_VERSION;

    wp_enqueue_script(
        'wpcgpt-chats',
        $script_url,
        array(),
        $script_version,
        array(
            'in_footer' => true,
            'strategy'   => 'defer',
        )
    );
}
add_action('wp_enqueue_scripts', 'wpcgpt_enqueue_frontend_assets');

require_once WPCGPT_PLUGIN_DIR . 'includes/Database/MigrationRunner.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Services/SettingsService.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Services/OpenAiService.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Services/FlowRuntimeService.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Services/FlowFileService.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Repositories/RoomRepository.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Repositories/ChatRepository.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Repositories/FlowCodeRepository.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Repositories/FlowFileRepository.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Repositories/FlowSessionRepository.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Api/SettingsController.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Api/RoomsController.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Api/ChatsController.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Api/FlowCodeController.php';
require_once WPCGPT_PLUGIN_DIR . 'includes/Plugin.php';

register_activation_hook(WPCGPT_PLUGIN_FILE, array('WpCustomGpt\\Plugin', 'activate'));

add_action('plugins_loaded', function () {
    $plugin = new WpCustomGpt\Plugin();
    $plugin->init();
});
