<?php

declare(strict_types=1);

namespace App\Support;

final class DeploymentNetworkConfiguration
{
    /** @return list<string> */
    public static function trustedHostPatterns(
        string $appUrl,
        string $configuredHosts,
        string $railwayServiceId,
        string $railwayPublicDomain,
    ): array {
        $hosts = [
            self::normalizeHost($appUrl),
            ...array_map(
                static fn (string $host): ?string => self::normalizeHost($host),
                explode(',', $configuredHosts),
            ),
        ];

        $normalizedRailwayDomain = self::normalizeHost($railwayPublicDomain);
        if (self::isRailway($railwayServiceId, $railwayPublicDomain)) {
            $hosts[] = 'healthcheck.railway.app';
            $hosts[] = $normalizedRailwayDomain;
        }

        return array_values(array_map(
            static fn (string $host): string => '^'.preg_quote($host, '#').'$',
            array_unique(array_filter($hosts)),
        ));
    }

    /** @return list<string>|string|null */
    public static function trustedProxies(
        string $configuredProxies,
        string $railwayServiceId,
        string $railwayPublicDomain,
    ): array|string|null {
        if (self::isRailway($railwayServiceId, $railwayPublicDomain)) {
            return '*';
        }

        $configuredProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', $configuredProxies),
        )));
        if ($configuredProxies === []) {
            return null;
        }

        if (count($configuredProxies) === 1 && in_array($configuredProxies[0], ['*', '**'], true)) {
            return $configuredProxies[0];
        }

        return $configuredProxies;
    }

    private static function isRailway(string $serviceId, string $publicDomain): bool
    {
        return trim($serviceId) !== '' || trim($publicDomain) !== '';
    }

    private static function normalizeHost(string $value): ?string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        $host = parse_url(
            str_contains($value, '://') ? $value : 'http://'.$value,
            PHP_URL_HOST,
        );
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = rtrim(trim($host, '[]'), '.');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        if (
            strlen($host) > 253
            || preg_match(
                '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
                $host,
            ) !== 1
        ) {
            return null;
        }

        return $host;
    }
}
