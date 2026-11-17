<?php

namespace App\Domain\Orders\Exceptions;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;

class InvalidOrderTransition extends BusinessRuleException
{
    public function __construct(public readonly OrderStatus $from, public readonly OrderStatus $to)
    {
        parent::__construct('invalid_order_transition', "An order cannot go from {$from->value} to {$to->value}.");
    }
}
