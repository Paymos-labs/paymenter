<?php

declare(strict_types=1);

namespace App\Attributes {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    class ExtensionMeta
    {
        public string $name;
        public string $description;
        public string $version;
        public string $author;
        public string $url;
        public string $icon;

        public function __construct(
            string $name,
            string $description,
            string $version,
            string $author,
            string $url = '',
            string $icon = ''
        ) {
            $this->name = $name;
            $this->description = $description;
            $this->version = $version;
            $this->author = $author;
            $this->url = $url;
            $this->icon = $icon;
        }
    }
}

namespace App\Classes\Extension {
    /**
     * Realistic Paymenter v1 Extension stub enforcing debug_backtrace()[1]['class'] locality.
     */
    abstract class Extension
    {
        /** @var array<string, mixed> */
        protected array $config = [];

        /**
         * @param array<string, mixed> $config
         */
        public function __construct(array $config = [])
        {
            $this->config = $config;
        }

        /**
         * @param array<string, mixed> $config
         */
        public function setStubConfig(array $config): void
        {
            $this->config = $config;
        }

        /**
         * Mirrors Paymenter's Extension::config($key) which inspects debug_backtrace()[1]['class'].
         *
         * @return mixed
         */
        public function config(string $key)
        {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $callerClass = $trace[1]['class'] ?? null;
            if ($callerClass !== static::class) {
                throw new \LogicException(
                    'Paymenter Extension::config() must be called directly from '
                    . static::class . ', got ' . (string) ($callerClass ?? 'global')
                );
            }

            return $this->config[$key] ?? null;
        }
    }

    abstract class Gateway extends Extension
    {
        public function boot(): void
        {
        }

        public function installed(): void
        {
        }

        public function supportsRefunds(): bool
        {
            return false;
        }
    }
}

namespace App\Models {
    class Gateway
    {
    }

    class InvoiceItem
    {
        public string $description;
        public string|float|int $price;
        public int $quantity;
        public ?string $type;

        public function __construct(string $description, string|float|int $price, int $quantity = 1, ?string $type = null)
        {
            $this->description = $description;
            $this->price = $price;
            $this->quantity = $quantity;
            $this->type = $type;
        }
    }

    class Invoice
    {
        /** @var array<string, self> */
        public static array $store = [];

        public int|string $id;
        public string $currency_code;
        public string|float|int $total;
        public string|float|int $remaining;
        public string $status;
        public int|string|null $user_id;
        public ?object $user;

        /** @var list<mixed> */
        public array $items = [];

        /** @var list<InvoiceTransaction> */
        public array $transactions = [];

        public function __construct(
            int|string $id,
            string|float|int $total,
            string $currencyCode = 'USD',
            string $status = 'pending',
            int|string|null $userId = null,
            ?object $user = null,
            array $items = []
        ) {
            $this->id = $id;
            $this->total = $total;
            $this->remaining = $total;
            $this->currency_code = $currencyCode;
            $this->status = $status;
            $this->user_id = $userId;
            $this->user = $user;
            $this->items = $items;
        }

        public static function resetStore(): void
        {
            self::$store = [];
        }

        public static function put(self $invoice): void
        {
            self::$store[(string) $invoice->id] = $invoice;
        }

        public static function find(int|string $id): ?self
        {
            return self::$store[(string) $id] ?? null;
        }
    }

    class InvoiceTransaction
    {
        public int|string $id;
        public int|string $invoice_id;
        public string $transaction_id;
        public string|float|int $amount;
        public string $status = 'succeeded';
        public bool $is_refunded = false;
        public ?string $paymos_refund_token = null;

        public function __construct(
            int|string $id,
            int|string $invoiceId,
            string $transactionId,
            string|float|int $amount
        ) {
            $this->id = $id;
            $this->invoice_id = $invoiceId;
            $this->transaction_id = $transactionId;
            $this->amount = $amount;
        }
    }
}

namespace App\Helpers {
    use App\Classes\Extension\Gateway;
    use App\Models\Invoice;
    use App\Models\InvoiceTransaction;

    class ExtensionHelper
    {
        /** @var list<array{invoice_id: int|string, gateway: string, amount: string|float|int, fee: ?float, transaction_id: ?string}> */
        public static array $payments = [];

        /** @var list<array{transaction_id: string, invoice_id: int|string, amount: string|float|int}> */
        public static array $recordRefundCalls = [];

        /** @var list<array{transaction_id: string, invoice_id: int|string, amount: string|float|int}> */
        public static array $refundLocallyCalls = [];

        public static ?\Throwable $throwOnNextPayment = null;

        public static function reset(): void
        {
            self::$payments = [];
            self::$recordRefundCalls = [];
            self::$refundLocallyCalls = [];
            self::$throwOnNextPayment = null;
        }

        public static function addPayment(
            int|string $invoiceId,
            string $gateway,
            string|float|int $amount,
            ?float $fee = null,
            ?string $transactionId = null
        ): void {
            if (self::$throwOnNextPayment !== null) {
                $err = self::$throwOnNextPayment;
                self::$throwOnNextPayment = null;
                throw $err;
            }

            self::$payments[] = [
                'invoice_id' => $invoiceId,
                'gateway' => $gateway,
                'amount' => $amount,
                'fee' => $fee,
                'transaction_id' => $transactionId,
            ];

            $invoice = Invoice::find($invoiceId);
            if ($invoice !== null) {
                $invoice->status = 'paid';
                $invoice->remaining = '0';
                $tx = new InvoiceTransaction(count($invoice->transactions) + 1, $invoice->id, (string) $transactionId, $amount);
                $invoice->transactions[] = $tx;
            }
        }

        /**
         * Mirrors upstream Paymenter ExtensionHelper::refund(): synchronously invokes
         * recordRefund() if and only if Gateway::refund() returns true.
         */
        public static function refund(Gateway $gateway, InvoiceTransaction $transaction, mixed $amount): bool
        {
            $ok = $gateway->refund($transaction, $amount);
            if ($ok === true) {
                self::recordRefund($transaction, $amount);
            }

            return $ok;
        }

        public static function recordRefund(InvoiceTransaction $transaction, mixed $amount): void
        {
            self::$recordRefundCalls[] = [
                'transaction_id' => $transaction->transaction_id,
                'invoice_id' => $transaction->invoice_id,
                'amount' => $amount,
            ];
            $transaction->status = 'refunded';
            $transaction->is_refunded = true;
            $invoice = Invoice::find($transaction->invoice_id);
            if ($invoice !== null) {
                $invoice->status = 'refunded';
            }
        }

        public static function refundLocally(InvoiceTransaction $transaction, mixed $amount): void
        {
            self::$refundLocallyCalls[] = [
                'transaction_id' => $transaction->transaction_id,
                'invoice_id' => $transaction->invoice_id,
                'amount' => $amount,
            ];
            $transaction->status = 'refunded';
            $transaction->is_refunded = true;
            $invoice = Invoice::find($transaction->invoice_id);
            if ($invoice !== null) {
                $invoice->status = 'refunded';
            }
        }
    }
}

namespace Filament\Forms\Components {
    class Placeholder
    {
        public string $name;
        public string $label = '';
        public mixed $content = null;

        private function __construct(string $name)
        {
            $this->name = $name;
        }

        public static function make(string $name): self
        {
            return new self($name);
        }

        public function label(string $label): self
        {
            $this->label = $label;

            return $this;
        }

        public function content(mixed $content): self
        {
            $this->content = $content;

            return $this;
        }
    }
}

namespace Filament\Support\Facades {
    class FilamentView
    {
        /** @var list<array{name: string, hook: callable, scopes: mixed}> */
        public static array $hooks = [];

        public static function reset(): void
        {
            self::$hooks = [];
        }

        public static function registerRenderHook(string $name, callable $hook, string|array|null $scopes = null): void
        {
            self::$hooks[] = ['name' => $name, 'hook' => $hook, 'scopes' => $scopes];
        }
    }
}

namespace Illuminate\Foundation\Http\Middleware {
    class PreventRequestForgery
    {
    }
}

namespace Illuminate\Support {
    class HtmlString
    {
        public string $html;

        public function __construct(string $html)
        {
            $this->html = $html;
        }

        public function __toString(): string
        {
            return $this->html;
        }
    }
}

namespace Illuminate\Support\Facades {
    class RegisteredRoute
    {
        public string $method;
        public string $uri;

        /** @var array<int, mixed> */
        public array $action;

        /** @var list<string> */
        public array $middleware = [];

        /** @var list<string> */
        public array $withoutMiddleware = [];

        public ?string $name = null;

        /**
         * @param array<int, mixed> $action
         */
        public function __construct(string $method, string $uri, array $action)
        {
            $this->method = $method;
            $this->uri = $uri;
            $this->action = $action;
        }

        /**
         * @param list<string>|string $middleware
         */
        public function middleware(array|string $middleware): self
        {
            $list = is_array($middleware) ? $middleware : [$middleware];
            $this->middleware = array_values(array_merge($this->middleware, $list));

            return $this;
        }

        /**
         * @param list<string> $middleware
         */
        public function withoutMiddleware(array $middleware): self
        {
            $this->withoutMiddleware = array_values(array_merge($this->withoutMiddleware, $middleware));

            return $this;
        }

        public function name(string $name): self
        {
            $this->name = $name;

            return $this;
        }
    }

    class Route
    {
        /** @var list<RegisteredRoute> */
        public static array $registered = [];

        public static function reset(): void
        {
            self::$registered = [];
        }

        /**
         * @param array<int, mixed> $action
         */
        public static function post(string $uri, array $action): RegisteredRoute
        {
            $route = new RegisteredRoute('POST', $uri, $action);
            self::$registered[] = $route;

            return $route;
        }

        /**
         * @param array<int, mixed> $action
         */
        public static function get(string $uri, array $action): RegisteredRoute
        {
            $route = new RegisteredRoute('GET', $uri, $action);
            self::$registered[] = $route;

            return $route;
        }
    }

    class View
    {
        /** @var array<string, string> */
        public static array $namespaces = [];

        public static function reset(): void
        {
            self::$namespaces = [];
        }

        public static function addNamespace(string $namespace, string $path): void
        {
            self::$namespaces[$namespace] = $path;
        }
    }

    class ScheduledEvent
    {
        /** @var callable */
        public $callback;
        public ?string $frequency = null;
        public ?string $name = null;
        public bool $withoutOverlapping = false;

        public function __construct(callable $callback)
        {
            $this->callback = $callback;
        }

        public function everyTenMinutes(): self
        {
            $this->frequency = 'everyTenMinutes';

            return $this;
        }

        public function name(string $name): self
        {
            $this->name = $name;

            return $this;
        }

        public function withoutOverlapping(): self
        {
            $this->withoutOverlapping = true;

            return $this;
        }
    }

    class Schedule
    {
        /** @var list<ScheduledEvent> */
        public static array $events = [];

        public static function reset(): void
        {
            self::$events = [];
        }

        public static function call(callable $callback): ScheduledEvent
        {
            $event = new ScheduledEvent($callback);
            self::$events[] = $event;

            return $event;
        }
    }

    class Auth
    {
        public static ?object $currentUser = null;

        public static function reset(): void
        {
            self::$currentUser = null;
        }

        public static function user(): ?object
        {
            return self::$currentUser;
        }
    }

    class Gate
    {
        /** @var callable|null */
        public static $resolver = null;

        public static function reset(): void
        {
            self::$resolver = null;
        }

        public static function forUser(?object $user): object
        {
            return new class($user) {
                private ?object $user;

                public function __construct(?object $user)
                {
                    $this->user = $user;
                }

                public function allows(string $ability, mixed $arguments = null): bool
                {
                    if (Gate::$resolver !== null) {
                        return (bool) (Gate::$resolver)($this->user, $ability, $arguments);
                    }

                    return false;
                }
            };
        }

        public static function allows(string $ability, mixed $arguments = null): bool
        {
            return self::forUser(Auth::user())->allows($ability, $arguments);
        }
    }
}

namespace Illuminate\Http {
    class HeaderBag
    {
        /** @var array<string, list<string>> */
        private array $headers = [];

        /**
         * @param array<string, string|list<string>> $headers
         */
        public function __construct(array $headers = [])
        {
            foreach ($headers as $key => $value) {
                $this->headers[strtolower($key)] = is_array($value) ? array_values($value) : [(string) $value];
            }
        }

        /**
         * @return array<string, list<string>>
         */
        public function all(): array
        {
            return $this->headers;
        }

        public function get(string $key, ?string $default = null): ?string
        {
            $normalized = strtolower($key);
            if (!isset($this->headers[$normalized][0])) {
                return $default;
            }

            return $this->headers[$normalized][0];
        }
    }

    class SessionStub
    {
        private string $token;

        public function __construct(string $token)
        {
            $this->token = $token;
        }

        public function token(): string
        {
            return $this->token;
        }
    }

    class Request
    {
        public HeaderBag $headers;
        private string $content;
        private ?object $user;
        private ?SessionStub $session;
        private string $hostUrl;

        /** @var array<string, mixed> */
        private array $inputs;

        private ?object $routeObject = null;

        /**
         * @param array<string, string|list<string>> $headers
         * @param array<string, mixed>               $inputs
         */
        public function __construct(
            string $content = '',
            array $headers = [],
            ?object $user = null,
            ?string $sessionCsrfToken = null,
            array $inputs = [],
            string $hostUrl = 'https://billing.example.com',
            ?object $routeObject = null
        ) {
            $this->content = $content;
            $this->headers = new HeaderBag($headers);
            $this->user = $user;
            $this->session = $sessionCsrfToken !== null ? new SessionStub($sessionCsrfToken) : null;
            $this->inputs = $inputs;
            $this->hostUrl = $hostUrl;
            $this->routeObject = $routeObject;
        }

        public function route(): ?object
        {
            return $this->routeObject;
        }

        public function getContent(): string
        {
            return $this->content;
        }

        public function header(string $key, ?string $default = null): ?string
        {
            return $this->headers->get($key, $default);
        }

        public function user(): ?object
        {
            return $this->user;
        }

        public function session(): ?SessionStub
        {
            return $this->session;
        }

        /**
         * @return array<string, mixed>
         */
        public function all(): array
        {
            return $this->inputs;
        }

        public function input(string $key, mixed $default = null): mixed
        {
            return $this->inputs[$key] ?? $default;
        }

        public function getSchemeAndHttpHost(): string
        {
            return $this->hostUrl;
        }
    }
}

namespace {
    final class PaymosTestGlobalState
    {
        public static ?\Illuminate\Http\Request $currentRequest = null;
    }

    if (!function_exists('request')) {
        function request(): ?\Illuminate\Http\Request
        {
            return PaymosTestGlobalState::$currentRequest;
        }
    }

    if (!function_exists('view')) {
        function view(string $name, array $data = []): object
        {
            return new class($name, $data) {
                private string $name;
                /** @var array<string, mixed> */
                private array $data;
                public function __construct(string $name, array $data)
                {
                    $this->name = $name;
                    $this->data = $data;
                }
                public function render(): string
                {
                    if ($this->name === 'paymos::connect') {
                        return '<div id="paymos-connect-panel"><button type="button" id="paymos-connect-button">Connect Paymos</button></div>';
                    }
                    $invoiceId = (string) ($this->data['panel']['invoice_id'] ?? $this->data['panel']['payment_key'] ?? '');
                    return '<div class="paymos-refunds-panel" data-view="' . $this->name . '" data-invoice="' . $invoiceId . '"></div>';
                }
            };
        }
    }
}

namespace PaymenterPaymosTests {
    use App\Attributes\ExtensionMeta;
    use App\Helpers\ExtensionHelper;
    use App\Models\Gateway as GatewayModel;
    use App\Models\Invoice;
    use App\Models\InvoiceItem;
    use App\Models\InvoiceTransaction;
    use Filament\Forms\Components\Placeholder;
    use Filament\Support\Facades\FilamentView;
    use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Facades\Schedule;
    use Illuminate\Support\Facades\View;
    use Paymos\Http\HttpResponse;
    use Paymos\Http\MockTransport;
    use Paymos\Http\TransportInterface;
    use Paymos\Plugin\RefundIntentStoreInterface;
    use Paymos\Plugin\RefundCandidateStoreInterface;
    use Paymos\Plugin\RefundStates;
    use Paymenter\Extensions\Gateways\Paymos\Internal\Config;
    use Paymenter\Extensions\Gateways\Paymos\Internal\ConnectHandler;
    use Paymenter\Extensions\Gateways\Paymos\Internal\CredentialStore;
    use Paymenter\Extensions\Gateways\Paymos\Internal\DbDriverInterface;
    use Paymenter\Extensions\Gateways\Paymos\Internal\PdoDbDriver;
    use Paymenter\Extensions\Gateways\Paymos\Internal\PdoEventStore;
    use Paymenter\Extensions\Gateways\Paymos\Internal\RefundIntentStore;
    use Paymenter\Extensions\Gateways\Paymos\Internal\SnapshotStore;
    use Paymenter\Extensions\Gateways\Paymos\Internal\Translation;
    use Paymenter\Extensions\Gateways\Paymos\Paymos;

    require_once dirname(__DIR__) . '/Paymos.php';

    /**
     * Deterministic relational storage driver for CLI tests without pdo_sqlite,
     * mirroring PdoDbDriver unique-key, CAS, ordering, locking, and transaction semantics.
     */
    final class InMemoryDbDriver implements DbDriverInterface
    {
        public bool $schemaEnsured = false;

        /** @var array<string, array<string, array<string, mixed>>> */
        private array $tables = [
            PdoEventStore::TABLE => [],
            SnapshotStore::TABLE => [],
            CredentialStore::TABLE => [],
            RefundIntentStore::TABLE => [],
            RefundIntentStore::EXTERNAL_TABLE => [],
        ];

        /** @var array<string, bool> */
        private array $locks = [];

        private bool $inTx = false;

        /** @var array<string, array<string, array<string, mixed>>>|null */
        private ?array $txSnapshot = null;

        public function ensureSchema(): void
        {
            $this->schemaEnsured = true;
            foreach ([
                PdoEventStore::TABLE,
                SnapshotStore::TABLE,
                CredentialStore::TABLE,
                RefundIntentStore::TABLE,
                RefundIntentStore::EXTERNAL_TABLE,
            ] as $table) {
                if (!isset($this->tables[$table])) {
                    $this->tables[$table] = [];
                }
            }
        }

        public function insertUnique(string $table, array $row, string $primaryKey): bool
        {
            $this->ensureSchema();
            $pk = (string) ($row[$primaryKey] ?? '');
            if ($pk === '') {
                throw new \InvalidArgumentException('Primary key cannot be empty.');
            }
            if (array_key_exists($pk, $this->tables[$table])) {
                return false;
            }
            if ($table === RefundIntentStore::TABLE) {
                foreach ($this->tables[$table] as $existing) {
                    if (
                        ((string) $existing['installation_id'] === (string) $row['installation_id']
                            && (string) $existing['request_token'] === (string) $row['request_token'])
                        || (string) $existing['external_order_id'] === (string) $row['external_order_id']
                        || ($row['withdrawal_id'] !== null && $existing['withdrawal_id'] !== null
                            && (string) $existing['withdrawal_id'] === (string) $row['withdrawal_id'])
                    ) {
                        return false;
                    }
                }
            }
            $this->tables[$table][$pk] = $row;

            return true;
        }

        public function upsert(string $table, array $row, string $primaryKey): void
        {
            $this->ensureSchema();
            $pk = (string) ($row[$primaryKey] ?? '');
            if ($pk === '') {
                throw new \InvalidArgumentException('Primary key cannot be empty.');
            }
            $this->tables[$table][$pk] = $row;
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
            $this->ensureSchema();
            $matches = [];
            foreach ($this->tables[$table] as $row) {
                if ($this->matchesPredicates($row, $where, [], $lteWhere, $gteWhere, $gtWhere)) {
                    $matches[] = $row;
                }
            }

            if ($orderBy !== null && trim($orderBy) !== '') {
                $clauses = array_values(array_filter(array_map('trim', explode(',', $orderBy))));
                usort($matches, static function (array $a, array $b) use ($clauses): int {
                    foreach ($clauses as $clause) {
                        $parts = preg_split('/\s+/', $clause);
                        $col = $parts[0] ?? '';
                        $dir = strtoupper($parts[1] ?? 'ASC');
                        $va = $a[$col] ?? null;
                        $vb = $b[$col] ?? null;
                        if ($va == $vb) {
                            continue;
                        }
                        $cmp = ($va < $vb) ? -1 : 1;
                        return $dir === 'DESC' ? -$cmp : $cmp;
                    }
                    return 0;
                });
            }

            if ($limit !== null) {
                return array_slice($matches, 0, max(0, $limit));
            }

            return $matches;
        }

        public function updateWhere(string $table, array $values, array $where, array $lteWhere = []): int
        {
            $this->ensureSchema();
            $affected = 0;
            foreach ($this->tables[$table] as $pk => $row) {
                if (!$this->matchesPredicates($row, $where, [], $lteWhere, [], [])) {
                    continue;
                }
                foreach ($values as $col => $val) {
                    $row[$col] = $val;
                }
                $this->tables[$table][$pk] = $row;
                $affected++;
            }

            return $affected;
        }

        public function deleteWhere(
            string $table,
            array $where,
            array $ltWhere = [],
            ?int $limit = null,
            array $lteWhere = []
        ): int {
            $this->ensureSchema();
            $deleted = 0;
            foreach ($this->tables[$table] as $pk => $row) {
                if ($limit !== null && $deleted >= $limit) {
                    break;
                }
                if (!$this->matchesPredicates($row, $where, $ltWhere, $lteWhere, [], [])) {
                    continue;
                }
                unset($this->tables[$table][$pk]);
                $deleted++;
            }

            return $deleted;
        }

        public function acquireNamedLock(string $name, int $timeoutSeconds = 10): bool
        {
            if (isset($this->locks[$name])) {
                return false;
            }
            $this->locks[$name] = true;

            return true;
        }

        public function ownsNamedLock(string $name): bool
        {
            return isset($this->locks[$name]);
        }

        public function releaseNamedLock(string $name): void
        {
            unset($this->locks[$name]);
        }

        public function inTransaction(): bool
        {
            return $this->inTx;
        }

        public function beginTransaction(): void
        {
            $this->inTx = true;
            $this->txSnapshot = $this->tables;
        }

        public function commit(): bool
        {
            $this->inTx = false;
            $this->txSnapshot = null;

            return true;
        }

        public function rollBack(): void
        {
            if ($this->inTx && $this->txSnapshot !== null) {
                $this->tables = $this->txSnapshot;
            }
            $this->inTx = false;
            $this->txSnapshot = null;
        }

        public function countRows(string $table): int
        {
            return count($this->tables[$table] ?? []);
        }

        /**
         * @return array<string, array<string, mixed>>
         */
        public function dumpTable(string $table): array
        {
            return $this->tables[$table] ?? [];
        }

        /**
         * @param array<string, mixed>       $row
         * @param array<string, scalar|null> $where
         * @param array<string, scalar>      $ltWhere
         * @param array<string, scalar>      $lteWhere
         * @param array<string, scalar>      $gteWhere
         * @param array<string, scalar>      $gtWhere
         */
        private function matchesPredicates(
            array $row,
            array $where,
            array $ltWhere,
            array $lteWhere,
            array $gteWhere,
            array $gtWhere
        ): bool {
            foreach ($where as $col => $val) {
                $actual = $row[$col] ?? null;
                if ($val === null) {
                    if ($actual !== null) {
                        return false;
                    }
                } elseif ((string) $actual !== (string) $val) {
                    return false;
                }
            }
            foreach ($ltWhere as $col => $val) {
                if (!isset($row[$col]) || !($row[$col] < $val)) {
                    return false;
                }
            }
            foreach ($lteWhere as $col => $val) {
                if (!isset($row[$col]) || !($row[$col] <= $val)) {
                    return false;
                }
            }
            foreach ($gteWhere as $col => $val) {
                if (!isset($row[$col]) || !($row[$col] >= $val)) {
                    return false;
                }
            }
            foreach ($gtWhere as $col => $val) {
                if (!isset($row[$col]) || !($row[$col] > $val)) {
                    return false;
                }
            }

            return true;
        }
    }

    /**
     * Negative probe proving Paymenter's Extension::config() truly rejects outside callers.
     */
    final class ExternalConfigCaller
    {
        public static function readFromOutside(Paymos $gateway, string $key): mixed
        {
            return $gateway->config($key);
        }
    }

    final class RecordingPdoStatement extends \PDOStatement
    {
        private string $sql;

        /** @var callable(string, array<int, mixed>): array{rows?: list<array<string, mixed>>, column?: mixed, rowCount?: int} */
        private $handler;

        /** @var list<array<string, mixed>> */
        private array $rows = [];

        private mixed $columnValue = null;
        private int $affected = 0;

        /**
         * @param callable(string, array<int, mixed>): array{rows?: list<array<string, mixed>>, column?: mixed, rowCount?: int} $handler
         */
        public function __construct(string $sql, callable $handler)
        {
            $this->sql = $sql;
            $this->handler = $handler;
        }

        public function execute(?array $params = null): bool
        {
            $result = ($this->handler)($this->sql, array_values($params ?? []));
            $this->rows = $result['rows'] ?? [];
            $this->columnValue = $result['column'] ?? null;
            $this->affected = (int) ($result['rowCount'] ?? 0);

            return true;
        }

        public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            return $this->rows;
        }

        public function fetchColumn(int $column = 0): mixed
        {
            return $this->columnValue;
        }

        public function rowCount(): int
        {
            return $this->affected;
        }
    }

    final class RecordingPdo extends \PDO
    {
        public string $driver;
        public bool $tablesExist;
        public bool $txActive;

        /** @var list<string> */
        public array $execCalls = [];

        /** @var list<array{sql: string, params: array<int, mixed>}> */
        public array $preparedCalls = [];

        public function __construct(string $driver, bool $tablesExist = false, bool $txActive = false)
        {
            $this->driver = $driver;
            $this->tablesExist = $tablesExist;
            $this->txActive = $txActive;
        }

        public function getAttribute(int $attribute): mixed
        {
            return $attribute === \PDO::ATTR_DRIVER_NAME ? $this->driver : null;
        }

        public function inTransaction(): bool
        {
            return $this->txActive;
        }

        public function exec(string $statement): int|false
        {
            $this->execCalls[] = $statement;
            if (stripos($statement, 'CREATE TABLE') === 0) {
                $this->tablesExist = true;
            }

            return 0;
        }

        public function prepare(string $query, array $options = []): \PDOStatement|false
        {
            return new RecordingPdoStatement($query, function (string $sql, array $params): array {
                $this->preparedCalls[] = ['sql' => $sql, 'params' => $params];
                if (stripos($sql, 'information_schema.tables') !== false || stripos($sql, 'sqlite_master') !== false) {
                    return ['column' => $this->tablesExist ? 5 : 0];
                }
                if (stripos($sql, 'GET_LOCK') !== false || stripos($sql, 'IS_USED_LOCK') !== false || stripos($sql, 'pg_try_advisory_lock') !== false) {
                    return ['column' => 1];
                }
                return ['rowCount' => 1];
            });
        }
    }

    final class RoleStub
    {
        /** @var list<string> */
        private array $permissions;

        /** @param list<string> $permissions */
        public function __construct(array $permissions)
        {
            $this->permissions = $permissions;
        }

        public function hasPermission(string $permission): bool
        {
            return in_array('*', $this->permissions, true) || in_array($permission, $this->permissions, true);
        }
    }

    final class UserStub
    {
        public int $id;
        public ?RoleStub $role;

        public function __construct(int $id, ?RoleStub $role = null)
        {
            $this->id = $id;
            $this->role = $role;
        }
    }

    function assertTrue(bool $cond, string $message): void
    {
        if (!$cond) {
            throw new \AssertionError($message);
        }
    }

    function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \AssertionError(
                $message . ' | expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
            );
        }
    }

    function jsonResponse(int $status, array $body, array $headers = []): HttpResponse
    {
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES);
        assertTrue(is_string($encoded), 'JSON encoding failed in test fixture.');

        return new HttpResponse($status, $encoded, array_merge(['content-type' => 'application/json'], $headers));
    }

    function signWebhook(string $secret, int $timestamp, string $rawBody, ?string $extraInvalidV1 = null): string
    {
        $sig = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if ($extraInvalidV1 !== null) {
            return 't=' . $timestamp . ',v1=' . $extraInvalidV1 . ',v1=' . $sig;
        }

        return 't=' . $timestamp . ',v1=' . $sig;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    function connectedEnvironments(bool $refundsEnabled = true): array
    {
        return [
            'sandbox' => [
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_test_paymenter_key_001',
                'api_secret' => 'sk_test_paymenter_secret_001',
                'project_id' => 'prj_paymenter_sandbox_001',
                'webhook_secret' => 'whsec_paymenter_sandbox_001',
                'capabilities' => $refundsEnabled ? ['invoice_refunds_v1'] : [],
                'refund_contract_version' => $refundsEnabled ? 1 : null,
                'refunds_enabled' => $refundsEnabled,
            ],
            'live' => [
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_live_paymenter_key_001',
                'api_secret' => 'sk_live_paymenter_secret_001',
                'project_id' => 'prj_paymenter_live_001',
                'webhook_secret' => 'whsec_paymenter_live_001',
                'capabilities' => $refundsEnabled ? ['invoice_refunds_v1'] : [],
                'refund_contract_version' => $refundsEnabled ? 1 : null,
                'refunds_enabled' => $refundsEnabled,
            ],
        ];
    }

    function appKeyFixture(): string
    {
        return 'base64:' . base64_encode(str_repeat('K', 32));
    }

    function makeGateway(
        array $settings = ['mode' => 'live', 'button_text' => 'Pay with Paymos'],
        ?InMemoryDbDriver &$db = null,
        ?TransportInterface $transport = null,
        ?callable $clock = null,
        bool $seedCredentials = true,
        bool $refundsEnabled = true,
        ?array &$logs = null
    ): Paymos {
        $db = $db ?? new InMemoryDbDriver();
        $logs = [];
        $gateway = new Paymos($settings);
        $gateway->setDbDriver($db);
        $gateway->setKeyProvider(static fn (): string => appKeyFixture());
        $gateway->setSourceUrlOverride('https://billing.example.com');
        if ($transport !== null) {
            $gateway->setTransport($transport);
        }
        if ($clock !== null) {
            $gateway->setClock($clock);
        }
        $gateway->setLogger(static function (string $level, string $message, array $context) use (&$logs): void {
            $logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
        });

        if ($seedCredentials) {
            $gateway->getCredentialStore()->saveCredentials(connectedEnvironments($refundsEnabled));
        }

        return $gateway;
    }

    function resetGlobals(): void
    {
        Invoice::resetStore();
        ExtensionHelper::reset();
        Route::reset();
        View::reset();
        FilamentView::reset();
        Schedule::reset();
        Auth::reset();
        Gate::reset();
    }

    // ── 1. ExtensionMeta, boot(), config() locality & zero-secret getConfig() ─
    function testMetadataBootAndConfigLocality(): void
    {
        resetGlobals();
        $ref = new \ReflectionClass(Paymos::class);
        $attrs = $ref->getAttributes(ExtensionMeta::class);
        assertSame(1, count($attrs), 'Paymos must declare #[ExtensionMeta].');
        /** @var ExtensionMeta $meta */
        $meta = $attrs[0]->newInstance();
        assertSame('Paymos', $meta->name, 'ExtensionMeta name must be Paymos.');
        assertSame('Paymos Labs', $meta->author, 'ExtensionMeta author must be Paymos Labs.');

        $db = new InMemoryDbDriver();
        $gateway = makeGateway(['mode' => 'sandbox', 'button_text' => 'Pay crypto'], $db);
        $gateway->boot();

        // Check routes registered: 1 CSRF-exempt webhook + 5 web admin routes (NO plain 'auth' middleware!)
        assertSame(6, count(Route::$registered), 'routes.php must register webhook + 5 admin routes.');
        $webhookRoute = Route::$registered[0];
        assertSame('/extensions/gateways/paymos/webhook', $webhookRoute->uri, 'Canonical CallbackPath must match.');
        assertTrue(in_array(PreventRequestForgery::class, $webhookRoute->withoutMiddleware, true), 'Webhook must be CSRF-exempt.');

        for ($i = 1; $i < 6; $i++) {
            $r = Route::$registered[$i];
            assertTrue(in_array('web', $r->middleware, true), 'Admin route must use web middleware (CSRF + session): ' . $r->uri);
            assertTrue(!in_array('auth', $r->middleware, true), 'Admin route must NOT rely on plain auth middleware: ' . $r->uri);
            assertSame([], $r->withoutMiddleware, 'Admin route must NEVER bypass CSRF: ' . $r->uri);
        }

        assertTrue(isset(View::$namespaces['paymos']), 'boot() must register paymos view namespace.');
        assertSame(2, count(FilamentView::$hooks), 'boot() must register both view & edit Filament invoice refund render hooks.');
        assertTrue(
            is_array(FilamentView::$hooks[0]['scopes']) && in_array('App\\Admin\\Resources\\InvoiceResource\\Pages\\ViewInvoice', FilamentView::$hooks[0]['scopes'], true),
            'R4: view-record render hook MUST be scoped to ViewInvoice.'
        );
        assertTrue(
            is_array(FilamentView::$hooks[1]['scopes']) && in_array('App\\Admin\\Resources\\InvoiceResource\\Pages\\EditInvoice', FilamentView::$hooks[1]['scopes'], true),
            'R4: edit-record render hook MUST be scoped to EditInvoice.'
        );

        // R4 defense-in-depth: paid invoice #5 exists, admin opens Users #5 / Services #5 / Products #5 / Tickets #5 -> hook MUST return ''!
        $inv5 = new Invoice(5, '25.00', 'USD', 'paid', 10);
        $inv5->remaining = '0';
        $inv5->transactions = [new InvoiceTransaction(1, 5, '0xtx_5', '25.00')];
        Invoice::put($inv5);
        $r4Transport = new MockTransport([
            jsonResponse(200, [
                'invoice_id' => 'inv_hook_5',
                'project_id' => 'prj_paymenter_sandbox_001',
                'status' => 'paid',
                'is_test' => true,
                'order' => ['external_id' => 'paymenter_5_0', 'amount' => '25', 'currency' => 'USD'],
                'refund_summary' => ['can_refund_full' => true, 'can_refund_overpayment' => false, 'full_refund_amount' => '25'],
            ]),
            jsonResponse(200, ['items' => [], 'next_cursor' => null]),
            jsonResponse(200, [
                'invoice_id' => 'inv_hook_5',
                'project_id' => 'prj_paymenter_sandbox_001',
                'status' => 'paid',
                'is_test' => true,
                'order' => ['external_id' => 'paymenter_5_0', 'amount' => '25', 'currency' => 'USD'],
                'refund_summary' => ['can_refund_full' => true, 'can_refund_overpayment' => false, 'full_refund_amount' => '25'],
            ]),
            jsonResponse(200, ['items' => [], 'next_cursor' => null]),
        ]);
        $r4Gw = makeGateway(['mode' => 'sandbox'], $db, $r4Transport);
        $r4Gw->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_5_0',
            'paymos_invoice_id' => 'inv_hook_5',
            'platform_ref' => '5',
            'amount' => '25',
            'currency' => 'USD',
            'environment' => 'sandbox',
            'project_id' => 'prj_paymenter_sandbox_001',
            'payment_url' => 'https://checkout.paymos.io/inv_hook_5',
            'transaction_id' => '0xtx_5',
            'status' => 'paid',
            'final_status' => 'paid',
            'renewal_attempt' => 0,
            'created_at' => 1700000000,
        ]);
        FilamentView::reset();
        $prevSchedule = Schedule::$events;
        $r4Gw->boot();
        Schedule::$events = $prevSchedule;
        $hookFn = FilamentView::$hooks[1]['hook'];
        $adminUser = new UserStub(1, new RoleStub(['admin.invoices.update']));
        $makeRoute = static function (?string $name, mixed $record, string $action = ''): object {
            return new class($name, $record, $action) {
                private ?string $name;
                private mixed $record;
                private string $action;
                public function __construct(?string $name, mixed $record, string $action) {
                    $this->name = $name; $this->record = $record; $this->action = $action;
                }
                public function getName(): ?string { return $this->name; }
                public function getActionName(): string { return $this->action; }
                public function parameter(string $key): mixed { return $key === 'record' ? $this->record : null; }
            };
        };

        // Non-invoice Filament resources with record=5 MUST return ''
        foreach ([
            ['filament.admin.resources.users.edit', '5', 'App\\Admin\\Resources\\UserResource\\Pages\\EditUser'],
            ['filament.admin.resources.services.edit', '5', 'App\\Admin\\Resources\\ServiceResource\\Pages\\EditService'],
            ['filament.admin.resources.products.view', '5', 'App\\Admin\\Resources\\ProductResource\\Pages\\ViewProduct'],
            ['filament.admin.resources.tickets.edit', '5', 'App\\Admin\\Resources\\TicketResource\\Pages\\EditTicket'],
            ['', '5', ''],
            ['filament.admin.resources.invoices.edit', (object) ['id' => 5], ''],
        ] as [$rName, $rec, $act]) {
            \PaymosTestGlobalState::$currentRequest = new Request('', [], $adminUser, 'tok', [], 'https://billing.example.com', $makeRoute($rName, $rec, $act));
            assertSame('', (string) $hookFn(), "R4: Non-Invoice route '{$rName}' MUST NOT render Invoice #5 refund panel.");
        }

        // Real InvoiceResource edit route (scalar '5') and Invoice object record MUST render the refunds panel
        \PaymosTestGlobalState::$currentRequest = new Request('', [], $adminUser, 'tok', [], 'https://billing.example.com', $makeRoute('filament.admin.resources.invoices.edit', '5', 'App\\Admin\\Resources\\InvoiceResource\\Pages\\EditInvoice'));
        $renderedByRoute = (string) $hookFn();
        assertTrue(str_contains($renderedByRoute, 'paymos-refunds-panel'), 'InvoiceResource edit route must render refunds panel.');

        \PaymosTestGlobalState::$currentRequest = new Request('', [], $adminUser, 'tok', [], 'https://billing.example.com', $makeRoute('filament.admin.resources.invoices.view', $inv5, 'App\\Admin\\Resources\\InvoiceResource\\Pages\\ViewInvoice'));
        $renderedByModel = (string) $hookFn();
        assertTrue(str_contains($renderedByModel, 'paymos-refunds-panel'), 'Invoice model record must render refunds panel.');
        \PaymosTestGlobalState::$currentRequest = null;
        assertSame(1, count(Schedule::$events), 'boot() must register scheduled reconciliation.');
        assertSame('everyTenMinutes', Schedule::$events[0]->frequency, 'Schedule must run every 10 minutes.');
        assertTrue(Schedule::$events[0]->withoutOverlapping, 'Schedule must enable withoutOverlapping().');

        // getConfig(): Filament Placeholder + mode + button_text, ZERO secret fields
        $fields = $gateway->getConfig();
        assertSame(3, count($fields), 'getConfig() must expose exactly 3 items (Connect placeholder, mode, button_text).');
        assertTrue($fields[0] instanceof Placeholder, 'First config item must be Filament Placeholder when Filament is present.');
        $html = (string) $fields[0]->content;
        assertTrue(str_contains($html, 'paymos-connect-button'), 'Connect placeholder must render Connect button.');
        assertTrue(!str_contains($html, 'sk_live_'), 'HTML must never contain API secret.');
        assertTrue(!str_contains($html, 'whsec_'), 'HTML must never contain webhook secret.');

        $names = [$fields[0]->name, $fields[1]['name'], $fields[2]['name']];
        assertSame(['paymos_connect', 'mode', 'button_text'], $names, 'Only Connect placeholder, mode, button_text may appear.');

        // Verify Extension::config() locality guard works and catches external callers
        $threw = false;
        try {
            ExternalConfigCaller::readFromOutside($gateway, 'mode');
        } catch (\LogicException $e) {
            $threw = true;
        }
        assertTrue($threw, 'External class calling $gateway->config() must throw LogicException.');
        assertTrue($gateway->canUseGateway('10.00', 'USD', 'invoice'), 'Paymos::canUseGateway() must succeed via internal readRawConfig().');
    }

    // ── 2. CredentialStore AesGcmEnvelope & strict validation ─────────────────
    function testCredentialStoreEncryptionAndValidation(): void
    {
        resetGlobals();
        $db = new InMemoryDbDriver();
        $now = 1700000000;
        $store = new CredentialStore($db, static fn (): string => appKeyFixture(), static function () use (&$now): int {
            return $now;
        });

        assertSame([], $store->loadCredentials(), 'Empty store must return [].');
        $store->saveCredentials(connectedEnvironments(true));

        // Stored DB value must be encrypted (not plaintext secrets)
        $rawRow = $db->selectOne(CredentialStore::TABLE, ['setting_key' => 'credentials_v1']);
        assertTrue(is_array($rawRow), 'Credentials envelope must be stored.');
        assertTrue(!str_contains((string) $rawRow['setting_value'], 'sk_live_paymenter_secret_001'), 'API secret must be encrypted at rest.');
        assertTrue(!str_contains((string) $rawRow['setting_value'], 'whsec_paymenter_live_001'), 'Webhook secret must be encrypted at rest.');

        $loaded = $store->loadCredentials();
        assertSame('prj_paymenter_live_001', $loaded['live']['project_id'], 'Live project_id must decrypt cleanly.');
        assertSame('prj_paymenter_sandbox_001', $loaded['sandbox']['project_id'], 'Sandbox project_id must decrypt cleanly.');

        $refundCfg = $store->refundConfig('live');
        assertSame(['invoice_refunds_v1'], $refundCfg['capabilities'], 'Capability invoice_refunds_v1 must load.');
        assertSame(1, $refundCfg['refund_contract_version'], 'refund_contract_version must be 1.');
        assertSame(true, $refundCfg['refunds_enabled'], 'refunds_enabled must be true.');

        // Missing app.key fails closed
        $noKeyStore = new CredentialStore($db, static fn (): string => '');
        $threwNoKey = false;
        try {
            $noKeyStore->loadCredentials();
        } catch (\RuntimeException $e) {
            $threwNoKey = true;
        }
        assertTrue($threwNoKey, 'Missing app.key must fail closed.');

        // Wrong app.key fails closed (AEAD tag verification)
        $wrongKeyStore = new CredentialStore($db, static fn (): string => 'base64:' . base64_encode(str_repeat('Z', 32)));
        $threwWrongKey = false;
        try {
            $wrongKeyStore->loadCredentials();
        } catch (\RuntimeException $e) {
            $threwWrongKey = true;
        }
        assertTrue($threwWrongKey, 'Wrong app.key must fail AEAD decryption.');

        // Reject invalid project_id (no prj_ prefix) and untrusted base_url and payout key rk_
        foreach ([
            'missing prj_' => ['project_id' => 'invalid_project'],
            'untrusted base_url' => ['base_url' => 'https://evil.example.com'],
            'payout key rk_' => ['api_key' => 'rk_live_payout_not_allowed'],
            'http base_url' => ['base_url' => 'http://api.paymos.io'],
        ] as $label => $override) {
            $bad = connectedEnvironments();
            $bad['live'] = array_merge($bad['live'], $override);
            $rejected = false;
            try {
                $store->saveCredentials($bad);
            } catch (\InvalidArgumentException $e) {
                $rejected = true;
            }
            assertTrue($rejected, 'CredentialStore must reject ' . $label);
        }

        // Device state auto-expires cleanly
        $store->saveState(['device_code' => 'dev_secret_123', 'expires_in' => 60]);
        assertSame('dev_secret_123', $store->loadState()['device_code'], 'Active device state must load.');
        $now += 61;
        assertSame([], $store->loadState(), 'Expired device state must auto-clear and return [].');
    }

    // ── 3. One-Click Connect flow & strict HTTP 403 admin authorization ───────
    function testConnectFlowAndAdminAuthorization403(): void
    {
        resetGlobals();
        $db = new InMemoryDbDriver();
        $transport = new MockTransport([
            jsonResponse(200, [
                'device_code' => 'dc_test_abc',
                'user_code' => 'ABCD-1234',
                'verification_uri_complete' => 'https://app.paymos.io/connect/approve?code=ABCD-1234',
                'expires_in' => 600,
                'interval' => 5,
            ]),
            jsonResponse(400, ['error' => 'authorization_pending']),
            jsonResponse(200, [
                'paymos_config' => [
                    'plugin' => 'paymenter',
                    'source_url' => 'https://billing.example.com',
                    'environments' => connectedEnvironments(true),
                ],
            ]),
        ]);

        $gateway = makeGateway(['mode' => 'sandbox'], $db, $transport, null, false);

        // CRITICAL SECURITY: Unauthenticated user -> 403 (0 HTTP calls)
        $unauthResp = $gateway->connectStart(new Request('{}', ['X-CSRF-TOKEN' => 'tok'], null, 'tok'));
        assertSame(403, $unauthResp['status'], 'Unauthenticated user must get HTTP 403 on connectStart.');

        // CRITICAL SECURITY: Regular authenticated customer (role = null) -> 403 on ALL Connect & Refund routes!
        $customer = new UserStub(501, null);
        $custRole = new UserStub(502, new RoleStub(['client.invoices.view']));
        foreach ([$customer, $custRole] as $regularUser) {
            $req = new Request(
                json_encode(['invoice_id' => '10', 'mode' => 'full', 'request_token' => RefundIntentStore::uuid()]),
                ['X-CSRF-TOKEN' => 'tok'],
                $regularUser,
                'tok'
            );
            assertSame(403, $gateway->connectStart($req)['status'], 'Customer must get 403 on connectStart.');
            assertSame(403, $gateway->connectPoll($req)['status'], 'Customer must get 403 on connectPoll.');
            assertSame(403, $gateway->refundPanel($req, '10')['status'], 'Customer must get 403 on refundPanel.');
            assertSame(403, $gateway->refundSubmit($req)['status'], 'Customer must get 403 on refundSubmit.');
            assertSame(403, $gateway->refundCheck($req)['status'], 'Customer must get 403 on refundCheck.');
        }

        // Wrong admin permission (invoice admin hitting Connect, gateway admin hitting Refunds) -> 403
        $invoiceOnlyAdmin = new UserStub(2, new RoleStub(['admin.invoices.update']));
        $gatewayOnlyAdmin = new UserStub(3, new RoleStub(['admin.gateways.update']));
        assertSame(
            403,
            $gateway->connectStart(new Request('{}', ['X-CSRF-TOKEN' => 'tok'], $invoiceOnlyAdmin, 'tok'))['status'],
            'Invoice-only admin must get 403 on Connect routes.'
        );
        assertSame(
            403,
            $gateway->refundSubmit(new Request('{}', ['X-CSRF-TOKEN' => 'tok'], $gatewayOnlyAdmin, 'tok'))['status'],
            'Gateway-only admin must get 403 on Refund routes.'
        );

        // Bad CSRF token -> 419 before any network call
        assertSame(
            419,
            $gateway->connectStart(new Request('{}', ['X-CSRF-TOKEN' => 'wrong'], $gatewayOnlyAdmin, 'expected_tok'))['status'],
            'Mismatched CSRF token must be rejected with HTTP 419.'
        );
        assertSame(0, count($transport->requests()), 'Zero network calls may be sent for unauthorized or bad-CSRF requests.');

        // Authorized admin over plain HTTP source URL -> 400 (0 network calls)
        $gateway->setSourceUrlOverride('http://billing.example.com');
        $httpResp = $gateway->connectStart(new Request('{}', ['X-CSRF-TOKEN' => 'tok'], $gatewayOnlyAdmin, 'tok'));
        assertSame(400, $httpResp['status'], 'Non-HTTPS Paymenter URL must be rejected.');
        assertSame(0, count($transport->requests()), 'Zero network calls may be sent for non-HTTPS URL.');
        $gateway->setSourceUrlOverride('https://billing.example.com');

        // Authorized admin happy path (role->hasPermission('admin.gateways.update'))
        $startResp = $gateway->connectStart(new Request(
            json_encode(['return_url' => 'https://billing.example.com/admin/gateways']),
            ['X-CSRF-TOKEN' => 'tok'],
            $gatewayOnlyAdmin,
            'tok'
        ));
        assertSame(200, $startResp['status'], 'Authorized admin connectStart must succeed.');
        assertSame('ABCD-1234', $startResp['body']['user_code'], 'user_code must be returned.');
        assertTrue(!isset($startResp['body']['device_code']), 'device_code must never be exposed to browser.');

        $pendingResp = $gateway->connectPoll(new Request('{}', ['X-CSRF-TOKEN' => 'tok'], $gatewayOnlyAdmin, 'tok'));
        assertSame(200, $pendingResp['status'], 'Pending poll must return 200.');
        assertSame('authorization_pending', $pendingResp['body']['status'], 'Pending status must pass through.');

        $doneResp = $gateway->connectPoll(new Request('{}', ['X-CSRF-TOKEN' => 'tok'], $gatewayOnlyAdmin, 'tok'));
        assertSame(200, $doneResp['status'], 'Approved poll must return 200.');
        assertSame('connected', $doneResp['body']['status'], 'Approved poll must return connected.');
        assertSame([], $gateway->getCredentialStore()->loadState(), 'Device state must be cleared after connect.');
        assertSame('prj_paymenter_live_001', $gateway->getCredentialStore()->loadCredentials()['live']['project_id'], 'Live credentials must be saved.');
        assertSame('prj_paymenter_sandbox_001', $gateway->getCredentialStore()->loadCredentials()['sandbox']['project_id'], 'Sandbox credentials must be saved.');

        // Mismatched plugin / source_url binding must reject and NOT overwrite credentials
        $mismatchDb = new InMemoryDbDriver();
        $mismatchTransport = new MockTransport([
            jsonResponse(200, [
                'device_code' => 'dc_mismatch',
                'user_code' => 'MISM-0001',
                'verification_uri_complete' => 'https://app.paymos.io/connect/approve?code=MISM-0001',
                'expires_in' => 600,
                'interval' => 5,
            ]),
            jsonResponse(200, [
                'paymos_config' => [
                    'plugin' => 'whmcs',
                    'source_url' => 'https://billing.example.com',
                    'environments' => connectedEnvironments(true),
                ],
            ]),
        ]);
        $mismatchGateway = makeGateway(['mode' => 'sandbox'], $mismatchDb, $mismatchTransport, null, false);
        assertSame(200, $mismatchGateway->connectStart(new Request('{}', ['X-CSRF-TOKEN' => 't'], $gatewayOnlyAdmin, 't'))['status'], 'Mismatch start ok.');
        $badPoll = $mismatchGateway->connectPoll(new Request('{}', ['X-CSRF-TOKEN' => 't'], $gatewayOnlyAdmin, 't'));
        assertSame(400, $badPoll['status'], 'Mismatched plugin slug must be rejected with 400.');
        assertSame([], $mismatchGateway->getCredentialStore()->loadCredentials(), 'Mismatched response must NOT save credentials.');

        // Also verify Gate::allows fallback for admin user without explicit role object
        Gate::$resolver = static fn (?object $user, string $ability, mixed $model): bool =>
            $user !== null && $user->id === 999 && $ability === 'update' && $model === GatewayModel::class;
        $gateAdmin = new UserStub(999, null);
        $gateCustomer = new UserStub(1000, null);
        assertSame(403, $mismatchGateway->connectPoll(new Request('{}', ['X-CSRF-TOKEN' => 't'], $gateCustomer, 't'))['status'], 'Non-admin denied by Gate must get 403.');
        // Gate-allowed admin passes auth (hits 400 because no active device state, not 403)
        assertSame(400, $mismatchGateway->connectPoll(new Request('{}', ['X-CSRF-TOKEN' => 't'], $gateAdmin, 't'))['status'], 'Gate-allowed admin must pass 403 auth gate.');
    }

    // ── 4. Checkout: STATUS_CREATING recovery, remaining priority, products[], replacement, RFC 9457 ─
    function testCheckoutLifecycleAndSafeApiProblems(): void
    {
        resetGlobals();
        $now = 1700100000;
        $clock = static function () use (&$now): int {
            return $now;
        };

        $transport = new MockTransport([
            // 1) Initial create with products[] and $invoice->remaining priority
            jsonResponse(201, [
                'invoice_id' => 'inv_checkout_001',
                'payment_url' => 'https://checkout.paymos.io/inv_checkout_001',
                'status' => 'awaiting_client',
                'expires_at' => $now + 1800,
            ]),
            // 2) Live GET check on reuse -> still open
            jsonResponse(200, [
                'invoice_id' => 'inv_checkout_001',
                'status' => 'awaiting_client',
                'expires_at' => $now + 1800,
            ]),
            // 3) Amount changed -> InvoiceReplacement::close() cancels old invoice
            jsonResponse(200, [
                'invoice_id' => 'inv_checkout_001',
                'status' => 'cancelled',
            ]),
            // 4) Replacement create with renew_count = 1
            jsonResponse(201, [
                'invoice_id' => 'inv_checkout_002',
                'payment_url' => 'https://checkout.paymos.io/inv_checkout_002',
                'status' => 'awaiting_client',
                'expires_at' => $now + 1800,
            ]),
            // 5) Amount changed again, cancel returns 409 and GET says awaiting_payment -> BLOCKED!
            jsonResponse(409, [
                'type' => 'https://paymos.io/docs/errors/codes#invoice_cannot_be_cancelled',
                'title' => 'Conflict',
                'status' => 409,
                'detail' => 'Invoice cannot be cancelled once a network is selected.',
                'code' => 'invoice_cannot_be_cancelled',
            ]),
            jsonResponse(200, [
                'invoice_id' => 'inv_checkout_002',
                'status' => 'awaiting_payment',
            ]),
        ]);

        $db = new InMemoryDbDriver();
        $gateway = makeGateway(['mode' => 'live'], $db, $transport, $clock);

        $invoice = new Invoice(
            101,
            '60.00',
            'USD',
            'pending',
            42,
            null,
            [
                new InvoiceItem('VPS Starter Monthly', '35.00', 1),
                new InvoiceItem('Dedicated IPv4', '14.90', 1),
            ]
        );
        // Partially credited invoice: remaining is 49.90 while total is 60.00
        $invoice->remaining = '49.90';

        $url = $gateway->pay($invoice, '60.00');
        assertSame('https://checkout.paymos.io/inv_checkout_001', $url, 'Checkout must return hosted payment_url.');

        $req1Body = json_decode($transport->requests()[0]['body'], true);
        assertSame('49.9', $req1Body['amount'], 'Checkout must prioritize $invoice->remaining and normalize decimal.');
        assertSame('USD', $req1Body['currency'], 'Currency must match invoice.');
        assertSame('prj_paymenter_live_001', $req1Body['project_id'], 'Live project_id must be sent.');
        assertSame('paymenter_101_0', $req1Body['external_order_id'], 'Deterministic external_order_id must be sent.');
        assertSame('42', $req1Body['client_id'], 'Native user_id must be sent as client_id.');
        assertTrue(!isset($req1Body['merchant_id']), 'merchant_id must NEVER be sent.');
        assertTrue(!isset($req1Body['lifetime']) && !isset($req1Body['ttl']), 'No TTL/lifetime field may be sent.');
        assertSame(2, count($req1Body['products']), 'Valid line items must be sent in products[].');
        assertSame('VPS Starter Monthly', $req1Body['products'][0]['title'], 'First product title must match.');
        assertSame('35', $req1Body['products'][0]['unit_amount'], 'First product unit_amount must normalize.');

        // Reuse existing invoice when nothing changed
        $reusedUrl = $gateway->pay($invoice, '49.90');
        assertSame('https://checkout.paymos.io/inv_checkout_001', $reusedUrl, 'Matching open invoice must be reused.');

        // Change amount -> closes old invoice via InvoiceReplacement, creates paymenter_101_1
        $invoice->remaining = '55.00';
        $replacedUrl = $gateway->pay($invoice, '55.00');
        assertSame('https://checkout.paymos.io/inv_checkout_002', $replacedUrl, 'Replacement must succeed after cancel.');
        $req4Body = json_decode($transport->requests()[3]['body'], true);
        assertSame('paymenter_101_1', $req4Body['external_order_id'], 'Replacement must bump renewal attempt.');

        // Change amount while inv_checkout_002 is awaiting_payment -> fail-closed block!
        $invoice->remaining = '70.00';
        $blocked = false;
        try {
            $gateway->pay($invoice, '70.00');
        } catch (\RuntimeException $e) {
            $blocked = true;
            assertSame(Translation::text('replacement_blocked', 'en'), $e->getMessage(), 'Blocked replacement must show localized replacement_blocked copy.');
        }
        assertTrue($blocked, 'Replacing an awaiting_payment invoice must fail closed.');

        // Crash recovery: pre-seed STATUS_CREATING intent -> replays EXACT same payload without new external_order_id
        $crashDb = new InMemoryDbDriver();
        $crashTransport = new MockTransport([
            jsonResponse(201, [
                'invoice_id' => 'inv_recovered_001',
                'payment_url' => 'https://checkout.paymos.io/inv_recovered_001',
                'status' => 'awaiting_client',
            ]),
        ]);
        $crashGateway = makeGateway(['mode' => 'live'], $crashDb, $crashTransport, $clock);
        $crashGateway->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_202_0',
            'paymos_invoice_id' => 'pending:paymenter_202_0',
            'platform_ref' => '202',
            'client_id' => '77',
            'amount' => '25',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => '',
            'status' => 'creating',
            'renewal_attempt' => 0,
        ]);
        $inv202 = new Invoice(202, '25.00', 'USD', 'pending', 77, null, [new InvoiceItem('Game Server', '25.00', 1)]);
        $recoveredUrl = $crashGateway->pay($inv202, '25.00');
        assertSame('https://checkout.paymos.io/inv_recovered_001', $recoveredUrl, 'Pending STATUS_CREATING intent must recover cleanly.');
        $recoverReq = json_decode($crashTransport->requests()[0]['body'], true);
        assertSame('paymenter_202_0', $recoverReq['external_order_id'], 'Recovery must replay exact external_order_id.');
        assertSame('25', $recoverReq['amount'], 'Recovery must replay exact amount.');

        // paid_late_noted guard blocks minting new invoice
        $crashGateway->getSnapshotStore()->markPaidLateNoted('paymenter_202_0', 'evt_late_1');
        $paidLateBlocked = false;
        try {
            $crashGateway->pay($inv202, '25.00');
        } catch (\RuntimeException $e) {
            $paidLateBlocked = true;
            assertSame(Translation::text('replacement_blocked', 'en'), $e->getMessage(), 'paid_late_noted must block new invoice.');
        }
        assertTrue($paidLateBlocked, 'paid_late_noted snapshot must block checkout.');

        // RFC 9457 application/problem+json (400/409/429) surfaces safe message without secrets
        $errTransport = new MockTransport([
            jsonResponse(400, [
                'type' => 'https://paymos.io/docs/errors/codes#unsupported_currency',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'Currency XYZ is not supported for this project.',
                'code' => 'unsupported_currency',
                'field' => 'currency',
            ], ['content-type' => 'application/problem+json']),
        ]);
        $errDb = new InMemoryDbDriver();
        $errGateway = makeGateway(['mode' => 'live'], $errDb, $errTransport, $clock);
        $errInv = new Invoice(303, '15.00', 'XYZ', 'pending', 88);
        $errCaught = false;
        try {
            $errGateway->pay($errInv, '15.00');
        } catch (\RuntimeException $e) {
            $errCaught = true;
            assertTrue(str_contains($e->getMessage(), 'Currency XYZ is not supported'), 'RFC 9457 detail must surface safely.');
            assertTrue(!str_contains($e->getMessage(), 'sk_live_'), 'Secret must never leak in API error message.');
        }
        assertTrue($errCaught, 'RFC 9457 error must throw without marking invoice paid.');
        assertSame('pending', $errInv->status, 'Failed checkout must never mark invoice paid.');

        // RFC 9457 409 Conflict & 429 RateLimit + non-English (ru) localized fallback without leaking secrets
        $err409Transport = new MockTransport([
            jsonResponse(409, [
                'type' => 'https://paymos.io/docs/errors/codes#idempotency_conflict',
                'title' => 'Conflict',
                'status' => 409,
                'detail' => 'Conflicting invoice parameters for sk_live_paymenter_secret_001.',
                'code' => 'idempotency_conflict',
            ], ['content-type' => 'application/problem+json']),
            jsonResponse(429, [
                'type' => 'https://paymos.io/docs/errors/codes#rate_limited',
                'title' => 'Too Many Requests',
                'status' => 429,
                'detail' => 'Rate limit exceeded.',
                'code' => 'rate_limited',
            ], ['content-type' => 'application/problem+json', 'retry-after' => '0']),
        ]);
        $errRuDb = new InMemoryDbDriver();
        $errRuGw = makeGateway(['mode' => 'live'], $errRuDb, $err409Transport, $clock);
        $errRuGw->setLocaleOverride('ru');
        $ruCaught = false;
        try {
            $errRuGw->pay(new Invoice(304, '15.00', 'USD', 'pending', 'user@example.com'), '15.00');
        } catch (\RuntimeException $e) {
            $ruCaught = true;
            assertSame(Translation::text('payment_cannot_be_used', 'ru'), $e->getMessage(), 'Non-English locale must show localized fallback on API error.');
            assertTrue(!str_contains($e->getMessage(), 'sk_live_'), 'Secret must never appear in error message.');
        }
        assertTrue($ruCaught, '409 Conflict must throw safe localized error.');

        // products[] amount-only fallback (>50 items, zero/negative item price) & email stripped from client_id
        $fallbackTransport = new MockTransport([
            jsonResponse(201, ['invoice_id' => 'inv_fb_1', 'payment_url' => 'https://checkout.paymos.io/inv_fb_1', 'status' => 'awaiting_client']),
            jsonResponse(201, ['invoice_id' => 'inv_fb_2', 'payment_url' => 'https://checkout.paymos.io/inv_fb_2', 'status' => 'awaiting_client']),
        ]);
        $fbDb = new InMemoryDbDriver();
        $fbGw = makeGateway(['mode' => 'live'], $fbDb, $fallbackTransport, $clock);
        $tooManyItems = [];
        for ($i = 1; $i <= 51; $i++) {
            $tooManyItems[] = new InvoiceItem('Item ' . $i, '1.00', 1);
        }
        $fbGw->pay(new Invoice(305, '51.00', 'USD', 'pending', 'buyer@example.com', null, $tooManyItems), '51.00');
        $fbReq1 = json_decode($fallbackTransport->requests()[0]['body'], true);
        assertTrue(!isset($fbReq1['products']), 'More than 50 line items must fall back to amount-only.');
        assertTrue(!isset($fbReq1['client_id']), 'Email address must NEVER be sent as client_id.');

        $fbGw->pay(new Invoice(306, '10.00', 'USD', 'pending', 99, null, [new InvoiceItem('Free item', '0.00', 1)]), '10.00');
        $fbReq2 = json_decode($fallbackTransport->requests()[1]['body'], true);
        assertTrue(!isset($fbReq2['products']), 'Zero-price line item must fall back to amount-only.');
        assertSame('99', $fbReq2['client_id'], 'Numeric user_id must be sent as client_id.');
    }

    // ── 5. Webhooks: Multi-env, dual-v1, 200 vs 409 dedup, reverse verify, AmountGuard, rollback, paid_late ─
    function testWebhookSecurityGuardsAndDedup(): void
    {
        resetGlobals();
        $now = 1700200000;
        $clock = static function () use (&$now): int {
            return $now;
        };

        $db = new InMemoryDbDriver();
        $invoice = new Invoice(401, '49.90', 'USD', 'pending', 42);
        Invoice::put($invoice);

        $liveInvoiceApiPayload = [
            'invoice_id' => 'inv_wh_401',
            'project_id' => 'prj_paymenter_live_001',
            'status' => 'paid',
            'is_test' => false,
            'order' => ['external_id' => 'paymenter_401_0', 'amount' => '49.9', 'currency' => 'USD'],
            'payment' => [
                'transfers' => [
                    ['status' => 'confirmed', 'tx_hash' => '0xconfirmed_hash_401'],
                ],
            ],
        ];

        $transport = new MockTransport([
            jsonResponse(200, $liveInvoiceApiPayload), // reverse verify for paid
            jsonResponse(200, array_merge($liveInvoiceApiPayload, ['status' => 'paid_over'])), // second paid_over (ignored)
        ]);

        $gateway = makeGateway(['mode' => 'live'], $db, $transport, $clock);
        $gateway->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_401_0',
            'paymos_invoice_id' => 'inv_wh_401',
            'platform_ref' => '401',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://checkout.paymos.io/inv_wh_401',
            'status' => 'awaiting_client',
            'renewal_attempt' => 0,
            'created_at' => $now,
        ]);

        $rawPaid = json_encode([
            'event_id' => 'evt_paid_401',
            'event_type' => 'invoice.paid',
            'version' => 1,
            'occurred_at' => $now,
            'data' => $liveInvoiceApiPayload,
        ], JSON_UNESCAPED_SLASHES);

        // Forged signature -> 401
        $forged = $gateway->webhook(new Request($rawPaid, ['X-Webhook-Signature' => 't=' . $now . ',v1=deadbeef']));
        assertSame(401, $forged['status'], 'Forged signature must return HTTP 401.');

        // Stale signature (>300s skew) -> 401
        $staleSig = signWebhook('whsec_paymenter_live_001', $now - 301, $rawPaid);
        $stale = $gateway->webhook(new Request($rawPaid, ['X-Webhook-Signature' => $staleSig]));
        assertSame(401, $stale['status'], 'Stale signature (>300s) must return HTTP 401.');

        // Concurrent in-flight delivery -> 409 Conflict (simulated by pre-reserving processing row)
        $otherWorkerStore = new PdoEventStore($db, $clock);
        assertTrue($otherWorkerStore->remember('evt_paid_401', 604800), 'Worker 1 reserves event.');
        $dualSig = signWebhook('whsec_paymenter_live_001', $now, $rawPaid, '0000oldrotatedsecret');
        $inFlight = $gateway->webhook(new Request($rawPaid, ['x-webhook-signature' => $dualSig]));
        assertSame(409, $inFlight['status'], 'Concurrent in-flight delivery must return HTTP 409 Conflict.');
        assertSame(0, count(ExtensionHelper::$payments), 'In-flight 409 must not credit payment.');
        $otherWorkerStore->release('evt_paid_401');

        // Valid dual-v1 webhook -> 200 + ExtensionHelper::addPayment() with confirmed tx_hash
        $ok = $gateway->webhook(new Request($rawPaid, ['x-webhook-signature' => $dualSig]));
        assertSame(200, $ok['status'], 'Valid dual-v1 webhook must return HTTP 200.');
        assertSame(1, count(ExtensionHelper::$payments), 'Payment must be credited once.');
        assertSame('0xconfirmed_hash_401', ExtensionHelper::$payments[0]['transaction_id'], 'Confirmed tx_hash must be recorded.');
        assertSame('paid', $invoice->status, 'Paymenter invoice must become paid.');

        // Committed duplicate delivery -> 200 without double credit
        $dup = $gateway->webhook(new Request($rawPaid, ['X-Webhook-Signature' => $dualSig]));
        assertSame(200, $dup['status'], 'Committed duplicate must return HTTP 200.');
        assertSame(true, $dup['body']['duplicate'] ?? false, 'Duplicate response must indicate duplicate.');
        assertSame(1, count(ExtensionHelper::$payments), 'Duplicate must NOT double-credit.');

        // Roll-back guard: late invoice.cancelled after paid -> 200 ignored, invoice stays paid!
        $rawCancel = json_encode([
            'event_id' => 'evt_late_cancel_401',
            'event_type' => 'invoice.cancelled',
            'version' => 1,
            'occurred_at' => $now + 5,
            'data' => array_merge($liveInvoiceApiPayload, ['status' => 'cancelled']),
        ], JSON_UNESCAPED_SLASHES);
        $cancelSig = signWebhook('whsec_paymenter_live_001', $now, $rawCancel);
        $cancelResp = $gateway->webhook(new Request($rawCancel, ['X-Webhook-Signature' => $cancelSig]));
        assertSame(200, $cancelResp['status'], 'Stale cancel after paid must be ignored cleanly.');
        assertSame('paid', $invoice->status, 'Paid invoice must NEVER be downgraded.');

        // AmountGuard mismatch -> manual_review, release lock, HTTP 400, 0 payments
        $inv402 = new Invoice(402, '99.00', 'USD', 'pending', 42); // tampered from 50.00 to 99.00
        Invoice::put($inv402);
        $mismatchApi = [
            'invoice_id' => 'inv_wh_402',
            'project_id' => 'prj_paymenter_sandbox_001',
            'status' => 'paid',
            'is_test' => true,
            'order' => ['external_id' => 'paymenter_402_0', 'amount' => '50', 'currency' => 'USD'],
        ];
        $mismatchTransport = new MockTransport([jsonResponse(200, $mismatchApi)]);
        $mismatchGw = makeGateway(['mode' => 'live'], $db, $mismatchTransport, $clock); // Notice live mode still verifies sandbox webhook!
        $mismatchGw->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_402_0',
            'paymos_invoice_id' => 'inv_wh_402',
            'platform_ref' => '402',
            'amount' => '50',
            'currency' => 'USD',
            'environment' => 'sandbox',
            'project_id' => 'prj_paymenter_sandbox_001',
            'payment_url' => 'https://checkout.paymos.io/inv_wh_402',
            'status' => 'awaiting_client',
            'renewal_attempt' => 0,
            'created_at' => $now,
        ]);
        $raw402 = json_encode([
            'event_id' => 'evt_mismatch_402',
            'event_type' => 'invoice.paid',
            'version' => 1,
            'occurred_at' => $now,
            'data' => $mismatchApi,
        ], JSON_UNESCAPED_SLASHES);
        $sig402 = signWebhook('whsec_paymenter_sandbox_001', $now, $raw402);
        $mismatchResp = $mismatchGw->webhook(new Request($raw402, ['X-Webhook-Signature' => $sig402]));
        assertSame(400, $mismatchResp['status'], 'AmountGuard mismatch must return HTTP 400.');
        assertSame('pending', $inv402->status, 'Mismatched invoice must stay pending.');
        assertSame(false, (new PdoEventStore($db, $clock))->isCommitted('evt_mismatch_402'), 'Failed mismatch event must release reservation.');

        // invoice.paid_late -> note-only + paid_late_noted, NEVER credits!
        $inv403 = new Invoice(403, '30.00', 'USD', 'pending', 42);
        Invoice::put($inv403);
        $paidLateApi = [
            'invoice_id' => 'inv_wh_403',
            'project_id' => 'prj_paymenter_live_001',
            'status' => 'expired',
            'paid_late_at' => $now,
            'is_test' => false,
            'order' => ['external_id' => 'paymenter_403_0', 'amount' => '30', 'currency' => 'USD'],
        ];
        $lateTransport = new MockTransport([jsonResponse(200, $paidLateApi)]);
        $lateGw = makeGateway(['mode' => 'live'], $db, $lateTransport, $clock);
        $lateGw->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_403_0',
            'paymos_invoice_id' => 'inv_wh_403',
            'platform_ref' => '403',
            'amount' => '30',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://checkout.paymos.io/inv_wh_403',
            'status' => 'expired',
            'renewal_attempt' => 0,
            'created_at' => $now,
        ]);
        $rawLate = json_encode([
            'event_id' => 'evt_paid_late_403',
            'event_type' => 'invoice.paid_late',
            'version' => 1,
            'occurred_at' => $now,
            'data' => $paidLateApi,
        ], JSON_UNESCAPED_SLASHES);
        $lateSig = signWebhook('whsec_paymenter_live_001', $now, $rawLate);
        $lateResp = $lateGw->webhook(new Request($rawLate, ['X-Webhook-Signature' => $lateSig]));
        assertSame(200, $lateResp['status'], 'invoice.paid_late must return HTTP 200.');
        assertSame('pending', $inv403->status, 'invoice.paid_late must NOT mark invoice paid.');
        assertSame(1, count($lateGw->getRecordedNotes()), 'invoice.paid_late must record a manual review note.');
        $snap403 = $lateGw->getSnapshotStore()->findByExternalOrderId('paymenter_403_0');
        assertSame('evt_paid_late_403', $snap403['paid_late_noted'], 'paid_late_noted must be persisted.');

        // Failed ExtensionHelper::addPayment() releases dedup reservation so retry succeeds
        $inv404 = new Invoice(404, '12.50', 'USD', 'pending', 42);
        Invoice::put($inv404);
        $retryApi = [
            'invoice_id' => 'inv_wh_404',
            'project_id' => 'prj_paymenter_live_001',
            'status' => 'paid',
            'is_test' => false,
            'order' => ['external_id' => 'paymenter_404_0', 'amount' => '12.5', 'currency' => 'USD'],
        ];
        $retryTransport = new MockTransport([jsonResponse(200, $retryApi), jsonResponse(200, $retryApi)]);
        $retryGw = makeGateway(['mode' => 'live'], $db, $retryTransport, $clock);
        $retryGw->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_404_0',
            'paymos_invoice_id' => 'inv_wh_404',
            'platform_ref' => '404',
            'amount' => '12.5',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://checkout.paymos.io/inv_wh_404',
            'status' => 'awaiting_client',
            'renewal_attempt' => 0,
            'created_at' => $now,
        ]);
        $raw404 = json_encode([
            'event_id' => 'evt_retry_404',
            'event_type' => 'invoice.paid',
            'version' => 1,
            'occurred_at' => $now,
            'data' => $retryApi,
        ], JSON_UNESCAPED_SLASHES);
        $sig404 = signWebhook('whsec_paymenter_live_001', $now, $raw404);
        ExtensionHelper::$throwOnNextPayment = new \RuntimeException('transient db outage');
        $failResp = $retryGw->webhook(new Request($raw404, ['X-Webhook-Signature' => $sig404]));
        assertSame(400, $failResp['status'], 'Failed addPayment must not return 200.');
        assertSame(false, (new PdoEventStore($db, $clock))->isCommitted('evt_retry_404'), 'Failed addPayment must release dedup lock.');
        $okRetry = $retryGw->webhook(new Request($raw404, ['X-Webhook-Signature' => $sig404]));
        assertSame(200, $okRetry['status'], 'Retried event after release must succeed.');
        assertSame('paid', $inv404->status, 'Invoice 404 must become paid on retry.');
    }

    // ── 6. Reconciler: 24h sweep + 600s throttle ──────────────────────────────
    function testReconciler24hSweepAnd600sThrottle(): void
    {
        resetGlobals();
        $now = 1700300000;
        $clock = static function () use (&$now): int {
            return $now;
        };

        $db = new InMemoryDbDriver();
        $invRecent = new Invoice(501, '20.00', 'USD', 'pending', 10);
        $invOld = new Invoice(502, '20.00', 'USD', 'pending', 10);
        Invoice::put($invRecent);
        Invoice::put($invOld);

        $transport = new MockTransport([
            // First run: GET inv_rec_501 (paid) + RefundDiscovery sandbox/live empty pages
            jsonResponse(200, [
                'invoice_id' => 'inv_rec_501',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'paid',
                'is_test' => false,
                'order' => ['external_id' => 'paymenter_501_0', 'amount' => '20', 'currency' => 'USD'],
                'payment' => ['transfers' => [['status' => 'confirmed', 'tx_hash' => '0xrec_501']]],
            ]),
            jsonResponse(200, ['items' => [], 'next_cursor' => null]),
            jsonResponse(200, ['items' => [], 'next_cursor' => null]),
            // Throttled second run (+120s): 0 invoice GETs (only refund discovery pages)
            jsonResponse(200, ['items' => [], 'next_cursor' => null]),
            jsonResponse(200, ['items' => [], 'next_cursor' => null]),
        ]);

        $gateway = makeGateway(['mode' => 'live'], $db, $transport, $clock);
        $gateway->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_501_0',
            'paymos_invoice_id' => 'inv_rec_501',
            'platform_ref' => '501',
            'amount' => '20',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://checkout.paymos.io/inv_rec_501',
            'status' => 'awaiting_client',
            'renewal_attempt' => 0,
            'created_at' => $now - 3600,
        ]);
        // Outside 24h window -> MUST NOT be polled
        $gateway->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_502_0',
            'paymos_invoice_id' => 'inv_rec_502',
            'platform_ref' => '502',
            'amount' => '20',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://checkout.paymos.io/inv_rec_502',
            'status' => 'awaiting_client',
            'renewal_attempt' => 0,
            'created_at' => $now - 90000,
        ]);

        $res1 = $gateway->runScheduledReconciliation(false);
        assertSame(1, $res1['invoices'], 'Reconciler must apply missed paid invoice within 24h.');
        assertSame('paid', $invRecent->status, 'Recent invoice must be marked paid.');
        assertSame('pending', $invOld->status, 'Invoice older than 24h must not be swept.');

        // Immediate retry within 600s -> throttled!
        $now += 120;
        $res2 = $gateway->runScheduledReconciliation(false);
        assertSame(0, $res2['invoices'], 'Reconciler must respect 600-second throttle.');

        // R8: Reconciler MUST reject mismatched is_test and empty API fields
        $now += 650;
        $inv503 = new Invoice(503, '30.00', 'USD', 'pending', 10);
        $inv504 = new Invoice(504, '40.00', 'USD', 'pending', 10);
        Invoice::put($inv503);
        Invoice::put($inv504);
        $r8Transport = new MockTransport([
            // inv_rec_503: live snapshot gets sandbox is_test=true -> MUST reject
            jsonResponse(200, [
                'invoice_id' => 'inv_rec_503',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'paid',
                'is_test' => true,
                'order' => ['external_id' => 'paymenter_503_0', 'amount' => '30', 'currency' => 'USD'],
            ]),
            // inv_rec_504: empty project_id / order.amount -> MUST reject
            jsonResponse(200, [
                'invoice_id' => 'inv_rec_504',
                'project_id' => '',
                'status' => 'paid',
                'is_test' => false,
                'order' => ['external_id' => 'paymenter_504_0', 'amount' => '', 'currency' => 'USD'],
            ]),
            jsonResponse(200, ['items' => [], 'next_cursor' => null]),
            jsonResponse(200, ['items' => [], 'next_cursor' => null]),
        ]);
        $r8Gw = makeGateway(['mode' => 'live'], $db, $r8Transport, $clock);
        $r8Gw->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_503_0',
            'paymos_invoice_id' => 'inv_rec_503',
            'platform_ref' => '503',
            'amount' => '30',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://checkout.paymos.io/inv_rec_503',
            'status' => 'awaiting_client',
            'renewal_attempt' => 0,
            'created_at' => $now - 60,
        ]);
        $r8Gw->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_504_0',
            'paymos_invoice_id' => 'inv_rec_504',
            'platform_ref' => '504',
            'amount' => '40',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://checkout.paymos.io/inv_rec_504',
            'status' => 'awaiting_client',
            'renewal_attempt' => 0,
            'created_at' => $now - 50,
        ]);
        $res3 = $r8Gw->runScheduledReconciliation(false);
        assertSame(0, $res3['invoices'], 'R8: Mismatched is_test and empty API fields must NOT settle invoices.');
        assertSame('pending', $inv503->status, 'R8: Invoice 503 must remain pending.');
        assertSame('pending', $inv504->status, 'R8: Invoice 504 must remain pending.');
    }

    // ── 7. Refunds: async-safe nativeRefund(), full + overpayment, RefundDiscovery, rejects ─
    function testRefundsAsyncSafeProjectionDiscoveryAndRejects(): void
    {
        resetGlobals();
        $now = 1700400000;
        $clock = static function () use (&$now): int {
            return $now;
        };

        $db = new InMemoryDbDriver();
        $store = new RefundIntentStore($db, null, $clock);
        assertTrue($store instanceof RefundIntentStoreInterface, 'RefundIntentStore must implement RefundIntentStoreInterface.');
        assertTrue($store instanceof RefundCandidateStoreInterface, 'RefundIntentStore must implement RefundCandidateStoreInterface.');

        // Seed paid invoice 601 with paid transaction
        $inv601 = new Invoice(601, '49.90', 'USD', 'paid', 42);
        $inv601->remaining = '0';
        $tx601 = new InvoiceTransaction(1, 601, '0xtx_601', '49.90');
        $inv601->transactions = [$tx601];
        Invoice::put($inv601);

        $invoice601Api = [
            'invoice_id' => 'inv_ref_601',
            'project_id' => 'prj_paymenter_live_001',
            'status' => 'paid',
            'is_test' => false,
            'order' => ['external_id' => 'paymenter_601_0', 'amount' => '49.9', 'currency' => 'USD'],
            'refund_summary' => [
                'can_refund_full' => true,
                'can_refund_overpayment' => false,
                'full_refund_amount' => '49.9',
                'overpayment_refund_amount' => null,
            ],
        ];

        $pendingRefundWire = static fn (string $extId): array => [
            'withdrawal_id' => 'wd_ref_601',
            'invoice_id' => 'inv_ref_601',
            'project_id' => 'prj_paymenter_live_001',
            'mode' => 'full',
            'external_order_id' => $extId,
            'status' => 'created',
            'amount' => '49.9',
            'currency' => 'USDT',
            'network' => 'TRON',
            'first_sender' => 'TFirstSenderWallet111111111111111111',
            'is_test' => false,
        ];

        $completedRefundWire = static fn (string $extId): array => array_merge(
            $pendingRefundWire($extId),
            ['status' => 'completed']
        );

        // Dynamic MockTransport queue built step-by-step: first initiate pending refund via native ExtensionHelper::refund()
        $capturedExtId = '';
        $step = 0;
        $dynamicTransport = new class($step, $capturedExtId, $invoice601Api, $pendingRefundWire, $completedRefundWire) implements \Paymos\Http\TransportInterface {
            public int $postRefundCalls = 0;
            public array $requests = [];
            private int $step;
            private string $extId;
            private array $invApi;
            private $pendingWire;
            private $completedWire;
            public bool $completeNow = false;

            public function __construct(&$step, &$extId, array $invApi, callable $pendingWire, callable $completedWire)
            {
                $this->step = &$step;
                $this->extId = &$extId;
                $this->invApi = $invApi;
                $this->pendingWire = $pendingWire;
                $this->completedWire = $completedWire;
            }

            public function request($method, $url, array $headers, $body, $timeoutSeconds): HttpResponse
            {
                $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
                $path = (string) parse_url($url, PHP_URL_PATH);

                if ($method === 'POST' && $path === '/v1/refunds') {
                    $this->postRefundCalls++;
                    $decoded = json_decode((string) $body, true);
                    $this->extId = (string) ($decoded['external_order_id'] ?? '');
                    return jsonResponse(201, ($this->pendingWire)($this->extId));
                }
                if ($method === 'GET' && str_starts_with($path, '/v1/refunds/')) {
                    $wire = $this->completeNow ? ($this->completedWire)($this->extId) : ($this->pendingWire)($this->extId);
                    return jsonResponse(200, $wire);
                }
                if ($method === 'GET' && str_starts_with($path, '/v1/invoices/')) {
                    return jsonResponse(200, $this->invApi);
                }

                return jsonResponse(404, ['error' => 'not_found']);
            }
        };

        $gateway = makeGateway(['mode' => 'live'], $db, $dynamicTransport, $clock);
        $gateway->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_601_0',
            'paymos_invoice_id' => 'inv_ref_601',
            'platform_ref' => '601',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://checkout.paymos.io/inv_ref_601',
            'transaction_id' => '0xtx_601',
            'status' => 'paid',
            'renewal_attempt' => 0,
            'created_at' => $now,
        ]);

        // Reject partial amount BEFORE any network call
        $partialThrew = false;
        try {
            ExtensionHelper::refund($gateway, $tx601, '10.00');
        } catch (\InvalidArgumentException $e) {
            $partialThrew = true;
        }
        assertTrue($partialThrew, 'Partial refund amount must be rejected immediately.');
        assertSame(0, count($dynamicTransport->requests), '0 HTTP calls allowed for partial refund.');
        assertSame(0, count(ExtensionHelper::$recordRefundCalls), 'No premature recordRefund on rejected partial.');

        // CRITICAL MONEY SAFETY: native ExtensionHelper::refund() while Paymos returns pending/created
        $inProgressThrew = false;
        try {
            ExtensionHelper::refund($gateway, $tx601, '49.90');
        } catch (\RuntimeException $e) {
            $inProgressThrew = true;
            assertTrue(str_contains($e->getMessage(), 'in progress'), 'Pending native refund must explain refund is in progress.');
        }
        assertTrue($inProgressThrew, 'nativeRefund() MUST NOT return true while refund is pending.');
        assertSame(0, count(ExtensionHelper::$recordRefundCalls), 'CRITICAL: ExtensionHelper::recordRefund() MUST NOT run on pending refund.');
        assertSame(0, count(ExtensionHelper::$refundLocallyCalls), 'CRITICAL: ExtensionHelper::refundLocally() MUST NOT run before completed.');
        assertSame('paid', $inv601->status, 'Invoice must stay paid while refund is still in progress.');
        assertSame(1, $dynamicTransport->postRefundCalls, 'Exactly 1 POST /v1/refunds must have been sent.');

        // Now Paymos completes the refund and delivers signed withdrawal.completed webhook!
        $dynamicTransport->completeNow = true;
        $whRefundBody = json_encode([
            'event_id' => 'evt_wd_completed_601',
            'event_type' => 'withdrawal.completed',
            'version' => 1,
            'occurred_at' => $now + 30,
            'data' => [
                'type' => 'refund',
                'withdrawal_id' => 'wd_ref_601',
                'invoice_id' => 'inv_ref_601',
                'project_id' => 'prj_paymenter_live_001',
                'mode' => 'full',
                'external_order_id' => $capturedExtId,
                'status' => 'completed',
                'is_test' => false,
            ],
        ], JSON_UNESCAPED_SLASHES);
        $whRefundSig = signWebhook('whsec_paymenter_live_001', $now, $whRefundBody);
        $whResp = $gateway->webhook(new Request($whRefundBody, ['X-Webhook-Signature' => $whRefundSig]));
        assertSame(200, $whResp['status'], 'withdrawal.completed refund webhook must return HTTP 200.');
        assertSame(1, count(ExtensionHelper::$refundLocallyCalls), 'ExtensionHelper::refundLocally() must fire ONCE on verified completed.');
        assertSame('refunded', $inv601->status, 'Paymenter invoice must transition to refunded on completed full refund.');
        assertSame(1, $dynamicTransport->postRefundCalls, 'No duplicate POST /v1/refunds allowed.');

        // Overpayment refund (paid_over): completes via admin route, leaves invoice paid, records note (NO refundLocally)
        $inv602 = new Invoice(602, '100.00', 'USD', 'paid', 42);
        $inv602->remaining = '0';
        $tx602 = new InvoiceTransaction(2, 602, '0xtx_602', '100.00');
        $inv602->transactions = [$tx602];
        Invoice::put($inv602);
        $gateway->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_602_0',
            'paymos_invoice_id' => 'inv_ref_602',
            'platform_ref' => '602',
            'amount' => '100',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://checkout.paymos.io/inv_ref_602',
            'transaction_id' => '0xtx_602',
            'status' => 'paid_over',
            'renewal_attempt' => 0,
            'created_at' => $now,
        ]);

        $overTransport = new class implements \Paymos\Http\TransportInterface {
            public int $posts = 0;
            private string $extId = '';
            public function request($method, $url, array $headers, $body, $timeoutSeconds): HttpResponse
            {
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($method === 'POST' && $path === '/v1/refunds') {
                    $this->posts++;
                    $decoded = json_decode((string) $body, true);
                    $this->extId = (string) ($decoded['external_order_id'] ?? '');
                    return jsonResponse(201, ['withdrawal_id' => 'wd_over_602', 'status' => 'completed']);
                }
                if ($method === 'GET' && $path === '/v1/refunds/wd_over_602') {
                    return jsonResponse(200, [
                        'withdrawal_id' => 'wd_over_602',
                        'invoice_id' => 'inv_ref_602',
                        'project_id' => 'prj_paymenter_live_001',
                        'mode' => 'overpayment',
                        'external_order_id' => $this->extId,
                        'status' => 'completed',
                        'amount' => '5.5',
                        'currency' => 'USDT',
                        'network' => 'TRON',
                        'first_sender' => 'TFirstSenderWallet222222222222222222',
                        'is_test' => false,
                    ]);
                }
                if ($method === 'GET' && $path === '/v1/invoices/inv_ref_602') {
                    return jsonResponse(200, [
                        'invoice_id' => 'inv_ref_602',
                        'project_id' => 'prj_paymenter_live_001',
                        'status' => 'paid_over',
                        'is_test' => false,
                        'order' => ['external_id' => 'paymenter_602_0', 'amount' => '100', 'currency' => 'USD'],
                        'refund_summary' => [
                            'can_refund_full' => true,
                            'can_refund_overpayment' => true,
                            'full_refund_amount' => '100',
                            'overpayment_refund_amount' => '5.5',
                        ],
                    ]);
                }
                return jsonResponse(200, ['items' => [], 'next_cursor' => null]);
            }
        };

        $overGw = makeGateway(['mode' => 'live'], $db, $overTransport, $clock);
        $admin = new UserStub(1, new RoleStub(['admin.invoices.update']));

        // Reject unmanaged fields (destination_address) & invalid UUID token BEFORE network call
        $unmanagedResp = $overGw->refundSubmit(new Request(
            json_encode([
                'invoice_id' => '602',
                'mode' => 'overpayment',
                'request_token' => RefundIntentStore::uuid(),
                'destination_address' => 'TAttackerWallet',
            ]),
            ['X-CSRF-TOKEN' => 'tok'],
            $admin,
            'tok'
        ));
        assertSame(409, $unmanagedResp['status'], 'Unmanaged destination_address must be rejected.');
        assertSame('refund_managed_input_only', $unmanagedResp['body']['reason'], 'Must return refund_managed_input_only.');
        assertSame(0, $overTransport->posts, '0 HTTP calls for unmanaged refund field.');

        $badTokenResp = $overGw->refundSubmit(new Request(
            json_encode([
                'invoice_id' => '602',
                'mode' => 'overpayment',
                'request_token' => 'not-a-valid-uuid',
            ]),
            ['X-CSRF-TOKEN' => 'tok'],
            $admin,
            'tok'
        ));
        assertSame(409, $badTokenResp['status'], 'Invalid UUID token must be rejected.');
        assertSame(0, $overTransport->posts, '0 HTTP calls for invalid token.');

        // Submit valid overpayment refund
        $overToken = RefundIntentStore::uuid();
        $overResp = $overGw->refundSubmit(new Request(
            json_encode([
                'invoice_id' => '602',
                'mode' => 'overpayment',
                'request_token' => $overToken,
            ]),
            ['X-CSRF-TOKEN' => 'tok'],
            $admin,
            'tok'
        ));
        assertSame(200, $overResp['status'], 'Overpayment refund submission must succeed.');
        assertSame('completed', $overResp['body']['cash_state'], 'Overpayment cash_state must be completed.');
        assertSame('applied', $overResp['body']['projection_state'], 'Overpayment projection_state must be applied.');
        assertSame('paid', $inv602->status, 'Main invoice MUST stay paid after overpayment refund.');
        assertSame(1, count(ExtensionHelper::$refundLocallyCalls), 'Overpayment must NOT invoke ExtensionHelper::refundLocally().');

        // Dashboard-initiated RefundDiscovery test (invoice 603 refunded directly in Paymos Dashboard -> 0 POST /v1/refunds!)
        $inv603 = new Invoice(603, '75.00', 'USD', 'paid', 42);
        $inv603->remaining = '0';
        $tx603 = new InvoiceTransaction(3, 603, '0xtx_603', '75.00');
        $inv603->transactions = [$tx603];
        Invoice::put($inv603);
        $gateway->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_603_0',
            'paymos_invoice_id' => 'inv_ref_603',
            'platform_ref' => '603',
            'amount' => '75',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://checkout.paymos.io/inv_ref_603',
            'transaction_id' => '0xtx_603',
            'status' => 'paid',
            'renewal_attempt' => 0,
            'created_at' => $now,
        ]);

        $discTransport = new class implements \Paymos\Http\TransportInterface {
            public int $postRefundCalls = 0;
            public function request($method, $url, array $headers, $body, $timeoutSeconds): HttpResponse
            {
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($method === 'POST' && $path === '/v1/refunds') {
                    $this->postRefundCalls++;
                }
                if ($method === 'GET' && $path === '/v1/refunds') {
                    return jsonResponse(200, [
                        'items' => [
                            [
                                'withdrawal_id' => 'wd_dash_603',
                                'invoice_id' => 'inv_ref_603',
                                'project_id' => 'prj_paymenter_live_001',
                                'mode' => 'full',
                                'external_order_id' => 'dash_ext_603',
                                'is_test' => false,
                            ],
                        ],
                        'next_cursor' => null,
                    ]);
                }
                if ($method === 'GET' && $path === '/v1/refunds/wd_dash_603') {
                    return jsonResponse(200, [
                        'withdrawal_id' => 'wd_dash_603',
                        'invoice_id' => 'inv_ref_603',
                        'project_id' => 'prj_paymenter_live_001',
                        'mode' => 'full',
                        'external_order_id' => 'dash_ext_603',
                        'status' => 'completed',
                        'amount' => '75',
                        'currency' => 'USDT',
                        'network' => 'TRON',
                        'first_sender' => 'TFirstSenderWallet333333333333333333',
                        'is_test' => false,
                    ]);
                }
                if ($method === 'GET' && $path === '/v1/invoices/inv_ref_603') {
                    return jsonResponse(200, [
                        'invoice_id' => 'inv_ref_603',
                        'project_id' => 'prj_paymenter_live_001',
                        'status' => 'paid',
                        'is_test' => false,
                        'order' => ['external_id' => 'paymenter_603_0', 'amount' => '75', 'currency' => 'USD'],
                    ]);
                }
                return jsonResponse(404, ['error' => 'not_found']);
            }
        };

        $discGw = makeGateway(['mode' => 'live'], $db, $discTransport, $clock);
        $panelResp = $discGw->refundPanel(new Request('', [], $admin), '603');
        assertSame(200, $panelResp['status'], 'Admin refundPanel must succeed.');
        assertSame(0, $discTransport->postRefundCalls, 'RefundDiscovery must NEVER call POST /v1/refunds.');
        assertSame(1, count($panelResp['body']['external']), 'Dashboard refund must appear in external observations.');
        assertSame(2, count(ExtensionHelper::$refundLocallyCalls), 'Completed Dashboard full refund must project locally via ExtensionHelper::refundLocally().');
        assertSame('refunded', $inv603->status, 'Invoice 603 must be marked refunded after Dashboard discovery.');
        assertSame([], $panelResp['body']['available_modes'], 'No refund buttons should remain after full refund.');

        // Candidate read retry (BUG-1347): transient 404 on first GET stores candidate, later reconcile uses GET only!
        $inv604 = new Invoice(604, '40.00', 'USD', 'paid', 42);
        $inv604->remaining = '0';
        $tx604 = new InvoiceTransaction(4, 604, '0xtx_604', '40.00');
        $inv604->transactions = [$tx604];
        Invoice::put($inv604);
        $candDb = new InMemoryDbDriver();
        $candTransport = new class implements \Paymos\Http\TransportInterface {
            public int $postCount = 0;
            public int $refundGetCount = 0;
            private string $extId = '';
            public function request($method, $url, array $headers, $body, $timeoutSeconds): HttpResponse
            {
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($method === 'POST' && $path === '/v1/refunds') {
                    $this->postCount++;
                    $decoded = json_decode((string) $body, true);
                    $this->extId = (string) ($decoded['external_order_id'] ?? '');
                    return jsonResponse(201, ['withdrawal_id' => 'wd_cand_604', 'status' => 'created']);
                }
                if ($method === 'GET' && $path === '/v1/refunds/wd_cand_604') {
                    $this->refundGetCount++;
                    if ($this->refundGetCount <= 2) {
                        return jsonResponse(404, [
                            'type' => 'https://paymos.io/docs/errors/codes#not_found',
                            'title' => 'Not Found',
                            'status' => 404,
                            'detail' => 'Not found yet',
                            'code' => 'not_found',
                        ]);
                    }
                    return jsonResponse(200, [
                        'withdrawal_id' => 'wd_cand_604',
                        'invoice_id' => 'inv_ref_604',
                        'project_id' => 'prj_paymenter_live_001',
                        'mode' => 'full',
                        'external_order_id' => $this->extId,
                        'status' => 'completed',
                        'amount' => '40',
                        'currency' => 'USDT',
                        'network' => 'TRON',
                        'first_sender' => 'TFirstSenderWallet444444444444444444',
                        'is_test' => false,
                    ]);
                }
                if ($method === 'GET' && $path === '/v1/invoices/inv_ref_604') {
                    return jsonResponse(200, [
                        'invoice_id' => 'inv_ref_604',
                        'project_id' => 'prj_paymenter_live_001',
                        'status' => 'paid',
                        'is_test' => false,
                        'order' => ['external_id' => 'paymenter_604_0', 'amount' => '40', 'currency' => 'USD'],
                        'refund_summary' => [
                            'can_refund_full' => true,
                            'can_refund_overpayment' => false,
                            'full_refund_amount' => '40',
                            'overpayment_refund_amount' => null,
                        ],
                    ]);
                }
                return jsonResponse(200, ['items' => [], 'next_cursor' => null]);
            }
        };
        $candGw = makeGateway(['mode' => 'live'], $candDb, $candTransport, $clock);
        $candGw->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_604_0',
            'paymos_invoice_id' => 'inv_ref_604',
            'platform_ref' => '604',
            'amount' => '40',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://checkout.paymos.io/inv_ref_604',
            'transaction_id' => '0xtx_604',
            'status' => 'paid',
            'renewal_attempt' => 0,
            'created_at' => $now,
        ]);
        $submitCand = $candGw->refundSubmit(new Request(
            json_encode(['invoice_id' => '604', 'mode' => 'full', 'request_token' => RefundIntentStore::uuid()]),
            ['X-CSRF-TOKEN' => 'tok'],
            $admin,
            'tok'
        ));
        assertSame(200, $submitCand['status'], 'Submit with transient 404 read must record candidate review.');
        assertSame('review', $submitCand['body']['cash_state'], 'Transient 404 read sets review state.');
        assertSame('refund_read_unavailable', $submitCand['body']['review_reason'], 'Reason must be refund_read_unavailable.');
        $opId = $submitCand['body']['operation_id'];

        $checkResp = $candGw->refundCheck(new Request(
            json_encode(['operation_id' => $opId]),
            ['X-CSRF-TOKEN' => 'tok'],
            $admin,
            'tok'
        ));
        assertSame(200, $checkResp['status'], 'Check status recovery must succeed.');
        assertSame(1, $candTransport->postCount, 'Candidate recovery must NEVER re-POST /v1/refunds.');
        $recoveredIntent = $candGw->getRefundIntentStore()->getIntent($opId);
        assertSame(RefundStates::COMPLETED, $recoveredIntent->cashState(), 'Recovered intent must reach completed.');
        assertSame(RefundStates::PROJECTION_APPLIED, $recoveredIntent->projectionState(), 'Recovered intent must project cleanly.');

        // Missing invoice_refunds_v1 capability -> refund_reconnect_required BEFORE any POST /v1/refunds
        $inv605 = new Invoice(605, '20.00', 'USD', 'paid', 42);
        $inv605->remaining = '0';
        $inv605->transactions = [new InvoiceTransaction(5, 605, '0xtx_605', '20.00')];
        Invoice::put($inv605);
        $noCapTransport = new MockTransport([]);
        $noCapDb = new InMemoryDbDriver();
        $noCapGw = makeGateway(['mode' => 'live'], $noCapDb, $noCapTransport, $clock, true, false);
        $noCapGw->getSnapshotStore()->save([
            'external_order_id' => 'paymenter_605_0',
            'paymos_invoice_id' => 'inv_ref_605',
            'platform_ref' => '605',
            'amount' => '20',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://checkout.paymos.io/inv_ref_605',
            'transaction_id' => '0xtx_605',
            'status' => 'paid',
            'renewal_attempt' => 0,
            'created_at' => $now,
        ]);
        $noCapResp = $noCapGw->refundSubmit(new Request(
            json_encode(['invoice_id' => '605', 'mode' => 'full', 'request_token' => RefundIntentStore::uuid()]),
            ['X-CSRF-TOKEN' => 'tok'],
            $admin,
            'tok'
        ));
        assertSame(409, $noCapResp['status'], 'Missing refund capability must reject submit.');
        assertSame('refund_reconnect_required', $noCapResp['body']['reason'], 'Must report refund_reconnect_required.');
        assertSame(0, count($noCapTransport->requests()), '0 HTTP requests may be sent when capability is absent.');
    }

    // ── 8. PdoDbDriver DDL guard across MySQL and PostgreSQL ──────────────────
    function testPdoDbDriverTransactionAndDialectSafety(): void
    {
        // Existing tables inside active tx -> 0 DDL statements
        $mysqlExisting = new RecordingPdo('mysql', true, true);
        (new PdoDbDriver($mysqlExisting))->ensureSchema();
        assertSame(0, count($mysqlExisting->execCalls), 'Existing schema inside active tx must emit zero DDL.');

        // Missing tables inside active tx -> throws RuntimeException (prevents MySQL implicit commit)
        $mysqlMissingInTx = new RecordingPdo('mysql', false, true);
        $threw = false;
        try {
            (new PdoDbDriver($mysqlMissingInTx))->ensureSchema();
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        assertTrue($threw, 'Missing tables inside active transaction must fail closed.');

        // Fresh MySQL and PostgreSQL installs create all 5 tables cleanly
        $mysqlFresh = new RecordingPdo('mysql', false, false);
        $mysqlDriver = new PdoDbDriver($mysqlFresh);
        $mysqlDriver->ensureSchema();
        assertSame(5, count($mysqlFresh->execCalls), 'MySQL fresh install must create all 5 tables.');
        assertTrue($mysqlDriver->acquireNamedLock('test-lock'), 'MySQL GET_LOCK must succeed.');

        $pgsqlFresh = new RecordingPdo('pgsql', false, false);
        $pgsqlDriver = new PdoDbDriver($pgsqlFresh);
        $pgsqlDriver->ensureSchema();
        assertTrue(count($pgsqlFresh->execCalls) >= 12, 'PostgreSQL fresh install must create all 5 tables + indexes.');
        assertTrue($pgsqlDriver->acquireNamedLock('test-lock'), 'PostgreSQL pg_try_advisory_lock must succeed.');
    }

    // ── 9. Six Native Locales, Docs, Composer & Zero-Leak audit ───────────────
    function testSixNativeLocalesAndPackageConformance(): void
    {
        $locales = Translation::supportedLocales();
        assertSame(['en', 'ru', 'de', 'es', 'tr', 'zh-Hans'], $locales, 'Must ship exact 6 canonical locales.');

        $enCatalog = Translation::catalog('en');
        $enKeys = array_keys($enCatalog);
        sort($enKeys);
        assertTrue(count($enKeys) >= 35, 'EN catalog must contain full Connect/Checkout/Refund key set.');

        $allowSameBrand = ['config_mode_sandbox', 'config_mode_live', 'connect_code'];

        foreach ($locales as $locale) {
            $catalog = Translation::catalog($locale);
            $keys = array_keys($catalog);
            sort($keys);
            assertSame($enKeys, $keys, 'Locale ' . $locale . ' must have 100% key parity with en.php.');

            foreach ($catalog as $key => $value) {
                assertTrue(is_string($value) && trim($value) !== '', "Locale {$locale} key {$key} must be non-empty.");
                if ($locale !== 'en' && !in_array($key, $allowSameBrand, true)) {
                    assertTrue(
                        $value !== $enCatalog[$key],
                        "Locale {$locale} key {$key} appears untranslated (identical to EN)."
                    );
                }
            }
        }

        // Russian tone check: no forbidden slang/bureaucratic register
        $ruJoined = implode("\n", Translation::catalog('ru'));
        assertTrue(!preg_match('/боево/ui', $ruJoined), 'RU locale must not use forbidden slang "боевой".');
        assertTrue(!preg_match('/\bданно(?:го|му|й|е)\b/ui', $ruJoined), 'RU locale must not use bureaucratic "данный".');

        // Locale alias resolution
        assertSame('zh-Hans', Translation::resolveLocale('zh_CN'), 'zh_CN must resolve to zh-Hans.');
        assertSame('ru', Translation::resolveLocale('ru_RU'), 'ru_RU must resolve to ru.');
        assertSame('de', Translation::resolveLocale('de-DE'), 'de-DE must resolve to de.');

        // Composer, README, CHANGELOG, LICENSE & zero-leak checks
        $root = dirname(__DIR__);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        assertSame('>=8.2', $composer['require']['php'] ?? null, 'composer.json must require PHP >=8.2.');
        assertSame('^1.11.0', $composer['require']['paymos/php-sdk'] ?? null, 'composer.json must require paymos/php-sdk ^1.11.0.');
        assertSame('https://github.com/Paymos-labs/paymenter', $composer['support']['source'] ?? null, 'composer.json source must point to Paymos-labs/paymenter.');

        $readme = (string) file_get_contents($root . '/README.md');
        assertTrue(str_contains($readme, '- PHP 8.2+'), 'README.md must declare "- PHP 8.2+".');
        assertTrue(str_contains($readme, 'https://github.com/Paymos-labs/paymenter'), 'README.md must link to Paymos-labs/paymenter.');
        assertTrue(!str_contains(strtolower($readme), 'instant settlement'), 'README.md must not claim "instant settlement".');
        assertTrue(preg_match('/github\.com\/(?!Paymos-labs\/)/i', $readme) !== 1, 'README.md must reference only Paymos-labs GitHub URLs.');

        $changelog = (string) file_get_contents($root . '/CHANGELOG.md');
        assertTrue(str_contains($changelog, '## [Unreleased]'), 'CHANGELOG.md must include ## [Unreleased].');

        $license = (string) file_get_contents($root . '/LICENSE');
        assertTrue(str_contains($license, 'MIT License'), 'LICENSE must be MIT.');

        // Legacy MVP files must be deleted
        assertTrue(!is_file($root . '/src/GatewayService.php'), 'Legacy GatewayService.php must be deleted.');
        assertTrue(!is_file($root . '/scripts/package.py'), 'Legacy scripts/package.py must be deleted.');
        assertTrue(!is_file($root . '/dist/Paymos-Paymenter-v1.0.0.zip'), 'Legacy dist ZIP must be deleted.');

        // Only official Paymos-labs GitHub URLs are permitted across shipped plugin files
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $path = $fileInfo->getPathname();
            if (str_contains($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $content = (string) file_get_contents($path);
            assertTrue(preg_match('/github\.com\/(?!Paymos-labs\/)/i', $content) !== 1, 'Non-Paymos-labs GitHub URL detected in ' . $path);
        }
    }

    $tests = [
        'metadata_boot_and_config_locality' => __NAMESPACE__ . '\\testMetadataBootAndConfigLocality',
        'credential_store_encryption_and_validation' => __NAMESPACE__ . '\\testCredentialStoreEncryptionAndValidation',
        'connect_flow_and_admin_authorization_403' => __NAMESPACE__ . '\\testConnectFlowAndAdminAuthorization403',
        'checkout_lifecycle_and_safe_api_problems' => __NAMESPACE__ . '\\testCheckoutLifecycleAndSafeApiProblems',
        'webhook_security_guards_and_dedup' => __NAMESPACE__ . '\\testWebhookSecurityGuardsAndDedup',
        'reconciler_24h_sweep_and_600s_throttle' => __NAMESPACE__ . '\\testReconciler24hSweepAnd600sThrottle',
        'refunds_async_safe_projection_discovery_and_rejects' => __NAMESPACE__ . '\\testRefundsAsyncSafeProjectionDiscoveryAndRejects',
        'pdo_db_driver_transaction_and_dialect_safety' => __NAMESPACE__ . '\\testPdoDbDriverTransactionAndDialectSafety',
        'six_native_locales_and_package_conformance' => __NAMESPACE__ . '\\testSixNativeLocalesAndPackageConformance',
    ];

    $passed = 0;
    foreach ($tests as $name => $fn) {
        try {
            $fn();
            $passed++;
            fwrite(STDOUT, "[PASS] {$name}\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "[FAIL] {$name}: {$e->getMessage()} ({$e->getFile()}:{$e->getLine()})\n");
            exit(1);
        }
    }

    fwrite(STDOUT, "\nAll {$passed} Paymenter Paymos test suites passed.\n");
}
