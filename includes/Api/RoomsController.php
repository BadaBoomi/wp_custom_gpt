<?php

namespace WpCustomGpt\Api;

use WP_Error;
use WP_REST_Request;
use WpCustomGpt\Repositories\RoomRepository;

class RoomsController
{
    private const NAMESPACE = 'wp-custom-gpt/v1';

    private RoomRepository $roomRepository;

    public function __construct(RoomRepository $roomRepository)
    {
        $this->roomRepository = $roomRepository;
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/rooms', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'listRooms'),
                'permission_callback' => array($this, 'isLoggedIn'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'createRoom'),
                'permission_callback' => array($this, 'isLoggedIn'),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/rooms/(?P<id>\\d+)', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'getRoom'),
                'permission_callback' => array($this, 'isLoggedIn'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'renameRoom'),
                'permission_callback' => array($this, 'isLoggedIn'),
            ),
            array(
                'methods' => 'DELETE',
                'callback' => array($this, 'deleteRoom'),
                'permission_callback' => array($this, 'isLoggedIn'),
            ),
        ));
    }

    public function listRooms(): array
    {
        $userId = get_current_user_id();
        return $this->roomRepository->listByUser((int) $userId);
    }

    public function createRoom(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        $name = is_array($payload) ? (string) ($payload['name'] ?? '') : '';

        if (trim($name) === '') {
            return new WP_Error('invalid_name', 'Raumname ist erforderlich.', array('status' => 400));
        }

        $room = $this->roomRepository->create((int) get_current_user_id(), $name);

        return rest_ensure_response($room);
    }

    public function getRoom(WP_REST_Request $request)
    {
        $roomId = (int) $request->get_param('id');
        $room = $this->roomRepository->getByIdForUser($roomId, (int) get_current_user_id());

        if (!$room) {
            return new WP_Error('not_found', 'Raum nicht gefunden.', array('status' => 404));
        }

        return rest_ensure_response($room);
    }

    public function renameRoom(WP_REST_Request $request)
    {
        $roomId = (int) $request->get_param('id');
        $payload = $request->get_json_params();
        $name = is_array($payload) ? (string) ($payload['name'] ?? '') : '';

        if (trim($name) === '') {
            return new WP_Error('invalid_name', 'Raumname ist erforderlich.', array('status' => 400));
        }

        $updated = $this->roomRepository->rename($roomId, (int) get_current_user_id(), $name);

        if (!$updated) {
            return new WP_Error('not_found', 'Raum nicht gefunden.', array('status' => 404));
        }

        return rest_ensure_response($updated);
    }

    public function deleteRoom(WP_REST_Request $request)
    {
        $roomId = (int) $request->get_param('id');
        $deleted = $this->roomRepository->delete($roomId, (int) get_current_user_id());

        if (!$deleted) {
            return new WP_Error('not_found', 'Raum nicht gefunden.', array('status' => 404));
        }

        return rest_ensure_response(array('deleted' => true));
    }

    public function isLoggedIn(): bool
    {
        return is_user_logged_in();
    }
}
