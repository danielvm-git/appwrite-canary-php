<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Share.php';

use Appwrite\Client;
use Appwrite\Query;
use Appwrite\Services\TablesDB;
use Appwrite\Services\Users;

/** @return array<string,mixed> */
function readRequestBody(object $req): array
{
    if (isset($req->bodyJson)) {
        if (is_array($req->bodyJson)) {
            return $req->bodyJson;
        }
        if (is_object($req->bodyJson)) {
            return json_decode(json_encode($req->bodyJson), true) ?: [];
        }
    }
    if (isset($req->body) && is_array($req->body)) {
        return $req->body;
    }
    if (isset($req->body) && is_object($req->body)) {
        return json_decode(json_encode($req->body), true) ?: [];
    }
    $raw = '';
    foreach (['bodyBinary', 'bodyText', 'bodyRaw', 'payload'] as $key) {
        if (isset($req->$key) && is_string($req->$key) && $req->$key !== '') {
            $raw = $req->$key;
            break;
        }
    }
    if ($raw === '' && isset($req->body) && is_string($req->body)) {
        $raw = $req->body;
    }
    if ($raw === '') {
        $input = file_get_contents('php://input');
        if (is_string($input) && $input !== '') {
            $raw = $input;
        }
    }
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

return function ($context) {
    if (($context->req->method ?? 'GET') !== 'POST') {
        return $context->res->json(['ok' => false, 'code' => 'method', 'message' => 'Use POST.'], 405);
    }

    $client = new Client();
    $client
        ->setEndpoint(getenv('APPWRITE_FUNCTION_API_ENDPOINT') ?: '')
        ->setProject(getenv('APPWRITE_FUNCTION_PROJECT_ID') ?: '')
        ->setKey($context->req->headers['x-appwrite-key'] ?? '');

    $tables = new TablesDB($client);
    $users = new Users($client);
    $body = readRequestBody($context->req);
    if (!isset($body['taskId']) && isset($body['body']) && is_string($body['body'])) {
        $inner = json_decode($body['body'], true);
        if (is_array($inner)) {
            $body = $inner;
        }
    }

    $db = new class ($tables) {
        public function __construct(private TablesDB $tables) {}

        public function getRow(array $params): array
        {
            $row = $this->tables->getRow($params['databaseId'], $params['tableId'], $params['rowId']);
            if (is_array($row)) {
                return $row;
            }
            return $row->toArray();
        }

        public function updateRow(array $params): void
        {
            $this->tables->updateRow(
                $params['databaseId'],
                $params['tableId'],
                $params['rowId'],
                $params['data'],
                $params['permissions'] ?? null,
            );
        }
    };

    $callerId = (string) ($context->req->headers['x-appwrite-user-id'] ?? '');

    try {
        $taskId = (string) ($body['taskId'] ?? $body['rowId'] ?? '');
        $result = shareTask([
            'callerId' => $callerId,
            'taskId' => $taskId,
            'email' => (string) ($body['email'] ?? ''),
            'db' => $db,
            'findUserByEmail' => static function (string $email) use ($users): ?array {
                $list = $users->list([Query::equal('email', [$email]), Query::limit(1)]);
                $listed = is_array($list) ? ($list['users'] ?? []) : ($list->users ?? []);
                $user = $listed[0] ?? null;
                if (!$user) {
                    return null;
                }
                if (is_array($user)) {
                    return $user;
                }
                return $user->toArray();
            },
        ]);
        $context->log(json_encode([
            'level' => 'info',
            'message' => 'task shared',
            'taskId' => $body['taskId'] ?? null,
        ], JSON_THROW_ON_ERROR));
        return $context->res->json(array_merge(['ok' => true], $result));
    } catch (ShareError $err) {
        return $context->res->json(['ok' => false, 'code' => $err->shareCode, 'message' => $err->getMessage()], $err->httpStatus);
    } catch (Throwable $err) {
        $context->error(json_encode([
            'level' => 'error',
            'message' => 'share failed',
            'taskId' => $body['taskId'] ?? null,
            'err' => $err->getMessage(),
        ], JSON_THROW_ON_ERROR));
        return $context->res->json(['ok' => false, 'code' => 'internal', 'message' => 'Could not share the task.'], 500);
    }
};