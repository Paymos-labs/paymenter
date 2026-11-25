<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

final class PdoDbDriver implements DbDriverInterface
{
    /** @var array<string, list<string>> */
    private const ALLOWED_TABLES = [
        PdoEventStore::TABLE => [
            'event_id',
            'owner_token',
            'state',
            'expires_at',
            'created_at',
        ],
        SnapshotStore::TABLE => [
            'external_order_id',
            'paymos_invoice_id',
            'platform_ref',
            'client_id',
            'payload_meta',
            'amount',
            'invoice_total',
            'currency',
            'environment',
            'project_id',
            'payment_url',
            'transaction_id',
            'expires_at',
            'renewal_attempt',
            'status',
            'paid_late_noted',
            'created_at',
            'updated_at',
        ],
        CredentialStore::TABLE => [
            'setting_key',
            'setting_value',
            'updated_at',
        ],
        RefundIntentStore::TABLE => [
            'operation_id',
            'installation_id',
            'request_token',
            'external_order_id',
            'native_payment_key',
            'withdrawal_id',
            'payload',
            'version',
            'created_at',
            'next_check',
        ],
        RefundIntentStore::EXTERNAL_TABLE => [
            'withdrawal_id',
            'native_payment_key',
            'payload',
            'updated_at',
        ],
    ];

    /** @var array<string, string> */
    private const PRIMARY_KEYS = [
        PdoEventStore::TABLE => 'event_id',
        SnapshotStore::TABLE => 'external_order_id',
        CredentialStore::TABLE => 'setting_key',
        RefundIntentStore::TABLE => 'operation_id',
        RefundIntentStore::EXTERNAL_TABLE => 'withdrawal_id',
    ];

    /** @var array<string, int> Process-local fallback locks for drivers without native advisory locks (e.g. SQLite). */
    private static array $localLocks = [];

    private \PDO $pdo;
    private string $driver;
    private bool $schemaEnsured = false;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $driverName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->driver = is_string($driverName) ? strtolower($driverName) : 'mysql';
    }

    public static function fromLaravel(): self
    {
        if (!class_exists(\Illuminate\Support\Facades\DB::class)) {
            throw new \RuntimeException('Laravel DB facade is not available for Paymos database storage.');
        }

        $connection = \Illuminate\Support\Facades\DB::connection();
        if (!is_object($connection) || !method_exists($connection, 'getPdo')) {
            throw new \RuntimeException('Laravel DB connection does not expose getPdo().');
        }

        $pdo = $connection->getPdo();
        if (!$pdo instanceof \PDO) {
            throw new \RuntimeException('Laravel DB connection did not return a PDO instance.');
        }

        return new self($pdo);
    }

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        // Read-only metadata check first: emit zero DDL statements when tables already exist
        // (prevents MySQL/MariaDB implicit commits inside active host transactions such as
        // Paymenter's ExtensionHelper::refund() DB::transaction()).
        if ($this->tablesExist()) {
            $this->schemaEnsured = true;
            return;
        }

        if ($this->pdo->inTransaction()) {
            throw new \RuntimeException(
                'Paymos database tables are not initialized and cannot be created inside an active database transaction.'
            );
        }

        if ($this->driver === 'mysql') {
            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS ' . PdoEventStore::TABLE . ' ('
                . 'event_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'owner_token VARCHAR(64) NOT NULL, '
                . 'state VARCHAR(16) NOT NULL, '
                . 'expires_at BIGINT UNSIGNED NOT NULL, '
                . 'created_at BIGINT UNSIGNED NOT NULL, '
                . 'INDEX idx_paymos_events_expires_at (expires_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS ' . SnapshotStore::TABLE . ' ('
                . 'external_order_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'paymos_invoice_id VARCHAR(128) NOT NULL, '
                . 'platform_ref VARCHAR(128) NOT NULL, '
                . 'client_id VARCHAR(64) NULL, '
                . 'payload_meta TEXT NULL, '
                . 'amount VARCHAR(64) NOT NULL, '
                . 'invoice_total VARCHAR(64) NULL, '
                . 'currency VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, '
                . 'project_id VARCHAR(64) NOT NULL, '
                . 'payment_url TEXT NOT NULL, '
                . 'transaction_id VARCHAR(128) NULL, '
                . 'expires_at BIGINT UNSIGNED NULL, '
                . 'renewal_attempt INT UNSIGNED NOT NULL DEFAULT 0, '
                . 'status VARCHAR(32) NOT NULL DEFAULT \'creating\', '
                . 'paid_late_noted VARCHAR(128) NULL, '
                . 'created_at BIGINT UNSIGNED NOT NULL, '
                . 'updated_at BIGINT UNSIGNED NOT NULL, '
                . 'INDEX idx_paymos_snapshots_invoice_id (paymos_invoice_id), '
                . 'INDEX idx_paymos_snapshots_platform_ref (platform_ref), '
                . 'INDEX idx_paymos_snapshots_transaction_id (transaction_id), '
                . 'INDEX idx_paymos_snapshots_created_at (created_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS ' . CredentialStore::TABLE . ' ('
                . 'setting_key VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'setting_value LONGTEXT NOT NULL, '
                . 'updated_at BIGINT UNSIGNED NOT NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS ' . RefundIntentStore::TABLE . ' ('
                . 'operation_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, '
                . 'installation_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'request_token VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'external_order_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'native_payment_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'withdrawal_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'payload LONGTEXT NOT NULL, '
                . 'version BIGINT UNSIGNED NOT NULL, '
                . 'created_at BIGINT UNSIGNED NOT NULL, '
                . 'next_check BIGINT UNSIGNED NOT NULL, '
                . 'UNIQUE KEY uq_paymos_refund_request_token (installation_id, request_token), '
                . 'UNIQUE KEY uq_paymos_refund_external_order_id (external_order_id), '
                . 'UNIQUE KEY uq_paymos_refund_withdrawal_id (withdrawal_id), '
                . 'INDEX idx_paymos_refund_payment_key (native_payment_key), '
                . 'INDEX idx_paymos_refund_due (next_check, operation_id)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS ' . RefundIntentStore::EXTERNAL_TABLE . ' ('
                . 'withdrawal_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, '
                . 'native_payment_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'payload LONGTEXT NOT NULL, '
                . 'updated_at BIGINT UNSIGNED NOT NULL, '
                . 'INDEX idx_paymos_refund_ext_payment_key (native_payment_key)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } else {
            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS ' . PdoEventStore::TABLE . ' ('
                . 'event_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'owner_token VARCHAR(64) NOT NULL, '
                . 'state VARCHAR(16) NOT NULL, '
                . 'expires_at BIGINT NOT NULL, '
                . 'created_at BIGINT NOT NULL'
                . ')'
            );
            $this->execDdl(
                'CREATE INDEX IF NOT EXISTS idx_paymos_events_expires_at ON ' . PdoEventStore::TABLE . ' (expires_at)'
            );

            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS ' . SnapshotStore::TABLE . ' ('
                . 'external_order_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'paymos_invoice_id VARCHAR(128) NOT NULL, '
                . 'platform_ref VARCHAR(128) NOT NULL, '
                . 'client_id VARCHAR(64) NULL, '
                . 'payload_meta TEXT NULL, '
                . 'amount VARCHAR(64) NOT NULL, '
                . 'invoice_total VARCHAR(64) NULL, '
                . 'currency VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, '
                . 'project_id VARCHAR(64) NOT NULL, '
                . 'payment_url TEXT NOT NULL, '
                . 'transaction_id VARCHAR(128) NULL, '
                . 'expires_at BIGINT NULL, '
                . 'renewal_attempt INTEGER NOT NULL DEFAULT 0, '
                . 'status VARCHAR(32) NOT NULL DEFAULT \'creating\', '
                . 'paid_late_noted VARCHAR(128) NULL, '
                . 'created_at BIGINT NOT NULL, '
                . 'updated_at BIGINT NOT NULL'
                . ')'
            );
            $this->execDdl(
                'CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_invoice_id ON ' . SnapshotStore::TABLE . ' (paymos_invoice_id)'
            );
            $this->execDdl(
                'CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_platform_ref ON ' . SnapshotStore::TABLE . ' (platform_ref)'
            );
            $this->execDdl(
                'CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_transaction_id ON ' . SnapshotStore::TABLE . ' (transaction_id)'
            );
            $this->execDdl(
                'CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_created_at ON ' . SnapshotStore::TABLE . ' (created_at)'
            );

            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS ' . CredentialStore::TABLE . ' ('
                . 'setting_key VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'setting_value TEXT NOT NULL, '
                . 'updated_at BIGINT NOT NULL'
                . ')'
            );

            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS ' . RefundIntentStore::TABLE . ' ('
                . 'operation_id VARCHAR(36) NOT NULL PRIMARY KEY, '
                . 'installation_id VARCHAR(36) NOT NULL, '
                . 'request_token VARCHAR(64) NOT NULL, '
                . 'external_order_id VARCHAR(200) NOT NULL UNIQUE, '
                . 'native_payment_key VARCHAR(64) NOT NULL, '
                . 'withdrawal_id VARCHAR(128) NULL UNIQUE, '
                . 'payload TEXT NOT NULL, '
                . 'version BIGINT NOT NULL, '
                . 'created_at BIGINT NOT NULL, '
                . 'next_check BIGINT NOT NULL, '
                . 'UNIQUE (installation_id, request_token)'
                . ')'
            );
            $this->execDdl(
                'CREATE INDEX IF NOT EXISTS idx_paymos_refund_payment_key ON ' . RefundIntentStore::TABLE . ' (native_payment_key)'
            );
            $this->execDdl(
                'CREATE INDEX IF NOT EXISTS idx_paymos_refund_due ON ' . RefundIntentStore::TABLE . ' (next_check, operation_id)'
            );

            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS ' . RefundIntentStore::EXTERNAL_TABLE . ' ('
                . 'withdrawal_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'native_payment_key VARCHAR(64) NOT NULL, '
                . 'payload TEXT NOT NULL, '
                . 'updated_at BIGINT NOT NULL'
                . ')'
            );
            $this->execDdl(
                'CREATE INDEX IF NOT EXISTS idx_paymos_refund_ext_payment_key ON ' . RefundIntentStore::EXTERNAL_TABLE . ' (native_payment_key)'
            );
        }

        $this->schemaEnsured = true;
    }

    public function insertUnique(string $table, array $row, string $primaryKey): bool
    {
        $this->assertTable($table);
        $this->assertColumn($table, $primaryKey);
        if ($row === []) {
            throw new \InvalidArgumentException('Insert row must not be empty.');
        }

        $columns = array_keys($row);
        foreach ($columns as $column) {
            $this->assertColumn($table, $column);
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        // Conflict-safe SQL avoids raising 23505 inside active PostgreSQL transactions.
        if ($this->driver === 'mysql') {
            $sql = sprintf(
                'INSERT IGNORE INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', $columns),
                $placeholders
            );
        } else {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT DO NOTHING',
                $table,
                implode(', ', $columns),
                $placeholders
            );
        }

        $stmt = $this->prepare($sql);
        if ($stmt->execute(array_values($row)) === false) {
            throw new \RuntimeException('Paymos database insert failed.');
        }

        return $stmt->rowCount() === 1;
    }

    public function upsert(string $table, array $row, string $primaryKey): void
    {
        $this->assertTable($table);
        $this->assertColumn($table, $primaryKey);
        if ($row === []) {
            throw new \InvalidArgumentException('Upsert row must not be empty.');
        }

        $columns = array_keys($row);
        foreach ($columns as $column) {
            $this->assertColumn($table, $column);
        }

        $updateColumns = array_values(array_filter(
            $columns,
            static fn (string $col): bool => $col !== $primaryKey
        ));

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        if ($this->driver === 'mysql') {
            $assignments = array_map(
                static fn (string $col): string => $col . ' = VALUES(' . $col . ')',
                $updateColumns
            );
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                $table,
                implode(', ', $columns),
                $placeholders,
                implode(', ', $assignments)
            );
        } else {
            $assignments = array_map(
                static fn (string $col): string => $col . ' = EXCLUDED.' . $col,
                $updateColumns
            );
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
                $table,
                implode(', ', $columns),
                $placeholders,
                $primaryKey,
                implode(', ', $assignments)
            );
        }

        $stmt = $this->prepare($sql);
        if ($stmt->execute(array_values($row)) === false) {
            throw new \RuntimeException('Paymos database upsert failed.');
        }
    }

    public function selectOne(string $table, array $where, ?string $orderBy = null): ?array
    {
        $rows = $this->selectAll($table, $where, $orderBy, 1);

        return $rows[0] ?? null;
    }

    public function selectAll(
        string $table,
        array $where,
        ?string $orderBy = null,
        ?int $limit = null,
        array $gteWhere = [],
        array $lteWhere = [],
        array $gtWhere = []
    ): array {
        $this->assertTable($table);
        [$whereSql, $params] = $this->buildWhere($table, $where, [], $lteWhere, $gteWhere, $gtWhere);
        $sql = 'SELECT * FROM ' . $table;
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }
        if ($orderBy !== null && trim($orderBy) !== '') {
            $sql .= ' ORDER BY ' . $this->sanitizeOrderBy($table, $orderBy);
        }
        if ($limit !== null) {
            if ($limit < 1) {
                return [];
            }
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $this->prepare($sql);
        if ($stmt->execute($params) === false) {
            throw new \RuntimeException('Paymos database select failed.');
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? array_values($rows) : [];
    }

    public function updateWhere(string $table, array $values, array $where, array $lteWhere = []): int
    {
        $this->assertTable($table);
        if ($values === []) {
            throw new \InvalidArgumentException('Update values must not be empty.');
        }

        $setClauses = [];
        $params = [];
        foreach ($values as $column => $value) {
            $this->assertColumn($table, $column);
            $setClauses[] = $column . ' = ?';
            $params[] = $value;
        }

        [$whereSql, $whereParams] = $this->buildWhere($table, $where, [], $lteWhere, [], []);
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setClauses);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        $stmt = $this->prepare($sql);
        if ($stmt->execute(array_merge($params, $whereParams)) === false) {
            throw new \RuntimeException('Paymos database update failed.');
        }

        return $stmt->rowCount();
    }

    public function deleteWhere(
        string $table,
        array $where,
        array $ltWhere = [],
        ?int $limit = null,
        array $lteWhere = []
    ): int {
        $this->assertTable($table);
        [$whereSql, $params] = $this->buildWhere($table, $where, $ltWhere, $lteWhere, [], []);

        if ($limit !== null) {
            if ($limit < 1) {
                return 0;
            }
            if ($this->driver === 'mysql') {
                $sql = 'DELETE FROM ' . $table;
                if ($whereSql !== '') {
                    $sql .= ' WHERE ' . $whereSql;
                }
                $sql .= ' LIMIT ' . $limit;
            } else {
                $pk = self::PRIMARY_KEYS[$table];
                $subquery = 'SELECT ' . $pk . ' FROM ' . $table;
                if ($whereSql !== '') {
                    $subquery .= ' WHERE ' . $whereSql;
                }
                $subquery .= ' LIMIT ' . $limit;
                $sql = 'DELETE FROM ' . $table . ' WHERE ' . $pk . ' IN (' . $subquery . ')';
            }
        } else {
            $sql = 'DELETE FROM ' . $table;
            if ($whereSql !== '') {
                $sql .= ' WHERE ' . $whereSql;
            }
        }

        $stmt = $this->prepare($sql);
        if ($stmt->execute($params) === false) {
            throw new \RuntimeException('Paymos database delete failed.');
        }

        return $stmt->rowCount();
    }

    public function acquireNamedLock(string $name, int $timeoutSeconds = 10): bool
    {
        if ($this->driver === 'mysql') {
            $stmt = $this->prepare('SELECT GET_LOCK(?, ?)');
            if ($stmt->execute([$name, max(0, $timeoutSeconds)]) === false) {
                return false;
            }

            return (int) $stmt->fetchColumn() === 1;
        }

        if ($this->driver === 'pgsql') {
            [$k1, $k2] = self::pgAdvisoryKeys($name);
            $deadline = microtime(true) + max(0, $timeoutSeconds);
            do {
                $stmt = $this->prepare('SELECT pg_try_advisory_lock(?, ?)');
                if ($stmt->execute([$k1, $k2]) !== false && (bool) $stmt->fetchColumn()) {
                    return true;
                }
                if (microtime(true) >= $deadline) {
                    return false;
                }
                usleep(50000);
            } while (true);
        }

        $owner = spl_object_id($this->pdo);
        if (isset(self::$localLocks[$name]) && self::$localLocks[$name] !== $owner) {
            return false;
        }
        self::$localLocks[$name] = $owner;

        return true;
    }

    public function ownsNamedLock(string $name): bool
    {
        if ($this->driver === 'mysql') {
            $stmt = $this->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
            if ($stmt->execute([$name]) === false) {
                return false;
            }

            return (int) $stmt->fetchColumn() === 1;
        }

        if ($this->driver === 'pgsql') {
            [$k1, $k2] = self::pgAdvisoryKeys($name);
            $stmt = $this->prepare(
                "SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype = 'advisory' "
                . 'AND pid = pg_backend_pid() AND classid = ? AND objid = ? AND granted)'
            );
            if ($stmt->execute([$k1, $k2]) === false) {
                return false;
            }

            return (bool) $stmt->fetchColumn();
        }

        return isset(self::$localLocks[$name]) && self::$localLocks[$name] === spl_object_id($this->pdo);
    }

    public function releaseNamedLock(string $name): void
    {
        if ($this->driver === 'mysql') {
            $stmt = $this->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$name]);
            return;
        }

        if ($this->driver === 'pgsql') {
            [$k1, $k2] = self::pgAdvisoryKeys($name);
            $stmt = $this->prepare('SELECT pg_advisory_unlock(?, ?)');
            $stmt->execute([$k1, $k2]);
            return;
        }

        if (isset(self::$localLocks[$name]) && self::$localLocks[$name] === spl_object_id($this->pdo)) {
            unset(self::$localLocks[$name]);
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->beginTransaction()) {
            throw new \RuntimeException('Failed to start Paymos database transaction.');
        }
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function pgAdvisoryKeys(string $name): array
    {
        $hash = hash('sha256', $name, true);
        $parts = unpack('N2', substr($hash, 0, 8));
        $k1 = (int) ($parts[1] ?? 0);
        $k2 = (int) ($parts[2] ?? 0);
        if ($k1 >= 0x80000000) {
            $k1 -= 0x100000000;
        }
        if ($k2 >= 0x80000000) {
            $k2 -= 0x100000000;
        }

        return [$k1, $k2];
    }

    /**
     * @param array<string, scalar|null> $where
     * @param array<string, scalar>      $ltWhere
     * @param array<string, scalar>      $lteWhere
     * @param array<string, scalar>      $gteWhere
     * @param array<string, scalar>      $gtWhere
     * @return array{0: string, 1: list<scalar>}
     */
    private function buildWhere(
        string $table,
        array $where,
        array $ltWhere,
        array $lteWhere,
        array $gteWhere,
        array $gtWhere
    ): array {
        $clauses = [];
        $params = [];

        foreach ($where as $column => $value) {
            $this->assertColumn($table, $column);
            if ($value === null) {
                $clauses[] = $column . ' IS NULL';
            } else {
                $clauses[] = $column . ' = ?';
                $params[] = $value;
            }
        }

        foreach ($ltWhere as $column => $value) {
            $this->assertColumn($table, $column);
            $clauses[] = $column . ' < ?';
            $params[] = $value;
        }

        foreach ($lteWhere as $column => $value) {
            $this->assertColumn($table, $column);
            $clauses[] = $column . ' <= ?';
            $params[] = $value;
        }

        foreach ($gteWhere as $column => $value) {
            $this->assertColumn($table, $column);
            $clauses[] = $column . ' >= ?';
            $params[] = $value;
        }

        foreach ($gtWhere as $column => $value) {
            $this->assertColumn($table, $column);
            $clauses[] = $column . ' > ?';
            $params[] = $value;
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function sanitizeOrderBy(string $table, string $orderBy): string
    {
        $parts = array_filter(array_map('trim', explode(',', $orderBy)), static fn (string $p): bool => $p !== '');
        if ($parts === []) {
            throw new \InvalidArgumentException('Invalid ORDER BY clause.');
        }

        $safe = [];
        foreach ($parts as $part) {
            $tokens = preg_split('/\s+/', $part);
            if (!is_array($tokens) || count($tokens) < 1 || count($tokens) > 2) {
                throw new \InvalidArgumentException('Invalid ORDER BY segment.');
            }
            $column = $tokens[0];
            $this->assertColumn($table, $column);
            $direction = isset($tokens[1]) ? strtoupper($tokens[1]) : 'ASC';
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                throw new \InvalidArgumentException('Invalid ORDER BY direction.');
            }
            $safe[] = $column . ' ' . $direction;
        }

        return implode(', ', $safe);
    }

    private function assertTable(string $table): void
    {
        if (!isset(self::ALLOWED_TABLES[$table])) {
            throw new \InvalidArgumentException('Unknown Paymos database table: ' . $table);
        }
    }

    private function assertColumn(string $table, string $column): void
    {
        if (!in_array($column, self::ALLOWED_TABLES[$table], true)) {
            throw new \InvalidArgumentException('Unknown Paymos database column: ' . $column);
        }
    }

    private function prepare(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare Paymos database statement.');
        }

        return $stmt;
    }

    private function execDdl(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new \RuntimeException('Failed to initialize Paymos database schema.');
        }
    }

    private function tablesExist(): bool
    {
        $tables = array_keys(self::ALLOWED_TABLES);
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));

        if ($this->driver === 'mysql') {
            $sql = 'SELECT COUNT(DISTINCT table_name) FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name IN (' . $placeholders . ')';
        } elseif ($this->driver === 'pgsql') {
            $sql = 'SELECT COUNT(DISTINCT table_name) FROM information_schema.tables '
                . 'WHERE table_schema = ANY(current_schemas(false)) AND table_name IN (' . $placeholders . ')';
        } else {
            $sql = "SELECT COUNT(DISTINCT name) FROM sqlite_master WHERE type = 'table' AND name IN (" . $placeholders . ')';
        }

        $stmt = $this->prepare($sql);
        if ($stmt->execute($tables) === false) {
            throw new \RuntimeException('Failed to inspect Paymos database schema metadata.');
        }

        return (int) $stmt->fetchColumn() === count($tables);
    }
}
