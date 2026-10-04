<?php

namespace Wexample\SymfonyAccountingBe\Helper;

/**
 * Structured communication (OGM / VCS): +++123/4567/89002+++, ten digits and a
 * two-digit check, their remainder modulo 97 (97 when it is 0). Belgian banks
 * carry it back untouched, so payments match their invoice exactly.
 */
class StructuredCommunicationHelper
{
    /**
     * @param int|string $base Up to ten digits identifying the invoice.
     */
    public static function generate(int|string $base): string
    {
        $digits = str_pad(substr(preg_replace('/\D/', '', (string) $base), -10), 10, '0', STR_PAD_LEFT);
        $check = (int) $digits % 97;
        $check = 0 === $check ? 97 : $check;

        return static::format($digits.str_pad((string) $check, 2, '0', STR_PAD_LEFT));
    }

    public static function format(string $twelveDigits): string
    {
        return sprintf('+++%s/%s/%s+++', substr($twelveDigits, 0, 3), substr($twelveDigits, 3, 4), substr($twelveDigits, 7, 5));
    }

    public static function isValid(string $communication): bool
    {
        $digits = preg_replace('/\D/', '', $communication);

        if (12 !== strlen($digits)) {
            return false;
        }

        $check = (int) substr($digits, 0, 10) % 97;

        return (0 === $check ? 97 : $check) === (int) substr($digits, 10, 2);
    }

    /**
     * Every valid structured communication in a text, normalized: "+++…+++",
     * "***…***", or twelve digits on their own.
     *
     * @return list<string>
     */
    public static function extract(string $text): array
    {
        preg_match_all('/(?:\+\+\+|\*\*\*)\s*(\d{3})\s*\/?\s*(\d{4})\s*\/?\s*(\d{5})\s*(?:\+\+\+|\*\*\*)|(?<!\d)(\d{12})(?!\d)/', $text, $matches, PREG_SET_ORDER);
        $found = [];

        foreach ($matches as $match) {
            $digits = ! empty($match[4]) ? $match[4] : $match[1].$match[2].$match[3];

            if (static::isValid($digits)) {
                $found[] = static::format($digits);
            }
        }

        return array_values(array_unique($found));
    }
}
