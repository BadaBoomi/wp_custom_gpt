<?php

namespace WpCustomGpt\Api;

use WP_Error;
use WP_REST_Request;
use WpCustomGpt\Repositories\ChatRepository;
use WpCustomGpt\Repositories\FlowSessionRepository;
use WpCustomGpt\Repositories\RoomRepository;
use WpCustomGpt\Services\FlowRuntimeService;
use WpCustomGpt\Services\OpenAiService;
use WpCustomGpt\Services\SettingsService;

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
    private SettingsService $settingsService;

    public function __construct(
        ChatRepository $chatRepository,
        RoomRepository $roomRepository,
        OpenAiService $openAiService,
        FlowSessionRepository $flowSessionRepository,
        FlowRuntimeService $flowRuntimeService,
        SettingsService $settingsService
    )
    {
        $this->chatRepository = $chatRepository;
        $this->roomRepository = $roomRepository;
        $this->openAiService = $openAiService;
        $this->flowSessionRepository = $flowSessionRepository;
        $this->flowRuntimeService = $flowRuntimeService;
        $this->settingsService = $settingsService;
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

        register_rest_route(self::NAMESPACE, '/chats/(?P<chatId>\\d+)', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'getChat'),
                'permission_callback' => array($this, 'isLoggedIn'),
            ),            array(
                'methods' => 'DELETE',
                'callback' => array($this, 'deleteChat'),
                'permission_callback' => array($this, 'isLoggedIn'),
            ),        ));

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

        register_rest_route(self::NAMESPACE, '/transient/send', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'sendTransientMessageToOpenAi'),
                'permission_callback' => array($this, 'canUseTransientMode'),
            ),
        ));
    }

    public function sendTransientMessageToOpenAi(WP_REST_Request $request)
    {
        if (!$this->settingsService->isTransientModeEnabled()) {
            return new WP_Error('transient_mode_disabled', 'Transient Mode ist deaktiviert.', array('status' => 403));
        }

        $payload = $request->get_json_params();
        $message = is_array($payload) ? trim((string) ($payload['message'] ?? '')) : '';
        $configId = is_array($payload) ? (int) ($payload['config_id'] ?? 0) : 0;
        $rawHistory = is_array($payload) && isset($payload['history']) && is_array($payload['history'])
            ? $payload['history']
            : array();

        if ($message === '') {
            return new WP_Error('invalid_message', 'Nachricht ist erforderlich.', array('status' => 400));
        }

        if ($configId <= 0) {
            return new WP_Error('invalid_config_id', 'Ungueltige Konfigurations-ID.', array('status' => 400));
        }

        $entry = $this->settingsService->findConfigurationEntryById($configId);
        if (!$entry) {
            return new WP_Error('configuration_not_found', 'Konfigurationseintrag nicht gefunden.', array('status' => 404));
        }

        $history = array();
        foreach ($rawHistory as $item) {
            if (!is_array($item)) {
                continue;
            }

            $role = isset($item['role']) ? (string) $item['role'] : '';
            $content = isset($item['content']) ? trim((string) $item['content']) : '';
            if ($content === '' || !in_array($role, array('user', 'assistant', 'system'), true)) {
                continue;
            }

            $history[] = array(
                'role' => $role,
                'content' => $content,
            );
        }

        if (count($history) > self::OPENAI_HISTORY_LIMIT) {
            $history = array_slice($history, -self::OPENAI_HISTORY_LIMIT);
        }

        $history[] = array(
            'role' => 'user',
            'content' => $message,
        );

        $promptId = trim((string) ($entry['promptId'] ?? ''));
        $openAiResult = $this->openAiService->createAssistantReply(
            $history,
            $promptId !== '' ? $promptId : null,
            array('user_id' => get_current_user_id())
        );

        if (is_wp_error($openAiResult)) {
            return $openAiResult;
        }

        $assistantText = (string) ($openAiResult['assistant_text'] ?? '');
        if ($assistantText === '') {
            return new WP_Error('openai_empty_response', 'OpenAI hat keine Antwort geliefert.', array('status' => 502));
        }

        return rest_ensure_response(array(
            'assistant_message' => array(
                'role' => 'assistant',
                'content' => $assistantText,
                'created_at' => gmdate('Y-m-d H:i:s'),
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
        $userId = (int) get_current_user_id();
        $payload = $request->get_json_params();
        $title = is_array($payload) ? (string) ($payload['title'] ?? 'Neuer Chat') : 'Neuer Chat';
        $configurationLabel = is_array($payload) ? trim((string) ($payload['configuration_label'] ?? '')) : '';

        if (trim($title) === '') {
            $title = 'Neuer Chat';
        }

        $entry = null;
        if ($configurationLabel !== '') {
            $entry = $this->settingsService->findConfigurationEntryByLabel($configurationLabel);
            if (!$entry) {
                return new WP_Error('configuration_not_found', 'Konfigurationseintrag nicht gefunden.', array('status' => 404));
            }

            $requiredAttributes = $this->normalizeEntryAttributeNames((array) ($entry['attrPrereq'] ?? array()));
            if (!empty($requiredAttributes)) {
                $roomAttributes = $this->roomRepository->getCustomAttributesForRoom($roomId, $userId);
                $lookup = array();
                foreach ($roomAttributes as $key => $value) {
                    $normalized = strtolower(trim((string) $key));
                    $normalized = preg_replace('/[^a-z0-9 _\-]/', '', $normalized);
                    if ($normalized !== '') {
                        $lookup[$normalized] = trim((string) $value);
                    }
                }

                $missing = array();
                foreach ($requiredAttributes as $attribute) {
                    if (!isset($lookup[$attribute]) || trim((string) $lookup[$attribute]) === '') {
                        $missing[] = $attribute;
                    }
                }

                if (!empty($missing)) {
                    return new WP_Error('missing_required_room_attributes', 'In diesem Raum fehlen die erforderlichen Attribute: ' . implode(', ', $missing), array('status' => 403));
                }
            }

            $maxPerRoom = max(0, (int) ($entry['maxPerRoom'] ?? $entry['max_per_room'] ?? 0));
            if ($maxPerRoom > 0) {
                $used = $this->chatRepository->countChatsByConfigLabelForRoom($roomId, $userId, $configurationLabel);
                if ($used >= $maxPerRoom) {
                    return new WP_Error('chat_type_limit_reached', 'Dieser Chattyp ist in diesem Raum bereits voll.', array('status' => 403));
                }
            }
        }

        $configPrompt = $entry ? (string) ($entry['prompt'] ?? '') : '';
        $configPromptId = $entry ? (string) ($entry['promptId'] ?? '') : '';
        $configIconUrl = $entry ? esc_url_raw((string) ($entry['iconUrl'] ?? '')) : '';
        $directive = $this->parseRuleFlowDirective($configPrompt);

        $chat = $this->chatRepository->createChat(
            $roomId,
            $userId,
            $title,
            $entry ? (string) ($entry['label'] ?? '') : '',
            $directive ? (string) $directive['cleaned_text'] : $configPrompt,
            $configPromptId,
            $configIconUrl
        );

        if (!$chat) {
            return new WP_Error('room_not_found', 'Raum nicht gefunden.', array('status' => 404));
        }

        $flowMeta = null;
        if ($directive) {
            $started = $this->startRuleFlow($chat, (int) $chat['id'], $userId, (string) $directive['flow_type']);
            $flowMeta = $started['flow'];
        }

        $chat['flow'] = $flowMeta;

        if ($directive === null && trim($configPrompt) !== '') {
            $greeting = $this->processRoomDirectivesForOutput($chat, $userId, $configPrompt);
            if (trim($greeting) !== '') {
                $assistantMessage = $this->chatRepository->addMessage((int) $chat['id'], $userId, 'assistant', $greeting, true);
                $chat['initial_assistant_message'] = $assistantMessage;
            }
        }

        return rest_ensure_response($chat);
    }

    public function getChat(WP_REST_Request $request)
    {
        $chatId = (int) $request->get_param('chatId');
        $chat = $this->chatRepository->getChatForUser($chatId, (int) get_current_user_id());

        if (!$chat) {
            return new WP_Error('chat_not_found', 'Chat nicht gefunden.', array('status' => 404));
        }

        return rest_ensure_response($chat);
    }

    public function deleteChat(WP_REST_Request $request)
    {
        $chatId = (int) $request->get_param('chatId');
        $userId = (int) get_current_user_id();
        $chat = $this->chatRepository->getChatForUser($chatId, $userId);

        if (!$chat) {
            return new WP_Error('chat_not_found', 'Chat nicht gefunden.', array('status' => 404));
        }

        $deleted = $this->chatRepository->delete($chatId, $userId);
        if (!$deleted) {
            return new WP_Error('chat_not_found', 'Chat nicht gefunden.', array('status' => 404));
        }

        $this->cleanupRoomAttributesProvidedByDeletedChat($chat, $chatId, $userId);

        return rest_ensure_response(array('deleted' => true));
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
        $openAiRequestContext['user_id'] = $userId;
        $history = $this->buildOpenAiHistory($chatId, $userId, $message, $openAiRequestContext);
        $promptId = isset($chat['config_prompt_id']) ? trim((string) $chat['config_prompt_id']) : '';
        $openAiResult = $this->openAiService->createAssistantReply(
            $history,
            $promptId !== '' ? $promptId : null,
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
            $started = $this->startRuleFlow($chat, $chatId, $userId, (string) $directive['flow_type']);
            $flowMeta = $started['flow'];
            if ($started['assistant_message']) {
                $savedAssistantMessage = $started['assistant_message'];
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

    /**
     * @return array{flow: ?array, assistant_message: ?array}
     */
    private function startRuleFlow(array $chat, int $chatId, int $userId, string $flowType): array
    {
        $initialResult = $this->flowRuntimeService->runInitialPrompt($flowType, $chatId, $userId, array());
        if (is_wp_error($initialResult)) {
            return array(
                'flow' => array(
                    'flow_type' => $flowType,
                    'status' => 'aborted',
                    'error' => $initialResult->get_error_message(),
                ),
                'assistant_message' => null,
            );
        }

        $createdSession = $this->flowSessionRepository->createOrReplace($chatId, $userId, $flowType, array());
        if (!$createdSession) {
            return array('flow' => null, 'assistant_message' => null);
        }

        $assistantMessage = null;
        $initialPrompt = $this->processRoomDirectivesForOutput($chat, $userId, (string) ($initialResult['initial_prompt'] ?? ''));
        if ($initialPrompt !== '') {
            $assistantMessage = $this->chatRepository->addMessage($chatId, $userId, 'assistant', $initialPrompt, true);
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

        return array(
            'flow' => array(
                'flow_type' => $flowType,
                'status' => $initStatus,
            ),
            'assistant_message' => $assistantMessage,
        );
    }

    private function normalizeEntryAttributeNames(array $values): array
    {
        $result = array();
        $seen = array();

        foreach ($values as $value) {
            $normalized = strtolower(trim((string) $value));
            $normalized = preg_replace('/[^a-z0-9 _\-]/', '', $normalized);
            $normalized = is_string($normalized) ? trim($normalized) : '';
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $result[] = $normalized;
        }

        return $result;
    }

    private function cleanupRoomAttributesProvidedByDeletedChat(array $chat, int $deletedChatId, int $userId): void
    {
        $roomId = isset($chat['room_id']) ? (int) $chat['room_id'] : 0;
        if ($roomId <= 0) {
            return;
        }

        $configLabel = trim((string) ($chat['config_label'] ?? ''));
        if ($configLabel === '') {
            return;
        }

        $entry = $this->settingsService->findConfigurationEntryByLabel($configLabel);
        if (!$entry) {
            return;
        }

        $providedAttributes = $this->normalizeEntryAttributeNames((array) ($entry['attrProv'] ?? array()));
        if (empty($providedAttributes)) {
            return;
        }

        $remainingChats = $this->chatRepository->listChatsForRoom($roomId, $userId);
        $keysToRemove = array();

        foreach ($providedAttributes as $attribute) {
            $stillProvided = false;
            foreach ($remainingChats as $candidate) {
                if ((int) ($candidate['id'] ?? 0) === $deletedChatId) {
                    continue;
                }

                $candidateLabel = trim((string) ($candidate['config_label'] ?? ''));
                if ($candidateLabel === '') {
                    continue;
                }

                $candidateEntry = $this->settingsService->findConfigurationEntryByLabel($candidateLabel);
                if (!$candidateEntry) {
                    continue;
                }

                $candidateProvided = $this->normalizeEntryAttributeNames((array) ($candidateEntry['attrProv'] ?? array()));
                if (in_array($attribute, $candidateProvided, true)) {
                    $stillProvided = true;
                    break;
                }
            }

            if (!$stillProvided) {
                $keysToRemove[] = $attribute;
            }
        }

        if (!empty($keysToRemove)) {
            $this->roomRepository->removeCustomAttributes($roomId, $userId, $keysToRemove);
        }
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

    public function canUseTransientMode(): bool
    {
        return $this->settingsService->isTransientModeEnabled();
    }
}
