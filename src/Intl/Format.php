<?php

declare(strict_types=1);

namespace App\Intl;

/**
 * Locale-aware formatting shared by the web interface and the console. The
 * locale defaults to \Locale::getDefault(), which Symfony's LocaleSwitcher
 * keeps in sync with the request (web) or the --locale option (console).
 */
final class Format
{
    private function __construct()
    {
    }

    public static function money(float|int $amount, int $decimals = 2, string $currency = 'EUR', ?string $locale = null): string
    {
        $formatter = new \NumberFormatter($locale ?? \Locale::getDefault(), \NumberFormatter::CURRENCY);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        $formatted = $formatter->formatCurrency((float) $amount, $currency);

        return false === $formatted ? number_format((float) $amount, $decimals).' '.$currency : $formatted;
    }

    public static function integer(int|float $value): string
    {
        return self::decimal($value, 0);
    }

    public static function decimal(int|float $value, int $decimals, ?string $locale = null): string
    {
        $formatter = new \NumberFormatter($locale ?? \Locale::getDefault(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        $formatted = $formatter->format($value);

        return false === $formatted ? number_format((float) $value, $decimals) : $formatted;
    }

    /**
     * A date with an ICU pattern, e.g. "EEE d MMM y" → "ven. 13 nov. 2026".
     */
    public static function date(\DateTimeInterface $date, string $pattern = 'd MMM y'): string
    {
        $formatter = new \IntlDateFormatter(\Locale::getDefault(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $date->getTimezone(), null, $pattern);
        $formatted = $formatter->format($date);

        return false === $formatted ? $date->format('Y-m-d') : $formatted;
    }
}
