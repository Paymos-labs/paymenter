@php
    $panel = is_array($panel ?? null) ? $panel : [];
    $labels = is_array($panel['labels'] ?? null) ? $panel['labels'] : [];
    $intents = is_array($panel['intents'] ?? null) ? $panel['intents'] : [];
    $external = is_array($panel['external'] ?? null) ? $panel['external'] : [];
    $availableModes = is_array($panel['available_modes'] ?? null) ? $panel['available_modes'] : [];
    $tokens = is_array($panel['tokens'] ?? null) ? $panel['tokens'] : [];
    $invoiceId = (string) ($panel['invoice_id'] ?? '');
    $reconnectRequired = !empty($panel['reconnect_required']);
    $submitUrl = $submitUrl ?? '/extensions/gateways/paymos/admin/refunds/submit';
    $checkUrl = $checkUrl ?? '/extensions/gateways/paymos/admin/refunds/check';
    $csrfToken = $csrfToken ?? (function_exists('csrf_token') ? (string) csrf_token() : '');
    $jsConfig = [
        'invoiceId' => $invoiceId,
        'submitUrl' => $submitUrl,
        'checkUrl' => $checkUrl,
        'csrfToken' => $csrfToken,
        'fallbackError' => $labels['refund_failed'] ?? 'Refund request failed.',
    ];
@endphp
<div id="paymos-refunds" class="mt-6 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5 space-y-4">
    <div class="flex items-center justify-between">
        <h3 class="text-base font-semibold">{{ $labels['refund_panel_title'] ?? 'Paymos refunds' }}</h3>
    </div>

    @if ($reconnectRequired)
        <div class="rounded-md bg-amber-50 dark:bg-amber-900/20 p-3 text-sm text-amber-800 dark:text-amber-200">
            {{ $labels['refund_reconnect_hint'] ?? 'Reconnect Paymos in Gateways settings to enable refunds.' }}
        </div>
    @endif

    @if (count($intents) === 0 && count($external) === 0)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $labels['refund_empty'] ?? 'No Paymos refunds have been requested for this invoice yet.' }}
        </p>
    @endif

    @foreach ($intents as $intent)
        <div class="rounded-lg border border-gray-200 dark:border-gray-800 p-3 text-sm space-y-1">
            <div><strong>{{ $labels['refund_label_operation'] ?? 'Operation' }}:</strong> <code>{{ $intent['operation_id'] ?? '' }}</code></div>
            <div><strong>{{ $labels['refund_label_type'] ?? 'Type' }}:</strong> <code>{{ $intent['mode'] ?? '' }}</code></div>
            <div><strong>{{ $labels['refund_label_cash_status'] ?? 'Cash status' }}:</strong> <code>{{ $intent['cash_state'] ?? '' }}</code></div>
            <div><strong>{{ $labels['refund_label_paymenter_record'] ?? 'Paymenter record' }}:</strong> <code>{{ $intent['projection_state'] ?? '' }}</code></div>
            @if (!empty($intent['amount_display']))
                <div><strong>{{ $labels['refund_label_amount'] ?? 'Amount' }}:</strong> <code>{{ $intent['amount_display'] }}</code></div>
            @endif
            @if (!empty($intent['review_reason']))
                <div><strong>{{ $labels['refund_label_review_reason'] ?? 'Review reason' }}:</strong> <code>{{ $intent['review_reason'] }}</code></div>
            @endif
            @if (!empty($intent['status_copy']))
                <p class="text-xs text-gray-600 dark:text-gray-300 pt-1">{{ $intent['status_copy'] }}</p>
            @endif
            @if (!in_array($intent['cash_state'] ?? '', ['completed', 'failed', 'cancelled'], true) || (($intent['cash_state'] ?? '') === 'completed' && ($intent['projection_state'] ?? '') !== 'applied'))
                <div class="pt-2">
                    <button
                        type="button"
                        class="paymos-refund-check inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 px-2.5 py-1 text-xs font-medium hover:bg-gray-50 dark:hover:bg-gray-800"
                        data-operation="{{ $intent['operation_id'] ?? '' }}"
                    >
                        {{ $labels['refund_button_check'] ?? 'Check status' }}
                    </button>
                </div>
            @endif
        </div>
    @endforeach

    @foreach ($external as $ext)
        <div class="rounded-lg border border-gray-200 dark:border-gray-800 p-3 text-sm space-y-1">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ $labels['refund_external_badge'] ?? 'Created in Paymos Dashboard' }}
            </div>
            <div><strong>{{ $labels['refund_label_operation'] ?? 'Operation' }}:</strong> <code>{{ $ext['withdrawal_id'] ?? '' }}</code></div>
            <div><strong>{{ $labels['refund_label_type'] ?? 'Type' }}:</strong> <code>{{ $ext['mode'] ?? '' }}</code></div>
            <div><strong>{{ $labels['refund_label_cash_status'] ?? 'Cash status' }}:</strong> <code>{{ $ext['status'] ?? '' }}</code></div>
            @if (!empty($ext['amount_display']))
                <div><strong>{{ $labels['refund_label_amount'] ?? 'Amount' }}:</strong> <code>{{ $ext['amount_display'] }}</code></div>
            @endif
            @if (!empty($ext['review_reason']))
                <div><strong>{{ $labels['refund_label_review_reason'] ?? 'Review reason' }}:</strong> <code>{{ $ext['review_reason'] }}</code></div>
            @endif
        </div>
    @endforeach

    @if (count($availableModes) > 0)
        <div class="flex flex-wrap gap-2 pt-1">
            @foreach ($availableModes as $mode)
                @if (!empty($tokens[$mode]))
                    <button
                        type="button"
                        class="paymos-refund-submit inline-flex items-center rounded-md bg-danger-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-danger-500 disabled:opacity-50"
                        data-mode="{{ $mode }}"
                        data-token="{{ $tokens[$mode] }}"
                    >
                        {{ $mode === 'full' ? ($labels['refund_button_full'] ?? 'Refund in full') : ($labels['refund_button_overpayment'] ?? 'Refund overpayment') }}
                    </button>
                @endif
            @endforeach
        </div>
    @endif

    <div class="paymos-refund-error hidden rounded-md bg-red-50 dark:bg-red-900/20 p-3 text-sm text-red-700 dark:text-red-200" role="alert"></div>
</div>
<script>
(function () {
    var root = document.getElementById('paymos-refunds');
    if (!root || root.dataset.paymosBound === '1') {
        return;
    }
    root.dataset.paymosBound = '1';

    var cfg = @json($jsConfig);
    var errorBox = root.querySelector('.paymos-refund-error');

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) {
            return meta.getAttribute('content');
        }
        return cfg.csrfToken || '';
    }

    function showError(msg) {
        if (!errorBox) {
            return;
        }
        errorBox.textContent = msg;
        errorBox.classList.remove('hidden');
    }

    function clearError() {
        if (!errorBox) {
            return;
        }
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
    }

    function postJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken()
            },
            body: JSON.stringify(payload)
        }).then(function (resp) {
            return resp.json().then(function (data) {
                if (!resp.ok) {
                    var msg = (data && data.message) ? String(data.message) : cfg.fallbackError;
                    if (data && data.reason) {
                        msg += ' (' + String(data.reason) + ')';
                    }
                    throw new Error(msg);
                }
                return data;
            });
        });
    }

    root.querySelectorAll('.paymos-refund-submit').forEach(function (button) {
        button.addEventListener('click', function () {
            var mode = button.getAttribute('data-mode');
            var token = button.getAttribute('data-token');
            if (!mode || !token) {
                return;
            }
            button.disabled = true;
            clearError();
            postJson(cfg.submitUrl, {
                invoice_id: cfg.invoiceId,
                mode: mode,
                request_token: token
            }).then(function () {
                window.location.reload();
            }).catch(function (err) {
                showError(err && err.message ? err.message : cfg.fallbackError);
                button.disabled = false;
            });
        });
    });

    root.querySelectorAll('.paymos-refund-check').forEach(function (button) {
        button.addEventListener('click', function () {
            var operationId = button.getAttribute('data-operation');
            if (!operationId) {
                return;
            }
            button.disabled = true;
            clearError();
            postJson(cfg.checkUrl, {
                operation_id: operationId
            }).then(function () {
                window.location.reload();
            }).catch(function (err) {
                showError(err && err.message ? err.message : cfg.fallbackError);
                button.disabled = false;
            });
        });
    });
})();
</script>
