<?php

namespace WpCustomGpt\Api;

use WP_Error;
use WP_REST_Request;
use WpCustomGpt\Repositories\ChatRepository;
use WpCustomGpt\Services\OpenAiService;

class ChatsController
{
    private const NAMESPACE = 'wp-custom-gpt/v1';

    private ChatRepository $chatRepository;
    private OpenAiService $openAiService;

    public function __construct(ChatRepository $chatRepository, OpenAiService $openAiService)
    {
        $this->chatRepository = $chatRepository;
        $this->openAiService = $openAiService;
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

        register_rest_route(self::NAMESPACE, '/chats/(?P<chatId>\\d+)/send', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'sendMessageToOpenAi'),
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

    public function sendMessageToOpenAi(WP_REST_Request $request)
    {
        $chatId = (int) $request->get_param('chatId');
        $userId = (int) get_current_user_id();
        $payload = $request->get_json_params();
        $message = is_array($payload) ? (string) ($payload['message'] ?? '') : '';

        if (trim($message) === '') {
            return new WP_Error('invalid_message', 'Message is required.', array('status' => 400));
        }

        $chat = $this->chatRepository->getChatForUser($chatId, $userId);
        if (!$chat) {
            return new WP_Error('chat_not_found', 'Chat not found.', array('status' => 404));
        }

        $savedUserMessage = $this->chatRepository->addMessage($chatId, $userId, 'user', $message);
        if (!$savedUserMessage) {
            return new WP_Error('save_failed', 'Could not save user message.', array('status' => 500));
        }

        $history = $this->chatRepository->listMessagesForChat($chatId, $userId);
        $openAiResult = $this->openAiService->createAssistantReply($history);

        if (is_wp_error($openAiResult)) {
            return $openAiResult;
        }

        $assistantText = (string) ($openAiResult['assistant_text'] ?? '');
        $savedAssistantMessage = $this->chatRepository->addMessage($chatId, $userId, 'assistant', $assistantText);

        if (!$savedAssistantMessage) {
            return new WP_Error('save_failed', 'Could not save assistant message.', array('status' => 500));
        }

        $raw = $openAiResult['raw'] ?? null;
        if (is_array($raw) && isset($raw['conversation']) && is_string($raw['conversation']) && $raw['conversation'] !== '') {
            $this->chatRepository->updateConversationId($chatId, $userId, $raw['conversation']);
        }

        return rest_ensure_response(array(
            'user_message' => $savedUserMessage,
            'assistant_message' => $savedAssistantMessage,
        ));
    }

    public function isLoggedIn(): bool
    {
        return is_user_logged_in();
    }
}
