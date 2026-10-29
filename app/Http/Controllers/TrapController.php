<?php

namespace App\Http\Controllers;

use App\Support\SearchBots;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Bot trap (Oct 28 2026). Every page carries a link people never see (hidden in the footer) to /x/…, which robots.txt
 * forbids. Search engines obey robots.txt; a client that requests /x/ is a scraper ignoring it. Its IP is then turned
 * away from record and search pages for 7 days (signed-out visitors only; accounts are capped separately).
 */
class TrapController extends Controller
{
    public const DAYS = 7;

    public function __invoke(Request $request)
    {
        if (! SearchBots::verified($request) && ! $request->user()) {
            Cache::put('trapped:'.$request->ip(), now()->toIso8601String(), now()->addDays(self::DAYS));
            Log::warning('Bot trap: '.$request->ip().' '.mb_substr((string) $request->userAgent(), 0, 200).' '.$request->path());
        }

        return response('Not found.', 404, ['X-Robots-Tag' => 'noindex, nofollow']);
    }

    public static function trapped(Request $request): bool
    {
        return ! $request->user() && Cache::has('trapped:'.$request->ip()) && ! SearchBots::verified($request);
    }
}
