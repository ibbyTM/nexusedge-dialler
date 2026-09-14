<?php
declare(strict_types=1);

/**
 * Normalise a UK phone number to E.164 (+44...).
 * Returns null when the input does not resolve to +44 followed by 9 or 10 digits.
 *
 * Handles spreadsheet export artefacts (447123456789.0, 4.47123456789E+11), spaces, brackets,
 * dashes, dots, and the common +44 (0)7... form.
 */
function normalise_phone($raw): ?string
{
    $s = trim((string)$raw);
    // Some exports wrap the number as ="..." or quote it.
    $s = trim($s, "=\"' ");
    if ($s === '') {
        return null;
    }
    // Scientific notation from spreadsheets, e.g. 4.47123456789E+11
    if (preg_match('/^\+?\d+(?:\.\d+)?[eE]\+?\d+$/', $s)) {
        $s = ltrim($s, '+');
        $s = number_format((float)$s, 0, '', '');
    }
    // Float-looking numbers: 447123456789.0 -> 447123456789
    $s = preg_replace('/\.0+$/', '', $s) ?? $s;
    $hasPlus = $s[0] === '+';
    $digits = preg_replace('/\D+/', '', $s) ?? '';
    if ($digits === '') {
        return null;
    }
    $national = null; // digits after the 44
    if ($hasPlus) {
        if (str_starts_with($digits, '44')) {
            $national = substr($digits, 2);
        } else {
            return null;
        }
    } elseif (str_starts_with($digits, '0044')) {
        $national = substr($digits, 4);
    } elseif (str_starts_with($digits, '44')) {
        $national = substr($digits, 2);
    } elseif (str_starts_with($digits, '0')) {
        $national = substr($digits, 1);
    } elseif (preg_match('/^[1-9]\d{9}$/', $digits)) {
        // Spreadsheets drop the leading 0 from numeric cells: 7700900123 -> 07700900123
        $national = $digits;
    } else {
        return null;
    }
    // "+44 (0)7..." leaves a stray leading 0 after the country code.
    if (str_starts_with($national, '0')) {
        $national = substr($national, 1);
    }
    if (!preg_match('/^[1-9]\d{8,9}$/', $national)) {
        return null;
    }
    return '+44' . $national;
}

/** Display form for a +44 number: 07123 456789 / 020 7946 0958 style. */
function format_phone_display(?string $e164): string
{
    if (!$e164 || !preg_match('/^\+44(\d{9,10})$/', $e164, $m)) {
        return (string)$e164;
    }
    $n = '0' . $m[1];
    $len = strlen($n);
    if ($len === 11 && $n[1] === '7') {
        return substr($n, 0, 5) . ' ' . substr($n, 5);
    }
    if ($len === 11 && ($n[1] === '2')) {
        return substr($n, 0, 3) . ' ' . substr($n, 3, 4) . ' ' . substr($n, 7);
    }
    if ($len === 11) {
        return substr($n, 0, 4) . ' ' . substr($n, 4, 3) . ' ' . substr($n, 7);
    }
    return substr($n, 0, 5) . ' ' . substr($n, 5);
}
