<?php

declare(strict_types=1);

namespace App\Command;

/**
 * Safe coercion of console arguments and options (typed as mixed by the
 * Console component) into scalars.
 */
final class InputValue
{
    private function __construct()
    {
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return \is_string($value) ? $value : $default;
    }
}
