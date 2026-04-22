<?php
declare(strict_types=1);

namespace LaenenAdminLock\Core\Lock;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Database-backed expiring edit lock for administration sessions.
 *
 * Public API surface is intentionally small: getStatus, acquire, heartbeat, release,
 * forceRelease, bulkStatus, listActive, bulkForeignLocks (used by the write subscriber).
 *
 * The service holds no per-request state other than two micro-optimisations:
 *  - $lastCleanupAt static throttles opportunistic cleanup to once per process per minute,
 *  - $userLabelCache caches user labels per request to avoid duplicate `user` lookups.
 */
final class RecordLockService
{
    public const HEADER_NAME = 'sw-lae-admin-lock-token';

    public const TTL_SECONDS = 1800; // 30 minutes
    public const HEARTBEAT_INTERVAL_SECONDS = 60;
    public const STATUS_POLL_INTERVAL_SECONDS = 15;

    public const PRIVILEGE_FORCE_UNLOCK = 'lae_admin_lock.force_unlock';
    public const PRIVILEGE_VIEWER = 'lae_admin_lock.viewer';

    private const TABLE = 'lae_admin_lock';
    private const CLEANUP_MIN_INTERVAL_SECONDS = 60.0;

    /** @var array<string, array{read: string, write: string}> */
    private const ENTITY_PRIVILEGES
        = [
            'customer' => ['read' => 'customer:read', 'write' => 'customer:update'],
            'order' => ['read' => 'order:read', 'write' => 'order:update'],
        ];

    private static ?float $lastCleanupAt = null;

    /** @var array<string, string> */
    private array $userLabelCache = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    // ---------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function getStatus(string $entityName, string $entityId, Context $context, ?string $ownerToken): array
    {
        $this->assertEntitySupported($entityName);
        $this->assertPrivilege($context, $entityName, 'read');
        $this->cleanupExpiredLocksThrottled();

        return $this->buildState(
            $entityName,
            $entityId,
            $context,
            $ownerToken,
            $this->fetchLockRow($entityName, $this->idBytes($entityId))
        );
    }

    /** @return array<string, mixed> */
    public function acquire(
        string $entityName,
        string $entityId,
        Context $context,
        ?string $ownerToken,
        ?string $note = null
    ): array {
        $this->assertEntitySupported($entityName);
        $this->assertPrivilege($context, $entityName, 'write');

        $ownerToken = $this->normalizeOwnerToken($ownerToken);
        $idBytes = $this->idBytes($entityId);
        $source = $this->getAdminApiSource($context);
        $userIdHex = $this->requireUserId($source);
        $userIdBytes = Uuid::fromHexToBytes($userIdHex);
        $userLabel = $this->resolveUserLabel($userIdBytes);

        $now = $this->utcNow();
        $expires = $now->modify('+' . self::TTL_SECONDS . ' seconds');
        $nowS = $this->fmt($now);
        $expiresS = $this->fmt($expires);
        $note = $note !== null ? mb_substr(trim($note), 0, 255) : null;

        $this->cleanupExpiredLocksThrottled();

        // Single-statement upsert:
        //   - INSERTs if no row,
        //   - takes over if the row is expired,
        //   - refreshes if we already own the row,
        //   - leaves the row untouched if a different live owner holds it.
        // We then SELECT to discover the actual owner.
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO `lae_admin_lock`
                (`entity_name`, `entity_id`, `owner_token`, `user_id`, `user_label`,
                 `note`, `locked_at`, `heartbeat_at`, `expires_at`)
            VALUES
                (:entityName, :entityId, :ownerToken, :userId, :userLabel,
                 :note, :now, :now, :expires)
            ON DUPLICATE KEY UPDATE
                `owner_token`  = IF(`expires_at` <= :now OR `owner_token` = :ownerToken OR `user_id` = :userId, VALUES(`owner_token`),  `owner_token`),
                `user_id`      = IF(`expires_at` <= :now OR `owner_token` = :ownerToken OR `user_id` = :userId, VALUES(`user_id`),      `user_id`),
                `user_label`   = IF(`expires_at` <= :now OR `owner_token` = :ownerToken OR `user_id` = :userId, VALUES(`user_label`),   `user_label`),
                `note`         = IF(`expires_at` <= :now OR `owner_token` = :ownerToken OR `user_id` = :userId, VALUES(`note`),         `note`),
                `locked_at`    = IF(`expires_at` <= :now OR (`user_id` = :userId AND `owner_token` <> :ownerToken), VALUES(`locked_at`), `locked_at`),
                `heartbeat_at` = IF(`expires_at` <= :now OR `owner_token` = :ownerToken OR `user_id` = :userId, VALUES(`heartbeat_at`), `heartbeat_at`),
                `expires_at`   = IF(`expires_at` <= :now OR `owner_token` = :ownerToken OR `user_id` = :userId, VALUES(`expires_at`),   `expires_at`)
            SQL,
            [
                'entityName' => $entityName,
                'entityId' => $idBytes,
                'ownerToken' => $ownerToken,
                'userId' => $userIdBytes,
                'userLabel' => $userLabel,
                'note' => $note,
                'now' => $nowS,
                'expires' => $expiresS,
            ],
            [
                'entityId' => ParameterType::BINARY,
                'userId' => ParameterType::BINARY,
            ]
        );

        return $this->buildState(
            $entityName,
            $entityId,
            $context,
            $ownerToken,
            $this->fetchLockRow($entityName, $idBytes)
        );
    }

    /** @return array<string, mixed> */
    public function heartbeat(string $entityName, string $entityId, Context $context, ?string $ownerToken): array
    {
        $this->assertEntitySupported($entityName);
        $this->assertPrivilege($context, $entityName, 'write');

        $ownerToken = $this->normalizeOwnerToken($ownerToken);
        $idBytes = $this->idBytes($entityId);
        $now = $this->utcNow();
        $expires = $now->modify('+' . self::TTL_SECONDS . ' seconds');

        // Pure UPDATE fast path. Affected rows = 0 means the lock was lost
        // (taken over, force-released or expired). The caller decides what to do.
        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE `lae_admin_lock`
            SET `heartbeat_at` = :now,
                `expires_at`   = :expires
            WHERE `entity_name` = :entityName
              AND `entity_id`   = :entityId
              AND `owner_token` = :ownerToken
              AND `expires_at`  > :now
            SQL,
            [
                'now' => $this->fmt($now),
                'expires' => $this->fmt($expires),
                'entityName' => $entityName,
                'entityId' => $idBytes,
                'ownerToken' => $ownerToken,
            ],
            ['entityId' => ParameterType::BINARY]
        );

        return $this->buildState(
            $entityName,
            $entityId,
            $context,
            $ownerToken,
            $this->fetchLockRow($entityName, $idBytes)
        );
    }

    /** @return array<string, mixed> */
    public function release(string $entityName, string $entityId, Context $context, ?string $ownerToken): array
    {
        $this->assertEntitySupported($entityName);
        $this->assertPrivilege($context, $entityName, 'write');

        $ownerToken = $this->normalizeOwnerToken($ownerToken);
        $idBytes = $this->idBytes($entityId);

        $this->connection->executeStatement(
            <<<'SQL'
            DELETE FROM `lae_admin_lock`
            WHERE `entity_name` = :entityName
              AND `entity_id`   = :entityId
              AND `owner_token` = :ownerToken
            SQL,
            [
                'entityName' => $entityName,
                'entityId' => $idBytes,
                'ownerToken' => $ownerToken,
            ],
            ['entityId' => ParameterType::BINARY]
        );

        return $this->buildState($entityName, $entityId, $context, $ownerToken, null);
    }

    /** @return array<string, mixed> */
    public function forceRelease(string $entityName, string $entityId, Context $context): array
    {
        $this->assertEntitySupported($entityName);

        $source = $this->getAdminApiSource($context);
        if (!$source->isAllowed(self::PRIVILEGE_FORCE_UNLOCK)) {
            throw new AccessDeniedHttpException(sprintf('Missing privilege "%s".', self::PRIVILEGE_FORCE_UNLOCK));
        }

        $idBytes = $this->idBytes($entityId);
        $this->connection->executeStatement(
            <<<'SQL'
            DELETE FROM `lae_admin_lock`
            WHERE `entity_name` = :entityName
              AND `entity_id`   = :entityId
            SQL,
            [
                'entityName' => $entityName,
                'entityId' => $idBytes,
            ],
            ['entityId' => ParameterType::BINARY]
        );

        return $this->buildState($entityName, $entityId, $context, null, null);
    }

    /**
     * Bulk status lookup for many ids of one or more entities. Used by list-view
     * indicators and by clients that want to batch-check multiple records.
     *
     * @param  array<string, list<string>>  $idsByEntity  ['customer' => [...hex], 'order' => [...hex]]
     *
     * @return array<string, array<string, array<string, mixed>>>  result[entity][hexId] = state
     */
    public function bulkStatus(array $idsByEntity, Context $context, ?string $ownerToken): array
    {
        $result = [];

        foreach ($idsByEntity as $entityName => $ids) {
            if (!isset(self::ENTITY_PRIVILEGES[$entityName])) {
                continue;
            }
            $this->assertPrivilege($context, $entityName, 'read');

            $result[$entityName] = [];
            $ids = array_values(array_filter($ids, [Uuid::class, 'isValid']));
            if ($ids === []) {
                continue;
            }

            $bytes = array_map(static fn(string $hex) => Uuid::fromHexToBytes($hex), $ids);

            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                SELECT `entity_name`, `entity_id`, `owner_token`, `user_id`, `user_label`,
                       `note`, `locked_at`, `heartbeat_at`, `expires_at`
                FROM `lae_admin_lock`
                WHERE `entity_name` = :entityName
                  AND `entity_id` IN (:ids)
                  AND `expires_at` > :now
                SQL,
                [
                    'entityName' => $entityName,
                    'ids' => $bytes,
                    'now' => $this->utcNowFormatted(),
                ],
                ['ids' => ArrayParameterType::BINARY]
            );

            $byHex = [];
            foreach ($rows as $row) {
                $byHex[Uuid::fromBytesToHex((string)$row['entity_id'])] = $row;
            }

            foreach ($ids as $hex) {
                $result[$entityName][$hex] = $this->buildState(
                    $entityName,
                    $hex,
                    $context,
                    $ownerToken,
                    $byHex[$hex] ?? null
                );
            }
        }

        return $result;
    }

    /**
     * Returns rows where another session (different owner_token) holds a live lock
     * for any of the given ids. Used by PreWriteValidationEvent subscriber.
     *
     * @param  array<string, list<string>>  $idsByEntity
     *
     * @return list<array<string, mixed>>
     */
    public function bulkForeignLocks(array $idsByEntity, string $ownerToken): array
    {
        $foreign = [];

        foreach ($idsByEntity as $entityName => $ids) {
            if (!isset(self::ENTITY_PRIVILEGES[$entityName]) || $ids === []) {
                continue;
            }

            $bytes = [];
            foreach ($ids as $hex) {
                if (Uuid::isValid($hex)) {
                    $bytes[] = Uuid::fromHexToBytes($hex);
                }
            }
            if ($bytes === []) {
                continue;
            }

            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                SELECT `entity_name`, `entity_id`, `owner_token`, `user_id`, `user_label`,
                       `note`, `locked_at`, `heartbeat_at`, `expires_at`
                FROM `lae_admin_lock`
                WHERE `entity_name` = :entityName
                  AND `entity_id` IN (:ids)
                  AND `expires_at` > :now
                  AND `owner_token` <> :ownerToken
                SQL,
                [
                    'entityName' => $entityName,
                    'ids' => $bytes,
                    'now' => $this->utcNowFormatted(),
                    'ownerToken' => $ownerToken,
                ],
                ['ids' => ArrayParameterType::BINARY]
            );

            foreach ($rows as $row) {
                $foreign[] = $row;
            }
        }

        return $foreign;
    }

    /**
     * Lists all currently active (non-expired) locks the caller is allowed to see.
     *
     * @return list<array<string, mixed>>
     */
    public function listActive(Context $context): array
    {
        $allowedEntities = [];
        foreach (array_keys(self::ENTITY_PRIVILEGES) as $entityName) {
            try {
                $this->assertPrivilege($context, $entityName, 'read');
                $allowedEntities[] = $entityName;
            } catch (AccessDeniedHttpException) {
                // Caller has no privilege for this entity, omit silently.
            }
        }

        if ($allowedEntities === []) {
            throw new AccessDeniedHttpException('No entity read privilege available for the lock overview.');
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT `entity_name`,
                   LOWER(HEX(`entity_id`)) AS `entity_id_hex`,
                   `owner_token`,
                   LOWER(HEX(`user_id`))   AS `user_id_hex`,
                   `user_label`, `note`,
                   `locked_at`, `heartbeat_at`, `expires_at`
            FROM `lae_admin_lock`
            WHERE `entity_name` IN (:entities)
              AND `expires_at` > :now
            ORDER BY `locked_at` DESC
            SQL,
            [
                'entities' => $allowedEntities,
                'now' => $this->utcNowFormatted(),
            ],
            ['entities' => ArrayParameterType::STRING]
        );

        $source = $this->getAdminApiSource($context);
        $canForce = $source->isAllowed(self::PRIVILEGE_FORCE_UNLOCK);

        return array_map(fn(array $r): array => [
            'entityName' => (string)$r['entity_name'],
            'entityId' => (string)$r['entity_id_hex'],
            'owner' => [
                'userId' => (string)$r['user_id_hex'],
                'label' => (string)$r['user_label'],
            ],
            'note' => $r['note'] !== null ? (string)$r['note'] : null,
            'lockedAt' => $this->isoFromStorage((string)$r['locked_at']),
            'heartbeatAt' => $this->isoFromStorage((string)$r['heartbeat_at']),
            'expiresAt' => $this->isoFromStorage((string)$r['expires_at']),
            'canForceRelease' => $canForce,
        ], $rows);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $row
     *
     * @return array<string, mixed>
     */
    private function buildState(
        string $entityName,
        string $entityId,
        Context $context,
        ?string $ownerToken,
        ?array $row
    ): array {
        $source = $context->getSource();
        $currentUserId = $source instanceof AdminApiSource ? $source->getUserId() : null;
        $canForce = $source instanceof AdminApiSource && $source->isAllowed(self::PRIVILEGE_FORCE_UNLOCK);

        $base = [
            'entityName' => $entityName,
            'entityId' => $entityId,
            'locked' => false,
            'ownedByCurrentSession' => false,
            'ownedByCurrentUser' => false,
            'owner' => null,
            'note' => null,
            'lockedAt' => null,
            'heartbeatAt' => null,
            'expiresAt' => null,
            'ttlSeconds' => self::TTL_SECONDS,
            'heartbeatIntervalSeconds' => self::HEARTBEAT_INTERVAL_SECONDS,
            'statusPollIntervalSeconds' => self::STATUS_POLL_INTERVAL_SECONDS,
            'canForceRelease' => $canForce,
        ];

        if ($row === null) {
            return $base;
        }

        $rowUserHex = Uuid::fromBytesToHex((string)$row['user_id']);
        $sameUser = $currentUserId !== null && hash_equals($rowUserHex, $currentUserId);
        $sameSession = $sameUser && $ownerToken !== null
            && hash_equals((string)$row['owner_token'], $ownerToken);

        return array_merge($base, [
            'locked' => true,
            'ownedByCurrentSession' => $sameSession,
            'ownedByCurrentUser' => $sameUser,
            'owner' => [
                'userId' => $rowUserHex,
                'label' => (string)($row['user_label'] ?? 'Another admin'),
            ],
            'note' => $row['note'] !== null ? (string)$row['note'] : null,
            'lockedAt' => $this->isoFromStorage((string)$row['locked_at']),
            'heartbeatAt' => $this->isoFromStorage((string)$row['heartbeat_at']),
            'expiresAt' => $this->isoFromStorage((string)$row['expires_at']),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function fetchLockRow(string $entityName, string $entityIdBytes): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT `entity_name`, `entity_id`, `owner_token`, `user_id`, `user_label`,
                   `note`, `locked_at`, `heartbeat_at`, `expires_at`
            FROM `lae_admin_lock`
            WHERE `entity_name` = :entityName
              AND `entity_id`   = :entityId
            LIMIT 1
            SQL,
            [
                'entityName' => $entityName,
                'entityId' => $entityIdBytes,
            ],
            ['entityId' => ParameterType::BINARY]
        );

        return $row === false ? null : $row;
    }

    private function cleanupExpiredLocksThrottled(): void
    {
        $now = microtime(true);
        if (self::$lastCleanupAt !== null && ($now - self::$lastCleanupAt) < self::CLEANUP_MIN_INTERVAL_SECONDS) {
            return;
        }
        self::$lastCleanupAt = $now;

        $this->connection->executeStatement(
            'DELETE FROM `lae_admin_lock` WHERE `expires_at` <= :now LIMIT 200',
            ['now' => $this->utcNowFormatted()]
        );
    }

    private function assertEntitySupported(string $entityName): void
    {
        if (!isset(self::ENTITY_PRIVILEGES[$entityName])) {
            throw new BadRequestHttpException(sprintf('Unsupported lock entity "%s".', $entityName));
        }
    }

    private function assertPrivilege(Context $context, string $entityName, string $kind): void
    {
        $source = $this->getAdminApiSource($context);
        $privilege = self::ENTITY_PRIVILEGES[$entityName][$kind] ?? null;

        if ($privilege === null || !$source->isAllowed($privilege)) {
            throw new AccessDeniedHttpException(sprintf('Missing privilege "%s".', (string)$privilege));
        }
    }

    private function getAdminApiSource(Context $context): AdminApiSource
    {
        $source = $context->getSource();
        if (!$source instanceof AdminApiSource) {
            throw new AccessDeniedHttpException('Edit locks are only available in administration context.');
        }

        return $source;
    }

    private function requireUserId(AdminApiSource $source): string
    {
        $userId = $source->getUserId();
        if ($userId === null || !Uuid::isValid($userId)) {
            throw new AccessDeniedHttpException('The current administration user could not be resolved.');
        }

        return $userId;
    }

    private function normalizeOwnerToken(?string $token): string
    {
        $token = trim((string)$token);
        if ($token === '') {
            throw new BadRequestHttpException(sprintf('Missing "%s" header.', self::HEADER_NAME));
        }
        if (mb_strlen($token) > 96) {
            throw new BadRequestHttpException('Edit lock owner token is too long.');
        }

        return $token;
    }

    private function idBytes(string $entityId): string
    {
        if (!Uuid::isValid($entityId)) {
            throw new BadRequestHttpException(sprintf('Invalid entity id "%s".', $entityId));
        }

        return Uuid::fromHexToBytes($entityId);
    }

    private function resolveUserLabel(string $userIdBytes): string
    {
        $key = bin2hex($userIdBytes);
        if (isset($this->userLabelCache[$key])) {
            return $this->userLabelCache[$key];
        }

        $user = $this->connection->fetchAssociative(
            'SELECT `first_name`, `last_name`, `username` FROM `user` WHERE `id` = :id LIMIT 1',
            ['id' => $userIdBytes],
            ['id' => ParameterType::BINARY]
        );

        $label = 'Another admin';
        if ($user !== false) {
            $full = trim(sprintf('%s %s', (string)($user['first_name'] ?? ''), (string)($user['last_name'] ?? '')));
            $username = trim((string)($user['username'] ?? ''));
            if ($full !== '' && $username !== '') {
                $label = sprintf('%s (%s)', $full, $username);
            } elseif ($full !== '') {
                $label = $full;
            } elseif ($username !== '') {
                $label = $username;
            }
        }

        return $this->userLabelCache[$key] = $label;
    }

    private function utcNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function utcNowFormatted(): string
    {
        return $this->fmt($this->utcNow());
    }

    private function fmt(\DateTimeImmutable $dt): string
    {
        return $dt->format('Y-m-d H:i:s.v');
    }

    private function isoFromStorage(string $stored): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $stored, new \DateTimeZone('UTC'))
            ?: new \DateTimeImmutable($stored, new \DateTimeZone('UTC'));

        return $dt->format(DATE_ATOM);
    }
}
