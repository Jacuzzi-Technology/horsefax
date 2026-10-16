<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Best-effort email to the Horsefax team. A failed email never loses the submission (it is already saved). */
final class Notify
{
    public static function staff(string $subject, string $body): void
    {
        $to = config('horsefax.notify_email');
        if (! $to) {
            return;
        }
        try {
            Mail::raw($body."\n\n".url('/admin'), fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            Log::warning('Horsefax staff notification failed: '.$e->getMessage());
        }
    }
}
