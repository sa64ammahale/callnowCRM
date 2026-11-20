<?php

if (!function_exists('normalize_indian_phone')) {
    function normalize_indian_phone(?string $raw, bool $requireMobileSeries = true): ?string {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw);

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        } elseif (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        if ($requireMobileSeries) {
            if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
                return null;
            }
        }

        return $digits;
    }
}

if (!function_exists('format_indian_phone')) {
    function format_indian_phone(?string $digits, string $format = 'e164'): string {
        $clean = normalize_indian_phone($digits, false);
        if ($clean === null) {
            return (string)$digits;
        }

        switch (strtolower($format)) {
            case 'e164':
                return '+91' . $clean;
            case 'display':
                return '+91 ' . substr($clean, 0, 5) . ' ' . substr($clean, 5);
            case 'national':
                return '0' . $clean;
            case 'dial_uri':
                return 'tel:+91' . $clean;
            case 'raw':
            default:
                return $clean;
        }
    }
}

if (!function_exists('is_valid_indian_mobile')) {
    function is_valid_indian_mobile(?string $raw): bool {
        return normalize_indian_phone($raw, true) !== null;
    }
}

if (!function_exists('mask_phone')) {
    function mask_phone(?string $mobile): string {
        if ($mobile === null || $mobile === '' || trim($mobile) === '' || $mobile === '—') {
            return '—';
        }
        $digits = preg_replace('/\D/', '', $mobile);
        if (strlen($digits) < 4) {
            return '—';
        }
        return str_repeat('•', strlen($digits) - 4) . substr($digits, -4);
    }
}
