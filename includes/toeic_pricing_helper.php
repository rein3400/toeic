<?php
/**
 * TOEIC product pricing and payment-routing helpers.
 */

require_once __DIR__ . '/settings.php';

if (!function_exists('toeicNormalizeExamType')) {
    function toeicNormalizeExamType(string $examType): string {
        return $examType === 'toeic_sw' ? 'toeic_sw' : 'toeic';
    }
}

if (!function_exists('toeicNormalizePricingTier')) {
    function toeicNormalizePricingTier(?string $tier): string {
        $tier = strtolower(trim((string)$tier));
        return in_array($tier, ['retail', 'partner', 'bulk'], true) ? $tier : 'retail';
    }
}

if (!function_exists('toeicGetProductPrice')) {
    function toeicGetProductPrice(string $examType, ?string $tier = 'retail'): int {
        $examType = toeicNormalizeExamType($examType);
        $tier = toeicNormalizePricingTier($tier);
        $legacyDefault = getSiteSetting('price_' . $examType, '175000');
        $configured = getSiteSetting('price_' . $examType . '_' . $tier, $legacyDefault);
        if ($configured === '') {
            $configured = $legacyDefault;
        }
        return max(0, (int)$configured);
    }
}

if (!function_exists('toeicGetPricingTiers')) {
    function toeicGetPricingTiers(string $examType): array {
        return [
            'retail' => toeicGetProductPrice($examType, 'retail'),
            'partner' => toeicGetProductPrice($examType, 'partner'),
            'bulk' => toeicGetProductPrice($examType, 'bulk'),
        ];
    }
}

if (!function_exists('toeicGetPaymentMode')) {
    function toeicGetPaymentMode(): string {
        $mode = strtolower(trim((string)getSiteSetting('payment_mode', 'direct_bank')));
        return $mode === 'tripay' ? 'tripay' : 'direct_bank';
    }
}

if (!function_exists('toeicPaymentSettingOrDefault')) {
    function toeicPaymentSettingOrDefault(string $key, string $default): string {
        $value = trim((string)getSiteSetting($key, $default));
        return $value === '' ? $default : $value;
    }
}

if (!function_exists('toeicGetBankTransferSettings')) {
    function toeicGetBankTransferSettings(): array {
        return [
            'display_label' => 'GoPay Manual',
            'payment_channel' => toeicPaymentSettingOrDefault('bank_name', 'GOPAY'),
            'bank_name' => toeicPaymentSettingOrDefault('bank_name', 'GOPAY'),
            'bank_account_number' => toeicPaymentSettingOrDefault('bank_account_number', '+62856-4359-7072'),
            'bank_account_holder' => toeicPaymentSettingOrDefault('bank_account_holder', 'Leonardus Bayu'),
            'instructions' => toeicPaymentSettingOrDefault(
                'bank_transfer_instructions',
                'Transfer sesuai nominal invoice ke GOPAY +62856-4359-7072 a.n. Leonardus Bayu, lalu kirim bukti pembayaran ke admin untuk aktivasi paket.'
            ),
        ];
    }
}

if (!function_exists('toeicIsDirectBankConfigured')) {
    function toeicIsDirectBankConfigured(): bool {
        $bank = toeicGetBankTransferSettings();
        return $bank['bank_name'] !== ''
            && $bank['bank_account_number'] !== ''
            && $bank['bank_account_holder'] !== '';
    }
}

if (!function_exists('toeicPaymentUsesTripay')) {
    function toeicPaymentUsesTripay(): bool {
        return toeicGetPaymentMode() === 'tripay'
            && defined('TRIPAY_API_KEY') && TRIPAY_API_KEY !== ''
            && defined('TRIPAY_PRIVATE_KEY') && TRIPAY_PRIVATE_KEY !== ''
            && defined('TRIPAY_MERCHANT_CODE') && TRIPAY_MERCHANT_CODE !== '';
    }
}

if (!function_exists('toeicCheckoutAvailable')) {
    function toeicCheckoutAvailable(): bool {
        return toeicGetPaymentMode() === 'direct_bank'
            ? toeicIsDirectBankConfigured()
            : toeicPaymentUsesTripay();
    }
}
?>
