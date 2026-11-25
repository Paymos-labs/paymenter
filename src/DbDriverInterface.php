<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

interface DbDriverInterface
{
    public function ensureSchema(): void;

    /**
     * Atomically insert a row if its unique/primary keys do not conflict.
     *
     * @param array<string, scalar|null> $row
     * @return bool True if inserted; false on unique/primary key conflict.
     */
    public function insertUnique(string $table, array $row, string $primaryKey): bool;

    /**
     * Atomically insert or update a row keyed by primary key.
     *
     * @param array<string, scalar|null> $row
     */
    public function upsert(string $table, array $row, string $primaryKey): void;

    /**
     * @param array<string, scalar|null> $where
     * @return array<string, mixed>|null
     */
    public function selectOne(string $table, array $where, ?string $orderBy = null): ?array;

    /**
     * @param array<string, scalar|null> $where
     * @param array<string, scalar>      $gteWhere
     * @param array<string, scalar>      $lteWhere
     * @param array<string, scalar>      $gtWhere
     * @return list<array<string, mixed>>
     */
    public function selectAll(
        string $table,
        array $where,
        ?string $orderBy = null,
        ?int $limit = null,
        array $gteWhere = [],
        array $lteWhere = [],
        array $gtWhere = []
    ): array;

    /**
     * Update rows matching exact equality ($where) and less-than-or-equal ($lteWhere) predicates.
     *
     * @param array<string, scalar|null> $values
     * @param array<string, scalar|null> $where
     * @param array<string, scalar>      $lteWhere
     * @return int Number of affected rows.
     */
    public function updateWhere(string $table, array $values, array $where, array $lteWhere = []): int;

    /**
     * Delete rows matching exact equality ($where), strictly less-than ($ltWhere),
     * and less-than-or-equal ($lteWhere) predicates, optionally bounded to $limit rows.
     *
     * @param array<string, scalar|null> $where
     * @param array<string, scalar>      $ltWhere
     * @param array<string, scalar>      $lteWhere
     * @return int Number of deleted rows.
     */
    public function deleteWhere(
        string $table,
        array $where,
        array $ltWhere = [],
        ?int $limit = null,
        array $lteWhere = []
    ): int;

    /**
     * Acquire a connection-scoped advisory lock (e.g. MySQL GET_LOCK / PostgreSQL pg_try_advisory_lock).
     */
    public function acquireNamedLock(string $name, int $timeoutSeconds = 10): bool;

    /**
     * Verify this connection still holds the advisory lock.
     */
    public function ownsNamedLock(string $name): bool;

    /**
     * Release the advisory lock if held by this connection.
     */
    public function releaseNamedLock(string $name): void;

    public function inTransaction(): bool;

    public function beginTransaction(): void;

    public function commit(): bool;

    public function rollBack(): void;
}
