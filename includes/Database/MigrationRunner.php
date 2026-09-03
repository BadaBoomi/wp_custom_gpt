<?php

namespace WpCustomGpt\Database;

class MigrationRunner
{
    private const SCHEMA_VERSION = '3';

    public static function maybeMigrate(): void
    {
        global $wpdb;

        $storedVersion = (string) get_option('wpcgpt_schema_version', '0');
        if ((int) $storedVersion >= (int) self::SCHEMA_VERSION) {
            $requiredTables = array(
                $wpdb->prefix . 'wpcgpt_rooms',
                $wpdb->prefix . 'wpcgpt_chats',
                $wpdb->prefix . 'wpcgpt_messages',
                $wpdb->prefix . 'wpcgpt_flow_sessions',
                $wpdb->prefix . 'wpcgpt_flow_code',
                $wpdb->prefix . 'wpcgpt_flow_files',
            );

            foreach ($requiredTables as $tableName) {
                $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName));
                if ((string) $existing !== $tableName) {
                    self::migrate();
                    return;
                }
            }

            return;
        }

        self::migrate();
    }

    public static function migrate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();

        $roomsTable = $wpdb->prefix . 'wpcgpt_rooms';
        $chatsTable = $wpdb->prefix . 'wpcgpt_chats';
        $messagesTable = $wpdb->prefix . 'wpcgpt_messages';
        $flowSessionsTable = $wpdb->prefix . 'wpcgpt_flow_sessions';
        $flowCodeTable = $wpdb->prefix . 'wpcgpt_flow_code';
        $flowFilesTable = $wpdb->prefix . 'wpcgpt_flow_files';

        $sqlRooms = "CREATE TABLE {$roomsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            custom_attributes LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_created_at (created_at)
        ) {$charsetCollate};";

        $sqlChats = "CREATE TABLE {$chatsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            room_id BIGINT UNSIGNED NOT NULL,
            conversation_id VARCHAR(191) NULL,
            title VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_room_id (room_id),
            KEY idx_created_at (created_at)
        ) {$charsetCollate};";

        $sqlMessages = "CREATE TABLE {$messagesTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            chat_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL,
            content LONGTEXT NOT NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_chat_id (chat_id),
            KEY idx_created_at (created_at)
        ) {$charsetCollate};";

        $sqlFlowSessions = "CREATE TABLE {$flowSessionsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            chat_id BIGINT UNSIGNED NOT NULL,
            flow_type VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL,
            state_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_chat_id (chat_id),
            KEY idx_user_id (user_id),
            KEY idx_status (status),
            KEY idx_updated_at (updated_at)
        ) {$charsetCollate};";

        $sqlFlowCode = "CREATE TABLE {$flowCodeTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            flow_type VARCHAR(100) NOT NULL,
            code_php LONGTEXT NOT NULL,
            version INT UNSIGNED NOT NULL,
            checksum CHAR(64) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_flow_type (flow_type),
            KEY idx_is_active (is_active),
            KEY idx_updated_at (updated_at)
        ) {$charsetCollate};";

        $sqlFlowFiles = "CREATE TABLE {$flowFilesTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            flow_type VARCHAR(100) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            relative_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL,
            uploaded_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_flow_type (flow_type),
            KEY idx_uploaded_by (uploaded_by),
            KEY idx_created_at (created_at)
        ) {$charsetCollate};";

        dbDelta($sqlRooms);
        dbDelta($sqlChats);
        dbDelta($sqlMessages);
        dbDelta($sqlFlowSessions);
        dbDelta($sqlFlowCode);
        dbDelta($sqlFlowFiles);

        update_option('wpcgpt_schema_version', self::SCHEMA_VERSION, false);
    }
}
