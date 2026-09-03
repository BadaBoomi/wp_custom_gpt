<?php

namespace WpCustomGpt\Api;

use WP_Error;
use WP_REST_Request;
use WpCustomGpt\Repositories\ChatRepository;

class ChatsController
{
    private const NAMESPACE = 'wp-custom-gpt/v1';

    private ChatRepository $chatRepository;

    public function __construct(ChatRepository $chatRepository)
    {
        $this->chatRepository = $chatRepository;
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/rooms/(?P<roomId>\\d+)/chats', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'listChatsForRoom'),
                'permission_callback' => array($this, 'isLoggedIn'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'createChat'),
                'permission_callback' => array($this, 'isLoggedIn'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/chats/(?P<chatId>\\d+)/messages', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'listMessagesForChat'),
                'permission_callback' => array($this, 'isLoggedIn'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'addMessage'),
                'permission_callback' => array($this, 'isLoggedIn'),
            ),
        ));
    }

    public function listChatsForRoom(WP_REST_Request $request): array
    {
        $roomId = (int) $request->get_param('roomId');
        return $this->chatRepository->listChatsForRoom($roomId, (int) get_current_user_id());
    }

    public function createChat(WP_REST_Request $request)
    {
        $roomId = (int) $request->get_param('roomId');
        $payload = $request->get_json_params();
        $title = is_array($payload) ? (string) ($payload['title'] ?? 'New Chat') : 'New Chat';

        if (trim($title) === '') {
            $title = 'New Chat';
        }

        $chat = $this->chatRepository->createChat($roomId, (int) get_current_user_id(), $title);

        if (!$chat) {
            return new WP_Error('room_not_found', 'Room not found.', array('status' => 404));
        }

        return rest_ensure_response($chat);
    }

    public function listMessagesForChat(WP_REST_Request $request): array
    {
        $chatId = (int) $request->get_param('chatId');
        return $this->chatRepository->listMessagesForChat($chatId, (int) get_current_user_id());
    }

    public function addMessage(WP_REST_Request $request)
    {
        $chatId = (int) $request->get_param('chatId');
        $payload = $request->get_json_params();

        $role = is_array($payload) ? (string) ($payload['role'] ?? 'user') : 'user';
        $content = is_array($payload) ? (string) ($payload['content'] ?? '') : '';

        if (trim($content) === '') {
            return new WP_Error('invalid_content', 'Message content is required.', array('status' => 400));
        }

        if (!in_array($role, array('user', 'assistant', 'system'), true)) {
            return new WP_Error('invalid_role', 'Role must be user, assistant or system.', array('status' => 400));
        }

        $message = $this->chatRepository->addMessage($chatId, (int) get_current_user_id(), $role, $content);

        if (!$message) {
            return new WP_Error('chat_not_found', 'Chat not found.', array('status' => 404));
        }

        return rest_ensure_response($message);
    }

    public function isLoggedIn(): bool
    {
        return is_user_logged_in();
    }
}
