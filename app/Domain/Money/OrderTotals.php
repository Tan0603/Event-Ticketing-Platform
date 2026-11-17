<?php

namespace App\Domain\Money;

final readonly class OrderTotals
{
    public function __construct(
        public int $subtotalMinor,
        public int $feeMinor,
        public int $totalMinor,
    ) {}
}
