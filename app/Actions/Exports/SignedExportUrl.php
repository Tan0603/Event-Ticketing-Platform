<?php

namespace App\Actions\Exports;

use Illuminate\Support\Facades\URL;

/** The temporary signed download link of an export. The tenant is in the path (a link cannot send a header) and is covered by the signature. */
class SignedExportUrl
{
    public function for(int $exportId): string
    {
        return URL::temporarySignedRoute(
            'public.attendee-exports.download',
            now()->addMinutes((int) config('ticketing.exports.link_minutes')),
            ['tenant' => (string) tenant('id'), 'export' => $exportId],
        );
    }
}
