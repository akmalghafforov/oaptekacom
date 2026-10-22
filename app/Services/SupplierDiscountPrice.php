<?php

namespace App\Services;

class SupplierDiscountPrice
{
    public function selectedPercent(?string $agreedPercent, string $defaultPercent): string
    {
        return bccomp($agreedPercent ?? '0', $defaultPercent, 2) >= 0
            ? bcadd($agreedPercent ?? '0', '0', 2)
            : bcadd($defaultPercent, '0', 2);
    }

    public function effectivePrice(string $price, ?string $supplierDiscountPercent): string
    {
        if ($supplierDiscountPercent === null || bccomp($supplierDiscountPercent, '0', 2) === 0) {
            return bcadd($price, '0', 2);
        }

        return bcdiv(
            bcmul($price, bcsub('100.00', $supplierDiscountPercent, 2), 4),
            '100',
            2,
        );
    }

    public function lineTotal(int $quantity, string $unitPrice): string
    {
        return bcmul((string) $quantity, $unitPrice, 2);
    }
}
