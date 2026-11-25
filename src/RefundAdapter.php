<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Paymos\Client;
use Paymos\Exception\ApiException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\CredentialSet;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundCopy;
use Paymos\Plugin\RefundDiscovery;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use Paymos\Webhook\WebhookEvent;

final class RefundAdapter implements NativeRefundProjectorInterface
{
    private const UNMANAGED_FIELDS = [
        'address',
        'destination_address',
        'network',
        'parts',
        'crypto_amount',
        'recipient',
        'wallet',
    ];

    private const DUE_CURSOR_KEY = 'refund_due_cursor';

    private Config $config;
    private CredentialStore $credentialStore;
    private SnapshotStore $snapshots;
    private RefundIntentStore $store;

    /** @var callable|null */
    private $clientFactory;

    /** @var callable|null */
    private $native;

    /** @var callable */
    private $authorize;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $logger;

    public function __construct(
        Config $config,
        CredentialStore $credentialStore,
        SnapshotStore $snapshots,
        RefundIntentStore $store,
        ?callable $clientFactory = null,
        ?callable $native = null,
        ?callable $authorize = null,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->config = $config;
        $this->credentialStore = $credentialStore;
        $this->snapshots = $snapshots;
        $this->store = $store;
        $this->clientFactory = $clientFactory;
        $this->native = $native;
        $this->authorize = $authorize ?: static function (): void {
            throw new \RuntimeException('refund_admin_access_denied');
        };
        $this->clock = $clock ?: static fn (): int => time();
        $this->logger = $logger ?: static function (string $level, string $message, array $context): void {
        };
    }

    /**
     * Submit a server-calculated full or overpayment refund through RefundCoordinator.
     *
     * @param array<string, mixed> $input
     */
    public function submit(array $input): RefundIntent
    {
        ($this->authorize)();

        $paymentKey = isset($input['invoice_id']) && is_scalar($input['invoice_id'])
            ? trim((string) $input['invoice_id'])
            : '';
        $token = isset($input['request_token']) && is_string($input['request_token'])
            ? strtolower(trim($input['request_token']))
            : '';
        $mode = isset($input['mode']) && is_string($input['mode'])
            ? strtolower(trim($input['mode']))
            : '';

        foreach (self::UNMANAGED_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                throw new \RuntimeException('refund_managed_input_only');
            }
        }

        if ($paymentKey === '' || !self::isValidUuidV4($token) || !in_array($mode, [RefundStates::FULL, RefundStates::OVERPAYMENT], true)) {
            throw new \RuntimeException('refund_durable_token_required');
        }

        return $this->store->withPaymentLock($paymentKey, function () use ($input, $paymentKey, $token, $mode): RefundIntent {
            $installation = $this->store->installationId();
            $existing = $this->store->findByRequestToken($installation, $token);
            if ($existing !== null) {
                if ($existing->paymentKey() !== $paymentKey || $existing->mode() !== $mode) {
                    throw new \RuntimeException('refund_request_conflict');
                }
                $this->assertRequestInput($input, $existing->fullDraft());
                $intent = $existing;
            } else {
                $nativeSnapshot = $this->nativeCall('snapshot', ['invoice_id' => $paymentKey]);
                $this->assertOriginalPaid($nativeSnapshot);
                $this->store->assertNoExternalReview($paymentKey);

                $draft = $mode === RefundStates::FULL ? $this->buildFullDraft($nativeSnapshot) : null;
                $this->assertRequestInput($input, $draft);

                $mapping = $nativeSnapshot['mapping'];
                $intent = new RefundIntent([
                    'operation_id' => RefundIntentStore::uuid(),
                    'request_token' => $token,
                    'installation_id' => $installation,
                    'platform' => 'paymenter',
                    'native_payment_key' => $paymentKey,
                    'invoice_id' => (string) $mapping['paymos_invoice_id'],
                    'project_id' => (string) $mapping['project_id'],
                    'environment' => (string) $mapping['environment'],
                    'mode' => $mode,
                    'full_draft' => $draft,
                ]);

                $preflightReason = $this->preflight($intent);
                if ($preflightReason !== null) {
                    throw new \RuntimeException(
                        $preflightReason === NativeRefundProjectorInterface::PREFLIGHT_RETRY
                            ? 'refund_preflight_unavailable'
                            : $preflightReason
                    );
                }

                $intent = $this->store->createIntent($intent);
            }

            $this->coordinator($intent)->submit($intent->operationId());
            $this->store->assertOwned();
            $this->reconcile($intent->operationId());

            return $this->store->getIntent($intent->operationId());
        });
    }

    /**
     * Async-safe Paymenter Gateway::refund() entry point.
     *
     * CRITICAL MONEY SAFETY: Paymenter's ExtensionHelper::refund() synchronously invokes
     * ExtensionHelper::recordRefund() whenever Gateway::refund() returns true.
     * Therefore this method returns true ONLY when cash_state === 'completed' is verified.
     * While pending / processing / unknown it throws a controlled informational exception
     * so no premature accounting debit occurs; actual projection runs once inside
     * applyCompleted() via ExtensionHelper::refundLocally() after verified completion.
     *
     * @param mixed $amount
     */
    public function nativeRefund(object $transaction, $amount, ?string $requestToken = null, ?string $locale = null): bool
    {
        $paymentKey = isset($transaction->invoice_id) && is_scalar($transaction->invoice_id)
            ? trim((string) $transaction->invoice_id)
            : '';
        $txId = isset($transaction->transaction_id) && is_scalar($transaction->transaction_id)
            ? trim((string) $transaction->transaction_id)
            : '';

        if ($paymentKey === '' && $txId !== '') {
            $snap = $this->snapshots->findByTransactionId($txId);
            if ($snap !== null) {
                $paymentKey = (string) ($snap['platform_ref'] ?? '');
            }
        }
        if ($paymentKey === '') {
            throw new \InvalidArgumentException('Paymenter transaction is missing invoice_id.');
        }

        $normalizedAmount = AmountGuard::normalizeDecimal($amount);
        if ($normalizedAmount === null || $normalizedAmount === '0' || $normalizedAmount[0] === '-') {
            throw new \InvalidArgumentException('Refund amount must be a positive decimal.');
        }

        if (isset($transaction->amount) && is_scalar($transaction->amount) && trim((string) $transaction->amount) !== '') {
            if (!AmountGuard::amountsEqual($normalizedAmount, (string) $transaction->amount)) {
                throw new \InvalidArgumentException('Paymos supports full invoice refunds only; partial amounts are not supported.');
            }
        }

        $token = $requestToken !== null ? trim($requestToken) : '';
        if ($token === '') {
            $installation = $this->store->installationId();
            $token = RefundIntentStore::deterministicUuid(
                'paymos-paymenter-native-refund:' . $installation . ':' . $paymentKey . ':' . $txId
            );
        }

        $input = [
            'invoice_id' => $paymentKey,
            'mode' => RefundStates::FULL,
            'request_token' => $token,
            'amount' => $normalizedAmount,
        ];
        if ($txId !== '') {
            $input['transaction_id'] = $txId;
        }

        $intent = $this->submit($input);

        if ($intent->cashState() === RefundStates::COMPLETED && $intent->projectionState() === RefundStates::PROJECTION_APPLIED) {
            return true;
        }

        if (in_array($intent->cashState(), [RefundStates::FAILED, RefundStates::CANCELLED, RefundStates::REVIEW], true)) {
            $reason = (string) ($intent->toArray()['review_reason'] ?? $intent->cashState());
            throw new \RuntimeException(
                Translation::text('refund_native_failed', $locale) . ($reason !== '' ? ' (' . $reason . ')' : '')
            );
        }

        // Pending / created / processing / unknown: MUST NOT return true (prevents premature recordRefund()).
        throw new \RuntimeException(Translation::text('refund_native_in_progress', $locale));
    }

    public function preflight(RefundIntent $intent): ?string
    {
        try {
            $nativeSnapshot = $this->nativeCall('snapshot', ['invoice_id' => $intent->paymentKey()]);
            $this->assertMapping($nativeSnapshot, $intent);
            $this->assertOriginalPaid($nativeSnapshot);

            if ($intent->mode() === RefundStates::FULL && $this->buildFullDraft($nativeSnapshot) !== $intent->fullDraft()) {
                throw new \RuntimeException('refund_native_draft_changed');
            }

            $refundConfig = $this->credentialStore->refundConfig($intent->environment());
            if (
                !CredentialSet::hasCapability($refundConfig, 'invoice_refunds_v1')
                || $refundConfig['refund_contract_version'] !== 1
                || $refundConfig['refunds_enabled'] !== true
            ) {
                throw new \RuntimeException('refund_reconnect_required');
            }

            try {
                $invoice = $this->client($intent->environment())->invoices()->get($intent->invoiceId());
            } catch (ApiException $error) {
                if ($error->statusCode() === 429 || $error->statusCode() >= 500) {
                    return NativeRefundProjectorInterface::PREFLIGHT_RETRY;
                }
                return $error->errorCode() !== '' ? $error->errorCode() : $error->getMessage();
            } catch (\RuntimeException $error) {
                return NativeRefundProjectorInterface::PREFLIGHT_RETRY;
            }

            $this->store->assertOwned();
            $this->assertInvoiceScope($invoice, $nativeSnapshot, $intent);

            $summary = $this->invoiceSummary($invoice, $intent->paymentKey(), $intent->invoiceId());
            $flag = $intent->mode() === RefundStates::FULL ? 'can_refund_full' : 'can_refund_overpayment';
            $amountKey = $intent->mode() === RefundStates::FULL ? 'full_refund_amount' : 'overpayment_refund_amount';

            if (($summary[$flag] ?? false) !== true) {
                throw new \RuntimeException('refund_mode_unavailable');
            }

            if (array_key_exists('refund_summary', $invoice)) {
                if (
                    !isset($summary[$amountKey])
                    || !is_string($summary[$amountKey])
                    || !preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $summary[$amountKey])
                    || AmountGuard::normalizeDecimal($summary[$amountKey]) === '0'
                ) {
                    throw new \RuntimeException('refund_mode_unavailable');
                }
            }

            return null;
        } catch (\Throwable $error) {
            return $error->getMessage();
        }
    }

    public function inspect(string $operationId): NativeProjectionResult
    {
        $intent = $this->store->getIntent($operationId);
        $nativeSnapshot = $this->nativeCall('snapshot', ['invoice_id' => $intent->paymentKey()]);

        try {
            $this->assertMapping($nativeSnapshot, $intent);
        } catch (\RuntimeException $error) {
            return $this->ambiguous($intent, $error->getMessage());
        }

        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            $projection = $intent->toArray()['projection'];

            return $projection === null
                ? new NativeProjectionResult(NativeProjectionResult::ABSENT, RefundStates::OVERPAYMENT)
                : NativeProjectionResult::fromStorage($projection);
        }

        $markerKey = $intent->withdrawalId() !== null ? 'paymos_refund_' . $intent->withdrawalId() : '';
        $markers = $this->snapshots->readRefundMarkers($nativeSnapshot['mapping']);
        $marker = ($markerKey !== '' && isset($markers[$markerKey]) && is_array($markers[$markerKey]))
            ? $markers[$markerKey]
            : null;

        if ($marker === null) {
            try {
                $this->assertOriginalPaid($nativeSnapshot);
                if ($this->buildFullDraft($nativeSnapshot) !== $intent->fullDraft()) {
                    throw new \RuntimeException('refund_native_draft_changed');
                }
            } catch (\RuntimeException $error) {
                return $this->ambiguous($intent, $error->getMessage());
            }

            return new NativeProjectionResult(NativeProjectionResult::ABSENT, RefundStates::FULL);
        }

        $draft = $intent->fullDraft();
        if (
            $draft === null
            || (string) ($marker['operation_id'] ?? '') !== $intent->operationId()
            || (string) ($marker['withdrawal_id'] ?? '') !== (string) $intent->withdrawalId()
            || (string) ($marker['amount_minor'] ?? '') !== $draft['amount_minor']
            || strtoupper((string) ($marker['currency'] ?? '')) !== strtoupper($draft['currency'])
            || trim((string) ($marker['native_id'] ?? '')) === ''
        ) {
            return $this->ambiguous($intent, 'refund_native_marker_conflict');
        }

        return new NativeProjectionResult(
            NativeProjectionResult::EXACT_MATCH,
            RefundStates::FULL,
            (string) $marker['native_id'],
            $draft['amount_minor'],
            $draft['currency']
        );
    }

    /**
     * Project a verified completed refund into Paymenter billing.
     * NEVER sends HTTP to Paymos.
     */
    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        $this->store->assertOwned();
        if (
            $refund->status() !== 'completed'
            || $refund->withdrawalId() !== $intent->withdrawalId()
            || $refund->invoiceId() !== $intent->invoiceId()
            || $refund->mode() !== $intent->mode()
        ) {
            return $this->ambiguous($intent, 'refund_completion_required');
        }

        $inspection = $this->inspect($intent->operationId());
        if ($inspection->kind() !== NativeProjectionResult::ABSENT) {
            return $inspection;
        }

        $nativeSnapshot = $this->nativeCall('snapshot', ['invoice_id' => $intent->paymentKey()]);
        $mapping = $nativeSnapshot['mapping'];

        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            $markerId = 'paymos_overpayment_' . $intent->operationId();
            $this->store->outsideTransaction(function () use ($intent, $refund, $markerId): void {
                $this->nativeCall('recordOverpaymentNote', [
                    'invoice_id' => $intent->paymentKey(),
                    'withdrawal_id' => $refund->withdrawalId(),
                    'operation_id' => $intent->operationId(),
                    'amount' => $refund->principalDecimal(),
                    'asset' => $refund->currency() . '/' . $refund->network(),
                    'marker_id' => $markerId,
                ]);
            });

            $this->snapshots->recordRefundMarker((string) $mapping['external_order_id'], $markerId, [
                'mode' => RefundStates::OVERPAYMENT,
                'operation_id' => $intent->operationId(),
                'withdrawal_id' => $refund->withdrawalId(),
                'amount' => $refund->principalDecimal(),
                'asset' => $refund->currency() . '/' . $refund->network(),
                'native_id' => $markerId,
            ]);

            return new NativeProjectionResult(
                NativeProjectionResult::EXACT_MATCH,
                RefundStates::OVERPAYMENT,
                $markerId,
                null,
                null,
                $refund->principalDecimal(),
                $refund->currency() . '/' . $refund->network()
            );
        }

        $draft = $intent->fullDraft();
        if ($draft === null) {
            return $this->ambiguous($intent, 'refund_full_draft_missing');
        }

        $markerKey = 'paymos_refund_' . $intent->withdrawalId();
        $this->store->outsideTransaction(function () use ($intent, $draft, $nativeSnapshot, $markerKey): void {
            $this->nativeCall('refundLocally', [
                'invoice_id' => $intent->paymentKey(),
                'transaction' => $nativeSnapshot['transaction'],
                'amount' => $draft['amount_decimal'],
                'withdrawal_id' => $intent->withdrawalId(),
                'marker_id' => $markerKey,
            ]);
        });

        $this->snapshots->recordRefundMarker((string) $mapping['external_order_id'], $markerKey, [
            'mode' => RefundStates::FULL,
            'operation_id' => $intent->operationId(),
            'withdrawal_id' => $intent->withdrawalId(),
            'amount_minor' => $draft['amount_minor'],
            'currency' => $draft['currency'],
            'native_id' => $markerKey,
        ]);

        return $this->inspect($intent->operationId());
    }

    public function reconcile(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);

        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($operationId): string {
            $current = $this->store->getIntent($operationId);
            $action = $this->coordinator($current)->reconcile($operationId);
            $this->store->assertOwned();

            $updated = $this->store->getIntent($operationId);
            if (
                $updated->mode() === RefundStates::FULL
                && $updated->cashState() === RefundStates::COMPLETED
                && $updated->projectionState() === RefundStates::PROJECTION_APPLIED
            ) {
                if ($this->store->claimEffect($updated->operationId(), 'confirmation', 1)->kind() === 'claimed') {
                    $this->store->recordEffectOutcome($updated->operationId(), 'confirmation', 1, RefundStates::EFFECT_SKIPPED);
                }
            }

            return $action;
        });
    }

    /**
     * Handle a signed withdrawal.* refund webhook by triggering signed GET reconciliation.
     */
    public function callback(WebhookEvent $event, string $environment): void
    {
        $payload = $event->toArray();
        $wire = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        if (
            ($wire['type'] ?? '') !== 'refund'
            || !isset($wire['withdrawal_id'], $wire['invoice_id'], $wire['project_id'], $wire['is_test'])
        ) {
            throw new \RuntimeException('refund_callback_scope_missing');
        }

        $withdrawalId = (string) $wire['withdrawal_id'];
        $intent = $this->store->findByWithdrawalId($withdrawalId);
        if ($intent === null && isset($wire['external_order_id']) && is_string($wire['external_order_id'])) {
            $intent = $this->store->findByExternalId($wire['external_order_id']);
        }

        if ($intent === null) {
            // Check if this withdrawal belongs to a Paymenter invoice (Dashboard-initiated refund).
            $snap = $this->snapshots->findByPaymosInvoiceId((string) $wire['invoice_id']);
            if ($snap !== null && isset($wire['external_order_id'], $wire['mode'])) {
                $this->observeExternal([
                    'withdrawal_id' => $withdrawalId,
                    'invoice_id' => (string) $wire['invoice_id'],
                    'project_id' => (string) $wire['project_id'],
                    'mode' => (string) ($wire['mode'] ?? $wire['refund_mode'] ?? ''),
                    'external_order_id' => (string) $wire['external_order_id'],
                    'is_test' => (bool) $wire['is_test'],
                ], $environment);
                return;
            }
            throw new \RuntimeException('refund_unbound_review');
        }

        if (
            $intent->environment() !== $environment
            || $wire['is_test'] !== ($environment === Config::ENV_SANDBOX)
            || (string) $wire['invoice_id'] !== $intent->invoiceId()
            || (string) $wire['project_id'] !== $intent->projectId()
            || (isset($wire['mode']) && $wire['mode'] !== $intent->mode())
            || (isset($wire['refund_mode']) && $wire['refund_mode'] !== $intent->mode())
            || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $withdrawalId)
        ) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }

        // Authoritative state comes strictly from the coordinator's fresh signed GETs.
        $this->reconcile($intent->operationId());
    }

    /**
     * Background reconciliation pass for due intents + RefundDiscovery of external Dashboard refunds.
     */
    public function run(?int $now = null): int
    {
        $now = $now === null ? $this->now() : $now;
        $cursor = $this->credentialStore->getSetting(self::DUE_CURSOR_KEY);
        $page = $this->store->listDue(new \DateTimeImmutable('@' . $now), 100, $cursor !== '' ? $cursor : null);
        $count = 0;

        foreach ($page->items() as $intent) {
            try {
                if ($intent->cashState() === RefundStates::NOT_SUBMITTED && $intent->projectionState() !== RefundStates::NATIVE_REVIEW) {
                    $this->coordinator($intent)->submit($intent->operationId());
                }
                $this->reconcile($intent->operationId());
                $count++;
            } catch (\Throwable $error) {
                $this->log('warning', 'Paymos refund reconcile failed: ' . $error->getMessage(), [
                    'operation_id' => $intent->operationId(),
                ]);
            } finally {
                try {
                    $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent, $now): void {
                        $this->store->defer($intent->operationId(), $now);
                    });
                } catch (\Throwable $deferError) {
                    $this->log('warning', 'Paymos refund backoff defer failed: ' . $deferError->getMessage(), [
                        'operation_id' => $intent->operationId(),
                    ]);
                }
            }
        }

        $nextCursor = $page->nextCursor();
        if ($nextCursor === null) {
            $this->credentialStore->deleteSetting(self::DUE_CURSOR_KEY);
        } else {
            $this->credentialStore->setSetting(self::DUE_CURSOR_KEY, $nextCursor);
        }

        $count += $this->discoverExternal();

        return $count;
    }

    /**
     * Discover refunds initiated outside Paymenter (e.g. directly in Paymos Dashboard) via RefundDiscovery::page().
     */
    public function discoverExternal(?string $onlyEnvironment = null): int
    {
        $count = 0;
        $environments = $onlyEnvironment !== null ? [$onlyEnvironment] : [Config::ENV_SANDBOX, Config::ENV_LIVE];

        foreach ($environments as $environment) {
            if (!$this->config->hasEnvironment($environment)) {
                continue;
            }
            try {
                $refundConfig = $this->credentialStore->refundConfig($environment);
                $projectId = $refundConfig['project_id'];
                if ($projectId === '') {
                    continue;
                }

                $cursorKey = 'refund_external_cursor_' . $environment . '_' . substr(hash('sha256', $projectId), 0, 16);
                $cursor = $this->credentialStore->getSetting($cursorKey);

                $result = RefundDiscovery::page(
                    $this->client($environment),
                    $environment,
                    ($cursor !== null && $cursor !== '') ? $cursor : null,
                    function (array $signal, string $env): bool {
                        $intent = $this->store->findByWithdrawalId((string) $signal['withdrawal_id'])
                            ?: $this->store->findByExternalId((string) $signal['external_order_id']);
                        if ($intent !== null) {
                            return false;
                        }

                        return $this->observeExternal($signal, $env);
                    }
                );

                if (!empty($result['next_cursor']) && is_string($result['next_cursor'])) {
                    $this->credentialStore->setSetting($cursorKey, $result['next_cursor']);
                } else {
                    $this->credentialStore->deleteSetting($cursorKey);
                }

                $count += (int) ($result['checked'] ?? 0);
            } catch (\Throwable $error) {
                $this->log('warning', 'Paymos external refund discovery failed: ' . $error->getMessage(), [
                    'environment' => $environment,
                ]);
            }
        }

        return $count;
    }

    /**
     * Independently verify an externally-created refund via signed GETs and project it if completed,
     * without ever issuing POST /v1/refunds.
     *
     * @param array<string, mixed> $signal
     */
    public function observeExternal(array $signal, string $environment): bool
    {
        if (
            !isset($signal['withdrawal_id'], $signal['invoice_id'], $signal['external_order_id'], $signal['mode'])
            || ($signal['is_test'] ?? null) !== ($environment === Config::ENV_SANDBOX)
        ) {
            throw new \RuntimeException('refund_callback_scope_missing');
        }

        $client = $this->client($environment);
        $wire = $client->refunds()->get((string) $signal['withdrawal_id']);
        $invoice = $client->invoices()->get((string) $signal['invoice_id']);
        $refund = VerifiedRefund::fromApi($wire, $invoice, new \DateTimeImmutable('@' . $this->now()));

        foreach (['withdrawal_id' => 'withdrawalId', 'invoice_id' => 'invoiceId', 'mode' => 'mode', 'external_order_id' => 'externalOrderId'] as $field => $method) {
            if ((string) $signal[$field] !== $refund->$method()) {
                throw new \RuntimeException('refund_callback_scope_conflict');
            }
        }
        if ($refund->environment() !== $environment || (isset($signal['project_id']) && (string) $signal['project_id'] !== $refund->projectId())) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }

        $orderExternalId = isset($invoice['order']['external_id']) && is_string($invoice['order']['external_id'])
            ? $invoice['order']['external_id']
            : '';
        $snapshot = $orderExternalId !== '' ? $this->snapshots->findByExternalOrderId($orderExternalId) : null;
        if ($snapshot === null) {
            $snapshot = $this->snapshots->findByPaymosInvoiceId($refund->invoiceId());
        }
        if ($snapshot === null) {
            // Refund belongs to another order/system on the same project — skip cleanly.
            return false;
        }

        $paymentKey = (string) ($snapshot['platform_ref'] ?? '');
        if ($paymentKey === '') {
            return false;
        }

        $this->store->withPaymentLock($paymentKey, function () use ($paymentKey, $snapshot, $refund, $environment): void {
            $refundConfig = $this->credentialStore->refundConfig($environment);
            if (
                (string) ($snapshot['paymos_invoice_id'] ?? '') !== $refund->invoiceId()
                || (string) ($snapshot['project_id'] ?? '') !== $refund->projectId()
                || strtolower((string) ($snapshot['environment'] ?? '')) !== $environment
                || ($refundConfig['project_id'] ?? '') !== $refund->projectId()
            ) {
                throw new \RuntimeException('refund_external_scope_conflict');
            }

            $stored = $this->store->observeExternal($paymentKey, $refund);

            if ($refund->status() !== 'completed' || !empty($stored['note_id'])) {
                return;
            }

            if ($refund->mode() === RefundStates::OVERPAYMENT) {
                $markerId = 'paymos_overpayment_ext_' . $refund->withdrawalId();
                $this->store->outsideTransaction(function () use ($paymentKey, $refund, $markerId): void {
                    $this->nativeCall('recordOverpaymentNote', [
                        'invoice_id' => $paymentKey,
                        'withdrawal_id' => $refund->withdrawalId(),
                        'operation_id' => $markerId,
                        'amount' => $refund->principalDecimal(),
                        'asset' => $refund->currency() . '/' . $refund->network(),
                        'marker_id' => $markerId,
                    ]);
                });
                $this->snapshots->recordRefundMarker((string) $snapshot['external_order_id'], $markerId, [
                    'mode' => RefundStates::OVERPAYMENT,
                    'withdrawal_id' => $refund->withdrawalId(),
                    'amount' => $refund->principalDecimal(),
                    'asset' => $refund->currency() . '/' . $refund->network(),
                    'native_id' => $markerId,
                    'external' => true,
                ]);
                $this->store->markExternalProjected($refund->withdrawalId(), $markerId, null);
                return;
            }

            // Completed external full refund: project locally once without POST /v1/refunds.
            $markerKey = 'paymos_refund_' . $refund->withdrawalId();
            $existingMarkers = $this->snapshots->readRefundMarkers($snapshot);
            if (isset($existingMarkers[$markerKey])) {
                $this->store->markExternalProjected($refund->withdrawalId(), $markerKey, null);
                return;
            }

            $nativeSnapshot = $this->nativeCall('snapshot', ['invoice_id' => $paymentKey]);
            $this->assertOriginalPaid($nativeSnapshot);
            $draft = $this->buildFullDraft($nativeSnapshot);

            $this->store->outsideTransaction(function () use ($paymentKey, $nativeSnapshot, $draft, $refund, $markerKey): void {
                $this->nativeCall('refundLocally', [
                    'invoice_id' => $paymentKey,
                    'transaction' => $nativeSnapshot['transaction'],
                    'amount' => $draft['amount_decimal'],
                    'withdrawal_id' => $refund->withdrawalId(),
                    'marker_id' => $markerKey,
                ]);
            });

            $this->snapshots->recordRefundMarker((string) $snapshot['external_order_id'], $markerKey, [
                'mode' => RefundStates::FULL,
                'withdrawal_id' => $refund->withdrawalId(),
                'amount_minor' => $draft['amount_minor'],
                'currency' => $draft['currency'],
                'native_id' => $markerKey,
                'external' => true,
            ]);
            $this->store->markExternalProjected($refund->withdrawalId(), $markerKey, null);
        });

        return true;
    }

    /**
     * Build the admin Refund panel view-model for a Paymenter invoice (after reconciling & discovering refunds).
     *
     * @return array<string, mixed>
     */
    public function panel(int|string $invoiceId, ?string $locale = null): array
    {
        ($this->authorize)();

        $paymentKey = trim((string) $invoiceId);
        $resolvedLocale = Translation::resolveLocale($locale);
        $refundCopyLocale = RefundCopy::locale($resolvedLocale);

        // Refresh external Dashboard refunds before reading panel state.
        $paidSnapshots = $this->snapshots->findPaidByPlatformRef($paymentKey);
        if ($paidSnapshots !== []) {
            $env = strtolower(trim((string) ($paidSnapshots[0]['environment'] ?? '')));
            if ($env !== '' && $this->config->hasEnvironment($env)) {
                $this->discoverExternal($env);
            }
        }

        return $this->store->withPaymentLock($paymentKey, function () use ($paymentKey, $resolvedLocale, $refundCopyLocale): array {
            $tokens = [
                RefundStates::FULL => RefundIntentStore::uuid(),
                RefundStates::OVERPAYMENT => RefundIntentStore::uuid(),
            ];
            $hasCompletedFull = false;
            $hasCompletedOver = false;
            $blocked = false;
            $intentRows = [];

            foreach ($this->store->forPayment($paymentKey) as $intent) {
                if (!in_array($intent->cashState(), [RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED], true)
                    || ($intent->cashState() === RefundStates::COMPLETED && $intent->projectionState() !== RefundStates::PROJECTION_APPLIED)) {
                    try {
                        $this->coordinator($intent)->reconcile($intent->operationId());
                        $intent = $this->store->getIntent($intent->operationId());
                    } catch (\Throwable $e) {
                        $this->log('warning', 'Paymos panel reconcile skipped: ' . $e->getMessage());
                    }
                }

                if ($this->store->blocksNewIntent($intent)) {
                    $tokens[$intent->mode()] = (string) $intent->toArray()['request_token'];
                    $blocked = true;
                }
                if ($intent->mode() === RefundStates::FULL && $intent->cashState() === RefundStates::COMPLETED) {
                    $hasCompletedFull = true;
                }
                if ($intent->mode() === RefundStates::OVERPAYMENT && $intent->cashState() === RefundStates::COMPLETED) {
                    $hasCompletedOver = true;
                }

                $obs = $intent->observation();
                $reason = $intent->toArray()['review_reason'];
                $intentRows[] = [
                    'operation_id' => $intent->operationId(),
                    'withdrawal_id' => $intent->withdrawalId(),
                    'mode' => $intent->mode(),
                    'cash_state' => $intent->cashState(),
                    'projection_state' => $intent->projectionState(),
                    'review_reason' => $reason,
                    'amount_display' => $obs !== null ? $obs->principalDecimal() . ' ' . $obs->currency() . '/' . $obs->network() : '',
                    'status_copy' => RefundCopy::state(
                        $intent->cashState(),
                        $intent->mode(),
                        $intent->projectionState(),
                        is_string($reason) ? $reason : null,
                        $refundCopyLocale
                    ),
                ];
            }

            $externalRows = [];
            foreach ($this->store->externalObservations($paymentKey) as $ext) {
                $obs = $ext['observation'] ?? [];
                $extMode = (string) ($obs['mode'] ?? '');
                $extStatus = (string) ($obs['status'] ?? '');
                if ($extMode === RefundStates::FULL && $extStatus === 'completed') {
                    $hasCompletedFull = true;
                }
                if ($extMode === RefundStates::OVERPAYMENT && $extStatus === 'completed') {
                    $hasCompletedOver = true;
                }
                if (!empty($ext['review_reason'])) {
                    $blocked = true;
                }
                $externalRows[] = [
                    'withdrawal_id' => (string) ($obs['withdrawal_id'] ?? ''),
                    'mode' => $extMode,
                    'status' => $extStatus,
                    'amount_display' => isset($obs['amount'], $obs['currency'], $obs['network'])
                        ? $obs['amount'] . ' ' . $obs['currency'] . '/' . $obs['network']
                        : '',
                    'projected' => !empty($ext['note_id']),
                    'review_reason' => $ext['review_reason'] ?? null,
                ];
            }

            $nativeSnapshot = null;
            $reconnectRequired = false;
            try {
                $nativeSnapshot = $this->nativeCall('snapshot', ['invoice_id' => $paymentKey]);
                $env = (string) ($nativeSnapshot['mapping']['environment'] ?? $this->config->environment());
                $refundConfig = $this->credentialStore->refundConfig($env);
                if (
                    !CredentialSet::hasCapability($refundConfig, 'invoice_refunds_v1')
                    || $refundConfig['refund_contract_version'] !== 1
                    || $refundConfig['refunds_enabled'] !== true
                ) {
                    $reconnectRequired = true;
                }
            } catch (\Throwable $e) {
                $nativeSnapshot = null;
            }

            $availableModes = [];
            if (is_array($nativeSnapshot) && !$reconnectRequired && !$blocked) {
                $invoicePaid = strtolower(trim((string) ($nativeSnapshot['invoice']['status'] ?? ''))) === 'paid';
                $mappingStatus = strtolower(trim((string) ($nativeSnapshot['mapping']['status'] ?? '')));
                $notRefunded = empty($nativeSnapshot['transaction_refunded']);

                if ($invoicePaid && $notRefunded && !$hasCompletedFull) {
                    $availableModes[] = RefundStates::FULL;
                    if (!$hasCompletedOver && $mappingStatus === 'paid_over') {
                        $availableModes[] = RefundStates::OVERPAYMENT;
                    }
                }
            }

            return [
                'invoice_id' => $paymentKey,
                'connected' => $this->config->configuredEnvironments() !== [],
                'reconnect_required' => $reconnectRequired,
                'intents' => $intentRows,
                'external' => $externalRows,
                'available_modes' => $availableModes,
                'tokens' => $tokens,
                'labels' => Translation::all($resolvedLocale),
                'refund_copy' => RefundCopy::labels($refundCopyLocale),
            ];
        });
    }

    /**
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $draft
     */
    private function assertRequestInput(array $input, ?array $draft): void
    {
        if ($draft === null) {
            if (array_key_exists('amount', $input) || array_key_exists('currency', $input) || array_key_exists('transaction_id', $input)) {
                throw new \RuntimeException('refund_cash_only_input');
            }
            return;
        }

        if (array_key_exists('amount', $input)) {
            if (!is_scalar($input['amount']) || !AmountGuard::amountsEqual((string) $input['amount'], (string) $draft['amount_decimal'])) {
                throw new \RuntimeException('refund_partial_or_changed_payment');
            }
        }
        if (array_key_exists('currency', $input)) {
            if (!is_string($input['currency']) || strtoupper(trim($input['currency'])) !== strtoupper((string) $draft['currency'])) {
                throw new \RuntimeException('refund_partial_or_changed_payment');
            }
        }
        if (array_key_exists('transaction_id', $input)) {
            if (!is_string($input['transaction_id']) || trim($input['transaction_id']) !== (string) $draft['original_transaction_id']) {
                throw new \RuntimeException('refund_partial_or_changed_payment');
            }
        }
    }

    /**
     * @param array<string, mixed> $nativeSnapshot
     */
    private function assertMapping(array $nativeSnapshot, RefundIntent $intent): void
    {
        $mapping = $nativeSnapshot['mapping'] ?? [];
        $refundConfig = $this->credentialStore->refundConfig($intent->environment());

        if (
            (string) ($nativeSnapshot['invoice']['id'] ?? '') !== $intent->paymentKey()
            || (string) ($mapping['paymos_invoice_id'] ?? '') !== $intent->invoiceId()
            || (string) ($mapping['project_id'] ?? '') !== $intent->projectId()
            || ($refundConfig['project_id'] ?? '') !== $intent->projectId()
            || strtolower((string) ($mapping['environment'] ?? '')) !== $intent->environment()
            || trim((string) ($mapping['external_order_id'] ?? '')) === ''
        ) {
            throw new \RuntimeException('refund_payment_mapping_conflict');
        }
    }

    /**
     * @param array<string, mixed> $nativeSnapshot
     */
    private function assertOriginalPaid(array $nativeSnapshot): void
    {
        $invoice = $nativeSnapshot['invoice'] ?? [];
        $mapping = $nativeSnapshot['mapping'] ?? [];

        if (
            strtolower(trim((string) ($invoice['status'] ?? ''))) !== 'paid'
            || !empty($nativeSnapshot['transaction_refunded'])
            || !isset($invoice['amount'], $invoice['currency'], $mapping['amount'], $mapping['currency'])
            || !in_array(strtolower((string) ($mapping['status'] ?? '')), ['paid', 'paid_over'], true)
            || !AmountGuard::amountsEqual((string) $invoice['amount'], (string) $mapping['amount'])
            || strtoupper((string) $invoice['currency']) !== strtoupper((string) $mapping['currency'])
        ) {
            throw new \RuntimeException('refund_original_full_paid_required');
        }
    }

    /**
     * @param array<string, mixed> $nativeSnapshot
     * @return array<string, mixed>
     */
    private function buildFullDraft(array $nativeSnapshot): array
    {
        $amount = AmountGuard::normalizeDecimal((string) ($nativeSnapshot['mapping']['amount'] ?? ''));
        $currency = strtoupper(trim((string) ($nativeSnapshot['mapping']['currency'] ?? '')));
        $origTxId = trim((string) ($nativeSnapshot['mapping']['transaction_id'] ?? ''));

        if ($amount === null || $amount === '0' || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $amount) || $currency === '' || $origTxId === '') {
            throw new \RuntimeException('refund_native_precision_invalid');
        }

        $parts = explode('.', $amount, 2);
        $minor = ltrim($parts[0] . str_pad($parts[1] ?? '', 2, '0'), '0');
        if ($minor === '') {
            throw new \RuntimeException('refund_native_precision_invalid');
        }

        return [
            'amount_decimal' => $amount,
            'amount_minor' => $minor,
            'currency' => $currency,
            'original_transaction_id' => $origTxId,
        ];
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $nativeSnapshot
     */
    private function assertInvoiceScope(array $invoice, array $nativeSnapshot, RefundIntent $intent): void
    {
        $mapping = $nativeSnapshot['mapping'] ?? [];
        if (
            ($invoice['invoice_id'] ?? '') !== $intent->invoiceId()
            || ($invoice['project_id'] ?? '') !== $intent->projectId()
            || ($invoice['is_test'] ?? null) !== ($intent->environment() === Config::ENV_SANDBOX)
            || ($invoice['order']['external_id'] ?? '') !== (string) ($mapping['external_order_id'] ?? '')
            || !AmountGuard::amountsEqual($invoice['order']['amount'] ?? '', (string) ($mapping['amount'] ?? ''))
            || strtoupper((string) ($invoice['order']['currency'] ?? '')) !== strtoupper((string) ($mapping['currency'] ?? ''))
        ) {
            throw new \RuntimeException('refund_invoice_scope_conflict');
        }
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    private function invoiceSummary(array $invoice, string $paymentKey, string $paymosInvoiceId): array
    {
        if (array_key_exists('refund_summary', $invoice)) {
            return is_array($invoice['refund_summary']) ? $invoice['refund_summary'] : [];
        }

        $status = isset($invoice['status']) && is_string($invoice['status']) ? strtolower(trim($invoice['status'])) : '';
        $payment = isset($invoice['payment']) && is_array($invoice['payment']) ? $invoice['payment'] : [];
        $completedOverpayment = false;

        foreach ($this->store->forPayment($paymentKey) as $prior) {
            if (
                $prior->invoiceId() === $paymosInvoiceId
                && $prior->mode() === RefundStates::OVERPAYMENT
                && $prior->cashState() === RefundStates::COMPLETED
            ) {
                $completedOverpayment = true;
                break;
            }
        }

        if (!$completedOverpayment) {
            foreach ($this->store->externalObservations($paymentKey) as $ext) {
                $obs = $ext['observation'] ?? [];
                if (
                    ($obs['invoice_id'] ?? '') === $paymosInvoiceId
                    && ($obs['mode'] ?? '') === RefundStates::OVERPAYMENT
                    && ($obs['status'] ?? '') === 'completed'
                ) {
                    $completedOverpayment = true;
                    break;
                }
            }
        }

        return [
            'can_refund_full' => in_array($status, ['paid', 'paid_over'], true),
            'can_refund_overpayment' => $status === 'paid_over' && !$completedOverpayment,
            'full_refund_amount' => null,
            'overpayment_refund_amount' => null,
            'refund_asset' => isset($payment['currency'], $payment['network'])
                ? ['currency' => $payment['currency'], 'network' => $payment['network']]
                : null,
        ];
    }

    private function ambiguous(RefundIntent $intent, string $reason): NativeProjectionResult
    {
        return new NativeProjectionResult(
            NativeProjectionResult::AMBIGUOUS,
            $intent->mode(),
            null,
            null,
            null,
            null,
            null,
            $reason
        );
    }

    private function coordinator(RefundIntent $intent): RefundCoordinator
    {
        return new RefundCoordinator(
            $this->client($intent->environment()),
            $this->store,
            $this,
            fn (): \DateTimeImmutable => new \DateTimeImmutable('@' . $this->now())
        );
    }

    private function client(string $environment): Client
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($environment, $this->config);
        }

        return new Client($this->config->clientConfig($environment));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function nativeCall(string $command, array $input): array
    {
        $this->store->assertOwned();

        if ($this->native !== null) {
            return ($this->native)($command, $input);
        }

        if ($command === 'snapshot') {
            return $this->defaultLoadSnapshot((string) ($input['invoice_id'] ?? ''));
        }

        if ($command === 'refundLocally') {
            $transaction = $input['transaction'] ?? null;
            $amount = $input['amount'] ?? null;
            if (!is_object($transaction) || !class_exists(ExtensionHelper::class) || !method_exists(ExtensionHelper::class, 'refundLocally')) {
                throw new \RuntimeException('refund_native_api_unavailable');
            }
            ExtensionHelper::refundLocally($transaction, $amount);
            return ['ok' => true];
        }

        if ($command === 'recordOverpaymentNote') {
            $this->log('info', 'Paymos overpayment refund completed', [
                'invoice_id' => (string) ($input['invoice_id'] ?? ''),
                'withdrawal_id' => (string) ($input['withdrawal_id'] ?? ''),
                'amount' => (string) ($input['amount'] ?? ''),
                'asset' => (string) ($input['asset'] ?? ''),
            ]);
            return ['ok' => true];
        }

        throw new \RuntimeException('refund_native_command_unknown');
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultLoadSnapshot(string $paymentKey): array
    {
        $paidSnapshots = $this->snapshots->findPaidByPlatformRef($paymentKey);
        if ($paidSnapshots === []) {
            throw new \RuntimeException('refund_payment_mapping_missing');
        }
        $mapping = $paidSnapshots[0];

        if (!class_exists(Invoice::class)) {
            throw new \RuntimeException('refund_native_api_unavailable');
        }
        $invoice = Invoice::find($paymentKey);
        if (!$invoice instanceof Invoice) {
            throw new \RuntimeException('refund_payment_mapping_missing');
        }

        $currency = '';
        if (isset($invoice->currency_code) && is_string($invoice->currency_code)) {
            $currency = strtoupper(trim($invoice->currency_code));
        } elseif (isset($invoice->currency) && is_object($invoice->currency) && isset($invoice->currency->code)) {
            $currency = strtoupper(trim((string) $invoice->currency->code));
        }

        $txId = (string) ($mapping['transaction_id'] ?? '');
        $transaction = null;
        $transactionRefunded = false;
        if (class_exists(InvoiceTransaction::class)) {
            if (method_exists(InvoiceTransaction::class, 'where')) {
                $query = InvoiceTransaction::where('invoice_id', $invoice->id);
                if ($txId !== '' && is_object($query) && method_exists($query, 'where')) {
                    $query = $query->where('transaction_id', $txId);
                }
                if (is_object($query) && method_exists($query, 'first')) {
                    $transaction = $query->first();
                }
            } elseif (isset($invoice->transactions) && is_iterable($invoice->transactions)) {
                foreach ($invoice->transactions as $candidate) {
                    if (is_object($candidate) && (string) ($candidate->transaction_id ?? '') === $txId) {
                        $transaction = $candidate;
                        break;
                    }
                }
            }
        }

        if (is_object($transaction)) {
            $txStatus = isset($transaction->status) ? strtolower(trim((string) ($transaction->status instanceof \BackedEnum ? $transaction->status->value : $transaction->status))) : '';
            if ($txStatus === 'refunded' || !empty($transaction->is_refunded)) {
                $transactionRefunded = true;
            }
        }

        $invoiceAmount = (is_object($transaction) && isset($transaction->amount) && is_scalar($transaction->amount) && trim((string) $transaction->amount) !== '')
            ? trim((string) $transaction->amount)
            : (isset($invoice->total) && is_scalar($invoice->total) ? trim((string) $invoice->total) : '');

        return [
            'invoice' => [
                'id' => (string) $invoice->id,
                'status' => strtolower(trim((string) ($invoice->status ?? ''))),
                'amount' => $invoiceAmount,
                'currency' => $currency,
            ],
            'mapping' => $mapping,
            'transaction' => $transaction,
            'transaction_refunded' => $transactionRefunded,
        ];
    }

    private static function isValidUuidV4(string $token): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $token) === 1;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        ($this->logger)($level, $this->config->sanitizeMessage($message), $context);
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
