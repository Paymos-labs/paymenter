<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos;

require_once __DIR__ . '/src/Autoloader.php';
Internal\Autoloader::register();

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Illuminate\Http\Request;
use Paymos\Client;
use Paymos\Exception\ApiException;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymenter\Extensions\Gateways\Paymos\Internal\CallbackProcessor;
use Paymenter\Extensions\Gateways\Paymos\Internal\CheckoutService;
use Paymenter\Extensions\Gateways\Paymos\Internal\Config;
use Paymenter\Extensions\Gateways\Paymos\Internal\ConnectHandler;
use Paymenter\Extensions\Gateways\Paymos\Internal\CredentialStore;
use Paymenter\Extensions\Gateways\Paymos\Internal\DbDriverInterface;
use Paymenter\Extensions\Gateways\Paymos\Internal\PdoDbDriver;
use Paymenter\Extensions\Gateways\Paymos\Internal\PdoEventStore;
use Paymenter\Extensions\Gateways\Paymos\Internal\Reconciler;
use Paymenter\Extensions\Gateways\Paymos\Internal\RefundAdapter;
use Paymenter\Extensions\Gateways\Paymos\Internal\RefundIntentStore;
use Paymenter\Extensions\Gateways\Paymos\Internal\SnapshotStore;
use Paymenter\Extensions\Gateways\Paymos\Internal\Translation;

#[ExtensionMeta(
    name: 'Paymos',
    description: 'Accept USDT, USDC, USD1, DAI stablecoins and gold-backed XAUT payments via Paymos.',
    version: '1.0.7',
    author: 'Paymos Labs',
    url: 'https://paymos.io',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCIgd2lkdGg9IjY0IiBoZWlnaHQ9IjY0Ij48cmVjdCB3aWR0aD0iNjQiIGhlaWdodD0iNjQiIHJ4PSIxNCIgZmlsbD0iIzBCMEYxQSIvPjxwYXRoIGQ9Ik0yMiAxOGgxNGM3LjczIDAgMTMgNC43IDEzIDEycy01LjI3IDEyLTEzIDEyaC03djhoLTdWMTh6bTcgMTdoNi42YzMuODcgMCA2LjQtMi4wMiA2LjQtNXMtMi41My01LTYuNC01SDI5djEweiIgZmlsbD0iI0Y2NzYxNyIvPjwvc3ZnPg=='
)]
class Paymos extends Gateway
{
    public const CALLBACK_PATH = '/extensions/gateways/paymos/webhook';

    private ?DbDriverInterface $dbDriver = null;
    private ?TransportInterface $transport = null;

    /** @var callable|null */
    private $clock = null;

    /** @var callable|null */
    private $logger = null;

    /** @var callable|null */
    private $adminNotifier = null;

    /** @var callable|null */
    private $keyProvider = null;

    /** @var callable|null */
    private $refundNativeHandler = null;

    /** @var callable|null */
    private $adminAuthorizer = null;

    private ?string $sourceUrlOverride = null;
    private ?string $connectBaseUrlOverride = null;
    private ?string $localeOverride = null;

    /** @var list<array{platform_ref: string, note: string, context: array<string, mixed>}> */
    private array $recordedNotes = [];

    public function boot(): void
    {
        require __DIR__ . '/routes.php';

        if (class_exists(\Illuminate\Support\Facades\View::class)) {
            \Illuminate\Support\Facades\View::addNamespace('paymos', __DIR__ . '/resources/views');
        }

        if (class_exists(\Filament\Support\Facades\FilamentView::class)) {
            $renderHook = function (): string {
                return $this->renderInvoiceRefundsHook();
            };
            $viewScopes = [
                'App\\Admin\\Resources\\InvoiceResource\\Pages\\ViewInvoice',
                'App\\Filament\\Resources\\InvoiceResource\\Pages\\ViewInvoice',
            ];
            $editScopes = [
                'App\\Admin\\Resources\\InvoiceResource\\Pages\\EditInvoice',
                'App\\Filament\\Resources\\InvoiceResource\\Pages\\EditInvoice',
            ];
            \Filament\Support\Facades\FilamentView::registerRenderHook(
                'panels::page.footer-widgets.after',
                $renderHook,
                $viewScopes
            );
            \Filament\Support\Facades\FilamentView::registerRenderHook(
                'panels::page.footer-widgets.after',
                $renderHook,
                $editScopes
            );
        }

        if (class_exists(\Illuminate\Support\Facades\Schedule::class)) {
            $event = \Illuminate\Support\Facades\Schedule::call(function (): void {
                try {
                    $this->runScheduledReconciliation(false);
                } catch (\Throwable $e) {
                    ($this->resolveLogger())('warning', 'Scheduled reconciliation failed: ' . Config::redactSecrets($e->getMessage()), []);
                }
            });
            if (is_object($event)) {
                if (method_exists($event, 'everyTenMinutes')) {
                    $event->everyTenMinutes();
                }
                if (method_exists($event, 'name')) {
                    $event->name('paymos-reconcile');
                }
                if (method_exists($event, 'withoutOverlapping')) {
                    $event->withoutOverlapping();
                }
            }
        }
    }

    public function installed(): void
    {
        try {
            $this->resolveDbDriver()->ensureSchema();
        } catch (\Throwable $e) {
            // Tables are also lazily ensured outside active transactions on first use.
        }
    }

    /**
     * Connect-only settings: Filament Placeholder for One-Click Connect Paymos and mode.
     * Paymenter labels the payment button with the gateway name, so there is no button text setting.
     * ZERO manual API key, API secret, project ID, or webhook secret fields.
     *
     * @param array<string, mixed> $values
     * @return list<mixed>
     */
    public function getConfig($values = []): array
    {
        $locale = $this->resolveLocale();
        $connected = false;
        try {
            $connected = $this->makeCredentialStore()->loadCredentials() !== [];
        } catch (\Throwable $e) {
            $connected = false;
        }

        $connectHtml = $this->renderConnectHtml($connected, $locale);
        $connectContent = class_exists(\Illuminate\Support\HtmlString::class)
            ? new \Illuminate\Support\HtmlString($connectHtml)
            : $connectHtml;
        $connectField = [
            'name' => 'paymos_connect',
            'label' => $connectContent,
            'type' => 'placeholder',
            'description' => Translation::text('config_connection_label', $locale),
            'content' => $connectHtml,
            'connected' => $connected,
        ];

        return [
            $connectField,
            [
                'name' => 'mode',
                'label' => Translation::text('config_mode_label', $locale),
                'type' => 'select',
                'description' => Translation::text('config_mode_description', $locale),
                'required' => true,
                'default' => Config::ENV_SANDBOX,
                'options' => [
                    Config::ENV_SANDBOX => Translation::text('config_mode_sandbox', $locale),
                    Config::ENV_LIVE => Translation::text('config_mode_live', $locale),
                ],
            ],
        ];
    }

    /**
     * @param mixed             $total
     * @param mixed             $currency
     * @param mixed             $type
     * @param array<int, mixed> $items
     */
    public function canUseGateway($total, $currency, $type, $items = []): bool
    {
        try {
            $this->loadActiveConfig();
        } catch (\Throwable $e) {
            return false;
        }

        $normalized = AmountGuard::normalizeDecimal($total);
        if ($normalized === null || $normalized === '0' || $normalized[0] === '-') {
            return false;
        }

        return is_string($currency) && trim($currency) !== '';
    }

    /**
     * Create or safely reuse/replace a Paymos hosted invoice and return its payment URL.
     * A checkout the buyer cannot use comes back as HTML (Paymenter renders a non-string
     * pay() result in the payment modal); an exception would reach the buyer as "Server Error".
     *
     * @param mixed $total
     * @return string|\Illuminate\Support\HtmlString
     */
    public function pay(Invoice $invoice, $total)
    {
        $config = $this->loadActiveConfig();
        $checkout = new CheckoutService(
            $config,
            $this->makeSnapshotStore(),
            $this->makeClientFactory(),
            $this->resolveClock(),
            $this->resolveLogger()
        );

        $invoiceId = trim((string) $invoice->id);
        $chargeAmount = $this->extractChargeAmount($invoice, $total);
        $invoiceTotal = $this->extractInvoiceTotal($invoice);
        $currency = $this->extractInvoiceCurrency($invoice);
        $clientId = $this->extractClientId($invoice);
        $items = $this->extractInvoiceItems($invoice);
        $locale = $this->resolveLocale();

        try {
            $result = $checkout->createCheckout([
                'platform_ref' => $invoiceId,
                'amount' => $chargeAmount,
                'invoice_total' => $invoiceTotal,
                'currency' => $currency,
                'client_id' => $clientId,
                'items' => $items,
                'locale' => $locale,
            ]);
        } catch (InvoiceReplacementBlockedException | ApiException $e) {
            return self::checkoutErrorHtml(CheckoutService::safeFailureMessage($e, $locale));
        }

        return $result['payment_url'];
    }

    private static function checkoutErrorHtml(string $message): \Illuminate\Support\HtmlString
    {
        return new \Illuminate\Support\HtmlString(
            '<div class="paymos-checkout-error" role="alert">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</div>'
        );
    }

    /**
     * Handle signed Paymos webhooks on POST /extensions/gateways/paymos/webhook.
     *
     * @return mixed
     */
    public function webhook(Request $request)
    {
        try {
            $config = $this->loadWebhookConfig();
        } catch (\Throwable $e) {
            return $this->respondJson(500, ['error' => 'configuration_error']);
        }

        $rawBody = (string) $request->getContent();
        $signatureHeader = $this->extractWebhookSignature($request);

        $processor = $this->makeCallbackProcessor($config);
        $result = $processor->handle(
            $rawBody,
            $signatureHeader,
            $this->makeBillingInvoiceLoader(),
            $this->makePaymentApplier(),
            $this->makeNoteRecorder(),
            ($this->resolveClock())()
        );

        return $this->respondJson($result['status'], $result['body']);
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    /**
     * Async-safe Paymenter Gateway::refund() entry point.
     * Never returns true while Paymos refund is pending, processing, or unknown.
     *
     * @param mixed $amount
     */
    public function refund(InvoiceTransaction $transaction, $amount): bool
    {
        // Paymenter calls refund() inside DB::transaction() and rolls it back when it throws. A
        // Paymos refund finishes later, so this call always throws until then: the refund would be
        // sent while its intent is rolled back. Nothing is sent; the panel tracks the refund.
        if ($this->resolveDbDriver()->inTransaction()) {
            throw new \RuntimeException(Translation::text('refund_native_use_panel', $this->resolveLocale()));
        }

        $config = $this->loadWebhookConfig();
        $authorize = $this->adminAuthorizer ?: function (): void {
            if (class_exists(\Illuminate\Support\Facades\Auth::class)) {
                $user = \Illuminate\Support\Facades\Auth::user();
                if ($user !== null && !$this->isAdminAuthorized(new Request(), 'admin.invoices.update', Invoice::class)) {
                    throw new \RuntimeException('refund_admin_access_denied');
                }
            }
        };

        $adapter = $this->makeRefundAdapter($config, $authorize);
        $token = null;
        if (isset($transaction->paymos_refund_token) && is_string($transaction->paymos_refund_token) && trim($transaction->paymos_refund_token) !== '') {
            $token = trim($transaction->paymos_refund_token);
        }

        return $adapter->nativeRefund($transaction, $amount, $token, $this->resolveLocale());
    }

    /**
     * Protected admin route: POST /extensions/gateways/paymos/admin/connect/start
     *
     * @return mixed
     */
    public function connectStart(Request $request)
    {
        $authError = $this->enforceAdminRouteAuth($request, 'admin.gateways.update', \App\Models\Gateway::class, true);
        if ($authError !== null) {
            return $authError;
        }

        try {
            $handler = new ConnectHandler(
                $this->makeCredentialStore(),
                $this->transport,
                static function (): void {
                },
                $this->connectBaseUrlOverride ?? ConnectHandler::CONNECT_BASE_URL
            );
            $sourceUrl = $this->resolveSourceUrl($request);
            $returnUrl = $this->requestString($request, 'return_url');
            $data = $handler->start($sourceUrl, $returnUrl);

            return $this->respondJson(200, $data);
        } catch (\InvalidArgumentException $e) {
            // Starting Connect only rejects arguments for the installation URL (not HTTPS or not a
            // clean origin, e.g. APP_URL=http:// behind a TLS proxy): say so in the admin's language.
            return $this->respondJson(400, ['error' => Translation::text('connect_https_required', $this->resolveLocale())]);
        } catch (\Throwable $e) {
            return $this->respondJson(400, [
                'error' => Translation::text('connect_failed', $this->resolveLocale()),
            ]);
        }
    }

    /**
     * Protected admin route: POST /extensions/gateways/paymos/admin/connect/poll
     *
     * @return mixed
     */
    public function connectPoll(Request $request)
    {
        $authError = $this->enforceAdminRouteAuth($request, 'admin.gateways.update', \App\Models\Gateway::class, true);
        if ($authError !== null) {
            return $authError;
        }

        try {
            $handler = new ConnectHandler(
                $this->makeCredentialStore(),
                $this->transport,
                static function (): void {
                },
                $this->connectBaseUrlOverride ?? ConnectHandler::CONNECT_BASE_URL
            );
            $sourceUrl = $this->resolveSourceUrl($request);
            $data = $handler->poll($sourceUrl);

            return $this->respondJson(200, $data);
        } catch (\InvalidArgumentException $e) {
            return $this->respondJson(400, ['error' => Config::redactSecrets($e->getMessage())]);
        } catch (\Throwable $e) {
            return $this->respondJson(400, [
                'error' => Translation::text('connect_failed', $this->resolveLocale()),
            ]);
        }
    }

    /**
     * Protected admin route: GET /extensions/gateways/paymos/admin/refunds/{invoiceId}
     *
     * @param int|string $invoiceId
     * @return mixed
     */
    public function refundPanel(Request $request, $invoiceId)
    {
        $authError = $this->enforceAdminRouteAuth($request, 'admin.invoices.update', Invoice::class, false);
        if ($authError !== null) {
            return $authError;
        }

        try {
            $config = $this->loadWebhookConfig();
            $adapter = $this->makeRefundAdapter($config, static function (): void {
            });
            $panel = $adapter->panel($invoiceId, $this->resolveLocale());

            return $this->respondJson(200, $panel);
        } catch (\Throwable $e) {
            return $this->respondJson(400, [
                'message' => Translation::text('refund_failed', $this->resolveLocale()),
                'reason' => Config::redactSecrets($e->getMessage()),
            ]);
        }
    }

    /**
     * Protected admin route: POST /extensions/gateways/paymos/admin/refunds/submit
     *
     * @return mixed
     */
    public function refundSubmit(Request $request)
    {
        $authError = $this->enforceAdminRouteAuth($request, 'admin.invoices.update', Invoice::class, true);
        if ($authError !== null) {
            return $authError;
        }

        try {
            $config = $this->loadWebhookConfig();
            $adapter = $this->makeRefundAdapter($config, static function (): void {
            });
            $input = $this->extractRequestPayload($request);
            $intent = $adapter->submit($input);

            return $this->respondJson(200, [
                'operation_id' => $intent->operationId(),
                'withdrawal_id' => $intent->withdrawalId(),
                'cash_state' => $intent->cashState(),
                'projection_state' => $intent->projectionState(),
                'review_reason' => $intent->toArray()['review_reason'],
            ]);
        } catch (\Throwable $e) {
            $reason = Config::redactSecrets($e->getMessage());
            $locale = $this->resolveLocale();
            $message = Translation::text('refund_failed', $locale);
            if ($reason === 'refund_reconnect_required') {
                $message .= ' ' . Translation::text('refund_reconnect_hint', $locale);
            }

            return $this->respondJson(409, [
                'message' => $message,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * Protected admin route: POST /extensions/gateways/paymos/admin/refunds/check
     *
     * @return mixed
     */
    public function refundCheck(Request $request)
    {
        $authError = $this->enforceAdminRouteAuth($request, 'admin.invoices.update', Invoice::class, true);
        if ($authError !== null) {
            return $authError;
        }

        try {
            $operationId = $this->requestString($request, 'operation_id');
            if ($operationId === '') {
                return $this->respondJson(400, ['message' => 'operation_id is required.']);
            }
            $config = $this->loadWebhookConfig();
            $adapter = $this->makeRefundAdapter($config, static function (): void {
            });
            $action = $adapter->reconcile($operationId);

            return $this->respondJson(200, ['ok' => true, 'action' => $action]);
        } catch (\Throwable $e) {
            return $this->respondJson(409, [
                'message' => Translation::text('refund_failed', $this->resolveLocale()),
                'reason' => Config::redactSecrets($e->getMessage()),
            ]);
        }
    }

    /**
     * Execute 10-minute scheduled invoice + refund reconciliation.
     *
     * @return array{invoices: int, refunds: int}
     */
    public function runScheduledReconciliation(bool $force = false): array
    {
        $config = $this->loadWebhookConfig();
        $credentialStore = $this->makeCredentialStore();
        $snapshots = $this->makeSnapshotStore();
        $processor = $this->makeCallbackProcessor($config);

        $reconciler = new Reconciler(
            $config,
            $credentialStore,
            $snapshots,
            $processor,
            $this->makeClientFactory(),
            $this->resolveClock(),
            $this->resolveLogger()
        );

        $invoicesApplied = $reconciler->run(
            $this->makeBillingInvoiceLoader(),
            $this->makePaymentApplier(),
            $this->makeNoteRecorder(),
            $force
        );

        $refundAdapter = $this->makeRefundAdapter($config, static function (): void {
        });
        $refundsChecked = $refundAdapter->run(($this->resolveClock())());

        return [
            'invoices' => $invoicesApplied,
            'refunds' => $refundsChecked,
        ];
    }

    public function setDbDriver(DbDriverInterface $dbDriver): self
    {
        $this->dbDriver = $dbDriver;

        return $this;
    }

    public function setTransport(TransportInterface $transport): self
    {
        $this->transport = $transport;

        return $this;
    }

    public function setClock(callable $clock): self
    {
        $this->clock = $clock;

        return $this;
    }

    public function setLogger(callable $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @param callable(string, string): void $notifier receives the Paymenter invoice id and the review note
     */
    public function setAdminNotifier(callable $notifier): self
    {
        $this->adminNotifier = $notifier;

        return $this;
    }

    public function setKeyProvider(callable $keyProvider): self
    {
        $this->keyProvider = $keyProvider;

        return $this;
    }

    public function setRefundNativeHandler(callable $handler): self
    {
        $this->refundNativeHandler = $handler;

        return $this;
    }

    public function setAdminAuthorizer(callable $authorizer): self
    {
        $this->adminAuthorizer = $authorizer;

        return $this;
    }

    public function setSourceUrlOverride(?string $sourceUrl): self
    {
        $this->sourceUrlOverride = $sourceUrl;

        return $this;
    }

    public function setConnectBaseUrlOverride(?string $connectBaseUrl): self
    {
        $this->connectBaseUrlOverride = $connectBaseUrl;

        return $this;
    }

    public function setLocaleOverride(?string $locale): self
    {
        $this->localeOverride = $locale;

        return $this;
    }

    /**
     * @return list<array{platform_ref: string, note: string, context: array<string, mixed>}>
     */
    public function getRecordedNotes(): array
    {
        return $this->recordedNotes;
    }

    public function getCredentialStore(): CredentialStore
    {
        return $this->makeCredentialStore();
    }

    public function getSnapshotStore(): SnapshotStore
    {
        return $this->makeSnapshotStore();
    }

    public function getRefundIntentStore(): RefundIntentStore
    {
        return $this->makeRefundIntentStore();
    }

    /**
     * All Extension::config() calls MUST live directly inside Paymos because
     * Paymenter's base Extension::config() resolves settings via debug_backtrace()[1]['class'].
     *
     * @return array<string, mixed>
     */
    private function readRawConfig(): array
    {
        return [
            'mode' => $this->config('mode'),
        ];
    }

    private function loadActiveConfig(): Config
    {
        $settings = $this->readRawConfig();
        $credentials = $this->makeCredentialStore()->loadCredentials();

        return Config::fromSettings($settings, $credentials);
    }

    private function loadWebhookConfig(): Config
    {
        $settings = $this->readRawConfig();
        $credentials = $this->makeCredentialStore()->loadCredentials();

        return Config::forWebhook($settings, $credentials);
    }

    private function makeCredentialStore(): CredentialStore
    {
        return new CredentialStore(
            $this->resolveDbDriver(),
            $this->keyProvider,
            $this->resolveClock()
        );
    }

    private function makeSnapshotStore(): SnapshotStore
    {
        return new SnapshotStore(
            $this->resolveDbDriver(),
            $this->resolveClock()
        );
    }

    private function makeRefundIntentStore(): RefundIntentStore
    {
        return new RefundIntentStore(
            $this->resolveDbDriver(),
            $this->makeCredentialStore(),
            $this->resolveClock()
        );
    }

    private function makeCallbackProcessor(Config $config): CallbackProcessor
    {
        $eventStore = new PdoEventStore($this->resolveDbDriver(), $this->resolveClock());
        $refundHandler = function (\Paymos\Webhook\WebhookEvent $event, string $environment) use ($config): void {
            $adapter = $this->makeRefundAdapter($config, static function (): void {
            });
            $adapter->callback($event, $environment);
        };

        return new CallbackProcessor(
            $config,
            $this->makeSnapshotStore(),
            $eventStore,
            $this->makeClientFactory(),
            $refundHandler,
            $this->resolveLogger()
        );
    }

    private function makeRefundAdapter(Config $config, ?callable $authorize = null): RefundAdapter
    {
        return new RefundAdapter(
            $config,
            $this->makeCredentialStore(),
            $this->makeSnapshotStore(),
            $this->makeRefundIntentStore(),
            $this->makeClientFactory(),
            $this->refundNativeHandler,
            $authorize,
            $this->resolveClock(),
            $this->resolveLogger()
        );
    }

    private function makeClientFactory(): callable
    {
        $transport = $this->transport;

        return static function (string $environment, Config $config) use ($transport): Client {
            return new Client($config->clientConfig($environment), $transport);
        };
    }

    private function makeBillingInvoiceLoader(): callable
    {
        return function (string $platformRef): ?array {
            $invoice = Invoice::find($platformRef);
            if (!$invoice instanceof Invoice) {
                return null;
            }

            $amount = $this->extractInvoiceRemainingOrTotal($invoice);
            $total = $this->extractInvoiceTotal($invoice);
            $currency = $this->extractInvoiceCurrency($invoice);
            $status = strtolower(trim((string) ($invoice->status ?? '')));
            $transactionIds = [];
            if (isset($invoice->transactions) && is_iterable($invoice->transactions)) {
                foreach ($invoice->transactions as $transaction) {
                    if (is_object($transaction) && isset($transaction->transaction_id) && is_scalar($transaction->transaction_id)) {
                        $transactionIds[] = (string) $transaction->transaction_id;
                    }
                }
            }

            return [
                'id' => (string) $invoice->id,
                'amount' => $amount,
                'total' => $total,
                'currency' => $currency,
                'is_paid' => ($status === 'paid'),
                'transaction_ids' => $transactionIds,
            ];
        };
    }

    private function makePaymentApplier(): callable
    {
        return static function (string $platformRef, string $amount, string $transactionId, array $eventData): void {
            $invoiceId = ctype_digit($platformRef) ? (int) $platformRef : $platformRef;
            ExtensionHelper::addPayment($invoiceId, 'Paymos', $amount, transactionId: $transactionId);
        };
    }

    private function makeNoteRecorder(): callable
    {
        return function (string $platformRef, string $note, array $context): void {
            $safeNote = Config::redactSecrets($note);
            $this->recordedNotes[] = [
                'platform_ref' => $platformRef,
                'note' => $safeNote,
                'context' => $context,
            ];
            // Manual review and paid_late must not disappear under LOG_LEVEL=warning.
            $level = ($context['severity'] ?? '') === 'warning' ? 'warning' : 'info';
            ($this->resolveLogger())($level, $safeNote, $context);
            if ($level === 'warning') {
                $this->notifyAdmin($platformRef, $safeNote);
            }
        };
    }

    /**
     * A review note must reach the admin, not only the log file (CMSP-12). A notification
     * failure never fails the webhook or the reconcile pass that recorded the note.
     */
    private function notifyAdmin(string $platformRef, string $note): void
    {
        try {
            ($this->resolveAdminNotifier())($platformRef, $note);
        } catch (\Throwable $e) {
            ($this->resolveLogger())('warning', 'Paymos admin notification failed: ' . Config::redactSecrets($e->getMessage()), []);
        }
    }

    private function resolveAdminNotifier(): callable
    {
        if ($this->adminNotifier !== null) {
            return $this->adminNotifier;
        }

        // Paymenter invoices have no notes. Its own channel to the admin is the system email
        // (Settings, system email address), which Admin > Email logs also lists. Older v1
        // releases without it keep the warning log only.
        return static function (string $platformRef, string $note): void {
            if (!method_exists(\App\Helpers\NotificationHelper::class, 'sendSystemEmailNotification')) {
                return;
            }
            // Webhook retries and every reconcile pass repeat a review note: one letter a day per invoice and note.
            if (class_exists(\Illuminate\Support\Facades\Cache::class)
                && !\Illuminate\Support\Facades\Cache::add('paymos:admin-note:' . sha1($platformRef . "\n" . $note), 1, 86400)) {
                return;
            }
            \App\Helpers\NotificationHelper::sendSystemEmailNotification(
                'Paymos: invoice #' . $platformRef . ' needs review',
                $note
            );
        };
    }

    /**
     * Strict admin authorization + CSRF verification for Connect and Refunds routes.
     * Regular authenticated customers (no admin role/permission) MUST be rejected with HTTP 403.
     *
     * @return mixed|null JSON error response if unauthorized, or null when authorized.
     */
    private function enforceAdminRouteAuth(
        Request $request,
        string $permission,
        string $modelClass,
        bool $requireCsrf
    ) {
        if ($this->adminAuthorizer !== null) {
            try {
                ($this->adminAuthorizer)($request, $permission, $modelClass);
            } catch (\Throwable $e) {
                return $this->respondJson(403, ['error' => 'forbidden']);
            }
        } elseif (!$this->isAdminAuthorized($request, $permission, $modelClass)) {
            return $this->respondJson(403, ['error' => 'forbidden']);
        }

        if ($requireCsrf && !$this->hasValidCsrfToken($request)) {
            return $this->respondJson(419, ['error' => 'csrf_token_mismatch']);
        }

        return null;
    }

    private function isAdminAuthorized(Request $request, string $permission, string $modelClass): bool
    {
        $user = null;
        if (method_exists($request, 'user')) {
            $user = $request->user();
        }
        if ($user === null && class_exists(\Illuminate\Support\Facades\Auth::class)) {
            try {
                $user = \Illuminate\Support\Facades\Auth::user();
            } catch (\Throwable $e) {
                $user = null;
            }
        }

        if (!is_object($user)) {
            return false;
        }

        if (isset($user->role) && is_object($user->role) && method_exists($user->role, 'hasPermission')) {
            return $user->role->hasPermission($permission) === true;
        }

        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($permission) === true;
        }

        if (class_exists(\Illuminate\Support\Facades\Gate::class)) {
            try {
                if (method_exists(\Illuminate\Support\Facades\Gate::class, 'forUser')) {
                    $gate = \Illuminate\Support\Facades\Gate::forUser($user);
                    if (is_object($gate) && method_exists($gate, 'allows')) {
                        return $gate->allows('update', $modelClass) === true;
                    }
                }
                if (method_exists(\Illuminate\Support\Facades\Gate::class, 'allows')) {
                    return \Illuminate\Support\Facades\Gate::allows('update', $modelClass) === true;
                }
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }

    private function hasValidCsrfToken(Request $request): bool
    {
        $expected = null;
        if (method_exists($request, 'session')) {
            try {
                $session = $request->session();
                if (is_object($session) && method_exists($session, 'token')) {
                    $token = $session->token();
                    if (is_string($token) && $token !== '') {
                        $expected = $token;
                    }
                }
            } catch (\Throwable $e) {
                $expected = null;
            }
        }

        if ($expected === null) {
            // In production Laravel's 'web' middleware already enforces PreventRequestForgery;
            // if a session token is absent outside a booted session, require X-CSRF-TOKEN when present on header/body.
            return true;
        }

        $provided = $request->header('X-CSRF-TOKEN');
        if (!is_string($provided) || trim($provided) === '') {
            $provided = $this->requestString($request, '_token');
        }

        return is_string($provided) && $provided !== '' && hash_equals($expected, trim($provided));
    }

    private function renderConnectHtml(bool $connected, string $locale): string
    {
        $labels = Translation::all($locale);
        $webhookUrl = self::CALLBACK_PATH;
        if (function_exists('url')) {
            try {
                $generated = \url(self::CALLBACK_PATH);
                if (is_string($generated) && trim($generated) !== '') {
                    $webhookUrl = $generated;
                }
            } catch (\Throwable $e) {
                // Keep canonical relative callback path outside booted request.
            }
        }

        if (function_exists('view') && class_exists(\Illuminate\Support\Facades\View::class)) {
            try {
                return (string) \view('paymos::connect', [
                    'connected' => $connected,
                    'labels' => $labels,
                    'webhookUrl' => $webhookUrl,
                ])->render();
            } catch (\Throwable $e) {
                // Fall back to static safe HTML when Blade is not booted.
            }
        }

        $status = htmlspecialchars(
            $connected ? $labels['config_connection_connected'] : $labels['config_connection_disconnected'],
            ENT_QUOTES,
            'UTF-8'
        );
        $button = htmlspecialchars(
            $connected ? $labels['connect_reconnect_button'] : $labels['connect_button'],
            ENT_QUOTES,
            'UTF-8'
        );
        $escapedWebhook = htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8');

        return '<div id="paymos-connect-panel"><p>' . $status . '</p>'
            . '<p><code>' . $escapedWebhook . '</code></p>'
            . '<button type="button" id="paymos-connect-button">' . $button . '</button>'
            . '<div id="paymos-connect-status"></div></div>';
    }

    private function renderInvoiceRefundsHook(): string
    {
        if (!function_exists('request') || !function_exists('view')) {
            return '';
        }

        try {
            $request = \request();
            if (!$request instanceof Request) {
                return '';
            }
            if (!$this->isAdminAuthorized($request, 'admin.invoices.update', Invoice::class)) {
                return '';
            }

            $route = method_exists($request, 'route') ? $request->route() : null;
            if (!is_object($route)) {
                return '';
            }

            $record = method_exists($route, 'parameter') ? $route->parameter('record') : null;
            if (!$this->isInvoiceResourceRoute($route, $record)) {
                return '';
            }

            $invoiceId = $record instanceof Invoice ? (string) $record->id : (is_scalar($record) ? trim((string) $record) : '');
            if ($invoiceId === '') {
                return '';
            }

            $paidSnapshots = $this->makeSnapshotStore()->findPaidByPlatformRef($invoiceId);
            if ($paidSnapshots === []) {
                return '';
            }

            $config = $this->loadWebhookConfig();
            $adapter = $this->makeRefundAdapter($config, static function (): void {
            });
            $panel = $adapter->panel($invoiceId, $this->resolveLocale());

            return (string) \view('paymos::refunds-panel', ['panel' => $panel])->render();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Ensure Filament render hook only fires on InvoiceResource view/edit pages,
     * never on unrelated Filament resources (Users, Services, Products, Tickets, etc.).
     */
    private function isInvoiceResourceRoute(object $route, mixed $record): bool
    {
        if ($record instanceof Invoice) {
            return true;
        }

        if (is_object($record)) {
            return false;
        }

        $routeName = method_exists($route, 'getName') ? (string) ($route->getName() ?? '') : '';
        if ($routeName !== '' && preg_match('/(?:^|\.)invoices\.(?:view|edit)$/', $routeName) === 1) {
            return true;
        }

        $actionName = method_exists($route, 'getActionName') ? (string) ($route->getActionName() ?? '') : '';
        if ($actionName !== ''
            && str_contains($actionName, 'InvoiceResource\\Pages\\')
            && (str_contains($actionName, 'ViewInvoice') || str_contains($actionName, 'EditInvoice'))
        ) {
            return true;
        }

        return false;
    }

    private function resolveSourceUrl(Request $request): string
    {
        if ($this->sourceUrlOverride !== null) {
            return rtrim(trim($this->sourceUrlOverride), '/');
        }

        if (function_exists('config')) {
            try {
                $appUrl = \config('app.url');
                if (is_string($appUrl) && trim($appUrl) !== '') {
                    return rtrim(trim($appUrl), '/');
                }
            } catch (\Throwable $e) {
                // Fall back to request root.
            }
        }

        if (method_exists($request, 'getSchemeAndHttpHost')) {
            $host = $request->getSchemeAndHttpHost();
            if (is_string($host) && trim($host) !== '') {
                return rtrim(trim($host), '/');
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRequestPayload(Request $request): array
    {
        $raw = (string) $request->getContent();
        if (trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (method_exists($request, 'all')) {
            $all = $request->all();
            if (is_array($all)) {
                return $all;
            }
        }

        return [];
    }

    private function requestString(Request $request, string $key): string
    {
        $payload = $this->extractRequestPayload($request);
        if (isset($payload[$key]) && is_scalar($payload[$key])) {
            return trim((string) $payload[$key]);
        }

        if (method_exists($request, 'input')) {
            $val = $request->input($key);
            if (is_scalar($val)) {
                return trim((string) $val);
            }
        }

        return '';
    }

    private function resolveDbDriver(): DbDriverInterface
    {
        if ($this->dbDriver !== null) {
            return $this->dbDriver;
        }

        $this->dbDriver = PdoDbDriver::fromLaravel();

        return $this->dbDriver;
    }

    private function resolveClock(): callable
    {
        return $this->clock ?: static fn (): int => time();
    }

    private function resolveLogger(): callable
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        return static function (string $level, string $message, array $context): void {
            if (!class_exists(\Illuminate\Support\Facades\Log::class)) {
                return;
            }
            try {
                \Illuminate\Support\Facades\Log::log($level, '[Paymos] ' . $message, $context);
            } catch (\Throwable $e) {
                // Ignore logging backend errors outside a booted Laravel app.
            }
        };
    }

    private function resolveLocale(): string
    {
        return Translation::resolveLocale($this->localeOverride);
    }

    /**
     * Priority: $invoice->remaining first (for partially credited/paid Paymenter invoices),
     * then $total argument, then $invoice->total.
     *
     * @param mixed $total
     */
    private function extractChargeAmount(Invoice $invoice, $total): string
    {
        if (isset($invoice->remaining) && is_scalar($invoice->remaining)) {
            $norm = AmountGuard::normalizeDecimal((string) $invoice->remaining);
            if ($norm !== null && $norm !== '0' && $norm[0] !== '-') {
                return $norm;
            }
        }

        $normTotalArg = AmountGuard::normalizeDecimal($total);
        if ($normTotalArg !== null && $normTotalArg !== '0' && $normTotalArg[0] !== '-') {
            return $normTotalArg;
        }

        if (isset($invoice->total) && is_scalar($invoice->total)) {
            $normTotal = AmountGuard::normalizeDecimal((string) $invoice->total);
            if ($normTotal !== null) {
                return $normTotal;
            }
        }

        return '';
    }

    private function extractInvoiceRemainingOrTotal(Invoice $invoice): string
    {
        if (isset($invoice->remaining) && is_scalar($invoice->remaining) && trim((string) $invoice->remaining) !== '') {
            return trim((string) $invoice->remaining);
        }

        return $this->extractInvoiceTotal($invoice);
    }

    private function extractInvoiceTotal(Invoice $invoice): string
    {
        if (isset($invoice->total) && is_scalar($invoice->total) && trim((string) $invoice->total) !== '') {
            return trim((string) $invoice->total);
        }

        return '';
    }

    private function extractInvoiceCurrency(Invoice $invoice): string
    {
        if (isset($invoice->currency_code) && is_string($invoice->currency_code) && trim($invoice->currency_code) !== '') {
            return strtoupper(trim($invoice->currency_code));
        }

        if (isset($invoice->currency)) {
            if (is_string($invoice->currency) && trim($invoice->currency) !== '') {
                return strtoupper(trim($invoice->currency));
            }
            if (is_object($invoice->currency) && isset($invoice->currency->code) && is_string($invoice->currency->code)) {
                $code = trim($invoice->currency->code);
                if ($code !== '') {
                    return strtoupper($code);
                }
            }
        }

        throw new \InvalidArgumentException('Paymenter invoice is missing currency_code.');
    }

    /**
     * Extract native numeric/string user ID — NEVER an email address.
     */
    private function extractClientId(Invoice $invoice): ?string
    {
        if (isset($invoice->user_id) && is_scalar($invoice->user_id)) {
            $id = trim((string) $invoice->user_id);
            if ($id !== '' && $id !== '0' && !str_contains($id, '@')) {
                return $id;
            }
        }

        if (isset($invoice->user) && is_object($invoice->user) && isset($invoice->user->id) && is_scalar($invoice->user->id)) {
            $id = trim((string) $invoice->user->id);
            if ($id !== '' && $id !== '0' && !str_contains($id, '@')) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return iterable<mixed>
     */
    private function extractInvoiceItems(Invoice $invoice): iterable
    {
        if (isset($invoice->items) && is_iterable($invoice->items)) {
            return $invoice->items;
        }

        return [];
    }

    private function extractWebhookSignature(Request $request): string
    {
        $header = $request->header('X-Webhook-Signature');
        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $lower = $request->header('x-webhook-signature');
        if (is_string($lower) && trim($lower) !== '') {
            return trim($lower);
        }

        if (isset($request->headers) && is_object($request->headers) && method_exists($request->headers, 'all')) {
            $all = $request->headers->all();
            if (is_array($all)) {
                foreach ($all as $name => $values) {
                    if (strtolower((string) $name) !== 'x-webhook-signature') {
                        continue;
                    }
                    if (is_array($values) && isset($values[0]) && is_string($values[0])) {
                        return trim($values[0]);
                    }
                    if (is_string($values)) {
                        return trim($values);
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $body
     * @return mixed
     */
    private function respondJson(int $status, array $body)
    {
        if (function_exists('response')) {
            $factory = \response();
            if (is_object($factory) && method_exists($factory, 'json')) {
                return $factory->json($body, $status);
            }
        }

        return [
            'status' => $status,
            'body' => $body,
        ];
    }
}
