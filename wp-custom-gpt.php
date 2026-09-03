<?php
/**
 * Plugin Name: WP Custom GPT
 * Description: Brings core features from pwa_custom_gpt into WordPress.
 * Version: 0.5.3
 * Author: Heiko
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WPCGPT_PLUGIN_VERSION')) {
    define('WPCGPT_PLUGIN_VERSION', '0.5.3');
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
