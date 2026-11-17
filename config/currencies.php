<?php

/*
 * Supported tenant currencies and their minor-unit exponent (10^exponent minor units per major unit).
 * A tenant sells in exactly one of these, chosen at sign-up and immutable afterwards.
 */
return [
    'MYR' => ['exponent' => 2],
    'SGD' => ['exponent' => 2],
    'IDR' => ['exponent' => 2],
    'USD' => ['exponent' => 2],
    'THB' => ['exponent' => 2],
    'PHP' => ['exponent' => 2],
    'VND' => ['exponent' => 0],
    'JPY' => ['exponent' => 0],
];
