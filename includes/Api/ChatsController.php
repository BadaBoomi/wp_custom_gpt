<?php

namespace WpCustomGpt\Api;

use WP_Error;
use WP_REST_Request;
use WpCustomGpt\Repositories\ChatRepository;
use WpCustomGpt\Repositories\FlowSessionRepository;
use WpCustomGpt\Repositories\RoomRepository;
use WpCustomGpt\Services\FlowRuntimeService;
use WpCustomGpt\Services\OpenAiService;

class ChatsController
{
    private const NAMESPACE = 'wp-custom-gpt/v1';
    private const DEFAULT_MESSAGES_LIMIT = 150;
    private const MAX_MESSAGES_LIMIT = 500;
    private const OPENAI_HISTORY_LIMIT = 30;

    private ChatRepository $chatRepository;
    private RoomRepository $roomRepository;
    private OpenAiService $openAiService;
    private FlowSessionRepository $flowSessionRepository;
    private FlowRuntimeService $flowRuntimeService;

    public function __construct(
        ChatRepository $chatRepository,
        RoomRepository $roomRepository,
        OpenAiService $openAiService,
        FlowSessionRepository $flowSessionRepository,
        FlowRuntimeService $flowRuntimeService
    )
    {
        $this->chatRepository = $chatRepository;
        $this->roomRepository = $roomRepository;
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
        $title = is_array($payload) ? (string) ($payload['title'] ?? 'Neuer Chat') : 'Neuer Chat';

        if (trim($title) === '') {
            $title = 'Neuer Chat';
        }

        $chat = $this->chatRepository->createChat($roomId, (int) get_current_user_id(), $title);

        if (!$chat) {
            return new WP_Error('room_not_found', 'Raum nicht gefunden.', array('status' => 404));
        }

        return rest_ensure_response($chat);
    }

    public function listMessagesForChat(WP_REST_Request $request): array
    {
        $chatId = (int) $request->get_param('chatId');
        $requestedLimit = (int) $request->get_param('limit');
        if ($requestedLimit <= 0) {
            $requestedLimit = self::DEFAULT_MESSAGES_LIMIT;
        }

        $limit = min(self::MAX_MESSAGES_LIMIT, $requestedLimit);
        return $this->chatRepository->listRecentMessagesForChat($chatId, (int) get_current_user_id(), $limit);
    }

    public function addMessage(WP_REST_Request $request)
    {
        $chatId = (int) $request->get_param('chatId');
        $payload = $request->get_json_params();

        $role = is_array($payload) ? (string) ($payload['role'] ?? 'user') : 'user';
        $content = is_array($payload) ? (string) ($payload['content'] ?? '') : '';

        if (trim($content) === '') {
            return new WP_Error('invalid_content', 'Nachrichteninhalt ist erforderlich.', array('status' => 400));
        }

        if (!in_array($role, array('user', 'assistant', 'system'), true)) {
            return new WP_Error('invalid_role', 'Rolle muss user, assistant oder system sein.', array('status' => 400));
        }

        $message = $this->chatRepository->addMessage($chatId, (int) get_current_user_id(), $role, $content);

        if (!$message) {
            return new WP_Error('chat_not_found', 'Chat nicht gefunden.', array('status' => 404));
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
            return new WP_Error('invalid_message', 'Nachricht ist erforderlich.', array('status' => 400));
        }

        $chat = $this->chatRepository->getChatForUser($chatId, $userId);
        if (!$chat) {
            return new WP_Error('chat_not_found', 'Chat nicht gefunden.', array('status' => 404));
        }

        $savedUserMessage = $this->chatRepository->addMessage($chatId, $userId, 'user', $message, true);
        if (!$savedUserMessage) {
            return new WP_Error('save_failed', 'Benutzernachricht konnte nicht gespeichert werden.', array('status' => 500));
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
                return new WP_Error('flow_missing_reply', 'Flow-Durchlauf lieferte keine Assistenten-Antwort.', array('status' => 500));
            }

            $flowReply = $this->processRoomDirectivesForOutput($chat, $userId, $flowReply);

            $savedAssistantMessage = null;
            if (trim($flowReply) !== '') {
                $savedAssistantMessage = $this->chatRepository->addMessage($chatId, $userId, 'assistant', $flowReply, true);
                if (!$savedAssistantMessage) {
                    return new WP_Error('save_failed', 'Assistenten-Nachricht konnte nicht gespeichert werden.', array('status' => 500));
                }
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

        $openAiRequestContext = $this->buildOpenAiRequestContext($chat, $chatId, $userId);
        $history = $this->buildOpenAiHistory($chatId, $userId, $message, $openAiRequestContext);
        $openAiResult = $this->openAiService->createAssistantReply(
            $history,
            $promptIdOverride !== '' ? $promptIdOverride : null,
            $openAiRequestContext
        );

        if (is_wp_error($openAiResult)) {
            return $openAiResult;
        }

        $assistantText = (string) ($openAiResult['assistant_text'] ?? '');
        $directive = $this->parseRuleFlowDirective($assistantText);
        $visibleAssistantText = $directive ? $directive['cleaned_text'] : $assistantText;
        $visibleAssistantText = $this->processRoomDirectivesForOutput($chat, $userId, $visibleAssistantText);

        $savedAssistantMessage = null;
        if (trim($visibleAssistantText) !== '') {
            $savedAssistantMessage = $this->chatRepository->addMessage($chatId, $userId, 'assistant', $visibleAssistantText, true);
            if (!$savedAssistantMessage) {
                return new WP_Error('save_failed', 'Assistenten-Nachricht konnte nicht gespeichert werden.', array('status' => 500));
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
                    $initialPrompt = $this->processRoomDirectivesForOutput($chat, $userId, $initialPrompt);
                    if ($initialPrompt !== '') {
                        $savedFlowPrompt = $this->chatRepository->addMessage($chatId, $userId, 'assistant', $initialPrompt, true);
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

    private function processRoomDirectivesForOutput(array $chat, int $userId, string $text): string
    {
        $text = $this->normalizeEscapedLineBreaks($text);
        $roomId = isset($chat['room_id']) ? (int) $chat['room_id'] : 0;
        $parsed = $this->extractSetDirectives($text);
        $setAttributes = $parsed['attributes'];

        $effectiveAttributes = array();
        if ($roomId > 0) {
            $effectiveAttributes = $this->roomRepository->getCustomAttributesForRoom($roomId, $userId);
        }

        if (!empty($setAttributes) && $roomId > 0) {
            $updatedRoom = $this->roomRepository->upsertCustomAttributes($roomId, $userId, $setAttributes);
            if (is_array($updatedRoom)) {
                $effectiveAttributes = $this->roomRepository->getCustomAttributesForRoom($roomId, $userId);
            }
        }

        return $this->replaceGetDirectives((string) $parsed['cleaned_text'], $effectiveAttributes);
    }

    private function extractSetDirectives(string $text): array
    {
        $pattern = '/\[set\|([^|\]\r\n]+)\|([^\]\r\n]*)\]/i';
        $attributes = array();

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = isset($match[1]) ? trim((string) $match[1]) : '';
                $value = isset($match[2]) ? trim((string) $match[2]) : '';
                if ($key === '') {
                    continue;
                }

                $attributes[$key] = $value;
            }
        }

        $cleaned = preg_replace($pattern, '', $text);
        $cleanedText = is_string($cleaned) ? trim(preg_replace('/\n{3,}/', "\n\n", $cleaned)) : '';

        return array(
            'cleaned_text' => $cleanedText,
            'attributes' => $attributes,
        );
    }

    private function replaceGetDirectives(string $text, array $attributes): string
    {
        $lookup = array();
        foreach ($attributes as $key => $value) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '') {
                continue;
            }

            $lookup[$normalizedKey] = (string) $value;
        }

        $pattern = '/\[get\|([^|\]\r\n]+)\]/i';
        $replaced = preg_replace_callback($pattern, static function (array $match) use ($lookup): string {
            $rawKey = isset($match[1]) ? trim((string) $match[1]) : '';
            if ($rawKey === '') {
                return '';
            }

            $normalizedKey = strtolower($rawKey);
            if (!array_key_exists($normalizedKey, $lookup)) {
                return '';
            }

            return (string) $lookup[$normalizedKey];
        }, $text);

        if (!is_string($replaced)) {
            return '';
        }

        return trim(preg_replace('/\n{3,}/', "\n\n", $replaced));
    }

    private function normalizeEscapedLineBreaks(string $text): string
    {
        $normalized = str_replace(
            array('\\r\\n', '\\n', '\\r'),
            array("\n", "\n", "\n"),
            $text
        );

        return str_replace('W&amp;W', 'W&W', $normalized);
    }

    private function buildOpenAiRequestContext(array $chat, int $chatId, int $userId): array
    {
        $roomId = isset($chat['room_id']) ? (int) $chat['room_id'] : 0;
        $conversationId = isset($chat['conversation_id']) ? trim((string) $chat['conversation_id']) : '';
        $roomAttributes = array();

        if ($roomId > 0) {
            $roomAttributes = $this->roomRepository->getCustomAttributesForRoom($roomId, $userId);
        }

        return array(
            'chat_id' => $chatId,
            'room_id' => $roomId > 0 ? $roomId : null,
            'conversation_id' => $conversationId !== '' ? $conversationId : null,
            'room_attributes' => is_array($roomAttributes) ? $roomAttributes : array(),
        );
    }

    private function buildOpenAiHistory(int $chatId, int $userId, string $currentMessage, array $requestContext): array
    {
        $conversationId = isset($requestContext['conversation_id']) ? trim((string) $requestContext['conversation_id']) : '';
        if ($conversationId !== '') {
            return array(
                array(
                    'role' => 'user',
                    'content' => $currentMessage,
                ),
            );
        }

        return $this->chatRepository->listRecentMessagesForChat($chatId, $userId, self::OPENAI_HISTORY_LIMIT, true);
    }

    public function isLoggedIn(): bool
    {
        return is_user_logged_in();
    }
}
