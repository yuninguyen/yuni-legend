<?php

namespace App\Observers;

use App\Models\Email;
use App\Jobs\SyncGoogleSheetJob;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class EmailObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Email $email): void
    {
        // Không dispatch ngược lại Sheet khi đang import TỪ Sheet (tránh spam quota Google API)
        if (Email::$syncingFromSheet) {
            return;
        }

        // Gọi Job đa năng để cập nhật dòng Email này lên Sheet
        \App\Jobs\SyncGoogleSheetJob::dispatch($email->id, \App\Models\Email::class);
    }

    public function deleted(Email $email): void
    {
        //
        \App\Jobs\SyncGoogleSheetJob::dispatch($email->id, \App\Models\Email::class, 'delete');
    }
}