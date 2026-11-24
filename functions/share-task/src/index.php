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
    if (isset($req->body) && is_array($req->body)) {
        return $req->body;
    }
    $raw = '';
    if (isset($req->bodyText) && is_string($req->bodyText)) {
        $raw = $req->bodyText;
    } elseif (isset($req->body) && is_string($req->body)) {
        $raw = $req->body;
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

    $db = new class ($tables) {
        public function __construct(private TablesDB $tables) {}

        public function getRow(array $params): array
        {
            $row = $this->tables->getRow($params['databaseId'], $params['tableId'], $params['rowId']);
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
        $result = shareTask([
            'callerId' => $callerId,
            'taskId' => (string) ($body['taskId'] ?? ''),
            'email' => (string) ($body['email'] ?? ''),
            'db' => $db,
            'findUserByEmail' => static function (string $email) use ($users): ?array {
                $list = $users->list([Query::equal('email', [$email]), Query::limit(1)]);
                $user = $list->users[0] ?? null;
                if (!$user) {
                    return null;
                }
                return $user->toArray();
            },
        ]);
        $context->log(json_encode([
            'level' => 'info',
            'message' => 'task shared',
            'taskId' => $body['taskId'] ?? null,
        ], JSON_THROW_ON_ERROR));
        return $context->res->json(['ok' => true, ...$result]);
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