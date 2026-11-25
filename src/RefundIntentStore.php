<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use Paymos\Exception\EventInProgressException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\ExternalRefundObservation;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundCandidateStoreInterface;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundIntentPage;
use Paymos\Plugin\RefundIntentStoreInterface;
use Paymos\Plugin\RefundRecipientIdentity;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;

final class RefundIntentStore implements RefundIntentStoreInterface, RefundCandidateStoreInterface
{
    public const TABLE = 'paymos_refund_intents';
    public const EXTERNAL_TABLE = 'paymos_refund_observations';
    private const INSTALLATION_KEY = 'refund_installation_v1';

    private DbDriverInterface $db;
    private CredentialStore $settings;

    /** @var callable */
    private $clock;

    private ?string $paymentKey = null;
    private int $depth = 0;
    private bool $ownsTransaction = false;

    public function __construct(
        DbDriverInterface $db,
        ?CredentialStore $settings = null,
        ?callable $clock = null
    ) {
        $this->db = $db;
        $this->clock = $clock ?: static fn (): int => time();
        $this->settings = $settings ?: new CredentialStore($db, null, $this->clock);
    }

    public function install(): void
    {
        $this->db->ensureSchema();
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function deterministicUuid(string $seed): string
    {
        $bytes = substr(hash('sha256', $seed, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public function installationId(): string
    {
        $existing = $this->settings->getSetting(self::INSTALLATION_KEY);
        if ($existing === null || $existing === '') {
            $this->settings->insertSettingUnique(self::INSTALLATION_KEY, self::uuid());
            $existing = $this->settings->getSetting(self::INSTALLATION_KEY);
        }

        if (!is_string($existing) || !preg_match('/^[a-f0-9-]{36}$/D', $existing)) {
            throw new \RuntimeException('refund_installation_missing');
        }

        return $existing;
    }

    public function withPaymentLock(string $paymentKey, callable $action)
    {
        $paymentKey = trim($paymentKey);
        if ($paymentKey === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $paymentKey)) {
            throw new \RuntimeException('refund_payment_key_invalid');
        }

        if ($this->paymentKey !== null) {
            if ($this->paymentKey !== $paymentKey) {
                throw new \RuntimeException('refund_lock_scope_conflict');
            }
            $this->assertOwned();

            return $action();
        }

        $this->db->ensureSchema();
        $lockName = $this->lockName($paymentKey);
        if (!$this->db->acquireNamedLock($lockName, 10)) {
            throw new EventInProgressException('refund_payment_busy');
        }

        $this->paymentKey = $paymentKey;
        try {
            return $action();
        } finally {
            $this->paymentKey = null;
            $this->db->releaseNamedLock($lockName);
        }
    }

    public function assertOwned(): void
    {
        if ($this->paymentKey === null || !$this->db->ownsNamedLock($this->lockName($this->paymentKey))) {
            throw new \RuntimeException('refund_payment_lock_lost');
        }
    }

    public function transaction(callable $action)
    {
        $this->assertOwned();
        if ($this->depth > 0) {
            return $action();
        }

        // If an outer host transaction (e.g. Paymenter's ExtensionHelper::refund() DB::transaction())
        // is already active on this connection, participate safely without starting a conflicting top-level beginTransaction().
        if ($this->db->inTransaction()) {
            $this->depth = 1;
            $this->ownsTransaction = false;
            try {
                $result = $action();
                $this->assertOwned();
                return $result;
            } finally {
                $this->depth = 0;
            }
        }

        $this->db->beginTransaction();
        $this->depth = 1;
        $this->ownsTransaction = true;
        try {
            $result = $action();
            $this->assertOwned();
            if (!$this->db->commit()) {
                throw new \RuntimeException('refund_commit_unknown');
            }
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        } finally {
            $this->depth = 0;
            $this->ownsTransaction = false;
        }
    }

    public function outsideTransaction(callable $action)
    {
        $this->assertOwned();
        $resume = $this->depth > 0 && $this->ownsTransaction;
        if ($resume) {
            if (!$this->db->commit()) {
                throw new \RuntimeException('refund_commit_unknown');
            }
            $this->depth = 0;
            $this->ownsTransaction = false;
        }

        try {
            $result = $action();
            $this->assertOwned();
            return $result;
        } finally {
            if ($resume) {
                $this->assertOwned();
                $this->db->beginTransaction();
                $this->depth = 1;
                $this->ownsTransaction = true;
            }
        }
    }

    public function createIntent(RefundIntent $intent): RefundIntent
    {
        $this->assertIntentLock($intent);
        if ($this->depth !== 0) {
            throw new \RuntimeException('refund_intent_must_commit');
        }

        return $this->transaction(function () use ($intent): RefundIntent {
            $data = $intent->toArray();
            $existing = $this->findByRequestToken((string) $data['installation_id'], (string) $data['request_token']);
            if ($existing !== null) {
                $existingData = $existing->toArray();
                foreach (['platform', 'native_payment_key', 'invoice_id', 'project_id', 'environment', 'mode', 'full_draft'] as $field) {
                    if ($existingData[$field] !== $data[$field]) {
                        throw new \RuntimeException('refund_request_conflict');
                    }
                }
                return $existing;
            }

            foreach ($this->forPayment($intent->paymentKey()) as $old) {
                if ($this->blocksNewIntent($old)) {
                    throw new \RuntimeException('refund_payment_has_unresolved_intent');
                }
            }

            $now = $this->now();
            $data = array_replace($data, [
                'created_at' => $now,
                'checks' => 0,
                'version' => 1,
            ]);

            $inserted = $this->db->insertUnique(self::TABLE, [
                'operation_id' => $intent->operationId(),
                'installation_id' => (string) $data['installation_id'],
                'request_token' => (string) $data['request_token'],
                'external_order_id' => $intent->externalOrderId(),
                'native_payment_key' => $intent->paymentKey(),
                'withdrawal_id' => null,
                'payload' => $this->encode($data),
                'version' => 1,
                'created_at' => $now,
                'next_check' => $now + 60,
            ], 'operation_id');

            if (!$inserted) {
                throw new \RuntimeException('refund_store_write_conflict');
            }

            return new RefundIntent($data);
        });
    }

    public function blocksNewIntent(RefundIntent $intent): bool
    {
        return $intent->projectionState() === RefundStates::NATIVE_REVIEW
            || ($intent->mode() === RefundStates::FULL && (
                $intent->cashState() === RefundStates::COMPLETED
                || $intent->toArray()['projection'] !== null
            ))
            || !in_array($intent->cashState(), [RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED], true)
            || ($intent->cashState() === RefundStates::COMPLETED && $intent->projectionState() !== RefundStates::PROJECTION_APPLIED);
    }

    public function findByRequestToken(string $installationId, string $token): ?RefundIntent
    {
        $this->db->ensureSchema();

        return $this->one([
            'installation_id' => $installationId,
            'request_token' => $token,
        ]);
    }

    public function getIntent(string $operationId): RefundIntent
    {
        $this->db->ensureSchema();
        $intent = $this->one(['operation_id' => $operationId]);
        if ($intent === null) {
            throw new \RuntimeException('refund_intent_missing');
        }

        return $intent;
    }

    public function findByExternalId(string $key): ?RefundIntent
    {
        $this->db->ensureSchema();

        return $this->one(['external_order_id' => $key]);
    }

    public function findByWithdrawalId(string $id): ?RefundIntent
    {
        $this->db->ensureSchema();

        return $this->one(['withdrawal_id' => $id]);
    }

    /**
     * @return list<RefundIntent>
     */
    public function forPayment(string $paymentKey): array
    {
        $this->db->ensureSchema();

        return $this->intents(['native_payment_key' => $paymentKey], 'created_at ASC, operation_id ASC');
    }

    public function bindWithdrawal(string $operationId, string $id): string
    {
        return $this->transaction(function () use ($operationId, $id): string {
            $intent = $this->getIntent($operationId);
            $this->assertIntentLock($intent);

            $other = $id !== '' ? $this->findByWithdrawalId($id) : null;
            if (
                $id === ''
                || ($other !== null && $other->operationId() !== $operationId)
                || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $id)
            ) {
                $this->recordState($operationId, RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_binding_conflict');
                return 'conflict';
            }

            if ($intent->withdrawalId() === $id) {
                return 'same';
            }

            $this->save($intent, ['withdrawal_id' => $id]);

            return 'bound';
        });
    }

    public function recordObservation(string $operationId, VerifiedRefund $snapshot): void
    {
        $this->transaction(function () use ($operationId, $snapshot): void {
            $intent = $this->getIntent($operationId);
            $pin = $intent->observation();
            if (
                $intent->withdrawalId() !== $snapshot->withdrawalId()
                || $intent->invoiceId() !== $snapshot->invoiceId()
                || $intent->projectId() !== $snapshot->projectId()
                || $intent->environment() !== $snapshot->environment()
                || $intent->mode() !== $snapshot->mode()
                || $intent->externalOrderId() !== $snapshot->externalOrderId()
                || (
                    $pin !== null
                    && (
                        !AmountGuard::amountsEqual($pin->principalDecimal(), $snapshot->principalDecimal())
                        || $pin->currency() !== $snapshot->currency()
                        || $pin->network() !== $snapshot->network()
                        || !RefundRecipientIdentity::same($pin, $snapshot)
                    )
                )
            ) {
                throw new \RuntimeException('refund_observation_conflict');
            }

            $this->save($intent, ['observation' => $snapshot->toArray()]);
        });
    }

    public function recordProjection(string $operationId, NativeProjectionResult $result): void
    {
        $this->transaction(function () use ($operationId, $result): void {
            $intent = $this->getIntent($operationId);
            $draft = $intent->fullDraft();
            $snapshot = $intent->observation();
            $old = $intent->toArray()['projection'];

            if (
                $result->kind() !== NativeProjectionResult::EXACT_MATCH
                || $result->mode() !== $intent->mode()
                || $result->nativeId() === null
                || $result->nativeId() === ''
                || $snapshot === null
                || $snapshot->status() !== 'completed'
                || ($old !== null && $old !== $result->toArray())
                || (
                    $draft !== null
                    && (
                        $result->nativeAmountMinor() !== $draft['amount_minor']
                        || $result->nativeCurrency() !== $draft['currency']
                        || $result->cryptoObservationAmount() !== null
                        || $result->cryptoAsset() !== null
                    )
                )
                || (
                    $draft === null
                    && (
                        $result->nativeAmountMinor() !== null
                        || $result->nativeCurrency() !== null
                        || !AmountGuard::amountsEqual($result->cryptoObservationAmount(), $snapshot->principalDecimal())
                        || $result->cryptoAsset() !== $snapshot->currency() . '/' . $snapshot->network()
                    )
                )
            ) {
                throw new \RuntimeException('refund_projection_conflict');
            }

            $this->save($intent, ['projection' => $result->toArray()]);
        });
    }

    public function recordReadCandidate(string $operationId, ?string $candidateWithdrawalId, int $retryCount): void
    {
        if ($candidateWithdrawalId !== null && trim($candidateWithdrawalId) === '') {
            throw new \InvalidArgumentException('refund_candidate_invalid');
        }
        if ($retryCount < 0) {
            throw new \InvalidArgumentException('refund_retry_count_invalid');
        }

        $this->transaction(function () use ($operationId, $candidateWithdrawalId, $retryCount): void {
            $this->save($this->getIntent($operationId), [
                'candidate_withdrawal_id' => $candidateWithdrawalId,
                'read_retry_count' => $retryCount,
            ]);
        });
    }

    public function recordState(string $operationId, string $cashState, string $projectionState, ?string $reason): void
    {
        if (
            !in_array($cashState, ['not_submitted', 'unknown', 'created', 'processing', 'completed', 'failed', 'cancelled', 'review'], true)
            || !in_array($projectionState, ['none', 'pending', 'applied', 'native_review'], true)
        ) {
            throw new \InvalidArgumentException('refund_state_invalid');
        }

        $this->transaction(function () use ($operationId, $cashState, $projectionState, $reason): void {
            $this->save($this->getIntent($operationId), [
                'cash_state' => $cashState,
                'projection_state' => $projectionState,
                'review_reason' => $reason,
            ]);
        });
    }

    public function claimEffect(string $operationId, string $kind, int $generation): EffectClaim
    {
        if ($kind === '' || $generation < 1) {
            throw new \InvalidArgumentException('refund_effect_invalid');
        }
        if ($this->depth !== 0) {
            throw new \RuntimeException('refund_effect_must_commit');
        }

        return $this->transaction(function () use ($operationId, $kind, $generation): EffectClaim {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (isset($effects[$key]) && $effects[$key] !== RefundStates::EFFECT_PENDING) {
                return new EffectClaim(EffectClaim::ALREADY_STARTED);
            }
            $effects[$key] = RefundStates::EFFECT_STARTED;
            $this->save($intent, ['effects' => $effects]);

            return new EffectClaim(EffectClaim::CLAIMED);
        });
    }

    public function recordEffectOutcome(string $operationId, string $kind, int $generation, string $state): void
    {
        if ($kind === '' || $generation < 1 || !in_array($state, ['delivered', 'failed_no_effect', 'unknown', 'skipped'], true)) {
            throw new \InvalidArgumentException('refund_effect_invalid');
        }

        $this->transaction(function () use ($operationId, $kind, $generation, $state): void {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (!isset($effects[$key]) || $effects[$key] !== RefundStates::EFFECT_STARTED) {
                throw new \RuntimeException('refund_effect_not_started');
            }
            $effects[$key] = $state;
            $this->save($intent, ['effects' => $effects]);
        });
    }

    public function listDue(\DateTimeImmutable $now, int $limit, ?string $cursor): RefundIntentPage
    {
        $this->db->ensureSchema();
        $boundedLimit = max(1, min(100, $limit));
        $gtWhere = ($cursor !== null && $cursor !== '') ? ['operation_id' => $cursor] : [];

        $rows = $this->db->selectAll(
            self::TABLE,
            [],
            'operation_id ASC',
            $boundedLimit,
            [],
            ['next_check' => $now->getTimestamp()],
            $gtWhere
        );

        $items = $this->hydrateRows($rows);
        $nextCursor = count($items) === $boundedLimit ? $items[count($items) - 1]->operationId() : null;

        return new RefundIntentPage($items, $nextCursor);
    }

    public function defer(string $operationId, ?int $now = null): void
    {
        $now = $now === null ? $this->now() : $now;
        $this->transaction(function () use ($operationId, $now): void {
            $intent = $this->getIntent($operationId);
            $data = $intent->toArray();
            $historical = in_array($intent->cashState(), [RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED, RefundStates::REVIEW], true);
            $checks = (int) ($data['checks'] ?? 0) + 1;
            $changes = ['checks' => $checks];
            $createdAt = (int) ($data['created_at'] ?? $now);

            if (!$historical && ($now - $createdAt) > 86400) {
                $changes['projection_state'] = RefundStates::NATIVE_REVIEW;
                $changes['review_reason'] = 'refund_unresolved_over_24h';
                $historical = true;
            }

            $delays = [60, 120, 300, 900, 3600];
            $delay = $historical ? 86400 : $delays[min($checks, count($delays) - 1)];
            $this->save($intent, $changes, $now + $delay);
        });
    }

    /**
     * Persist an externally-initiated Paymos refund observation (discovered via RefundDiscovery or webhook).
     *
     * @return array<string, mixed>
     */
    public function observeExternal(string $paymentKey, VerifiedRefund $refund): array
    {
        $this->assertOwned();
        if ($this->paymentKey !== $paymentKey) {
            throw new \RuntimeException('refund_lock_scope_conflict');
        }

        return $this->transaction(function () use ($paymentKey, $refund): array {
            $old = $this->findExternalByWithdrawalId($refund->withdrawalId());
            $folded = ExternalRefundObservation::fold($old, $refund, $paymentKey);

            $this->db->upsert(self::EXTERNAL_TABLE, [
                'withdrawal_id' => $refund->withdrawalId(),
                'native_payment_key' => $paymentKey,
                'payload' => $this->encode($folded),
                'updated_at' => $this->now(),
            ], 'withdrawal_id');

            return $folded;
        });
    }

    /**
     * Mark an externally-discovered refund observation as projected/noted in Paymenter.
     */
    public function markExternalProjected(string $withdrawalId, string $markerId, ?string $reviewReason = null): void
    {
        $this->assertOwned();
        $this->transaction(function () use ($withdrawalId, $markerId, $reviewReason): void {
            $old = $this->findExternalByWithdrawalId($withdrawalId);
            if ($old === null) {
                throw new \RuntimeException('refund_external_missing');
            }
            $old['note_id'] = $markerId;
            $old['review_reason'] = $reviewReason;

            $this->db->upsert(self::EXTERNAL_TABLE, [
                'withdrawal_id' => $withdrawalId,
                'native_payment_key' => (string) $old['payment_key'],
                'payload' => $this->encode($old),
                'updated_at' => $this->now(),
            ], 'withdrawal_id');
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findExternalByWithdrawalId(string $withdrawalId): ?array
    {
        $this->db->ensureSchema();
        $row = $this->db->selectOne(self::EXTERNAL_TABLE, ['withdrawal_id' => $withdrawalId]);
        if ($row === null || !isset($row['payload']) || !is_string($row['payload'])) {
            return null;
        }
        $decoded = json_decode($row['payload'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('refund_external_history_corrupt');
        }

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function externalObservations(string $paymentKey): array
    {
        $this->db->ensureSchema();
        $rows = $this->db->selectAll(self::EXTERNAL_TABLE, ['native_payment_key' => $paymentKey], 'updated_at ASC, withdrawal_id ASC');
        $out = [];
        foreach ($rows as $row) {
            if (!isset($row['payload']) || !is_string($row['payload'])) {
                throw new \RuntimeException('refund_external_history_corrupt');
            }
            $decoded = json_decode($row['payload'], true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('refund_external_history_corrupt');
            }
            $out[] = $decoded;
        }

        return $out;
    }

    public function assertNoExternalReview(string $paymentKey): void
    {
        foreach ($this->externalObservations($paymentKey) as $row) {
            if (!empty($row['review_reason'])) {
                throw new \RuntimeException((string) $row['review_reason']);
            }
        }
    }

    /**
     * @param array<string, scalar|null> $where
     */
    private function one(array $where): ?RefundIntent
    {
        $rows = $this->intents($where);
        if (count($rows) > 1) {
            throw new \RuntimeException('refund_identity_ambiguous');
        }

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, scalar|null> $where
     * @return list<RefundIntent>
     */
    private function intents(array $where, ?string $orderBy = null): array
    {
        $rows = $this->db->selectAll(self::TABLE, $where, $orderBy);

        return $this->hydrateRows($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<RefundIntent>
     */
    private function hydrateRows(array $rows): array
    {
        $intents = [];
        foreach ($rows as $row) {
            $raw = $row['payload'] ?? null;
            if (!is_string($raw)) {
                throw new \RuntimeException('refund_store_corrupt');
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new \RuntimeException('refund_store_corrupt');
            }
            $intents[] = new RefundIntent($data);
        }

        return $intents;
    }

    private function assertIntentLock(RefundIntent $intent): void
    {
        $this->assertOwned();
        if ($this->paymentKey !== $intent->paymentKey()) {
            throw new \RuntimeException('refund_lock_scope_conflict');
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function save(RefundIntent $intent, array $changes, ?int $nextCheck = null): void
    {
        $this->assertIntentLock($intent);
        $old = $intent->toArray();
        $oldVersion = (int) ($old['version'] ?? 1);
        $data = array_replace($old, $changes, ['version' => $oldVersion + 1]);

        $values = [
            'payload' => $this->encode($data),
            'withdrawal_id' => $data['withdrawal_id'],
            'version' => $data['version'],
        ];
        if ($nextCheck !== null) {
            $values['next_check'] = $nextCheck;
        }

        $affected = $this->db->updateWhere(
            self::TABLE,
            $values,
            [
                'operation_id' => $intent->operationId(),
                'version' => $oldVersion,
            ]
        );

        if ($affected !== 1) {
            throw new \RuntimeException('refund_store_write_conflict');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('refund_store_encoding_failed');
        }

        return $json;
    }

    private function lockName(string $paymentKey): string
    {
        return 'paymos-paymenter-invoice:' . $paymentKey;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
