<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use Paymos\Client;
use Paymos\Exception\ApiException;
use Paymos\Exception\NotFoundException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoicePresentation;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymos\Plugin\InvoiceReplacementResult;
use Paymos\Plugin\StatusMapper;

final class CheckoutService
{
    public const STATUS_CREATING = SnapshotStore::STATUS_CREATING;

    private Config $config;
    private SnapshotStore $snapshots;

    /** @var callable|null */
    private $clientFactory;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $logger;

    public function __construct(
        Config $config,
        SnapshotStore $snapshots,
        ?callable $clientFactory = null,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->config = $config;
        $this->snapshots = $snapshots;
        $this->clientFactory = $clientFactory;
        $this->clock = $clock ?: static fn (): int => time();
        $this->logger = $logger ?: static function (string $level, string $message, array $context): void {
        };
    }

    /**
     * Create or safely reuse/replace a Paymos hosted invoice for a Paymenter invoice.
     *
     * @param array<string, mixed> $context Keys: platform_ref, amount, invoice_total?, currency, client_id?, items?, locale?
     * @return array{invoice_id: string, payment_url: string, external_order_id: string, reused: bool}
     */
    public function createCheckout(array $context): array
    {
        $platformRef = trim((string) ($context['platform_ref'] ?? ''));
        if ($platformRef === '') {
            throw new \InvalidArgumentException('Paymenter invoice identifier is required.');
        }

        $normalizedAmount = AmountGuard::normalizeDecimal($context['amount'] ?? null);
        if ($normalizedAmount === null || $normalizedAmount === '0' || $normalizedAmount[0] === '-') {
            throw new \InvalidArgumentException('Paymenter invoice amount must be a positive decimal.');
        }

        $normalizedInvoiceTotal = null;
        if (isset($context['invoice_total']) && $context['invoice_total'] !== '') {
            $candidateTotal = AmountGuard::normalizeDecimal($context['invoice_total']);
            if ($candidateTotal !== null && $candidateTotal !== '0' && $candidateTotal[0] !== '-') {
                $normalizedInvoiceTotal = $candidateTotal;
            }
        }

        $currency = strtoupper(trim((string) ($context['currency'] ?? '')));
        if ($currency === '') {
            throw new \InvalidArgumentException('Paymenter invoice currency is required.');
        }

        $clientId = self::sanitizeClientId($context['client_id'] ?? null);
        $rawItems = isset($context['items']) && is_iterable($context['items']) ? $context['items'] : [];
        $products = self::extractProducts($rawItems);

        $existing = $this->snapshots->findLatestForPlatformRef($platformRef);

        if ($existing !== null && self::isPendingIntent($existing)) {
            // Replay the exact persisted intent before considering reuse or replacement.
            $existing = $this->recoverPendingIntent($existing, $clientId, $products);
        }

        if ($existing !== null && !empty($existing['paid_late_noted'])) {
            $this->blockPaidLate($existing, 'Paymos invoice was paid late and requires manual review.');
        }

        if ($existing !== null && $this->snapshotMatches($existing, $normalizedAmount, $currency)
            && $this->keepsExistingInvoice($existing)) {
            return [
                'invoice_id' => (string) $existing['paymos_invoice_id'],
                'payment_url' => (string) $existing['payment_url'],
                'external_order_id' => (string) $existing['external_order_id'],
                'reused' => true,
            ];
        }

        if ($existing !== null) {
            $this->closeBeforeReplacing($existing);
        }

        $renewCount = $existing !== null ? ((int) ($existing['renewal_attempt'] ?? 0) + 1) : 0;
        $externalOrderId = 'paymenter_' . $platformRef . '_' . $renewCount;

        $payload = [
            'project_id' => $this->config->projectId(),
            'amount' => $normalizedAmount,
            'currency' => $currency,
            'external_order_id' => $externalOrderId,
        ];
        if ($clientId !== null) {
            $payload['client_id'] = $clientId;
        }
        if ($products !== null) {
            $payload['products'] = $products;
        }

        // Persist STATUS_CREATING intent BEFORE sending POST /v1/invoices so a crash
        // or dropped HTTP response replays this exact operation without minting a duplicate.
        $intent = [
            'external_order_id' => $externalOrderId,
            'paymos_invoice_id' => self::pendingInvoiceId($externalOrderId),
            'platform_ref' => $platformRef,
            'client_id' => $clientId,
            'amount' => $normalizedAmount,
            'invoice_total' => $normalizedInvoiceTotal,
            'currency' => $currency,
            'environment' => $this->config->environment(),
            'project_id' => $this->config->projectId(),
            'payment_url' => '',
            'status' => self::STATUS_CREATING,
            'renewal_attempt' => $renewCount,
        ];
        $this->snapshots->save($intent);

        try {
            $response = $this->client()->invoices()->create($payload);
        } catch (ApiException $e) {
            $this->log('error', 'Paymos invoice creation failed', [
                'platform_ref' => $platformRef,
                'external_order_id' => $externalOrderId,
                'status_code' => $e->statusCode(),
                'code' => $e->errorCode(),
                'field' => $e->field(),
                'detail' => $this->config->sanitizeMessage((string) ($e->detail() ?? $e->getMessage())),
            ]);
            throw $e;
        }

        $bound = $this->bindCreatedInvoice($intent, $response);

        return [
            'invoice_id' => (string) $bound['paymos_invoice_id'],
            'payment_url' => (string) $bound['payment_url'],
            'external_order_id' => (string) $bound['external_order_id'],
            'reused' => false,
        ];
    }

    /**
     * Produce a buyer-safe localized message for checkout failures without exposing secrets.
     */
    public static function safeFailureMessage(\Throwable $e, ?string $locale = null): string
    {
        if ($e instanceof InvoiceReplacementBlockedException) {
            return Translation::text('replacement_blocked', $locale);
        }

        if ($e instanceof ApiException) {
            $detail = $e->detail();
            if ($detail !== null && trim($detail) !== '' && Translation::isEnglish($locale)) {
                $safe = Config::redactSecrets(trim($detail));
                $field = $e->field();
                if ($field !== null && trim($field) !== '') {
                    $safe .= ' (' . trim($field) . ')';
                }
                return $safe;
            }

            return Translation::text('payment_cannot_be_used', $locale);
        }

        return Translation::text('payment_unavailable', $locale);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function isPendingIntent(array $row): bool
    {
        return strtolower(trim((string) ($row['status'] ?? ''))) === self::STATUS_CREATING;
    }

    /**
     * Extract 1..50 valid Paymos products[] entries from Paymenter invoice items.
     * Falls back to null (amount-only) if empty, above 50, or any item is invalid or non-positive.
     *
     * @param iterable<mixed> $rawItems
     * @return array<int, array<string, mixed>>|null
     */
    public static function extractProducts(iterable $rawItems): ?array
    {
        $candidates = [];
        foreach ($rawItems as $item) {
            $data = self::itemToArray($item);
            if ($data === null) {
                return null;
            }

            if (self::isExcludedItem($data)) {
                continue;
            }

            [$title, $description] = self::extractItemTitleAndDescription($data);
            $quantity = self::extractItemQuantity($data);
            $rawUnitAmount = self::extractItemUnitAmount($data);
            $formattedAmount = self::formatPositiveUnitAmount($rawUnitAmount);
            if ($formattedAmount === null) {
                return null;
            }

            $candidates[] = [
                'title' => $title,
                'description' => $description,
                'quantity' => $quantity,
                'unit_amount' => $formattedAmount,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        return InvoicePresentation::normalizeProducts($candidates);
    }

    /**
     * Replay the persisted STATUS_CREATING intent with identical operation identity.
     *
     * @param array<string, mixed>                  $intent
     * @param array<int, array<string, mixed>>|null $products
     * @return array<string, mixed>
     */
    private function recoverPendingIntent(array $intent, ?string $clientId, ?array $products): array
    {
        $payload = [
            'project_id' => (string) $intent['project_id'],
            'amount' => (string) $intent['amount'],
            'currency' => (string) $intent['currency'],
            'external_order_id' => (string) $intent['external_order_id'],
        ];
        $effectiveClientId = self::sanitizeClientId($intent['client_id'] ?? $clientId);
        if ($effectiveClientId !== null) {
            $payload['client_id'] = $effectiveClientId;
        }
        if ($products !== null) {
            $payload['products'] = $products;
        }

        $env = (string) ($intent['environment'] ?? $this->config->environment());
        $response = $this->client($env)->invoices()->create($payload);

        return $this->bindCreatedInvoice($intent, $response);
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function bindCreatedInvoice(array $intent, array $response): array
    {
        $paymosInvoiceId = isset($response['invoice_id']) && is_scalar($response['invoice_id'])
            ? trim((string) $response['invoice_id'])
            : '';
        $paymentUrl = isset($response['payment_url']) && is_scalar($response['payment_url'])
            ? trim((string) $response['payment_url'])
            : '';

        if ($paymosInvoiceId === '' || $paymentUrl === '') {
            throw new \RuntimeException('Paymos invoice create response is missing invoice_id or payment_url.');
        }

        $status = isset($response['status']) && is_scalar($response['status'])
            ? strtolower(trim((string) $response['status']))
            : '';

        $row = $intent;
        $row['paymos_invoice_id'] = $paymosInvoiceId;
        $row['payment_url'] = $paymentUrl;
        $row['expires_at'] = isset($response['expires_at']) && is_numeric($response['expires_at'])
            ? (int) $response['expires_at']
            : null;
        // Leave native apply open if a recovered idempotent response already reached a terminal status.
        $row['status'] = StatusMapper::isFinalStatus($status) ? 'created' : ($status !== '' ? $status : 'created');

        $this->snapshots->save($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function snapshotMatches(array $row, string $amount, string $currency): bool
    {
        return AmountGuard::amountsEqual($row['amount'] ?? '', $amount)
            && strtoupper(trim((string) ($row['currency'] ?? ''))) === strtoupper($currency)
            && trim((string) ($row['project_id'] ?? '')) === $this->config->projectId()
            && strtolower(trim((string) ($row['environment'] ?? ''))) === $this->config->environment()
            && trim((string) ($row['payment_url'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function keepsExistingInvoice(array $row): bool
    {
        $now = $this->now();
        if (InvoiceRenewal::isRequired($row, $now)) {
            return false;
        }
        if (StatusMapper::isFinalStatus((string) ($row['status'] ?? ''))) {
            return true;
        }

        try {
            $invoice = $this->client((string) $row['environment'])->invoices()->get((string) $row['paymos_invoice_id']);
        } catch (NotFoundException $e) {
            return false;
        } catch (\Exception $e) {
            return true;
        }

        if (is_array($invoice) && !empty($invoice['paid_late_at'])) {
            $marker = is_scalar($invoice['paid_late_at']) ? (string) $invoice['paid_late_at'] : '1';
            $this->snapshots->markPaidLateNoted((string) $row['external_order_id'], $marker);
            $row['paid_late_noted'] = $marker;
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }

        if (!InvoiceRenewal::isRequired($invoice, $now)) {
            return true;
        }

        $status = isset($invoice['status']) && is_scalar($invoice['status']) ? strtolower(trim((string) $invoice['status'])) : '';
        if ($status !== '') {
            $this->snapshots->updateStatus((string) $row['paymos_invoice_id'], $status);
        }

        return false;
    }

    /**
     * Fail-closed close of a previous Paymos invoice before minting a replacement.
     *
     * @param array<string, mixed> $row
     */
    private function closeBeforeReplacing(array $row): void
    {
        if (!empty($row['paid_late_noted'])) {
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }

        $environment = (string) ($row['environment'] ?? $this->config->environment());
        $recorded = (string) ($row['status'] ?? '');
        $observed = new \stdClass();
        $observed->invoice = null;

        $replacement = new InvoiceReplacement(function () use ($environment, $observed) {
            $client = $this->client($environment);

            return new class($client, $observed) {
                private Client $client;
                private \stdClass $observed;

                public function __construct(Client $client, \stdClass $observed)
                {
                    $this->client = $client;
                    $this->observed = $observed;
                }

                public function invoices(): object
                {
                    return new class($this->client->invoices(), $this->observed) {
                        private object $invoices;
                        private \stdClass $observed;

                        public function __construct(object $invoices, \stdClass $observed)
                        {
                            $this->invoices = $invoices;
                            $this->observed = $observed;
                        }

                        public function cancel(string $invoiceId, string $reason): array
                        {
                            $response = $this->invoices->cancel($invoiceId, $reason);
                            if (is_array($response) && (!empty($response['paid_late_at']) || !is_array($this->observed->invoice) || empty($this->observed->invoice['paid_late_at']))) {
                                $this->observed->invoice = $response;
                            }
                            return $response;
                        }

                        public function get(string $invoiceId): array
                        {
                            $response = $this->invoices->get($invoiceId);
                            if (is_array($response) && (!empty($response['paid_late_at']) || !is_array($this->observed->invoice) || empty($this->observed->invoice['paid_late_at']))) {
                                $this->observed->invoice = $response;
                            }
                            return $response;
                        }
                    };
                }
            };
        });

        $result = $replacement->close((string) ($row['paymos_invoice_id'] ?? ''), $recorded);

        if (is_array($observed->invoice) && !empty($observed->invoice['paid_late_at'])) {
            $marker = is_scalar($observed->invoice['paid_late_at']) ? (string) $observed->invoice['paid_late_at'] : '1';
            $this->snapshots->markPaidLateNoted((string) $row['external_order_id'], $marker);
            $row['paid_late_noted'] = $marker;
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }

        if ($result->isClosed()) {
            if ($result->status() !== '' && $result->status() !== $recorded) {
                $this->snapshots->updateStatus((string) $row['paymos_invoice_id'], $result->status());
            }
            return;
        }

        $this->log('warning', 'Manual review: ' . $result->summary(), [
            'platform_ref' => (string) ($row['platform_ref'] ?? ''),
            'paymos_invoice_id' => $result->invoiceId(),
            'status' => $result->status(),
            'reason' => $result->reason(),
            'detail' => $this->config->sanitizeMessage($result->detail()),
        ]);

        throw new InvoiceReplacementBlockedException($result);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function blockPaidLate(array $row, string $detail): never
    {
        $result = InvoiceReplacementResult::blocked(
            (string) ($row['paymos_invoice_id'] ?? ''),
            (string) ($row['status'] ?? ''),
            InvoiceReplacementResult::REASON_PAID,
            $detail
        );

        $this->log('warning', 'Manual review: ' . $result->summary(), [
            'platform_ref' => (string) ($row['platform_ref'] ?? ''),
            'paymos_invoice_id' => $result->invoiceId(),
            'status' => $result->status(),
            'reason' => $result->reason(),
            'detail' => $detail,
        ]);

        throw new InvoiceReplacementBlockedException($result);
    }

    private function client(?string $environment = null): Client
    {
        $env = $environment ?? $this->config->environment();
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($env, $this->config);
        }

        return new Client($this->config->clientConfig($env));
    }

    private static function pendingInvoiceId(string $externalOrderId): string
    {
        return 'pending:' . $externalOrderId;
    }

    private static function sanitizeClientId(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '' || $trimmed === '0' || str_contains($trimmed, '@')) {
            return null;
        }

        return $trimmed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function itemToArray(mixed $item): ?array
    {
        if (is_array($item)) {
            return $item;
        }
        if (is_object($item)) {
            if (method_exists($item, 'toArray')) {
                $converted = $item->toArray();
                if (is_array($converted)) {
                    return $converted;
                }
            }
            $data = [];
            foreach (['title', 'name', 'description', 'quantity', 'qty', 'unit_amount', 'unit_price', 'price', 'amount', 'type'] as $prop) {
                if (isset($item->$prop)) {
                    $data[$prop] = $item->$prop;
                }
            }
            return $data;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function isExcludedItem(array $item): bool
    {
        $type = isset($item['type']) && is_scalar($item['type']) ? strtolower(trim((string) $item['type'])) : '';
        if ($type === '') {
            return false;
        }

        $excluded = ['discount', 'coupon', 'promo', 'tax', 'fee', 'gateway_fee', 'payment_fee', 'shipping', 'credit'];
        if (in_array($type, $excluded, true)) {
            return true;
        }

        return str_starts_with($type, 'promo') || str_ends_with($type, 'discount');
    }

    /**
     * @param array<string, mixed> $item
     * @return array{0: ?string, 1: ?string}
     */
    private static function extractItemTitleAndDescription(array $item): array
    {
        if (isset($item['title']) && is_string($item['title']) && trim($item['title']) !== '') {
            $desc = isset($item['description']) && is_string($item['description']) ? $item['description'] : null;
            return [$item['title'], $desc];
        }
        if (isset($item['name']) && is_string($item['name']) && trim($item['name']) !== '') {
            $desc = isset($item['description']) && is_string($item['description']) ? $item['description'] : null;
            return [$item['name'], $desc];
        }
        if (isset($item['description']) && is_string($item['description'])) {
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $item['description']));
            $title = array_shift($lines);
            $rest = trim(implode("\n", $lines));
            return [$title, $rest !== '' ? $rest : null];
        }

        return [null, null];
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function extractItemQuantity(array $item): mixed
    {
        if (array_key_exists('quantity', $item)) {
            return $item['quantity'];
        }
        if (array_key_exists('qty', $item)) {
            return $item['qty'];
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function extractItemUnitAmount(array $item): mixed
    {
        foreach (['unit_amount', 'unit_price', 'price', 'amount'] as $key) {
            if (array_key_exists($key, $item)) {
                return $item[$key];
            }
        }

        return null;
    }

    private static function formatPositiveUnitAmount(mixed $raw): ?string
    {
        $normalized = AmountGuard::normalizeDecimal($raw);
        if ($normalized === null || $normalized === '0' || $normalized[0] === '-') {
            return null;
        }

        return $normalized;
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
