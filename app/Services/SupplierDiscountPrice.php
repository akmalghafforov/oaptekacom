<?php

namespace App\Services;

class SupplierDiscountPrice
{
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
