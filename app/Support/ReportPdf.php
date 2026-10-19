<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * One-click PDF of a Horsefax record or report (Richard, Oct 19 2026: the browser's print dialog hides "save as PDF").
 * The page the reader is already allowed to see is rendered to HTML and printed by a headless Chrome on the server
 * (Chrome for Testing's chrome-headless-shell in /opt), using the same print layout as the Print button.
 */
class ReportPdf
{
    public static function binary(): string
    {
        return (string) config('horsefax.chrome');
    }

    public static function available(): bool
    {
        return is_executable(self::binary());
    }

    public static function fromHtml(string $html): ?string
    {
        // Relative asset paths (/img, /build) resolve against the site, since Chrome reads the page from a file.
        $html = preg_replace('/<head>/i', '<head><base href="'.rtrim(config('app.url'), '/').'/">', $html, 1);
        $dir = sys_get_temp_dir().'/hfpdf-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        file_put_contents($dir.'/page.html', $html);
        try {
            $r = Process::timeout(90)->run([self::binary(), '--no-sandbox', '--disable-gpu', '--no-pdf-header-footer', '--hide-scrollbars',
                '--virtual-time-budget=8000',
                // The page is our own, read from a file: let it load horsefax.com's web fonts, which browsers otherwise block for files.
                '--allow-file-access-from-files', '--disable-web-security', '--user-data-dir='.$dir.'/profile', '--print-to-pdf='.$dir.'/out.pdf', 'file://'.$dir.'/page.html']);
            $pdf = is_file($dir.'/out.pdf') ? file_get_contents($dir.'/out.pdf') : null;
            if (! $pdf) {
                report(new \RuntimeException('PDF render failed: '.$r->errorOutput()));
            }

            return $pdf ?: null;
        } finally {
            Process::run(['rm', '-rf', $dir]);
        }
    }

    public static function filename(\App\Models\Horse $horse, bool $timeline): string
    {
        return 'Horsefax-'.$horse->horsefax_id.'-'.\Illuminate\Support\Str::slug($horse->displayName()).($timeline ? '-life-timeline' : '').'.pdf';
    }
}
