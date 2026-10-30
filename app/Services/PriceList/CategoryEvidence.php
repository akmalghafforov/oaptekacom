<?php

namespace App\Services\PriceList;

use stdClass;

class CategoryEvidence
{
    /**
     * Preserve classification and matching metadata without carrying prior evidence.
     *
     * @param  array<array-key, mixed>|stdClass  $value
     * @return array<array-key, mixed>|stdClass
     */
    public function sanitize(array|stdClass $value): array|stdClass
    {
        $clean = $value instanceof stdClass ? clone $value : $value;
        foreach ($value as $key => $metadata) {
            if ($key === 'evidence') {
                if ($clean instanceof stdClass) {
                    unset($clean->{$key});
                } else {
                    unset($clean[$key]);
                }

                continue;
            }

            if (is_array($metadata) || $metadata instanceof stdClass) {
                if ($clean instanceof stdClass) {
                    $clean->{$key} = $this->sanitize($metadata);
                } else {
                    $clean[$key] = $this->sanitize($metadata);
                }
            }
        }

        return $clean;
    }
}
