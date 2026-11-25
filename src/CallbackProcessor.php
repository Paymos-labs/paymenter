<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use Paymos\Client;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\EventInProgressException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\CommitAwareEventStoreInterface;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use Paymos\Webhook\WebhookEvent;

final class CallbackProcessor
{
    private Config $config;
    private SnapshotStore $snapshots;
    private CommitAwareEventStoreInterface $eventStore;

    /** @var callable|null */
    private $clientFactory;

    /** @var callable|null */
    private $refundHandler;

    /** @var callable */
    private $logger;

    public function __construct(
        Config $config,
        SnapshotStore $snapshots,
        CommitAwareEventStoreInterface $eventStore,
        ?callable $clientFactory = null,
        ?callable $refundHandler = null,
        ?callable $logger = null
    ) {
        $this->config = $config;
        $this->snapshots = $snapshots;
        $this->eventStore = $eventStore;
        $this->clientFactory = $clientFactory;
        $this->refundHandler = $refundHandler;
        $this->logger = $logger ?: static function (string $level, string $message, array $context): void {
        };
    }

    /**
     * Handle a signed Paymos webhook across Sandbox and Live environments.
     *
     * @param callable(string): (?array{id: string, amount: string, total?: string, currency: string, is_paid: bool}) $loadBillingInvoice
     * @param callable(string, string, string, array<string, mixed>): void                                            $applyPayment
     * @param callable(string, string, array<string, mixed>): void                                                    $recordNote
     * @return array{status: int, body: array<string, mixed>, duplicate: bool}
     */
    public function handle(
        string $rawBody,
        string $signatureHeader,
        callable $loadBillingInvoice,
        callable $applyPayment,
        callable $recordNote,
        ?int $now = null
    ): array {
        $reservedEventId = null;
        $refundDelivery = false;

        try {
            $verified = (new MultiEnvironmentWebhookVerifier($this->config->webhookSecrets(), $this->eventStore))
                ->process($signatureHeader, $rawBody, $now);
            $environment = $verified->environment();
            $event = $verified->event();
            $reservedEventId = $event->id();

            $payload = $event->toArray();
            $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

            // Route refund withdrawal.* events to RefundAdapter::callback()
            if (
                str_starts_with(strtolower($event->type()), 'withdrawal.')
                && (($data['type'] ?? '') === 'refund' || !empty($data['invoice_id']))
            ) {
                $refundDelivery = true;
                if ($this->refundHandler !== null) {
                    ($this->refundHandler)($event, $environment);
                }
                $this->commitEvent($reservedEventId);

                return [
                    'status' => 200,
                    'body' => ['ok' => true, 'event_type' => $event->type()],
                    'duplicate' => false,
                ];
            }

            if (!$event->isInvoiceEvent()) {
                $this->commitEvent($reservedEventId);

                return [
                    'status' => 200,
                    'body' => ['ok' => true, 'ignored' => true, 'event_type' => $event->type()],
                    'duplicate' => false,
                ];
            }

            $this->assertPayloadEnvironment($event, $environment);

            $outcome = $this->applyVerifiedEvent(
                $event,
                $environment,
                $loadBillingInvoice,
                $applyPayment,
                $recordNote,
                true,
                false
            );

            $this->commitEvent($reservedEventId);

            return [
                'status' => 200,
                'body' => $outcome,
                'duplicate' => false,
            ];
        } catch (DuplicateEventException $e) {
            return [
                'status' => 200,
                'body' => ['ok' => true, 'duplicate' => true],
                'duplicate' => true,
            ];
        } catch (EventInProgressException $e) {
            if ($refundDelivery && $reservedEventId !== null) {
                $this->releaseEvent($reservedEventId);
            }
            return [
                'status' => 409,
                'body' => ['error' => 'event_in_progress'],
                'duplicate' => false,
            ];
        } catch (SignatureMismatchException $e) {
            return [
                'status' => 401,
                'body' => ['error' => 'invalid_signature'],
                'duplicate' => false,
            ];
        } catch (TimestampSkewException $e) {
            return [
                'status' => 401,
                'body' => ['error' => 'timestamp_skew'],
                'duplicate' => false,
            ];
        } catch (\InvalidArgumentException $e) {
            if ($reservedEventId !== null) {
                $this->releaseEvent($reservedEventId);
            }
            $this->log('error', 'Paymos webhook configuration error: ' . $e->getMessage());
            return [
                'status' => 500,
                'body' => ['error' => 'configuration_error'],
                'duplicate' => false,
            ];
        } catch (\RuntimeException $e) {
            if ($reservedEventId !== null) {
                $this->releaseEvent($reservedEventId);
            }
            if ($e->getMessage() === 'refund_unbound_review') {
                return [
                    'status' => 409,
                    'body' => ['error' => 'refund_unbound_review'],
                    'duplicate' => false,
                ];
            }
            $this->log('warning', 'Paymos webhook rejected: ' . $e->getMessage());
            return [
                'status' => 400,
                'body' => ['error' => 'processing_failed', 'reason' => $this->config->sanitizeMessage($e->getMessage())],
                'duplicate' => false,
            ];
        } catch (\Throwable $e) {
            if ($reservedEventId !== null) {
                $this->releaseEvent($reservedEventId);
            }
            $this->log('error', 'Paymos webhook internal failure: ' . $e->getMessage());
            return [
                'status' => 500,
                'body' => ['error' => 'internal_error'],
                'duplicate' => false,
            ];
        }
    }

    /**
     * Apply a live invoice fetched by Reconciler through the exact same guards as webhooks.
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $snapshot
     * @param callable(string): (?array{id: string, amount: string, total?: string, currency: string, is_paid: bool}) $loadBillingInvoice
     * @param callable(string, string, string, array<string, mixed>): void                                            $applyPayment
     * @param callable(string, string, array<string, mixed>): void                                                    $recordNote
     */
    public function applyTrustedInvoice(
        array $invoice,
        array $snapshot,
        callable $loadBillingInvoice,
        callable $applyPayment,
        callable $recordNote,
        int $now,
        ?string $overrideEventType = null
    ): bool {
        $invoiceId = isset($invoice['invoice_id']) && is_scalar($invoice['invoice_id']) ? (string) $invoice['invoice_id'] : '';
        $status = isset($invoice['status']) && is_scalar($invoice['status']) ? strtolower(trim((string) $invoice['status'])) : '';
        $eventType = $overrideEventType ?? self::eventTypeForStatus($status);

        $event = new WebhookEvent([
            'event_id' => 'reconcile_' . $invoiceId . '_' . ($overrideEventType === 'invoice.paid_late' ? 'paid_late' : $status),
            'event_type' => $eventType,
            'version' => 1,
            'occurred_at' => $now,
            'data' => $invoice,
        ]);

        $outcome = $this->applyVerifiedEvent(
            $event,
            (string) $snapshot['environment'],
            $loadBillingInvoice,
            $applyPayment,
            $recordNote,
            false,
            true
        );

        return !empty($outcome['applied']);
    }

    /**
     * @param callable(string): (?array{id: string, amount: string, total?: string, currency: string, is_paid: bool}) $loadBillingInvoice
     * @param callable(string, string, string, array<string, mixed>): void                                            $applyPayment
     * @param callable(string, string, array<string, mixed>): void                                                    $recordNote
     * @return array<string, mixed>
     */
    private function applyVerifiedEvent(
        WebhookEvent $event,
        string $environment,
        callable $loadBillingInvoice,
        callable $applyPayment,
        callable $recordNote,
        bool $reverseVerify,
        bool $fromCron
    ): array {
        $externalOrderId = $event->externalOrderId();
        $snapshot = $externalOrderId !== '' ? $this->snapshots->findByExternalOrderId($externalOrderId) : null;
        if ($snapshot === null && $event->invoiceId() !== '') {
            $snapshot = $this->snapshots->findByPaymosInvoiceId($event->invoiceId());
        }
        if ($snapshot === null) {
            throw new \RuntimeException('Paymos Paymenter invoice snapshot was not found.');
        }

        $this->assertSnapshotMatchesEvent($snapshot, $event, $environment);

        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        $platformRef = (string) $snapshot['platform_ref'];
        $recordedStatus = (string) ($snapshot['status'] ?? '');

        // Stale-after-final guard (checked BEFORE reverse-verify for everything except paid_late):
        // nothing leaves a terminal status on the server, so late out-of-order events for a finalized
        // snapshot are ignored cleanly with 200 without redundant API calls or retry loops.
        if ($action !== StatusMapper::ACTION_NOTE_PAID_LATE && StatusMapper::isFinalStatus($recordedStatus)) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'stale_after_final',
                'applied' => false,
            ];
        }

        // Reverse-verify terminal and paid_late events before trusting webhook state.
        if ($reverseVerify && $this->requiresReverseVerify($action)) {
            $verification = (new InvoiceReverseVerifier($this->client($environment)))->verify($event, [
                'invoice_id' => (string) $snapshot['paymos_invoice_id'],
                'project_id' => (string) $snapshot['project_id'],
                'external_order_id' => (string) $snapshot['external_order_id'],
                'amount' => (string) $snapshot['amount'],
                'currency' => (string) $snapshot['currency'],
                'environment' => $environment,
            ]);

            if (!$verification->isVerified()) {
                $recordNote($platformRef, 'Paymos manual review: reverse verification failed (' . $verification->reason() . ').', [
                    'reason' => $verification->reason(),
                    'invoice_id' => $event->invoiceId(),
                    'event_id' => $event->id(),
                ]);
                throw new \RuntimeException('Paymos reverse verification failed: ' . $verification->reason());
            }
        }

        // invoice.paid_late: note-only after terminal unpaid status — never credits or reopens the invoice.
        if ($action === StatusMapper::ACTION_NOTE_PAID_LATE) {
            $firstTime = $this->snapshots->markPaidLateNoted((string) $snapshot['external_order_id'], $event->id());
            if ($firstTime) {
                $recordNote(
                    $platformRef,
                    'Paymos: customer finished paying after the invoice closed (paid_late). Review manually before fulfilling or refunding. Invoice: '
                    . $event->invoiceId(),
                    [
                        'event_id' => $event->id(),
                        'invoice_id' => $event->invoiceId(),
                        'paid_late_at' => $event->paidLateAt(),
                    ]
                );
            }

            return [
                'ok' => true,
                'action' => $action,
                'paid_late_noted' => true,
                'applied' => $firstTime,
            ];
        }

        $billingInvoice = $loadBillingInvoice($platformRef);
        if (!is_array($billingInvoice)) {
            if ($fromCron) {
                $this->snapshots->updateStatusByExternalOrderId(
                    (string) $snapshot['external_order_id'],
                    SnapshotStore::STATUS_INVOICE_MISSING
                );
                return ['ok' => true, 'ignored' => true, 'reason' => 'billing_invoice_missing', 'applied' => false];
            }
            throw new \RuntimeException('Paymenter invoice was not found: ' . $platformRef);
        }

        // Roll-back & double-payment guard: an already-paid Paymenter invoice must never
        // be downgraded or credited a second time.
        if (!empty($billingInvoice['is_paid']) && self::isCompleteOrDowngrade($action)) {
            $this->snapshots->updateStatusByExternalOrderId((string) $snapshot['external_order_id'], 'paid');
            $recordNote($platformRef, 'Paymos: ignored ' . $event->type() . ' because Paymenter invoice is already paid.', [
                'event_id' => $event->id(),
                'invoice_id' => $event->invoiceId(),
            ]);

            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'already_paid',
                'applied' => false,
            ];
        }

        if ($action !== StatusMapper::ACTION_PAYMENT_COMPLETE) {
            $nextStatus = $event->status() !== '' ? $event->status() : $action;
            $this->snapshots->updateStatusByExternalOrderId((string) $snapshot['external_order_id'], $nextStatus);
            if (in_array($action, [StatusMapper::ACTION_CANCEL_ORDER, StatusMapper::ACTION_FAIL_ORDER], true)) {
                $recordNote($platformRef, 'Paymos invoice closed without full payment (' . $nextStatus . ').', [
                    'event_id' => $event->id(),
                    'invoice_id' => $event->invoiceId(),
                    'status' => $nextStatus,
                ]);
            }

            return [
                'ok' => true,
                'status' => $nextStatus,
                'applied' => false,
            ];
        }

        // AmountGuard triangulation: snapshot ↔ current Paymenter invoice ↔ webhook order.
        $billingAmount = (string) ($billingInvoice['amount'] ?? '');
        $billingCurrency = strtoupper(trim((string) ($billingInvoice['currency'] ?? '')));

        $safe = AmountGuard::isSafeToComplete(
            $snapshot['amount'],
            $snapshot['currency'],
            $billingAmount,
            $billingCurrency,
            $event->orderAmount(),
            $event->orderCurrency()
        );

        if (!$safe) {
            $summary = AmountGuard::mismatchSummary(
                $snapshot['amount'],
                $snapshot['currency'],
                $billingAmount,
                $billingCurrency,
                $event->orderAmount(),
                $event->orderCurrency()
            );
            $recordNote($platformRef, 'Manual review: ' . $summary, [
                'event_id' => $event->id(),
                'invoice_id' => $event->invoiceId(),
                'reason' => 'manual_review',
            ]);
            throw new \RuntimeException('Paymos payment amount or currency mismatch requires manual review.');
        }

        $transactionId = self::extractTransactionId($event);
        $creditAmount = AmountGuard::normalizeDecimal($snapshot['amount']);
        if ($creditAmount === null) {
            throw new \RuntimeException('Paymos snapshot amount is invalid.');
        }

        $payload = $event->toArray();
        $eventData = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        $applyPayment($platformRef, $creditAmount, $transactionId, $eventData);

        $finalStatus = $event->status() !== '' ? strtolower(trim($event->status())) : 'paid';
        $this->snapshots->markCompleted((string) $snapshot['external_order_id'], $transactionId, $finalStatus);

        return [
            'ok' => true,
            'status' => $finalStatus,
            'transaction_id' => $transactionId,
            'applied' => true,
        ];
    }

    /**
     * Extract confirmed on-chain tx_hash from data.payment.transfers[], falling back to data.invoice_id.
     */
    public static function extractTransactionId(WebhookEvent $event): string
    {
        $payload = $event->toArray();
        $transfers = $payload['data']['payment']['transfers'] ?? null;
        if (is_array($transfers)) {
            $lastConfirmed = '';
            foreach ($transfers as $transfer) {
                if (!is_array($transfer)) {
                    continue;
                }
                $status = strtolower(trim((string) ($transfer['status'] ?? '')));
                $txHash = isset($transfer['tx_hash']) && is_scalar($transfer['tx_hash'])
                    ? trim((string) $transfer['tx_hash'])
                    : '';
                if ($status === 'confirmed' && $txHash !== '') {
                    $lastConfirmed = $txHash;
                }
            }
            if ($lastConfirmed !== '') {
                return $lastConfirmed;
            }
        }

        $invoiceId = $event->invoiceId();

        return $invoiceId !== '' ? $invoiceId : $event->id();
    }

    private function assertPayloadEnvironment(WebhookEvent $event, string $environment): void
    {
        $isTest = $event->isTest();
        if ($isTest === null) {
            return;
        }
        if ($environment === Config::ENV_SANDBOX && $isTest !== true) {
            throw new \RuntimeException('Sandbox webhook payload is not marked as test.');
        }
        if ($environment === Config::ENV_LIVE && $isTest !== false) {
            throw new \RuntimeException('Live webhook payload is marked as test.');
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function assertSnapshotMatchesEvent(array $snapshot, WebhookEvent $event, string $environment): void
    {
        if (strtolower((string) ($snapshot['environment'] ?? '')) !== $environment) {
            throw new \RuntimeException('Paymos event environment does not match Paymenter invoice snapshot.');
        }
        if (($snapshot['project_id'] ?? '') !== '' && $event->projectId() !== '' && (string) $snapshot['project_id'] !== $event->projectId()) {
            throw new \RuntimeException('Paymos event project does not match Paymenter invoice snapshot.');
        }
        if (($snapshot['external_order_id'] ?? '') !== '' && $event->externalOrderId() !== '' && (string) $snapshot['external_order_id'] !== $event->externalOrderId()) {
            throw new \RuntimeException('Paymos event external order id does not match Paymenter invoice snapshot.');
        }
        if (($snapshot['paymos_invoice_id'] ?? '') !== '' && $event->invoiceId() !== '' && (string) $snapshot['paymos_invoice_id'] !== $event->invoiceId()) {
            throw new \RuntimeException('Paymos event invoice id does not match Paymenter invoice snapshot.');
        }
    }

    private function requiresReverseVerify(string $action): bool
    {
        return in_array($action, [
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
            StatusMapper::ACTION_NOTE_PAID_LATE,
        ], true);
    }

    private static function isCompleteOrDowngrade(string $action): bool
    {
        return in_array($action, [
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_CONFIRMING,
            StatusMapper::ACTION_AWAITING_PAYMENT,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ], true);
    }

    private static function eventTypeForStatus(string $status): string
    {
        return match (StatusMapper::invoiceAction('', $status)) {
            StatusMapper::ACTION_CONFIRMING => 'invoice.confirming',
            StatusMapper::ACTION_AWAITING_PAYMENT => 'invoice.underpaid_waiting',
            StatusMapper::ACTION_PAYMENT_COMPLETE => ($status === 'paid_over') ? 'invoice.paid_over' : 'invoice.paid',
            StatusMapper::ACTION_FAIL_ORDER => 'invoice.underpaid',
            StatusMapper::ACTION_CANCEL_ORDER => ($status === 'expired') ? 'invoice.expired' : 'invoice.cancelled',
            default => 'invoice.updated',
        };
    }

    private function client(string $environment): Client
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($environment, $this->config);
        }

        return new Client($this->config->clientConfig($environment));
    }

    private function commitEvent(string $eventId): void
    {
        if (method_exists($this->eventStore, 'commit')) {
            if ($this->eventStore->commit($eventId) !== true) {
                throw new \RuntimeException('Webhook event reservation could not be committed.');
            }
        }
    }

    private function releaseEvent(string $eventId): void
    {
        if (method_exists($this->eventStore, 'release')) {
            try {
                $this->eventStore->release($eventId);
            } catch (\Throwable $e) {
                // Preserve original failure; reservation expires naturally.
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        ($this->logger)($level, $this->config->sanitizeMessage($message), $context);
    }
}
