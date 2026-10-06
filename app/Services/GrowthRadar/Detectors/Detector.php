<?php

namespace App\Services\GrowthRadar\Detectors;

use App\Services\GrowthRadar\Benchmarks;
use App\Services\GrowthRadar\Card;
use App\Services\GrowthRadar\SellerContext;

/** Rule-based finder of one card type. Returns zero or more candidate cards. */
interface Detector
{
    /** @return Card[] */
    public function detect(SellerContext $ctx, Benchmarks $bench): array;
}
