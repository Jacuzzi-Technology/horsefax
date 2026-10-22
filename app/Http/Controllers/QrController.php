<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Support\HorseQr;
use Illuminate\Http\Request;

/** QR codes (with the HF mark) that open a horse's Horsefax record: for sale catalogs, stall cards, papers. */
class QrController extends Controller
{
    public function horse(Request $request, Horse $horse, string $format)
    {
        abort_unless($horse->public_summary || $horse->listed || $horse->canBeViewedPrivatelyBy($request->user()), 404);
        $url = route('horse.public', $horse);
        $name = 'horsefax-'.$horse->horsefax_id.'.'.$format;

        return $format === 'svg'
            ? response(HorseQr::svg($url), 200, ['Content-Type' => 'image/svg+xml', 'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'"', 'Cache-Control' => 'public, max-age=86400'])
            : response(HorseQr::png($url), 200, ['Content-Type' => 'image/png', 'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'"', 'Cache-Control' => 'public, max-age=86400']);
    }

    /** Staff: QR codes for a sale catalog. One line per horse: "hip, HF-XXXX-XXXX" (or just the Horsefax ID). */
    public function batchForm()
    {
        return view('admin.qr-batch');
    }

    public function batch(Request $request)
    {
        $data = $request->validate(['lines' => 'required|string|max:50000', 'sale' => 'nullable|string|max:80']);
        $zip = new \ZipArchive();
        $path = tempnam(sys_get_temp_dir(), 'hfqr');
        $zip->open($path, \ZipArchive::OVERWRITE);
        $index = ["hip,horsefax_id,name,url"];
        $missing = [];
        foreach (preg_split('/\R/', $data['lines']) as $line) {
            if (! preg_match('/^\s*(?:([0-9A-Za-z]+)\s*[,\t ]\s*)?(HF-[A-Z0-9]{4}-[A-Z0-9]{4})\s*$/i', $line, $m)) {
                if (trim($line) !== '') {
                    $missing[] = trim($line);
                }
                continue;
            }
            $horse = Horse::where('horsefax_id', strtoupper($m[2]))->first();
            if (! $horse) {
                $missing[] = trim($line);
                continue;
            }
            $hip = $m[1] ?: null;
            $base = ($hip ? 'hip-'.$hip.'-' : '').$horse->horsefax_id;
            $url = route('horse.public', $horse);
            $zip->addFromString($base.'.png', HorseQr::png($url));
            $zip->addFromString($base.'.svg', HorseQr::svg($url));
            $index[] = implode(',', [$hip, $horse->horsefax_id, '"'.str_replace('"', '""', $horse->displayName()).'"', $url]);
        }
        $zip->addFromString('index.csv', implode("\n", $index)."\n");
        if ($missing) {
            $zip->addFromString('not-found.txt', implode("\n", $missing)."\n");
        }
        $zip->close();
        $name = 'horsefax-qr-'.\Illuminate\Support\Str::slug($data['sale'] ?: 'catalog').'.zip';

        return response()->download($path, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
