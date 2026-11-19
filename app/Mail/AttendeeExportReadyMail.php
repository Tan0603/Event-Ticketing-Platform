<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your attendee export is ready": a temporary signed link. It is queued, so it carries plain values (the link
 * was signed inside the tenant when the export finished) and needs no tenant context when it renders.
 */
class AttendeeExportReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $adminName,
        public readonly string $eventTitle,
        public readonly int $rowCount,
        public readonly string $downloadUrl,
        public readonly int $validMinutes,
    ) {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Attendee export for {$this->eventTitle} is ready");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.attendee-export-ready');
    }
}
