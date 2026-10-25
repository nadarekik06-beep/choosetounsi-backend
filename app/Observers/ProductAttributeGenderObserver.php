<?php

namespace App\Observers;

use App\Models\ProductAttributeValue;
use App\Services\Gender\GenderResolver;

/** The seller's "gender" attribute row was written or removed → recompute products.gender. */
class ProductAttributeGenderObserver
{
    public function saved(ProductAttributeValue $pav): void
    {
        $this->changed($pav);
    }

    public function deleted(ProductAttributeValue $pav): void
    {
        $this->changed($pav);
    }

    private function changed(ProductAttributeValue $pav): void
    {
        try {
            $genderAttr = app(GenderResolver::class)->attributeId();
        } catch (\Throwable $e) {
            report($e);
            return;
        }

        if ($genderAttr && (int) $pav->attribute_id === (int) $genderAttr && $pav->product_id) {
            ProductGenderObserver::sync((int) $pav->product_id);
            ProductGenderObserver::defer((int) $pav->product_id);
        }
    }
}
