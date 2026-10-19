<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Record\AttachSaleLot;
use App\Actions\Record\MatchSaleLots;
use App\Http\Controllers\Controller;
use App\Models\Horse;
use App\Models\SaleLot;
use App\Models\ShowRecord;
use App\Support\Audit;
use Illuminate\Http\Request;

/** Staff review of published sale lots the matcher couldn't settle: same-name candidates, and conflicts. */
class SaleLotController extends Controller
{
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['queued', 'conflict', 'held', 'matched', 'unmatched', 'dismissed'], true) ? $request->query('status') : 'queued';
        $q = trim((string) $request->query('q'));
        $lots = SaleLot::with(['source', 'horse'])
            ->when($status === 'matched', fn ($w) => $w->whereNotNull('horse_id'), fn ($w) => $status === 'unmatched'
                ? $w->whereNull('horse_id')->whereNull('review_status')->whereIn('status', ['sold', 'high_bid', 'no_sale', 'offered'])
                : $w->whereNull('horse_id')->where('review_status', $status))
            ->when($q !== '', fn ($w) => $w->where('name_key', 'like', '%'.ShowRecord::key($q).'%'))
            ->orderByDesc('sale_date')->paginate(50)->withQueryString();
        $candidates = MatchSaleLots::matchable(Horse::whereIn(\Illuminate\Support\Facades\DB::raw('lower(registered_name)'), $lots->pluck('horse_name')->map(fn ($n) => mb_strtolower(trim($n)))->unique()->all()))
            ->with(['pedigree' => fn ($p) => $p->whereIn('position', ['s', 'd'])])->get()
            ->groupBy(fn ($h) => ShowRecord::key($h->registered_name));
        $counts = SaleLot::selectRaw("count(*) filter (where horse_id is not null) matched, count(*) filter (where horse_id is null and review_status = 'queued') queued, count(*) filter (where horse_id is null and review_status = 'conflict') conflict, count(*) total")->first();

        return view('admin.sale-lots', ['lots' => $lots, 'candidates' => $candidates, 'status' => $status, 'q' => $q, 'counts' => $counts,
            'sources' => \App\Models\DataSource::whereIn('key', ['liveag', 'premier', 'platinum', 'wsqha'])->get()]);
    }

    public function attach(Request $request, SaleLot $lot, AttachSaleLot $attach)
    {
        $data = $request->validate(['horsefax_id' => 'required|string', 'note' => 'required|string|max:1000']);
        $horse = Horse::where('horsefax_id', strtoupper(trim($data['horsefax_id'])))->firstOrFail();
        abort_if($lot->horse_id && $lot->horse_id !== $horse->id, 409, 'This lot is already on another horse.');
        if (! $lot->publishable()) {
            return back()->withErrors(['horsefax_id' => 'This kind of result ('.str_replace('_', ' ', $lot->status).') is not published on records yet.']);
        }
        // Staff still can't attach a lot the record contradicts (registration number, sire, dam, foal year).
        if ($why = app(MatchSaleLots::class)->contradiction($lot, $horse)) {
            return back()->withErrors(['horsefax_id' => 'That record contradicts this lot ('.$why.'). Correct the record first if it is wrong.']);
        }
        $attach($lot, $horse, $request->user(), 'staff');
        Audit::record('sale_lot.attach_note', $horse, null, ['note' => $data['note'], 'lot' => $lot->id], $horse->id);

        return back()->with('status', $lot->horse_name.' ('.$lot->sale_name.') attached to '.$horse->displayName().'.');
    }

    public function dismiss(Request $request, SaleLot $lot)
    {
        $data = $request->validate(['note' => 'required|string|max:255']);
        $lot->forceFill(['review_status' => 'dismissed', 'review_note' => $data['note']])->save();

        return back()->with('status', 'Dismissed. The lot stays in the index, unattached.');
    }
}
