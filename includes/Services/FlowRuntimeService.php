<?php

namespace WpCustomGpt\Services;

use WP_Error;
use WpCustomGpt\Repositories\FlowCodeRepository;

class FlowRuntimeService
{
    private const MAX_CODE_BYTES = 64000;

    private FlowCodeRepository $flowCodeRepository;

    public function __construct(FlowCodeRepository $flowCodeRepository)
    {
        $this->flowCodeRepository = $flowCodeRepository;
    }

    public function listFlows(): array
    {
        return $this->flowCodeRepository->listAll();
    }

    public function getFlow(string $flowType): ?array
    {
        return $this->flowCodeRepository->findByFlowType($flowType);
    }

    public function saveFlowCode(string $flowType, string $codePhp, int $updatedBy): array|WP_Error
    {
        $validation = $this->validateCode($codePhp);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $saved = $this->flowCodeRepository->save($flowType, $codePhp, $updatedBy);
        if (!$saved) {
            return new WP_Error('flow_save_failed', 'Could not save flow code.', array('status' => 500));
        }

        return $saved;
    }

    public function deactivateFlow(string $flowType, int $updatedBy): bool
    {
        return $this->flowCodeRepository->deactivate($flowType, $updatedBy);
    }

    public function runInitialPrompt(string $flowType, int $chatId, int $userId, array $state): array|WP_Error
    {
        return $this->execute($flowType, array(
            'mode' => 'initial',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'session' => array(
                'flow_type' => $flowType,
                'state' => $state,
            ),
        ));
    }

    public function runTurn(string $flowType, int $chatId, int $userId, array $state, string $userInput): array|WP_Error
    {
        return $this->execute($flowType, array(
            'mode' => 'turn',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'user_input' => $userInput,
            'session' => array(
                'flow_type' => $flowType,
                'state' => $state,
            ),
        ));
    }

    public function validateCode(string $codePhp): true|WP_Error
    {
        $trimmed = trim($codePhp);
        if ($trimmed === '') {
            return new WP_Error('flow_code_empty', 'Flow code must not be empty.', array('status' => 400));
        }

        if (strlen($codePhp) > self::MAX_CODE_BYTES) {
            return new WP_Error('flow_code_too_large', 'Flow code exceeds the size limit.', array('status' => 400));
        }

        if (stripos($codePhp, '<?php') !== false) {
            return new WP_Error('flow_code_invalid_format', 'Do not include PHP opening tags in flow code.', array('status' => 400));
        }

        $forbiddenTokens = array(
            'eval(',
            'assert(',
            'exec(',
            'shell_exec(',
            'passthru(',
            'system(',
            'proc_open(',
            'popen(',
            'pcntl_exec(',
            'curl_exec(',
            'file_put_contents(',
            'fopen(',
            'unlink(',
            'rmdir(',
            'rename(',
            'copy(',
            'require(',
            'require_once(',
            'include(',
            'include_once(',
        );

        $lower = strtolower($codePhp);
        foreach ($forbiddenTokens as $token) {
            if (strpos($lower, $token) !== false) {
                return new WP_Error('flow_code_forbidden_token', 'Flow code uses forbidden token: ' . $token, array('status' => 400));
            }
        }

        $compiler = static function () use ($codePhp) {
            return eval('return static function(array $context): array {' . "\n" . $codePhp . "\n" . '};');
        };

        try {
            $compiled = $compiler();
        } catch (\ParseError $error) {
            return new WP_Error('flow_code_parse_error', $error->getMessage(), array('status' => 400));
        }

        if (!is_callable($compiled)) {
            return new WP_Error('flow_code_not_callable', 'Flow code could not be compiled.', array('status' => 400));
        }

        return true;
    }

    private function execute(string $flowType, array $context): array|WP_Error
    {
        $flow = $this->flowCodeRepository->findActiveByFlowType($flowType);
        if (!$flow) {
            return new WP_Error('flow_not_found', 'No active flow definition found for flow type.', array('status' => 404));
        }

        $compiled = $this->compileClosure((string) $flow['code_php']);
        if (is_wp_error($compiled)) {
            return $compiled;
        }

        try {
            $rawResult = $compiled($context);
        } catch (\Throwable $error) {
            return new WP_Error('flow_runtime_error', $error->getMessage(), array('status' => 500));
        }

        if (!is_array($rawResult)) {
            return new WP_Error('flow_invalid_result', 'Flow handler must return an array.', array('status' => 500));
        }

        return $this->normalizeResult($rawResult);
    }

    private function compileClosure(string $codePhp): callable|WP_Error
    {
        $compiler = static function () use ($codePhp) {
            return eval('return static function(array $context): array {' . "\n" . $codePhp . "\n" . '};');
        };

        try {
            $compiled = $compiler();
        } catch (\ParseError $error) {
            return new WP_Error('flow_code_parse_error', $error->getMessage(), array('status' => 500));
        }

        if (!is_callable($compiled)) {
            return new WP_Error('flow_code_not_callable', 'Flow code could not be compiled.', array('status' => 500));
        }

        return $compiled;
    }

    private function normalizeResult(array $rawResult): array|WP_Error
    {
        $status = isset($rawResult['status']) ? strtolower((string) $rawResult['status']) : 'running';
        if (!in_array($status, array('running', 'completed', 'aborted'), true)) {
            return new WP_Error('flow_invalid_status', 'Flow status must be running, completed or aborted.', array('status' => 500));
        }

        $assistantReply = isset($rawResult['assistant_reply']) ? trim((string) $rawResult['assistant_reply']) : '';
        $initialPrompt = isset($rawResult['initial_prompt']) ? trim((string) $rawResult['initial_prompt']) : '';

        if ($assistantReply === '' && $initialPrompt === '') {
            return new WP_Error('flow_missing_reply', 'Flow must return assistant_reply or initial_prompt.', array('status' => 500));
        }

        $state = isset($rawResult['state']) && is_array($rawResult['state']) ? $rawResult['state'] : array();

        return array(
            'status' => $status,
            'assistant_reply' => $assistantReply,
            'initial_prompt' => $initialPrompt,
            'state' => $state,
        );
    }
}
