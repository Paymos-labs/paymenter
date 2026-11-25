<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use Paymos\Plugin\AesGcmEnvelope;
use Paymos\Plugin\CredentialSet;

final class CredentialStore
{
    public const TABLE = 'paymos_settings';

    private const CREDENTIALS_KEY = 'credentials_v1';
    private const STATE_KEY = 'connect_state_v1';
    private const CREDENTIALS_AAD = 'paymos-paymenter-credentials-v1';
    private const STATE_AAD = 'paymos-paymenter-connect-state-v1';

    private DbDriverInterface $db;

    /** @var callable|null */
    private $keyProvider;

    /** @var callable */
    private $clock;

    public function __construct(
        DbDriverInterface $db,
        ?callable $keyProvider = null,
        ?callable $clock = null
    ) {
        $this->db = $db;
        $this->keyProvider = $keyProvider;
        $this->clock = $clock ?: static fn (): int => time();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loadCredentials(): array
    {
        $payload = $this->loadEnvelope(self::CREDENTIALS_KEY, self::CREDENTIALS_AAD);
        if ($payload === []) {
            return [];
        }

        if (
            !isset($payload['schema'], $payload['environments'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['environments'])
        ) {
            throw new \RuntimeException('Stored Paymos credentials have an invalid schema.');
        }

        return CredentialSet::normalize($payload['environments']);
    }

    /**
     * @param array<string, mixed> $environments
     */
    public function saveCredentials(array $environments): void
    {
        $normalized = CredentialSet::normalize($environments);
        if ($normalized === []) {
            throw new \InvalidArgumentException('Paymos credentials must include at least one valid environment.');
        }

        $this->saveEnvelope(self::CREDENTIALS_KEY, self::CREDENTIALS_AAD, [
            'schema' => 1,
            'environments' => $normalized,
        ]);
    }

    /**
     * @return array{project_id: string, capabilities: list<string>, refund_contract_version: ?int, refunds_enabled: bool}
     */
    public function refundConfig(string $environment): array
    {
        if (!in_array($environment, ['sandbox', 'live'], true)) {
            throw new \InvalidArgumentException('refund_environment_invalid');
        }

        $credentials = $this->loadCredentials();
        $values = isset($credentials[$environment]) && is_array($credentials[$environment])
            ? $credentials[$environment]
            : [];

        return [
            'project_id' => isset($values['project_id']) && is_string($values['project_id']) ? $values['project_id'] : '',
            'capabilities' => CredentialSet::hasCapability($values, 'invoice_refunds_v1')
                ? ['invoice_refunds_v1']
                : [],
            'refund_contract_version' => isset($values['refund_contract_version']) && is_int($values['refund_contract_version'])
                ? $values['refund_contract_version']
                : null,
            'refunds_enabled' => CredentialSet::refundsEnabled($values),
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function saveState(array $state): void
    {
        $expiresIn = isset($state['expires_in']) && is_numeric($state['expires_in'])
            ? max(1, (int) $state['expires_in'])
            : 600;

        $this->saveEnvelope(self::STATE_KEY, self::STATE_AAD, [
            'schema' => 1,
            'expires_at' => $this->now() + $expiresIn,
            'state' => $state,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function loadState(): array
    {
        $payload = $this->loadEnvelope(self::STATE_KEY, self::STATE_AAD);
        if (
            !isset($payload['schema'], $payload['expires_at'], $payload['state'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['state'])
            || $this->now() >= (int) $payload['expires_at']
        ) {
            $this->clearState();
            return [];
        }

        return $payload['state'];
    }

    public function clearState(): void
    {
        $this->deleteSetting(self::STATE_KEY);
    }

    public function getSetting(string $key): ?string
    {
        $this->db->ensureSchema();
        $row = $this->db->selectOne(self::TABLE, ['setting_key' => $key]);
        if ($row === null || !isset($row['setting_value']) || !is_scalar($row['setting_value'])) {
            return null;
        }

        return (string) $row['setting_value'];
    }

    public function setSetting(string $key, string $value): void
    {
        $this->db->ensureSchema();
        $this->db->upsert(self::TABLE, [
            'setting_key' => $key,
            'setting_value' => $value,
            'updated_at' => $this->now(),
        ], 'setting_key');
    }

    public function insertSettingUnique(string $key, string $value): bool
    {
        $this->db->ensureSchema();

        return $this->db->insertUnique(self::TABLE, [
            'setting_key' => $key,
            'setting_value' => $value,
            'updated_at' => $this->now(),
        ], 'setting_key');
    }

    public function deleteSetting(string $key): void
    {
        $this->db->ensureSchema();
        $this->db->deleteWhere(self::TABLE, ['setting_key' => $key]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadEnvelope(string $settingKey, string $aad): array
    {
        $encoded = $this->getSetting($settingKey);
        if ($encoded === null || $encoded === '') {
            return [];
        }

        return AesGcmEnvelope::open($encoded, $this->keyMaterial(), $aad);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function saveEnvelope(string $settingKey, string $aad, array $payload): void
    {
        $encoded = AesGcmEnvelope::seal($payload, $this->keyMaterial(), $aad);
        $this->setSetting($settingKey, $encoded);
    }

    private function keyMaterial(): string
    {
        $raw = null;
        if ($this->keyProvider !== null) {
            $raw = ($this->keyProvider)();
        } elseif (function_exists('config')) {
            try {
                $raw = \config('app.key');
            } catch (\Throwable $e) {
                $raw = null;
            }
        }

        if (!is_string($raw)) {
            throw new \RuntimeException('Paymenter app.key is not configured.');
        }

        $material = trim($raw);
        if (str_starts_with($material, 'base64:')) {
            $decoded = base64_decode(substr($material, 7), true);
            if (!is_string($decoded) || $decoded === '') {
                throw new \RuntimeException('Paymenter app.key is invalid.');
            }
            return $decoded;
        }

        if ($material === '') {
            throw new \RuntimeException('Paymenter app.key is not configured.');
        }

        return $material;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
