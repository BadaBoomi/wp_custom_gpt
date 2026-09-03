<?php

namespace WpCustomGpt\Api;

use WP_Error;
use WP_REST_Request;
use WpCustomGpt\Repositories\ChatRepository;
use WpCustomGpt\Repositories\FlowSessionRepository;
use WpCustomGpt\Services\FlowRuntimeService;
use WpCustomGpt\Services\OpenAiService;

class ChatsController
{
    private const NAMESPACE = 'wp-custom-gpt/v1';

    private ChatRepository $chatRepository;
    private OpenAiService $openAiService;
    private FlowSessionRepository $flowSessionRepository;
    private FlowRuntimeService $flowRuntimeService;

    public function __construct(
        ChatRepository $chatRepository,
        OpenAiService $openAiService,
        FlowSessionRepository $flowSessionRepository,
        FlowRuntimeService $flowRuntimeService
    )
    {
        $this->chatRepository = $chatRepository;
        $this->openAiService = $openAiService;
        $this->flowSessionRepository = $flowSessionRepository;
        $this->flowRuntimeService = $flowRuntimeService;
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
        $promptIdOverride = is_array($payload) ? trim((string) ($payload['prompt_id'] ?? '')) : '';

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

        $activeSession = $this->flowSessionRepository->getActiveForChat($chatId, $userId);
        if ($activeSession) {
            $flowResult = $this->flowRuntimeService->runTurn(
                (string) $activeSession['flow_type'],
                $chatId,
                $userId,
                isset($activeSession['state']) && is_array($activeSession['state']) ? $activeSession['state'] : array(),
                $message
            );

            if (is_wp_error($flowResult)) {
                $this->flowSessionRepository->abort((int) $activeSession['id'], $userId, isset($activeSession['state']) && is_array($activeSession['state']) ? $activeSession['state'] : array());
                return $flowResult;
            }

            $flowReply = (string) ($flowResult['assistant_reply'] ?? '');
            if ($flowReply === '') {
                return new WP_Error('flow_missing_reply', 'Flow turn returned no assistant reply.', array('status' => 500));
            }

            $savedAssistantMessage = $this->chatRepository->addMessage($chatId, $userId, 'assistant', $flowReply);
            if (!$savedAssistantMessage) {
                return new WP_Error('save_failed', 'Could not save assistant message.', array('status' => 500));
            }

            $nextState = isset($flowResult['state']) && is_array($flowResult['state']) ? $flowResult['state'] : array();
            $status = (string) ($flowResult['status'] ?? 'running');
            if ($status === 'completed') {
                $this->flowSessionRepository->complete((int) $activeSession['id'], $userId, $nextState);
            } elseif ($status === 'aborted') {
                $this->flowSessionRepository->abort((int) $activeSession['id'], $userId, $nextState);
            } else {
                $this->flowSessionRepository->updateRunningState((int) $activeSession['id'], $userId, $nextState);
            }

            return rest_ensure_response(array(
                'user_message' => $savedUserMessage,
                'assistant_message' => $savedAssistantMessage,
                'flow' => array(
                    'flow_type' => (string) $activeSession['flow_type'],
                    'status' => $status,
                ),
            ));
        }

        $history = $this->chatRepository->listMessagesForChat($chatId, $userId);
        $openAiResult = $this->openAiService->createAssistantReply(
            $history,
            $promptIdOverride !== '' ? $promptIdOverride : null
        );

        if (is_wp_error($openAiResult)) {
            return $openAiResult;
        }

        $assistantText = (string) ($openAiResult['assistant_text'] ?? '');
        $directive = $this->parseRuleFlowDirective($assistantText);
        $visibleAssistantText = $directive ? $directive['cleaned_text'] : $assistantText;

        $savedAssistantMessage = null;
        if (trim($visibleAssistantText) !== '') {
            $savedAssistantMessage = $this->chatRepository->addMessage($chatId, $userId, 'assistant', $visibleAssistantText);
            if (!$savedAssistantMessage) {
                return new WP_Error('save_failed', 'Could not save assistant message.', array('status' => 500));
            }
        }

        $flowMeta = null;
        if ($directive) {
            $flowType = (string) $directive['flow_type'];
            $initialResult = $this->flowRuntimeService->runInitialPrompt($flowType, $chatId, $userId, array());
            if (!is_wp_error($initialResult)) {
                $createdSession = $this->flowSessionRepository->createOrReplace($chatId, $userId, $flowType, array());
                if ($createdSession) {
                    $initialPrompt = (string) ($initialResult['initial_prompt'] ?? '');
                    if ($initialPrompt !== '') {
                        $savedFlowPrompt = $this->chatRepository->addMessage($chatId, $userId, 'assistant', $initialPrompt);
                        if ($savedFlowPrompt) {
                            $savedAssistantMessage = $savedFlowPrompt;
                        }
                    }

                    $initState = isset($initialResult['state']) && is_array($initialResult['state']) ? $initialResult['state'] : array();
                    $initStatus = (string) ($initialResult['status'] ?? 'running');
                    if ($initStatus === 'completed') {
                        $this->flowSessionRepository->complete((int) $createdSession['id'], $userId, $initState);
                    } elseif ($initStatus === 'aborted') {
                        $this->flowSessionRepository->abort((int) $createdSession['id'], $userId, $initState);
                    } else {
                        $this->flowSessionRepository->updateRunningState((int) $createdSession['id'], $userId, $initState);
                    }

                    $flowMeta = array(
                        'flow_type' => $flowType,
                        'status' => $initStatus,
                    );
                }
            } else {
                $flowMeta = array(
                    'flow_type' => $flowType,
                    'status' => 'aborted',
                    'error' => $initialResult->get_error_message(),
                );
            }
        }

        $raw = $openAiResult['raw'] ?? null;
        if (is_array($raw) && isset($raw['conversation']) && is_string($raw['conversation']) && $raw['conversation'] !== '') {
            $this->chatRepository->updateConversationId($chatId, $userId, $raw['conversation']);
        }

        return rest_ensure_response(array(
            'user_message' => $savedUserMessage,
            'assistant_message' => $savedAssistantMessage,
            'flow' => $flowMeta,
        ));
    }

    private function parseRuleFlowDirective(string $text): ?array
    {
        $normalizedText = str_replace("\r\n", "\n", $text);
        $pattern = '/\[\[\s*start_rule_flow\s*:\s*([a-zA-Z0-9_\-]{1,100})\s*\]\]/i';

        if (!preg_match($pattern, $normalizedText, $matches)) {
            return null;
        }

        $flowType = strtolower(trim((string) ($matches[1] ?? '')));
        if ($flowType === '') {
            return null;
        }

        $cleaned = preg_replace($pattern, '', $normalizedText, 1);
        $cleanedText = is_string($cleaned) ? trim($cleaned) : '';

        return array(
            'flow_type' => $flowType,
            'cleaned_text' => $cleanedText,
        );
    }

    public function isLoggedIn(): bool
    {
        return is_user_logged_in();
    }
}
