<?php

namespace App\Controller;

use App\Entity\Passwords;
use App\Entity\Rooms;
use App\Entity\Roommembers;
use App\Entity\Tokens;
use App\Entity\Users;
use App\Traits\GeneralTrait;
use App\Traits\MatrixSynapseTrait;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;

/**
 * API Controller to serve a mock of the Matrix API.
 *
 * @Route("/{serverID}/_matrix/client")
 */
class MatrixController extends AbstractController {

    use GeneralTrait, MatrixSynapseTrait;

    /**
     * @Route("/r0", name="endpoint")
     */
    public function endpoint(): JsonResponse
    {
        return new JsonResponse((object) [
            'errcode' => 'M_UNRECOGNIZED',
            'error' => 'Unrecognized request'
        ], 404);
    }

    /**
     * Login a user.
     *
     * @Route("/r0/login", name="login")
     * @param string $serverID
     * @param Request $request
     * @return JsonResponse
     */
    public function login(string $serverID, Request $request) : JsonResponse {
        // 1. Check HTTP method is accepted.
        $accessCheck = $this->authHttpCheck(['POST'], $request, false);
        if (!$accessCheck['status']) {
            return $accessCheck['message'];
        }

        $payload = json_decode($request->getContent());
        $check = $this->validateRequest((array)$payload, ['identifier', 'type']);
        if (!$check['status']) {
            return $check['message'];
        }

        // 1. Check if type is in the $palyload->identifier.
        // 2. Return loginidentifier property if no error.
        $check = $this->loginIdentifierType($payload->identifier);
        if (!$check['status']) {
            return $check['message'];
        }

        if ($payload->type === 'm.login.password') {
            if (!isset($payload->password)) {
                return new JsonResponse((object) [
                    'errcode' => 'M_INVALID_PARAM',
                    'error' => 'Bad parameter: password'
                ], 400);
            }

            $entityManager = $this->getDoctrine()->getManager();
            $user = $entityManager->getRepository(Users::class)->findOneBy($check['loginidentifier']);

            $passwordpatter = $user ? $user->getPasswordpattern() : null;
            $userid = $user ? $user->getId() : null;
            $password = $entityManager->getRepository(Passwords::class)->findOneBy([
                'password' => $this->hashPassword($payload->password, $passwordpatter)['token'],
                'userid' => $userid
            ]);

            // Check if user with its password is found.
            if ($user && $password) {
                $token = $entityManager->getRepository(Tokens::class)->findOneBy(['userid' => $user->getId()]);

                // Assign client server id if the server id is NULL.
                if (is_null($token->getServerid())) {
                    $token->setServerid($serverID);
                }

                // Check if refresh_token is in the body and set to true,
                // then generate a new refresh_token.
                if (isset($payload->refresh_token) && $payload->refresh_token === true) {
                    $token->setRefreshToken($this->generateToken('refresh-token'));
                    $response['refresh_token'] = $token->getRefreshToken();
                }

                $token->setAccessToken($this->generateToken());
                $entityManager->persist($token);
                $entityManager->flush();

                $response['user_id'] = $user->getUserid();
                $response['access_token'] = $token->getAccesstoken();
                // $response['refresh_token'] = $token->getRefreshtoken();
                $response['home_server'] = $request->getHost();

                return new JsonResponse((object) $response, 200);
            } else {
                return new JsonResponse((object) [
                    'errcode' => 'M_FORBIDDEN',
                    'error' => 'Invalid username or password'
                ], 403);
            }
        }

        return new JsonResponse((object) [
            'errcode' => 'M_UNKNOWN',
            'error' => 'Bad login type.'
        ], 403);
    }

    /**
     * Refresh the tokens.
     *
     * @Route("/r0/refresh", name="refresh")
     * @param string $serverID
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(string $serverID, Request $request) : JsonResponse {
        // 1. Check HTTP method is accepted.
        $accessCheck = $this->authHttpCheck(['POST'], $request, false);
        if (!$accessCheck['status']) {
            return $accessCheck['message'];
        }

        $payload = json_decode($request->getContent());
        $check = $this->validateRequest((array)$payload, ['refresh_token']);
        if (!$check['status']) {
            return $check['message'];
        }

        $tokens = $this->getToken($serverID, $payload->refresh_token);
        if (!empty($tokens)) {
            $tokens->setAccesstoken($this->generateToken('access-token'));
            $tokens->setRefreshtoken($this->generateToken('refresh-token'));
            $tokens->setServerid($serverID);

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($tokens);
            $entityManager->flush();

            return new JsonResponse((object)[
                'access_token' => $tokens->getAccesstoken(),
                'refresh_token' => $tokens->getRefreshtoken()
            ], 200);
        } else {
            return new JsonResponse((object)[
                'errcode' => 'M_UNKNOWN_TOKEN',
                'refresh_token' => 'Invalid token'
            ], 401);
        }
    }

    /**
     * Create Matrix room.
     *
     * @Route("/r0/createRoom", name="createRoom")
     * @param string $serverID
     * @param Request $request
     * @return JsonResponse
     */
    public function createRoom(string $serverID, Request $request) : JsonResponse {
        // 1. Check call auth.
        // 2. Check HTTP method is accepted.
        $accessCheck = $this->authHttpCheck(['POST'], $request);
        if (!$accessCheck['status']) {
            return $accessCheck['message'];
        }

        $payload = json_decode($request->getContent());
        $roomName = $payload->name ?? rand();
        $host = $request->getHost();

        // Create a mock room ID. This isn't the way Synapse does it (I think), but it's a good enough approximation.
        $roomID = '!'. substr(hash('sha256', ($serverID . $roomName . (string)time())), 0, 18) . ':' . $host;
        $response['room_id'] = $roomID;

        // Store the room in the DB.
        $entityManager = $this->getDoctrine()->getManager();
        $room = new Rooms();

        $room->setRoomid($roomID);
        $room->setName($roomName);
        $room->setTopic($payload->topic ?? null);
        $room->setServerid($serverID);
        $room->setCreator($accessCheck['user_id']);
        if (isset($payload->room_alias_name) && !empty($payload->room_alias_name)) {
            $room_alias = "#{$payload->room_alias_name}:{$host}";
            $check_alias = $entityManager->getRepository(Rooms::class)->findOneBy(['roomalias' => $room_alias]);
            if (empty($check_alias)) {
                $room->setRoomAlias($room_alias);
                $response['room_alias'] = $room_alias;
            } else {
                return new JsonResponse((object) [
                    'errcode' => 'M_ROOM_IN_USE',
                    'error' => 'Room alias already taken'
                ], 400);
            }
        }

        $entityManager->persist($room);
        $entityManager->flush();

        return new JsonResponse((object) $response, 200);
    }

    /**
     * Create Matrix room.
     *
     * @Route("/r0/rooms/{roomID}/kick", name="kick")
     * @param Request $request
     * @return JsonResponse
     */
    public function kick(string $roomID, Request $request) : JsonResponse {
        // 1. Check call auth.
        // 2. Check HTTP method is accepted.
        $accessCheck = $this->authHttpCheck(['POST'], $request);
        if (!$accessCheck['status']) {
            return $accessCheck['message'];
        }

        // Check room exists.
        $roomCheck = $this->roomExists($roomID);
        if (!$roomCheck['status']) {
            return $roomCheck['message'];
        }

        $payload = json_decode($request->getContent());
        $check = $this->validateRequest((array)$payload, ['user_id']);
        if (!$check['status']) {
            return $check['message'];
        }

        $roommembers = $this->getRoomMember($roomID, $payload->user_id);
        if (empty($roommembers)) {
            return new JsonResponse((object) [
                'errcode' => 'M_NOT_MEMBER',
                'error' => 'The target user_id is not a room member.'
            ], 403);
        }

        // Update th membership.
        $roommembers->setState('leave');
        $roommembers->setReason($payload->reason ?? null);

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($roommembers);
        $entityManager->flush();
        return new JsonResponse((object)[]);
    }

    /**
     * Update various room state components.
     *
     * @Route("/r0/rooms/{roomID}/state/{eventType}")
     * @Route("/r0/rooms/{roomID}/state/{eventType}/")
     * @param string $serverID
     * @param string $eventType
     * @param Request $request
     * @return JsonResponse
     */
    public function roomState(string $serverID, string $roomID, string $eventType, Request $request) : JsonResponse {
        // 1. Check call auth.
        // 2. Check HTTP method is accepted.
        $accessCheck = $this->authHttpCheck(['PUT'], $request);
        if (!$accessCheck['status']) {
            return $accessCheck['message'];
        }

        // Check room exists. If exists, "room" property is added.
        $roomCheck = $this->roomExists($roomID, true);
        if (!$roomCheck['status']) {
            return $roomCheck['message'];
        }
        $room = $roomCheck['room'];
        $payload = json_decode($request->getContent());

        if ($eventType == 'm.room.topic') {
            $check = $this->validateRequest((array)$payload, ['topic']);
            if (!$check['status']) {
                return $check['message'];
            }
            $room->setTopic($payload->topic);

        } elseif ($eventType == 'm.room.name') {
            // Update room name.
            $check = $this->validateRequest((array)$payload, ['name']);
            if (!$check['status']) {
                return $check['message'];
            }
            $room->setName($payload->name);

        } elseif ($eventType == 'm.room.avatar') {
            // Update room avatar.
            $check = $this->validateRequest((array)$payload, ['url']);
            if (!$check['status']) {
                return $check['message'];
            }
            $room->setAvatar($payload->url);
        } else {
            // Unknown state.
            return new JsonResponse((object) [
                'errcode' => 'M_UNRECOGNIZED',
                'error' => 'Unrecognized request'
            ], 404);
        }

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($room);
        $entityManager->flush();

        // Create a mock event ID. This isn't the way Synapse does it (I think), but it's a good enough approximation.
        // This ID doesn't change if the seed data is the same.
        $eventID = substr(hash('sha256', ($serverID . $roomID . $eventType)), 0, 44);

        return new JsonResponse((object) [
            'event_id' => $eventID,
        ], 200);
    }

    /**
     * Gets all joined members of a group.
     *
     * @Route("/r0/rooms/{roomID}/joined_members", name="getJoinedMembers")
     * @param string $serverID
     * @param string $roomID
     * @param Request $request
     * @return JsonResponse
     */
    public function getJoinedMembers(string $serverID, string $roomID, Request $request) : JsonResponse {
        // 1. Check call auth.
        // 2. Check HTTP method is accepted.
        $accessCheck = $this->authHttpCheck(['GET'], $request);
        if (!$accessCheck['status']) {
            return $accessCheck['message'];
        }

        // Get all joined members.
        $room_members = $this->getDoctrine()
            ->getRepository(Roommembers::class)
            ->findBy(['roomid' => $roomID, 'serverid' => $serverID, 'state' => null]);

        $joined_members = new stdClass();
        foreach ($room_members as $member) {
            $userid = $member->getUserid();

            $user = $this->getDoctrine()
                ->getRepository(Users::class)
                ->findBy(['userid' => $userid, 'serverid' => $serverID])[0];

            $userdetail = new stdClass();
            $userdetail->avatar_url = $user->getAvatarurl();
            $userdetail->display_name = $user->getDisplayname();
            $joined_members->{$userid} = $userdetail;
        }

        return new JsonResponse((object) [
            'joined' => $joined_members
        ], 200);
    }
}
