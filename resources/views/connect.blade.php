@php
    $connected = !empty($connected);
    $statusText = $connected
        ? ($labels['config_connection_connected'] ?? 'Connected')
        : ($labels['config_connection_disconnected'] ?? 'Not connected');
    $buttonLabel = $connected
        ? ($labels['connect_reconnect_button'] ?? $labels['connect_button'] ?? 'Reconnect Paymos')
        : ($labels['connect_button'] ?? 'Connect Paymos');
    $webhookUrl = $webhookUrl ?? '/extensions/gateways/paymos/webhook';
    $startUrl = $startUrl ?? '/extensions/gateways/paymos/admin/connect/start';
    $pollUrl = $pollUrl ?? '/extensions/gateways/paymos/admin/connect/poll';
    $csrfToken = $csrfToken ?? (function_exists('csrf_token') ? (string) csrf_token() : '');
    $jsConfig = [
        'startUrl' => $startUrl,
        'pollUrl' => $pollUrl,
        'csrfToken' => $csrfToken,
        'labels' => [
            'starting' => $labels['connect_starting'] ?? 'Starting…',
            'waiting' => $labels['connect_waiting'] ?? 'Waiting for approval.',
            'open' => $labels['connect_open'] ?? 'Open the approval page',
            'blocked' => $labels['connect_blocked'] ?? 'Your browser blocked the approval tab.',
            'code' => $labels['connect_code'] ?? 'Code:',
            'failed' => $labels['connect_failed'] ?? 'Could not connect Paymos. Please try again.',
            'httpsRequired' => $labels['connect_https_required'] ?? 'Paymenter panel URL must start with https://.',
        ],
    ];
@endphp
<div id="paymos-connect-panel" class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-sm font-medium">{{ $statusText }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ $labels['connect_callback_label'] ?? 'Webhook endpoint:' }}
                <code>{{ $webhookUrl }}</code>
            </p>
        </div>
        <button
            type="button"
            id="paymos-connect-button"
            class="inline-flex items-center rounded-md bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 disabled:opacity-50"
        >
            {{ $buttonLabel }}
        </button>
    </div>
    <div id="paymos-connect-status" class="text-sm text-gray-600 dark:text-gray-300" aria-live="polite"></div>
</div>
<script>
(function () {
    var cfg = @json($jsConfig);
    var btn = document.getElementById('paymos-connect-button');
    var statusEl = document.getElementById('paymos-connect-status');
    if (!btn || !statusEl || btn.dataset.paymosBound === '1') {
        return;
    }
    btn.dataset.paymosBound = '1';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) {
            return meta.getAttribute('content');
        }
        return cfg.csrfToken || '';
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
            body: JSON.stringify(payload || {})
        }).then(function (resp) {
            return resp.json().then(function (data) {
                if (!resp.ok) {
                    throw new Error((data && data.error) ? String(data.error) : cfg.labels.failed);
                }
                return data;
            });
        });
    }

    var manual = false;
    function say(text) {
        if (!manual) {
            statusEl.textContent = text;
        }
    }

    btn.addEventListener('click', function () {
        if (window.location.protocol !== 'https:') {
            statusEl.textContent = cfg.labels.httpsRequired;
            return;
        }
        btn.disabled = true;
        manual = false;
        statusEl.textContent = cfg.labels.starting;

        // Open tab synchronously on click so browsers do not block it on slow networks.
        var tab = window.open('', '_blank');
        if (tab) {
            try { tab.opener = null; } catch (e) {}
        }

        postJson(cfg.startUrl, { return_url: window.location.href }).then(function (data) {
            if (!data || !data.verification_url) {
                throw new Error(cfg.labels.failed);
            }
            if (tab && !tab.closed) {
                tab.location = data.verification_url;
                statusEl.textContent = cfg.labels.waiting + ' ' + cfg.labels.code + ' ' + String(data.user_code || '');
            } else {
                manual = true;
                statusEl.textContent = '';
                var link = document.createElement('a');
                link.href = data.verification_url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = cfg.labels.open;
                statusEl.appendChild(document.createTextNode(cfg.labels.blocked + ' '));
                statusEl.appendChild(link);
                statusEl.appendChild(document.createTextNode(' ' + cfg.labels.code + ' ' + String(data.user_code || '')));
            }

            var intervalMs = Math.max(1, Number(data.interval || 5)) * 1000;
            function poll() {
                postJson(cfg.pollUrl, {}).then(function (res) {
                    if (res && res.status === 'connected') {
                        window.location.reload();
                        return;
                    }
                    var delay = (res && res.status === 'slow_down') ? intervalMs + 5000 : intervalMs;
                    window.setTimeout(poll, delay);
                }).catch(function () {
                    say(cfg.labels.failed);
                    btn.disabled = false;
                });
            }
            window.setTimeout(poll, intervalMs);
        }).catch(function () {
            if (tab && !tab.closed) {
                tab.close();
            }
            statusEl.textContent = cfg.labels.failed;
            btn.disabled = false;
        });
    });
})();
</script>
