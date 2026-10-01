<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

/** Host support: major.x, major.minor.x, or an inclusive stable-version range. */
final class HostVersionCompatibility
{
    public static function matches(mixed $support, mixed $host): bool
    {
        if (!is_string($support) || !is_string($host) || strpbrk($support, "\r\n") !== false
            || preg_match('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?\z/D', $host, $version) !== 1) {
            return false;
        }
        $prerelease = $version[4] ?? '';
        foreach ($prerelease === '' ? [] : explode('.', $prerelease) as $part) {
            if (preg_match('/\A[0-9]+\z/D', $part) === 1
                && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $part) !== 1) return false;
        }
        $core = $version[1] . '.' . $version[2] . '.' . $version[3];
        $number = '(?:0|[1-9][0-9]*)';
        $stable = $number . '\.' . $number . '\.' . $number;
        $matched = false;
        foreach (explode('|', $support) as $branch) {
            $branch = trim($branch, " \t");
            if (preg_match('/\A(' . $number . ')(?:\.(' . $number . '))?\.x\z/D', $branch, $wildcard) === 1) {
                $matched = $matched || ($wildcard[1] === $version[1]
                    && (!isset($wildcard[2]) || $wildcard[2] === $version[2]));
                continue;
            }
            if (preg_match('/\A>=(' . $stable . ')(?:[ \t]+<=(' . $stable . '))?\z/D', $branch, $range) !== 1
                || (isset($range[2]) && self::compareCore($range[1], $range[2]) > 0)) return false;
            $minimum = self::compareCore($core, $range[1]);
            $aboveMinimum = $minimum > 0 || ($minimum === 0 && $prerelease === '');
            $belowMaximum = !isset($range[2]) || self::compareCore($core, $range[2]) <= 0;
            $matched = $matched || ($aboveMinimum && $belowMaximum);
        }
        return $matched;
    }

    private static function compareCore(string $left, string $right): int
    {
        $rightParts = explode('.', $right);
        foreach (explode('.', $left) as $index => $part) {
            $comparison = strlen($part) <=> strlen($rightParts[$index]);
            if ($comparison === 0) $comparison = strcmp($part, $rightParts[$index]) <=> 0;
            if ($comparison !== 0) return $comparison;
        }
        return 0;
    }
}
