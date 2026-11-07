<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Record\OffspringEarnings;
use App\Http\Controllers\Controller;
use App\Models\OffspringEarning;
use Illuminate\Http\Request;

/** Leading sires and dams by offspring earnings, with coverage and the NRHA benchmark. Staff only (Richard, Oct 22 2026). */
class OffspringController extends Controller
{
    public function index(Request $request)
    {
        $role = $request->query('role') === 'dam' ? 'dam' : 'sire';
        $org = (string) $request->query('org', OffspringEarnings::BENCHMARK_ORG);
        $orgs = OffspringEarning::where('organization', '!=', 'all')->distinct()->orderBy('organization')->pluck('organization');

        $leaders = OffspringEarning::where('role', $role)->where('organization', $org)->where('earnings_cents', '>', 0)
            ->with('horse')->orderByDesc('earnings_cents')->limit(100)->get();
        $totals = OffspringEarning::where('role', $role)->where('organization', 'all')->whereIn('horse_id', $leaders->pluck('horse_id'))->get()->keyBy('horse_id');

        $run = OffspringEarnings::lastRun();

        return view('admin.offspring', [
            'role' => $role, 'org' => $org, 'orgs' => $orgs, 'leaders' => $leaders, 'totals' => $totals, 'run' => $run,
            'benchmark' => collect($run?->stats['benchmark'] ?? [])->where('role', $role)->values(),
        ]);
    }
}
