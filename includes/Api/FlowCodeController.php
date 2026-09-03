<?php

namespace WpCustomGpt\Api;

use WP_Error;
use WP_REST_Request;
use WpCustomGpt\Services\FlowFileService;
use WpCustomGpt\Services\FlowRuntimeService;

class FlowCodeController
{
    private const NAMESPACE = 'wp-custom-gpt/v1';

    private FlowRuntimeService $flowRuntimeService;
    private FlowFileService $flowFileService;

    public function __construct(FlowRuntimeService $flowRuntimeService, FlowFileService $flowFileService)
    {
        $this->flowRuntimeService = $flowRuntimeService;
        $this->flowFileService = $flowFileService;
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/flows', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'listFlows'),
                'permission_callback' => array($this, 'canManageFlows'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/flows/(?P<flowType>[a-zA-Z0-9_\-]+)', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'getFlow'),
                'permission_callback' => array($this, 'canManageFlows'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'saveFlow'),
                'permission_callback' => array($this, 'canManageFlows'),
            ),
            array(
                'methods' => 'DELETE',
                'callback' => array($this, 'deactivateFlow'),
                'permission_callback' => array($this, 'canManageFlows'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/flows/validate', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'validateFlowCode'),
                'permission_callback' => array($this, 'canManageFlows'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/flows/(?P<flowType>[a-zA-Z0-9_\-]+)/files', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'listFlowFiles'),
                'permission_callback' => array($this, 'canManageFlows'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'uploadFlowFile'),
                'permission_callback' => array($this, 'canManageFlows'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/flows/(?P<flowType>[a-zA-Z0-9_\-]+)/files/(?P<fileId>\d+)', array(
            array(
                'methods' => 'DELETE',
                'callback' => array($this, 'deleteFlowFile'),
                'permission_callback' => array($this, 'canManageFlows'),
            ),
        ));
    }

    public function listFlows(): array
    {
        return $this->flowRuntimeService->listFlows();
    }

    public function getFlow(WP_REST_Request $request)
    {
        $flowType = (string) $request->get_param('flowType');
        $flow = $this->flowRuntimeService->getFlow($flowType);

        if (!$flow) {
            return new WP_Error('flow_not_found', 'Flow not found.', array('status' => 404));
        }

        return rest_ensure_response($flow);
    }

    public function saveFlow(WP_REST_Request $request)
    {
        $flowType = (string) $request->get_param('flowType');
        $payload = $request->get_json_params();

        if (!is_array($payload)) {
            return new WP_Error('invalid_payload', 'Request body must be JSON.', array('status' => 400));
        }

        $codePhp = isset($payload['code_php']) ? (string) $payload['code_php'] : '';
        $saved = $this->flowRuntimeService->saveFlowCode($flowType, $codePhp, (int) get_current_user_id());

        if (is_wp_error($saved)) {
            return $saved;
        }

        return rest_ensure_response($saved);
    }

    public function deactivateFlow(WP_REST_Request $request)
    {
        $flowType = (string) $request->get_param('flowType');
        $ok = $this->flowRuntimeService->deactivateFlow($flowType, (int) get_current_user_id());

        if (!$ok) {
            return new WP_Error('flow_deactivate_failed', 'Could not deactivate flow.', array('status' => 500));
        }

        return rest_ensure_response(array('ok' => true));
    }

    public function validateFlowCode(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('invalid_payload', 'Request body must be JSON.', array('status' => 400));
        }

        $codePhp = isset($payload['code_php']) ? (string) $payload['code_php'] : '';
        $result = $this->flowRuntimeService->validateCode($codePhp);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array('ok' => true));
    }

    public function listFlowFiles(WP_REST_Request $request)
    {
        $flowType = (string) $request->get_param('flowType');
        $files = $this->flowFileService->listFiles($flowType);

        if (is_wp_error($files)) {
            return $files;
        }

        return rest_ensure_response($files);
    }

    public function uploadFlowFile(WP_REST_Request $request)
    {
        $flowType = (string) $request->get_param('flowType');
        $fileParams = $request->get_file_params();
        $file = isset($fileParams['file']) && is_array($fileParams['file']) ? $fileParams['file'] : array();

        $saved = $this->flowFileService->saveUpload($flowType, $file, (int) get_current_user_id());
        if (is_wp_error($saved)) {
            return $saved;
        }

        return rest_ensure_response($saved);
    }

    public function deleteFlowFile(WP_REST_Request $request)
    {
        $flowType = (string) $request->get_param('flowType');
        $fileId = (int) $request->get_param('fileId');

        if ($fileId <= 0) {
            return new WP_Error('invalid_file_id', 'Invalid file id.', array('status' => 400));
        }

        $deleted = $this->flowFileService->deleteFile($flowType, $fileId);
        if (!$deleted) {
            return new WP_Error('flow_file_delete_failed', 'Could not delete flow file.', array('status' => 404));
        }

        return rest_ensure_response(array('ok' => true));
    }

    public function canManageFlows(): bool
    {
        return current_user_can('manage_options');
    }
}
