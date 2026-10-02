<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

/** Shared ordering for repository recommendations and ordinary upload upgrades. */
final class PluginVersion
{
    /** Plugin versions use SemVer precedence, including arbitrary preview labels. */
    public static function compare(string $left, string $right): int
    {
        [$leftCore, $leftPre] = array_pad(explode('-', $left, 2), 2, null);
        [$rightCore, $rightPre] = array_pad(explode('-', $right, 2), 2, null);
        $core = version_compare($leftCore, $rightCore);
        if ($core !== 0) return $core;
        if ($leftPre === $rightPre) return 0;
        if ($leftPre === null) return 1;
        if ($rightPre === null) return -1;
        $leftParts = explode('.', $leftPre);
        $rightParts = explode('.', $rightPre);
        foreach ($leftParts as $index => $part) {
            if (!isset($rightParts[$index])) return 1;
            $other = $rightParts[$index];
            if ($part === $other) continue;
            $numeric = ctype_digit($part);
            $otherNumeric = ctype_digit($other);
            if ($numeric && $otherNumeric) {
                $part = ltrim($part, '0') ?: '0';
                $other = ltrim($other, '0') ?: '0';
                $comparison = strlen($part) <=> strlen($other);
                if ($comparison === 0) $comparison = strcmp($part, $other);
            } else {
                $comparison = $numeric !== $otherNumeric ? ($numeric ? -1 : 1) : strcmp($part, $other);
            }
            if ($comparison !== 0) return $comparison;
        }
        return count($leftParts) <=> count($rightParts);
    }

}
