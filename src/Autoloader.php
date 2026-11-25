<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

final class Autoloader
{
    private const INTERNAL_PREFIX = 'Paymenter\\Extensions\\Gateways\\Paymos\\Internal\\';
    private const SDK_PREFIX = 'Paymos\\';

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        spl_autoload_register(static function (string $class): void {
            if (strncmp($class, self::INTERNAL_PREFIX, strlen(self::INTERNAL_PREFIX)) === 0) {
                $relative = substr($class, strlen(self::INTERNAL_PREFIX));
                $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($path)) {
                    require_once $path;
                }
                return;
            }

            if (strncmp($class, self::SDK_PREFIX, strlen(self::SDK_PREFIX)) !== 0) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, strlen(self::SDK_PREFIX))) . '.php';

            // 1. Vendored SDK inside release ZIP (extensions/Gateways/Paymos/vendor/paymos/php-sdk/src/)
            $vendored = dirname(__DIR__) . '/vendor/paymos/php-sdk/src/' . $relative;
            if (is_file($vendored)) {
                require_once $vendored;
                return;
            }

            // 2. Explicit PAYMOS_SDK_SRC override used by isolated release preflight
            $envSdkSrc = getenv('PAYMOS_SDK_SRC');
            if (is_string($envSdkSrc) && trim($envSdkSrc) !== '') {
                $envBase = rtrim(trim($envSdkSrc), '/\\');
                foreach ([$envBase . '/' . $relative, $envBase . '/src/' . $relative] as $envPath) {
                    if (is_file($envPath)) {
                        require_once $envPath;
                        return;
                    }
                }
            }

            // 3. Monorepo fallback (plugins/php-sdk/src/) for local development and tests
            $monorepo = dirname(__DIR__, 2) . '/php-sdk/src/' . $relative;
            if (is_file($monorepo)) {
                require_once $monorepo;
            }
        });
    }
}
