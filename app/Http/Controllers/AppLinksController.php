<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Universal links for the Horsefax phone app: iOS fetches this file (through Apple's CDN) when the app is installed,
 * then opens horsefax.com/horse/... links — including printed Horsefax QR codes scanned with the Camera app — in the app.
 * Without the app, the same links open the website as before.
 */
class AppLinksController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'applinks' => [
                'details' => [[
                    'appIDs' => array_values(config('horsefax.ios_app_ids', [])),
                    'components' => [
                        // Files, not pages: QR images and PDF downloads stay in the browser.
                        ['/' => '/horse/*/qr.*', 'exclude' => true],
                        ['/' => '/horse/*/pdf', 'exclude' => true],
                        ['/' => '/horse/*'],
                    ],
                ]],
            ],
        ], 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }
}
