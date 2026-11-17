<?php

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

/** Renders the QR code of a ticket code. The QR carries only the code; the door looks it up. */
final class TicketQr
{
    public static function png(string $code, int $size = 240): string
    {
        return (new Builder(writer: new PngWriter, data: $code, size: $size, margin: 8))->build()->getString();
    }
}
