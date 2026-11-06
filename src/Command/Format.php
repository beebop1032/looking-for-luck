<?php

declare(strict_types=1);

namespace App\Command;

/**
 * Shared console formatting helpers.
 */
final class Format
{
    private function __construct()
    {
    }

    public static function money(float $amount): string
    {
        return number_format($amount, 2).' €';
    }

    public static function integer(int|float $value): string
    {
        return number_format($value);
    }
}
