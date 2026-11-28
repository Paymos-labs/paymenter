<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use Paymos\ClientConfig;
use Paymos\Plugin\CredentialSet;

final class Config
{
    public const DEFAULT_BASE_URL = CredentialSet::DEFAULT_BASE_URL;
    public const ENV_LIVE = 'live';
    public const ENV_SANDBOX = 'sandbox';

    private string $mode;

    /** @var array<string, array<string, mixed>> */
    private array $environments;

    /**
     * @param array<string, array<string, mixed>> $environments
     */
    private function __construct(string $mode, array $environments)
    {
        $this->mode = $mode;
        $this->environments = $environments;
    }

    /**
     * Build validated config for active checkout/API calls.
     * Secrets come exclusively from the Connect-provisioned CredentialStore envelope.
     *
     * @param array<string, mixed> $settings    Non-secret settings read directly inside Paymos::config(): mode.
     * @param array<string, mixed> $credentials Environments loaded from CredentialStore::loadCredentials().
     */
    public static function fromSettings(array $settings, array $credentials): self
    {
        $mode = self::resolveMode($settings);
        $normalized = CredentialSet::normalize($credentials);

        $config = new self($mode, $normalized);
        $config->assertEnvironmentConfigured($mode);

        $secrets = $config->webhookSecrets();
        if ($secrets === [] || !isset($secrets[$mode])) {
            throw new \InvalidArgumentException('Paymos ' . $mode . ' webhook secret is required for the selected mode.');
        }

        return $config;
    }

    /**
     * Build validated config for multi-environment webhook handling and background reconciliation.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $credentials
     */
    public static function forWebhook(array $settings, array $credentials): self
    {
        $mode = self::resolveMode($settings);
        $normalized = CredentialSet::normalize($credentials);

        $config = new self($mode, $normalized);
        if ($config->webhookSecrets() === []) {
            throw new \InvalidArgumentException('Paymos is not connected: at least one environment credential set is required.');
        }

        return $config;
    }

    public function environment(): string
    {
        return $this->mode;
    }

    public function isSandbox(): bool
    {
        return $this->mode === self::ENV_SANDBOX;
    }


    public function hasEnvironment(string $environment): bool
    {
        $environment = self::normalizeEnvironment($environment);

        return isset($this->environments[$environment]);
    }

    public function clientConfig(?string $environment = null, int $timeoutSeconds = 30): ClientConfig
    {
        $env = $environment === null ? $this->mode : self::normalizeEnvironment($environment);
        $this->assertEnvironmentConfigured($env);

        return new ClientConfig(
            $this->apiKey($env),
            $this->apiSecret($env),
            $this->baseUrl($env),
            $timeoutSeconds
        );
    }

    public function projectId(?string $environment = null): string
    {
        return $this->environmentField($environment, 'project_id');
    }

    public function apiKey(?string $environment = null): string
    {
        return $this->environmentField($environment, 'api_key');
    }

    public function apiSecret(?string $environment = null): string
    {
        return $this->environmentField($environment, 'api_secret');
    }

    public function webhookSecret(?string $environment = null): string
    {
        return $this->environmentField($environment, 'webhook_secret');
    }

    public function baseUrl(?string $environment = null): string
    {
        $value = $this->environmentField($environment, 'base_url');

        return $value !== '' ? rtrim($value, '/') : self::DEFAULT_BASE_URL;
    }

    /**
     * @return array<string, string>
     */
    public function webhookSecrets(): array
    {
        $secrets = [];
        foreach ([self::ENV_SANDBOX, self::ENV_LIVE] as $env) {
            if (isset($this->environments[$env]['webhook_secret']) && is_string($this->environments[$env]['webhook_secret'])) {
                $secret = trim($this->environments[$env]['webhook_secret']);
                if ($secret !== '') {
                    $secrets[$env] = $secret;
                }
            }
        }

        return $secrets;
    }

    /**
     * @return list<string>
     */
    public function configuredEnvironments(): array
    {
        return array_values(array_keys($this->environments));
    }

    public function sanitizeMessage(string $message): string
    {
        $secrets = [];
        foreach ($this->environments as $env) {
            foreach (['api_secret', 'webhook_secret'] as $field) {
                if (isset($env[$field]) && is_string($env[$field]) && $env[$field] !== '') {
                    $secrets[] = $env[$field];
                }
            }
        }

        return self::redactSecrets($message, $secrets);
    }

    /**
     * @param list<string> $secrets
     */
    public static function redactSecrets(string $message, array $secrets = []): string
    {
        $sanitized = $message;
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $sanitized = str_replace($secret, '[REDACTED]', $sanitized);
            }
        }

        $replacedAuth = preg_replace('/\bHMAC-SHA256\s+[^\s,;]+/i', 'HMAC-SHA256 [REDACTED]', $sanitized);
        if (is_string($replacedAuth)) {
            $sanitized = $replacedAuth;
        }

        $replacedKeys = preg_replace('/\b(?:(?:pk|sk|rk)_(?:live|test)|whsec)_[A-Za-z0-9_-]+\b/', '[REDACTED]', $sanitized);

        return is_string($replacedKeys) ? $replacedKeys : $sanitized;
    }

    /**
     * Prevent accidental secret exposure via var_dump() or debug traces.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $masked = [];
        foreach ($this->environments as $env => $values) {
            $masked[$env] = [
                'project_id' => $values['project_id'] ?? '',
                'api_key' => $values['api_key'] ?? '',
                'api_secret' => '[REDACTED]',
                'webhook_secret' => '[REDACTED]',
                'base_url' => $values['base_url'] ?? self::DEFAULT_BASE_URL,
                'capabilities' => $values['capabilities'] ?? [],
                'refund_contract_version' => $values['refund_contract_version'] ?? null,
                'refunds_enabled' => $values['refunds_enabled'] ?? false,
            ];
        }

        return [
            'mode' => $this->mode,
            'environments' => $masked,
        ];
    }

    private function assertEnvironmentConfigured(string $environment): void
    {
        $env = self::normalizeEnvironment($environment);
        if (!isset($this->environments[$env])) {
            throw new \InvalidArgumentException('Paymos ' . $env . ' credentials are not connected.');
        }

        foreach (['api_key', 'api_secret', 'project_id', 'webhook_secret'] as $field) {
            if ($this->environmentField($env, $field) === '') {
                throw new \InvalidArgumentException('Paymos ' . $env . ' configuration is missing ' . $field . '.');
            }
        }
    }

    private function environmentField(?string $environment, string $field): string
    {
        $env = $environment === null ? $this->mode : self::normalizeEnvironment($environment);
        if (!isset($this->environments[$env][$field]) || !is_scalar($this->environments[$env][$field])) {
            return '';
        }

        return trim((string) $this->environments[$env][$field]);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function resolveMode(array $settings): string
    {
        $raw = isset($settings['mode']) && is_scalar($settings['mode'])
            ? strtolower(trim((string) $settings['mode']))
            : '';

        if ($raw === '') {
            return self::ENV_SANDBOX;
        }

        return self::normalizeEnvironment($raw);
    }


    private static function normalizeEnvironment(string $environment): string
    {
        $normalized = strtolower(trim($environment));
        if (!in_array($normalized, [self::ENV_SANDBOX, self::ENV_LIVE], true)) {
            throw new \InvalidArgumentException('Paymos mode must be "sandbox" or "live".');
        }

        return $normalized;
    }
}
