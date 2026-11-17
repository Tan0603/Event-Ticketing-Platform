<?php

use App\Domain\Money\FeeCalculator;

it('rounds the percentage half-up with integer maths', function (int $amount, int $bps, int $expected) {
    expect(FeeCalculator::percentFee($amount, $bps))->toBe($expected);
})->with([
    'exact' => [10000, 500, 500],
    'rounds .5 up' => [10, 500, 1],        // 0.5 -> 1
    'rounds below .5 down' => [9, 500, 0], // 0.45 -> 0
    'half-up on a larger amount' => [1010, 500, 51], // 50.5 -> 51
    'zero amount' => [0, 500, 0],
    'zero rate' => [10000, 0, 0],
    'one third' => [10001, 333, 333], // 333.03 -> 333
    'just under half' => [149, 100, 1], // 1.49 -> 1
    'exactly half' => [150, 100, 2],    // 1.50 -> 2
]);

it('charges the fixed fee per ticket, but nothing on a free ticket', function () {
    expect(FeeCalculator::ticketFee(10000, 500, 50))->toBe(550)
        ->and(FeeCalculator::ticketFee(0, 500, 50))->toBe(0);
});

it('rounds the fee of each ticket, not of the order (spec worked example: 159.97)', function () {
    // GA 45.00 x 2 -> 2.25 + 0.50 = 2.75 each; Early Bird 19.99 x 3 -> 0.9995 -> 1.00 + 0.50 = 1.50 each
    expect(FeeCalculator::ticketFee(4500, 500, 50))->toBe(275)
        ->and(FeeCalculator::ticketFee(1999, 500, 50))->toBe(150);

    $totals = FeeCalculator::totals([[4500, 2], [1999, 3]], 500, 50);

    expect($totals->subtotalMinor)->toBe(14997)
        ->and($totals->feeMinor)->toBe(1000)   // 5.50 + 4.50
        ->and($totals->totalMinor)->toBe(15997)
        ->and($totals->totalMinor)->toBe($totals->subtotalMinor + $totals->feeMinor);
});

it('refuses amounts that would overflow and negative input', function () {
    expect(fn () => FeeCalculator::percentFee(PHP_INT_MAX, 500))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FeeCalculator::percentFee(-1, 500))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FeeCalculator::percentFee(100, -1))->toThrow(InvalidArgumentException::class);
});
