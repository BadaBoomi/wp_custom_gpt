<?php

namespace WpCustomGpt\Services;

use WP_Error;
use WpCustomGpt\Repositories\FlowCodeRepository;
use WpCustomGpt\Services\FlowFileService;

class FlowRuntimeService
{
    private const MAX_CODE_BYTES = 64000;

    private FlowCodeRepository $flowCodeRepository;
    private FlowFileService $flowFileService;

    public function __construct(FlowCodeRepository $flowCodeRepository, FlowFileService $flowFileService)
    {
        $this->flowCodeRepository = $flowCodeRepository;
        $this->flowFileService = $flowFileService;
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
            return new WP_Error('flow_save_failed', 'Flow-Code konnte nicht gespeichert werden.', array('status' => 500));
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
            return new WP_Error('flow_code_empty', 'Flow-Code darf nicht leer sein.', array('status' => 400));
        }

        if (strlen($codePhp) > self::MAX_CODE_BYTES) {
            return new WP_Error('flow_code_too_large', 'Flow-Code ueberschreitet das Groessenlimit.', array('status' => 400));
        }

        if (stripos($codePhp, '<?php') !== false) {
            return new WP_Error('flow_code_invalid_format', 'Bitte keine PHP-Start-Tags im Flow-Code verwenden.', array('status' => 400));
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
                return new WP_Error('flow_code_forbidden_token', 'Flow-Code verwendet ein verbotenes Token: ' . $token, array('status' => 400));
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
            return new WP_Error('flow_code_not_callable', 'Flow-Code konnte nicht kompiliert werden.', array('status' => 400));
        }

        return true;
    }

    private function execute(string $flowType, array $context): array|WP_Error
    {
        $flow = $this->flowCodeRepository->findActiveByFlowType($flowType);
        if (!$flow) {
            return new WP_Error('flow_not_found', 'Keine aktive Flow-Definition fuer diesen Flow-Typ gefunden.', array('status' => 404));
        }

        $flowFiles = $this->flowFileService->listFiles($flowType);
        if (is_wp_error($flowFiles)) {
            $flowFiles = array();
        }

        $context['flow_files'] = $flowFiles;

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
            return new WP_Error('flow_invalid_result', 'Flow-Handler muss ein Array zurueckgeben.', array('status' => 500));
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
            return new WP_Error('flow_code_not_callable', 'Flow-Code konnte nicht kompiliert werden.', array('status' => 500));
        }

        return $compiled;
    }

    private function normalizeResult(array $rawResult): array|WP_Error
    {
        $status = isset($rawResult['status']) ? strtolower((string) $rawResult['status']) : 'running';
        if (!in_array($status, array('running', 'completed', 'aborted'), true)) {
            return new WP_Error('flow_invalid_status', 'Flow-Status muss running, completed oder aborted sein.', array('status' => 500));
        }

        $assistantReply = isset($rawResult['assistant_reply']) ? trim((string) $rawResult['assistant_reply']) : '';
        $initialPrompt = isset($rawResult['initial_prompt']) ? trim((string) $rawResult['initial_prompt']) : '';

        if ($assistantReply === '' && $initialPrompt === '') {
            return new WP_Error('flow_missing_reply', 'Flow muss assistant_reply oder initial_prompt zurueckgeben.', array('status' => 500));
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
