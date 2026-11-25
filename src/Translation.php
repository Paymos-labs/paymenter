<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

final class Translation
{
    /** @var list<string> */
    private const SUPPORTED_LOCALES = ['en', 'ru', 'de', 'es', 'tr', 'zh-Hans'];

    /** @var array<string, string> */
    private const ALIASES = [
        'en' => 'en',
        'en-us' => 'en',
        'en-gb' => 'en',
        'english' => 'en',
        'ru' => 'ru',
        'ru-ru' => 'ru',
        'russian' => 'ru',
        'de' => 'de',
        'de-de' => 'de',
        'de-at' => 'de',
        'de-ch' => 'de',
        'german' => 'de',
        'deutsch' => 'de',
        'es' => 'es',
        'es-es' => 'es',
        'es-mx' => 'es',
        'es-419' => 'es',
        'spanish' => 'es',
        'espanol' => 'es',
        'tr' => 'tr',
        'tr-tr' => 'tr',
        'turkish' => 'tr',
        'zh' => 'zh-Hans',
        'zh-cn' => 'zh-Hans',
        'zh-hans' => 'zh-Hans',
        'zh-hans-cn' => 'zh-Hans',
        'zh-sg' => 'zh-Hans',
        'chinese' => 'zh-Hans',
    ];

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /**
     * @return list<string>
     */
    public static function supportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
    }

    public static function resolveLocale(?string $locale = null): string
    {
        $candidates = [];
        if ($locale !== null && trim($locale) !== '') {
            $candidates[] = $locale;
        }

        if (function_exists('app')) {
            try {
                $app = \app();
                if (is_object($app) && method_exists($app, 'getLocale')) {
                    $appLocale = $app->getLocale();
                    if (is_string($appLocale) && trim($appLocale) !== '') {
                        $candidates[] = $appLocale;
                    }
                }
            } catch (\Throwable $e) {
                // Fall back to config/default outside a booted Laravel app.
            }
        }

        if (function_exists('config')) {
            try {
                $cfgLocale = \config('app.locale');
                if (is_string($cfgLocale) && trim($cfgLocale) !== '') {
                    $candidates[] = $cfgLocale;
                }
            } catch (\Throwable $e) {
                // Ignore outside booted Laravel config container.
            }
        }

        foreach ($candidates as $candidate) {
            $normalized = strtolower(str_replace('_', '-', trim($candidate)));
            if ($normalized === '') {
                continue;
            }
            if (isset(self::ALIASES[$normalized])) {
                return self::ALIASES[$normalized];
            }
            $prefix = explode('-', $normalized, 2)[0];
            if (isset(self::ALIASES[$prefix])) {
                return self::ALIASES[$prefix];
            }
            return 'en';
        }

        return 'en';
    }

    public static function isEnglish(?string $locale = null): bool
    {
        return self::resolveLocale($locale) === 'en';
    }

    public static function text(string $key, ?string $locale = null): string
    {
        $resolved = self::resolveLocale($locale);
        $catalog = self::catalog($resolved);
        if (isset($catalog[$key]) && is_scalar($catalog[$key])) {
            return (string) $catalog[$key];
        }

        $fallback = self::catalog('en');

        return isset($fallback[$key]) && is_scalar($fallback[$key]) ? (string) $fallback[$key] : $key;
    }

    /**
     * @return array<string, string>
     */
    public static function all(?string $locale = null): array
    {
        $resolved = self::resolveLocale($locale);
        $fallback = self::catalog('en');
        if ($resolved === 'en') {
            return $fallback;
        }

        return array_replace($fallback, self::catalog($resolved));
    }

    /**
     * @return array<string, string>
     */
    public static function catalog(string $locale): array
    {
        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        $path = dirname(__DIR__) . '/lang/' . $locale . '.php';
        $loaded = is_file($path) ? require $path : [];
        self::$cache[$locale] = is_array($loaded) ? $loaded : [];

        return self::$cache[$locale];
    }
}
