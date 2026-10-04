<?php

namespace Wexample\SymfonyAccountingBe\Helper;

/**
 * Belgian enterprise numbers (numéro d'entreprise / ondernemingsnummer): ten
 * digits starting with 0 or 1, the last two being 97 minus the first eight
 * modulo 97. The VAT number is "BE" followed by the same ten digits.
 */
class BeIdentityHelper
{
    public static function normalize(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);

        // Older numbers have nine digits: a leading zero was added in 2007.
        return 9 === strlen($digits) ? '0'.$digits : $digits;
    }

    public static function isValidEnterpriseNumber(string $number): bool
    {
        $number = static::normalize($number);

        if (! preg_match('/^[01]\d{9}$/', $number)) {
            return false;
        }

        return 97 - ((int) substr($number, 0, 8) % 97) === (int) substr($number, 8, 2);
    }

    /**
     * "0123456749" → "0123.456.749".
     */
    public static function format(string $number): string
    {
        $number = static::normalize($number);

        return substr($number, 0, 4).'.'.substr($number, 4, 3).'.'.substr($number, 7, 3);
    }

    public static function toVatNumber(string $number): string
    {
        return 'BE'.static::normalize($number);
    }

    public static function isValidVatNumber(string $vatNumber): bool
    {
        $vatNumber = strtoupper(preg_replace('/[\s.\-]/', '', $vatNumber));

        return str_starts_with($vatNumber, 'BE') && static::isValidEnterpriseNumber(substr($vatNumber, 2));
    }
}
