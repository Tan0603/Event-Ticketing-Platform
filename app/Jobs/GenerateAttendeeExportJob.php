<?php

namespace App\Jobs;

use App\Actions\Exports\GenerateAttendeeExport;
use App\Jobs\Concerns\RunsInTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Builds ONE attendee export of ONE tenant. Takes ids only and runs inside that tenant. */
class GenerateAttendeeExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenant, SerializesModels;

    /** Failures are recorded on the export row; the admin simply requests a new export. No automatic retry. */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $exportId,
    ) {}

    public function handle(GenerateAttendeeExport $generate): void
    {
        $this->inTenant(fn () => $generate->execute($this->exportId));
    }

    /** Called when the queue gives up (timeout, killed worker): the export must not stay "processing" for ever. */
    public function failed(Throwable $e): void
    {
        $this->inTenantOnFailure(fn () => app(GenerateAttendeeExport::class)->markFailed($this->exportId, $e));
    }
}
