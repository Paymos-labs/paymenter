<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use Paymos\Connect\DeviceConnectClient;
use Paymos\Http\TransportInterface;

final class ConnectHandler
{
    public const PLUGIN_SLUG = 'paymenter';
    public const CONNECT_BASE_URL = 'https://app.paymos.io';

    private CredentialStore $credentialStore;
    private ?TransportInterface $transport;

    /** @var callable */
    private $authorize;

    private string $connectBaseUrl;

    public function __construct(
        CredentialStore $credentialStore,
        ?TransportInterface $transport = null,
        ?callable $authorize = null,
        string $connectBaseUrl = self::CONNECT_BASE_URL
    ) {
        $this->credentialStore = $credentialStore;
        $this->transport = $transport;
        $this->authorize = $authorize ?: static function (): void {
            throw new \RuntimeException('paymos_connect_access_denied');
        };
        $this->connectBaseUrl = $connectBaseUrl;
    }

    /**
     * Start RFC 8628 One-Click Connect Paymos flow.
     *
     * @return array{verification_url: string, user_code: string, interval: int}
     */
    public function start(string $sourceUrl, string $returnUrl = ''): array
    {
        ($this->authorize)();

        $normalizedSource = self::assertHttpsSourceUrl($sourceUrl);
        $normalizedReturn = trim($returnUrl);
        if ($normalizedReturn !== '' && !self::isHttpsUrl($normalizedReturn)) {
            $normalizedReturn = '';
        }

        $client = new DeviceConnectClient($this->connectBaseUrl, $this->transport);
        $state = $client->start(self::PLUGIN_SLUG, $normalizedSource, $normalizedReturn);

        $this->credentialStore->saveState($state);

        return [
            'verification_url' => (string) $state['verification_url'],
            'user_code' => (string) $state['user_code'],
            'interval' => (int) $state['interval'],
        ];
    }

    /**
     * Poll active device authorization state and atomically store Sandbox + Live credentials on approval.
     *
     * @return array{status: string}
     */
    public function poll(string $sourceUrl): array
    {
        ($this->authorize)();

        $normalizedSource = self::assertHttpsSourceUrl($sourceUrl);
        $state = $this->credentialStore->loadState();
        if (!isset($state['device_code']) || !is_string($state['device_code']) || trim($state['device_code']) === '') {
            throw new \RuntimeException('No active Paymos connection request.');
        }

        $client = new DeviceConnectClient($this->connectBaseUrl, $this->transport);
        $result = $client->poll($state['device_code']);
        $status = isset($result['status']) && is_string($result['status']) ? $result['status'] : '';

        if ($status === 'connected') {
            $plugin = isset($result['plugin']) && is_string($result['plugin']) ? trim($result['plugin']) : '';
            $boundSourceUrl = isset($result['source_url']) && is_string($result['source_url'])
                ? rtrim(trim($result['source_url']), '/')
                : '';

            if ($plugin !== self::PLUGIN_SLUG || $boundSourceUrl !== $normalizedSource) {
                $this->credentialStore->clearState();
                throw new \RuntimeException('Paymos connection response does not match this Paymenter installation.');
            }

            if (!isset($result['credentials']) || !is_array($result['credentials'])) {
                $this->credentialStore->clearState();
                throw new \RuntimeException('Paymos connection response did not include credentials.');
            }

            $this->credentialStore->saveCredentials($result['credentials']);
            $this->credentialStore->clearState();

            return ['status' => 'connected'];
        }

        if (in_array($status, ['authorization_pending', 'slow_down'], true)) {
            return ['status' => $status];
        }

        $this->credentialStore->clearState();
        throw new \RuntimeException('Paymos connection was denied or expired.');
    }

    private static function assertHttpsSourceUrl(string $sourceUrl): string
    {
        $normalized = rtrim(trim($sourceUrl), '/');
        if (!self::isHttpsUrl($normalized)) {
            throw new \InvalidArgumentException('Paymenter URL must use HTTPS.');
        }

        $parts = parse_url($normalized);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Paymenter URL must be a clean HTTPS origin.');
        }

        return $normalized;
    }

    private static function isHttpsUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && strtolower((string) $parts['scheme']) === 'https'
            && trim((string) $parts['host']) !== '';
    }
}
