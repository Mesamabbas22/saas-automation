<?php

namespace App\Services\WhatsApp;

/**
 * Normalises phone numbers to the format the Cloud API expects: country code + number,
 * digits only, no "+" (e.g. 923001234567).
 */
class PhoneNumber
{
    public const PAKISTAN_CODE = '92';

    /**
     * Handles "+92 300 1234567", "0092300...", and local "0300..." (assumed Pakistan).
     */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        if (str_starts_with($digits, '03') && strlen($digits) === 11) {
            return self::PAKISTAN_CODE.substr($digits, 1);
        }

        return $digits;
    }
}
