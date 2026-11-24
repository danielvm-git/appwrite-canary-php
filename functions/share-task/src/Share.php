<?php

declare(strict_types=1);

final class ShareError extends Exception
{
    public string $shareCode;
    public int $httpStatus;

    public function __construct(string $shareCode, string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->shareCode = $shareCode;
        $this->httpStatus = $httpStatus;
    }
}

/**
 * @param array{callerId:string,taskId:string,email:string,db:object,findUserByEmail:callable,databaseId?:string,tableId?:string} $params
 * @return array{sharedWith:list<string>,sharedNames:list<string>}
 */
function shareTask(array $params): array
{
    $callerId = $params['callerId'] ?? '';
    $taskId = $params['taskId'] ?? '';
    $email = $params['email'] ?? '';
    $db = $params['db'];
    $findUserByEmail = $params['findUserByEmail'];
    $databaseId = $params['databaseId'] ?? 'todo';
    $tableId = $params['tableId'] ?? 'tasks';

    if ($callerId === '') {
        throw new ShareError('unauthorized', 'Sign in to share tasks.', 401);
    }
    $target = strtolower(trim((string) $email));
    if ($taskId === '') {
        throw new ShareError('invalid', 'Missing task id.');
    }
    if (!preg_match('/^\S+@\S+\.\S+$/', $target)) {
        throw new ShareError('invalid', 'Enter a valid email.');
    }

    try {
        $row = $db->getRow(['databaseId' => $databaseId, 'tableId' => $tableId, 'rowId' => $taskId]);
    } catch (Throwable $err) {
        $http = $err->getCode();
        if ($http === 404 || str_contains($err->getMessage(), '404')) {
            throw new ShareError('not_found', 'Task not found.', 404);
        }
        throw $err;
    }

    if (($row['userId'] ?? '') !== $callerId) {
        throw new ShareError('forbidden', 'Only the owner can share this task.', 403);
    }

    $user = $findUserByEmail($target);
    if (!$user) {
        throw new ShareError('no_user', 'No user with that email.', 404);
    }
    if (($user['$id'] ?? '') === $callerId) {
        throw new ShareError('self', 'That’s you.');
    }

    $sharedWith = $row['sharedWith'] ?? [];
    if (in_array($user['$id'], $sharedWith, true)) {
        throw new ShareError('already', 'Already shared.', 409);
    }

    $nextWith = [...$sharedWith, $user['$id']];
    $sharedNames = $row['sharedNames'] ?? [];
    $nextNames = [...$sharedNames, $user['name'] ?? explode('@', $target)[0]];

    $permissions = array_values(array_unique(array_merge(
        $row['$permissions'] ?? [],
        ownerPermissions((string) $row['userId']),
        ['read("user:' . $user['$id'] . '")', 'update("user:' . $user['$id'] . '")'],
    )));

    $db->updateRow([
        'databaseId' => $databaseId,
        'tableId' => $tableId,
        'rowId' => $taskId,
        'data' => ['sharedWith' => $nextWith, 'sharedNames' => $nextNames],
        'permissions' => $permissions,
    ]);

    return ['sharedWith' => $nextWith, 'sharedNames' => $nextNames];
}

/** @return list<string> */
function ownerPermissions(string $userId): array
{
    return [
        'read("user:' . $userId . '")',
        'update("user:' . $userId . '")',
        'delete("user:' . $userId . '")',
    ];
}