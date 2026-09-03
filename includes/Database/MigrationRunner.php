<?php

namespace WpCustomGpt\Database;

class MigrationRunner
{
    private const SCHEMA_VERSION = '1';

    public static function migrate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();

        $roomsTable = $wpdb->prefix . 'wpcgpt_rooms';
        $chatsTable = $wpdb->prefix . 'wpcgpt_chats';
        $messagesTable = $wpdb->prefix . 'wpcgpt_messages';
        $flowSessionsTable = $wpdb->prefix . 'wpcgpt_flow_sessions';

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

        dbDelta($sqlRooms);
        dbDelta($sqlChats);
        dbDelta($sqlMessages);
        dbDelta($sqlFlowSessions);

        update_option('wpcgpt_schema_version', self::SCHEMA_VERSION, false);
    }
}
