<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use Paymos\Client;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceReconciler;
use Paymos\Plugin\StatusMapper;

final class Reconciler
{
    public const THROTTLE_SECONDS = 600;
    public const LOOKBACK_SECONDS = 86400;
    public const BATCH_LIMIT = 50;
    public const THROTTLE_KEY = 'reconcile_last_run';
    public const SCHEDULE_JITTER_SECONDS = 30;
    private const CURSOR_KEY = 'reconcile_cursor';

    private Config $config;
    private CredentialStore $credentialStore;
    private SnapshotStore $snapshots;
    private CallbackProcessor $callbackProcessor;

    /** @var callable|null */
    private $clientFactory;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $logger;

    public function __construct(
        Config $config,
        CredentialStore $credentialStore,
        SnapshotStore $snapshots,
        CallbackProcessor $callbackProcessor,
        ?callable $clientFactory = null,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->config = $config;
        $this->credentialStore = $credentialStore;
        $this->snapshots = $snapshots;
        $this->callbackProcessor = $callbackProcessor;
        $this->clientFactory = $clientFactory;
        $this->clock = $clock ?: static fn (): int => time();
        $this->logger = $logger ?: static function (string $level, string $message, array $context): void {
        };
    }

    /**
     * Sweep up to 50 unpaid Paymos invoices created within the last 24 hours,
     * throttled to at most once every 600 seconds unless $force is true.
     *
     * @param callable(string): (?array{id: string, amount: string, total?: string, currency: string, is_paid: bool}) $loadBillingInvoice
     * @param callable(string, string, string, array<string, mixed>): void                                            $applyPayment
     * @param callable(string, string, array<string, mixed>): void                                                    $recordNote
     */
    public function run(
        callable $loadBillingInvoice,
        callable $applyPayment,
        callable $recordNote,
        bool $force = false
    ): int {
        $now = $this->now();

        if (!$force && !$this->isDue($now)) {
            return 0;
        }

        $this->credentialStore->setSetting(self::THROTTLE_KEY, (string) $now);

        // More than one batch of open invoices: each run continues where the previous one stopped,
        // and starts again from the newest once the end is reached.
        $rows = $this->snapshots->findUnpaidRecent(self::BATCH_LIMIT, $now - self::LOOKBACK_SECONDS, $this->readCursor());
        $last = $rows === [] ? null : $rows[count($rows) - 1];
        if (count($rows) >= self::BATCH_LIMIT && $last !== null) {
            $this->credentialStore->setSetting(self::CURSOR_KEY, (string) json_encode([
                'created_at' => (int) ($last['created_at'] ?? 0),
                'external_order_id' => (string) ($last['external_order_id'] ?? ''),
            ]));
        } else {
            $this->credentialStore->deleteSetting(self::CURSOR_KEY);
        }
        $appliedCount = 0;

        foreach ($rows as $row) {
            if (CheckoutService::isPendingIntent($row)) {
                continue;
            }

            $environment = strtolower(trim((string) ($row['environment'] ?? '')));
            $invoiceId = trim((string) ($row['paymos_invoice_id'] ?? ''));
            if ($environment === '' || $invoiceId === '' || !$this->config->hasEnvironment($environment)) {
                continue;
            }

            try {
                $reconciler = new InvoiceReconciler($this->client($environment));
                $results = $reconciler->reconcile([$invoiceId]);
                if (!isset($results[0])) {
                    continue;
                }

                $invoice = $results[0]->invoice();
                if (!self::snapshotMatches($row, $invoice)) {
                    $this->log('warning', 'Paymos reconciler snapshot mismatch', [
                        'paymos_invoice_id' => $invoiceId,
                        'external_order_id' => (string) ($row['external_order_id'] ?? ''),
                    ]);
                    continue;
                }

                if (!empty($invoice['paid_late_at']) && empty($row['paid_late_noted'])) {
                    $this->callbackProcessor->applyTrustedInvoice(
                        $invoice,
                        $row,
                        $loadBillingInvoice,
                        $applyPayment,
                        $recordNote,
                        $now,
                        'invoice.paid_late'
                    );
                }

                $status = strtolower(trim((string) ($invoice['status'] ?? '')));
                if (StatusMapper::invoiceAction('', $status) === StatusMapper::ACTION_IGNORE) {
                    continue;
                }

                if ($this->callbackProcessor->applyTrustedInvoice(
                    $invoice,
                    $row,
                    $loadBillingInvoice,
                    $applyPayment,
                    $recordNote,
                    $now
                )) {
                    $appliedCount++;
                }
            } catch (\Throwable $e) {
                $this->log('error', 'Paymos reconciler row error: ' . $e->getMessage(), [
                    'paymos_invoice_id' => $invoiceId,
                    'external_order_id' => (string) ($row['external_order_id'] ?? ''),
                ]);
            }
        }

        return $appliedCount;
    }

    /**
     * The Laravel schedule fires every 10 minutes, but each start lands a second or two apart;
     * a strict 600 s check would skip every other run when one start drifts.
     */
    private function isDue(int $now): bool
    {
        $raw = $this->credentialStore->getSetting(self::THROTTLE_KEY);
        $lastRun = ($raw !== null && is_numeric($raw)) ? (int) $raw : 0;

        return $lastRun <= 0 || ($now - $lastRun) >= self::THROTTLE_SECONDS - self::SCHEDULE_JITTER_SECONDS;
    }

    /**
     * @return array{created_at: int, external_order_id: string}|null
     */
    private function readCursor(): ?array
    {
        $raw = $this->credentialStore->getSetting(self::CURSOR_KEY);
        $decoded = $raw !== null && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)
            || !isset($decoded['created_at'], $decoded['external_order_id'])
            || !is_numeric($decoded['created_at'])
            || !is_string($decoded['external_order_id'])
        ) {
            return null;
        }

        return ['created_at' => (int) $decoded['created_at'], 'external_order_id' => $decoded['external_order_id']];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $invoice
     */
    private static function snapshotMatches(array $row, array $invoice): bool
    {
        if (!array_key_exists('is_test', $invoice) || !is_bool($invoice['is_test'])) {
            return false;
        }
        $expectedIsTest = strtolower(trim((string) ($row['environment'] ?? ''))) === 'sandbox';
        if ($invoice['is_test'] !== $expectedIsTest) {
            return false;
        }

        $projectId = self::field($invoice, ['project_id']);
        $externalId = self::field($invoice, ['order', 'external_id']);
        $amount = self::field($invoice, ['order', 'amount']);
        $currency = strtoupper(self::field($invoice, ['order', 'currency']));

        return self::requiredEqual((string) ($row['project_id'] ?? ''), $projectId)
            && self::requiredEqual((string) ($row['external_order_id'] ?? ''), $externalId)
            && self::requiredAmountEqual((string) ($row['amount'] ?? ''), $amount)
            && self::requiredEqual(strtoupper((string) ($row['currency'] ?? '')), $currency);
    }

    private static function requiredEqual(string $expected, string $actual): bool
    {
        $expected = trim($expected);
        $actual = trim($actual);

        return $expected !== '' && $actual !== '' && $expected === $actual;
    }

    private static function requiredAmountEqual(string $expected, string $actual): bool
    {
        $expected = trim($expected);
        $actual = trim($actual);

        return $expected !== '' && $actual !== '' && AmountGuard::amountsEqual($expected, $actual);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $path
     */
    private static function field(array $payload, array $path): string
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? trim((string) $current) : '';
    }

    private function client(string $environment): Client
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($environment, $this->config);
        }

        return new Client($this->config->clientConfig($environment));
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
