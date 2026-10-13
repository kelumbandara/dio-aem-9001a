<?php

namespace App\Modules\Communications\Services;

/**
 * Puts an e-mail address or phone number in one form, so the same person is never two addresses (and an opt-out always matches).
 * E-mail: trimmed, lower case, must look like an address. Phone: digits only with the country code first ("077 123 4567" and
 * "+94 77 123 4567" both become 94771234567); numbers that cannot be real are refused.
 */
final class Address
{
    public static function normalize(string $channel, string $raw): ?string
    {
        return $channel === 'email' ? self::email($raw) : ($channel === 'whatsapp' ? self::phone($raw) : null);
    }

    public static function email(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));

        return $v !== '' && mb_strlen($v) <= 190 && filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? $v : null;
    }

    public static function phone(string $raw, ?string $country = null): ?string
    {
        $country ??= (string) config('communications.default_country_code', '94');
        $text = trim($raw);
        if ($text === '' || preg_match('/^\+?[\d ().\-]*\d$/', $text) !== 1) {
            return null;
        }
        $international = str_starts_with($text, '+');
        $digits = preg_replace('/\D/', '', $text) ?? '';
        if ($digits === '') {
            return null;
        }

        if (! $international && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);                                     // 0094 77 … : the international prefix
        } elseif (! $international && str_starts_with($digits, '0')) {
            $digits = $country.substr($digits, 1);                            // 077 … : the national form
        } elseif (! $international && ! str_starts_with($digits, $country)) {
            $digits = $country.$digits;                                       // 77 123 4567 without a zero
        }

        if (strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0') {
            return null;
        }
        if (str_starts_with($digits, $country) && $country === '94' && strlen($digits) !== 11) {
            return null;                                                      // a Sri Lankan number is 94 and nine digits
        }

        return $digits;
    }

    /** A phone number shown with a plus sign, an address as it is. */
    public static function display(string $channel, string $address): string
    {
        return $channel === 'whatsapp' ? '+'.$address : $address;
    }

    /** Hides the middle of an address for lists and logs ("94•••••567", "a•••@x.lk"). */
    public static function mask(string $channel, string $address): string
    {
        if ($channel === 'whatsapp') {
            return strlen($address) > 5 ? substr($address, 0, 2).str_repeat('•', max(3, strlen($address) - 5)).substr($address, -3) : $address;
        }
        [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');

        return $domain === '' ? $address : mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
