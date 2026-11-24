<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ShareTest extends TestCase
{
  /** @return array{db:object,findUserByEmail:callable,task:array<string,mixed>} */
  private function makeFixtures(array $row = []): array
  {
    $task = array_merge([
      '$id' => 't1',
      'userId' => 'owner',
      'title' => 'Ship',
      'sharedWith' => [],
      'sharedNames' => [],
      '$permissions' => ['read("user:owner")', 'update("user:owner")', 'delete("user:owner")'],
    ], $row);

    $db = new class ($task) {
      public array $lastUpdate = [];
      private bool $fail404 = false;

      public function __construct(private array $task) {}

      public function rejectNextGet(): void
      {
        $this->fail404 = true;
      }

      public function getRow(array $params): array
      {
        if ($this->fail404) {
          $this->fail404 = false;
          throw new \Exception('Row not found', 404);
        }
        return $this->task;
      }

      public function updateRow(array $params): void
      {
        $this->lastUpdate = $params;
        $this->task = array_merge($this->task, $params['data']);
        $this->task['$permissions'] = $params['permissions'];
      }
    };

    $people = [
      'ben@desk.dev' => ['$id' => 'ben', 'name' => 'Ben Lima', 'email' => 'ben@desk.dev'],
      'owner@desk.dev' => ['$id' => 'owner', 'name' => 'Ana Souza', 'email' => 'owner@desk.dev'],
    ];

    $findUserByEmail = static function (string $email) use ($people): ?array {
      return $people[$email] ?? null;
    };

    return ['db' => $db, 'findUserByEmail' => $findUserByEmail, 'task' => $task];
  }

  private function expectCode(callable $fn, string $code): void
  {
    try {
      $fn();
      self::fail('expected ShareError');
    } catch (\ShareError $e) {
      self::assertSame($code, $e->shareCode);
    }
  }

  public function testGrantsReadAndUpdateAndRecordsName(): void
  {
    $s = $this->makeFixtures();
    $result = shareTask([
      'callerId' => 'owner',
      'taskId' => 't1',
      'email' => ' Ben@Desk.dev ',
      'db' => $s['db'],
      'findUserByEmail' => $s['findUserByEmail'],
    ]);

    self::assertSame(['ben'], $result['sharedWith']);
    self::assertSame(['Ben Lima'], $result['sharedNames']);
    $update = $s['db']->lastUpdate;
    self::assertSame('todo', $update['databaseId']);
    self::assertSame('tasks', $update['tableId']);
    self::assertSame('t1', $update['rowId']);
    self::assertContains('read("user:ben")', $update['permissions']);
    self::assertContains('update("user:ben")', $update['permissions']);
    self::assertContains('delete("user:owner")', $update['permissions']);
    self::assertNotContains('delete("user:ben")', $update['permissions']);
  }

  public function testAddsOwnerPermissionsWhenRowHasNone(): void
  {
    $s = $this->makeFixtures(['$permissions' => []]);
    shareTask([
      'callerId' => 'owner',
      'taskId' => 't1',
      'email' => 'ben@desk.dev',
      'db' => $s['db'],
      'findUserByEmail' => $s['findUserByEmail'],
    ]);
    $perms = $s['db']->lastUpdate['permissions'];
    self::assertContains('read("user:owner")', $perms);
    self::assertContains('update("user:owner")', $perms);
    self::assertContains('delete("user:owner")', $perms);
  }

  public function testRejectsAnonymousCallers(): void
  {
    $s = $this->makeFixtures();
    $this->expectCode(
      fn () => shareTask([
        'callerId' => '',
        'taskId' => 't1',
        'email' => 'ben@desk.dev',
        'db' => $s['db'],
        'findUserByEmail' => $s['findUserByEmail'],
      ]),
      'unauthorized',
    );
  }

  public function testRejectsInvalidEmailBeforeDatabase(): void
  {
    $s = $this->makeFixtures();
    $this->expectCode(
      fn () => shareTask([
        'callerId' => 'owner',
        'taskId' => 't1',
        'email' => 'ben',
        'db' => $s['db'],
        'findUserByEmail' => $s['findUserByEmail'],
      ]),
      'invalid',
    );
    self::assertSame([], $s['db']->lastUpdate);
  }

  public function testOnlyOwnerCanShare(): void
  {
    $s = $this->makeFixtures();
    $this->expectCode(
      fn () => shareTask([
        'callerId' => 'ben',
        'taskId' => 't1',
        'email' => 'owner@desk.dev',
        'db' => $s['db'],
        'findUserByEmail' => $s['findUserByEmail'],
      ]),
      'forbidden',
    );
    self::assertSame([], $s['db']->lastUpdate);
  }

  public function testMapsMissingRowToNotFound(): void
  {
    $s = $this->makeFixtures();
    $s['db']->rejectNextGet();
    $this->expectCode(
      fn () => shareTask([
        'callerId' => 'owner',
        'taskId' => 'nope',
        'email' => 'ben@desk.dev',
        'db' => $s['db'],
        'findUserByEmail' => $s['findUserByEmail'],
      ]),
      'not_found',
    );
  }

  public function testReportsUnknownSelfAndDuplicateInvites(): void
  {
    $a = $this->makeFixtures();
    $this->expectCode(
      fn () => shareTask([
        'callerId' => 'owner',
        'taskId' => 't1',
        'email' => 'zoe@desk.dev',
        'db' => $a['db'],
        'findUserByEmail' => $a['findUserByEmail'],
      ]),
      'no_user',
    );

    $b = $this->makeFixtures();
    $this->expectCode(
      fn () => shareTask([
        'callerId' => 'owner',
        'taskId' => 't1',
        'email' => 'owner@desk.dev',
        'db' => $b['db'],
        'findUserByEmail' => $b['findUserByEmail'],
      ]),
      'self',
    );

    $c = $this->makeFixtures(['sharedWith' => ['ben'], 'sharedNames' => ['Ben Lima']]);
    $this->expectCode(
      fn () => shareTask([
        'callerId' => 'owner',
        'taskId' => 't1',
        'email' => 'ben@desk.dev',
        'db' => $c['db'],
        'findUserByEmail' => $c['findUserByEmail'],
      ]),
      'already',
    );
  }
}