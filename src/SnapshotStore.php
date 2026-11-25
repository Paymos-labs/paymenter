<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\StatusMapper;

final class SnapshotStore
{
    public const TABLE = 'paymos_invoice_snapshots';
    public const STATUS_CREATING = 'creating';
    public const STATUS_INVOICE_MISSING = 'paymenter_invoice_missing';

    private DbDriverInterface $db;

    /** @var callable */
    private $clock;

    public function __construct(DbDriverInterface $db, ?callable $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?: static fn (): int => time();
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function save(array $snapshot): void
    {
        $externalOrderId = trim((string) ($snapshot['external_order_id'] ?? ''));
        if ($externalOrderId === '') {
            throw new \InvalidArgumentException('Snapshot external_order_id is required.');
        }

        $this->db->ensureSchema();
        $now = $this->now();
        $existing = $this->db->selectOne(self::TABLE, ['external_order_id' => $externalOrderId]);

        $status = self::nullableString($snapshot['status'] ?? null)
            ?? self::nullableString($snapshot['final_status'] ?? null)
            ?? self::STATUS_CREATING;
        $paidLateNoted = self::nullableString($snapshot['paid_late_noted'] ?? null)
            ?? self::nullableString($existing['paid_late_noted'] ?? null);

        $row = [
            'external_order_id' => $externalOrderId,
            'paymos_invoice_id' => trim((string) ($snapshot['paymos_invoice_id'] ?? '')),
            'platform_ref' => trim((string) ($snapshot['platform_ref'] ?? '')),
            'client_id' => self::nullableString($snapshot['client_id'] ?? null),
            'payload_meta' => self::nullableString($snapshot['payload_meta'] ?? ($existing['payload_meta'] ?? null)),
            'amount' => trim((string) ($snapshot['amount'] ?? '')),
            'invoice_total' => self::nullableString($snapshot['invoice_total'] ?? ($existing['invoice_total'] ?? null)),
            'currency' => strtoupper(trim((string) ($snapshot['currency'] ?? ''))),
            'environment' => strtolower(trim((string) ($snapshot['environment'] ?? ''))),
            'project_id' => trim((string) ($snapshot['project_id'] ?? '')),
            'payment_url' => trim((string) ($snapshot['payment_url'] ?? '')),
            'transaction_id' => self::nullableString($snapshot['transaction_id'] ?? ($existing['transaction_id'] ?? null)),
            'expires_at' => self::nullableInt($snapshot['expires_at'] ?? null),
            'renewal_attempt' => max(0, (int) ($snapshot['renewal_attempt'] ?? 0)),
            'status' => strtolower($status),
            'paid_late_noted' => $paidLateNoted,
            'created_at' => isset($snapshot['created_at']) && is_numeric($snapshot['created_at'])
                ? (int) $snapshot['created_at']
                : (isset($existing['created_at']) && is_numeric($existing['created_at']) ? (int) $existing['created_at'] : $now),
            'updated_at' => isset($snapshot['updated_at']) && is_numeric($snapshot['updated_at'])
                ? (int) $snapshot['updated_at']
                : $now,
        ];

        $this->db->upsert(self::TABLE, $row, 'external_order_id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByExternalOrderId(string $externalOrderId): ?array
    {
        $externalOrderId = trim($externalOrderId);
        if ($externalOrderId === '') {
            return null;
        }

        $this->db->ensureSchema();

        return $this->db->selectOne(self::TABLE, ['external_order_id' => $externalOrderId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPaymosInvoiceId(string $paymosInvoiceId): ?array
    {
        $paymosInvoiceId = trim($paymosInvoiceId);
        if ($paymosInvoiceId === '') {
            return null;
        }

        $this->db->ensureSchema();

        return $this->db->selectOne(self::TABLE, ['paymos_invoice_id' => $paymosInvoiceId], 'updated_at DESC');
    }

    /**
     * Unambiguous snapshot lookup by confirmed transaction_id.
     *
     * @return array<string, mixed>|null
     */
    public function findByTransactionId(string $transactionId): ?array
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            return null;
        }

        $this->db->ensureSchema();

        return $this->db->selectOne(self::TABLE, ['transaction_id' => $transactionId], 'updated_at DESC');
    }

    /**
     * Latest snapshot for a Paymenter invoice regardless of amount/env/project.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestForPlatformRef(
        string $platformRef,
        ?string $amount = null,
        ?string $currency = null,
        ?string $environment = null,
        ?string $projectId = null
    ): ?array {
        $platformRef = trim($platformRef);
        if ($platformRef === '') {
            return null;
        }

        $this->db->ensureSchema();
        $where = ['platform_ref' => $platformRef];
        if ($environment !== null && trim($environment) !== '') {
            $where['environment'] = strtolower(trim($environment));
        }
        if ($projectId !== null && trim($projectId) !== '') {
            $where['project_id'] = trim($projectId);
        }

        $rows = $this->db->selectAll(self::TABLE, $where, 'renewal_attempt DESC, updated_at DESC');
        $targetCurrency = $currency !== null ? strtoupper(trim($currency)) : null;

        foreach ($rows as $row) {
            if ($amount !== null && !AmountGuard::amountsEqual($row['amount'] ?? '', $amount)) {
                continue;
            }
            if ($targetCurrency !== null && strtoupper(trim((string) ($row['currency'] ?? ''))) !== $targetCurrency) {
                continue;
            }
            return $row;
        }

        return null;
    }

    /**
     * Return all paid snapshots associated with a Paymenter invoice id.
     *
     * @return list<array<string, mixed>>
     */
    public function findPaidByPlatformRef(string $platformRef): array
    {
        $platformRef = trim($platformRef);
        if ($platformRef === '') {
            return [];
        }

        $this->db->ensureSchema();
        $rows = $this->db->selectAll(self::TABLE, ['platform_ref' => $platformRef], 'updated_at DESC');

        $paid = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (StatusMapper::invoiceAction('', $status) === StatusMapper::ACTION_PAYMENT_COMPLETE) {
                $paid[] = $row;
            }
        }

        return $paid;
    }

    /**
     * Return up to $limit non-final snapshots created at or after $sinceTimestamp (for 24h reconciler sweep).
     *
     * @return list<array<string, mixed>>
     */
    public function findUnpaidRecent(int $limit = 50, int $sinceTimestamp = 0): array
    {
        $this->db->ensureSchema();
        $rows = $this->db->selectAll(
            self::TABLE,
            [],
            'created_at DESC, updated_at DESC',
            null,
            ['created_at' => max(0, $sinceTimestamp)]
        );

        $unpaid = [];
        foreach ($rows as $row) {
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if ($status === self::STATUS_CREATING || $status === self::STATUS_INVOICE_MISSING) {
                continue;
            }
            if (StatusMapper::isFinalStatus($status)) {
                continue;
            }
            $unpaid[] = $row;
            if (count($unpaid) >= max(1, $limit)) {
                break;
            }
        }

        return $unpaid;
    }

    public function markCompleted(string $externalOrderId, string $transactionId, string $finalStatus): void
    {
        $this->db->ensureSchema();
        $this->db->updateWhere(
            self::TABLE,
            [
                'transaction_id' => trim($transactionId),
                'status' => strtolower(trim($finalStatus)),
                'updated_at' => $this->now(),
            ],
            [
                'external_order_id' => trim($externalOrderId),
            ]
        );
    }

    public function updateStatus(string $paymosInvoiceId, string $status): void
    {
        $this->db->ensureSchema();
        $this->db->updateWhere(
            self::TABLE,
            [
                'status' => strtolower(trim($status)),
                'updated_at' => $this->now(),
            ],
            [
                'paymos_invoice_id' => trim($paymosInvoiceId),
            ]
        );
    }

    public function updateStatusByExternalOrderId(string $externalOrderId, string $status): void
    {
        $this->db->ensureSchema();
        $this->db->updateWhere(
            self::TABLE,
            [
                'status' => strtolower(trim($status)),
                'updated_at' => $this->now(),
            ],
            [
                'external_order_id' => trim($externalOrderId),
            ]
        );
    }

    /**
     * Record idempotent paid_late marker on both column and payload_meta.
     * Returns true on the first call for this snapshot, false if already noted.
     */
    public function markPaidLateNoted(string $externalOrderId, string $marker): bool
    {
        $row = $this->findByExternalOrderId($externalOrderId);
        if ($row === null) {
            return false;
        }

        $meta = $this->decodeMeta($row);
        if (!empty($row['paid_late_noted']) || !empty($meta['paid_late_noted'])) {
            return false;
        }

        $value = trim($marker) !== '' ? trim($marker) : '1';
        $meta['paid_late_noted'] = $value;

        $this->db->updateWhere(
            self::TABLE,
            [
                'paid_late_noted' => $value,
                'payload_meta' => $this->encodeMeta($meta),
                'updated_at' => $this->now(),
            ],
            [
                'external_order_id' => trim($externalOrderId),
            ]
        );

        return true;
    }

    /**
     * Persist refund marker metadata on the snapshot for idempotent projection inspection.
     *
     * @param array<string, mixed> $marker
     */
    public function recordRefundMarker(string $externalOrderId, string $markerKey, array $marker): void
    {
        $row = $this->findByExternalOrderId($externalOrderId);
        if ($row === null) {
            throw new \RuntimeException('Snapshot not found for refund marker.');
        }

        $meta = $this->decodeMeta($row);
        if (!isset($meta['refund_markers']) || !is_array($meta['refund_markers'])) {
            $meta['refund_markers'] = [];
        }
        $meta['refund_markers'][$markerKey] = $marker;

        $this->db->updateWhere(
            self::TABLE,
            [
                'payload_meta' => $this->encodeMeta($meta),
                'updated_at' => $this->now(),
            ],
            [
                'external_order_id' => trim($externalOrderId),
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function readRefundMarkers(array $row): array
    {
        $meta = $this->decodeMeta($row);
        if (!isset($meta['refund_markers']) || !is_array($meta['refund_markers'])) {
            return [];
        }

        return $meta['refund_markers'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeMeta(array $row): array
    {
        $rawMeta = $row['payload_meta'] ?? null;
        if (is_string($rawMeta) && trim($rawMeta) !== '') {
            $decoded = json_decode($rawMeta, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function encodeMeta(array $meta): string
    {
        $encoded = json_encode($meta, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{}';
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
