<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\RadiographAccess;
use App\Models\RadiographFile;
use App\Models\RadiographSet;
use App\Models\ShareLink;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Radiograph sets: the owner (or their veterinarian, as a contributor) adds them; buyers view them from the record or
 * the catalog QR when the owner allows: sale access, an approved request, or a report link. Every view is logged.
 */
class RadiographController extends Controller
{
    public const MAX_KB = 153600; // 150 MB a file (DICOM files can be large)

    private function canAdd(Request $request, Horse $horse): bool
    {
        $u = $request->user();

        return $horse->canBeEditedBy($u) || (($c = $horse->activeContributor($u)) && $c->role === 'veterinarian');
    }

    private function manageable(Request $request, Horse $horse, ?RadiographSet $set = null): void
    {
        abort_unless($horse->canBeEditedBy($request->user()) || $request->user()?->isStaff(), 403);
        abort_if($set && $set->horse_id !== $horse->id, 404);
    }

    // ---- Owner side

    public function index(Request $request, Horse $horse)
    {
        abort_unless($this->canAdd($request, $horse) || $horse->canBeViewedPrivatelyBy($request->user()), 403);

        return view('radiographs.index', ['horse' => $horse, 'sets' => RadiographSet::where('horse_id', $horse->id)->withCount(['files', 'viewsLog'])
            ->withCount(['access as pending_count' => fn ($q) => $q->where('status', 'requested')])->orderByDesc('taken_on')->get(), 'canAdd' => $this->canAdd($request, $horse)]);
    }

    public function store(Request $request, Horse $horse)
    {
        abort_unless($this->canAdd($request, $horse), 403);
        $data = $request->validate([
            'taken_on' => 'required|date|before_or_equal:today', 'purpose' => 'required|in:'.implode(',', array_keys(RadiographSet::PURPOSES)),
            'clinic' => 'nullable|string|max:160', 'veterinarian' => 'nullable|string|max:160', 'views' => 'nullable|string|max:255',
        ]);
        $vet = ($c = $horse->activeContributor($request->user())) && $c->role === 'veterinarian';
        $set = RadiographSet::create($data + ['horse_id' => $horse->id, 'uploaded_by' => $request->user()->id, 'source_type' => $vet ? 'professional_attested' : 'owner_reported']);
        Audit::record('radiographs.set_created', $set, null, $data, $horse->id);

        return redirect()->route('radiographs.manage', [$horse, $set])->with('status', 'Set created. Now add the files.');
    }

    public function manage(Request $request, Horse $horse, RadiographSet $set)
    {
        abort_unless(($this->canAdd($request, $horse) || $horse->canBeViewedPrivatelyBy($request->user())) && $set->horse_id === $horse->id, 403);

        return view('radiographs.manage', [
            'horse' => $horse, 'set' => $set->load('files'), 'canManage' => $horse->canBeEditedBy($request->user()),
            'requests' => $set->access()->with('user')->orderByRaw("case status when 'requested' then 0 else 1 end")->latest()->get(),
            'views' => $set->viewsLog()->with('user')->latest('created_at')->limit(200)->get(),
        ]);
    }

    public function upload(Request $request, Horse $horse, RadiographSet $set)
    {
        abort_unless($this->canAdd($request, $horse) && $set->horse_id === $horse->id, 403);
        $request->validate(['file' => 'required|file|max:'.self::MAX_KB]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 132);
        $kind = match (true) {
            $ext === 'dcm' || $ext === 'dicom' || substr($head, 128, 4) === 'DICM' => 'dicom',
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && @getimagesize($file->getRealPath()) => 'image',
            $ext === 'pdf' && str_starts_with($head, '%PDF') => 'pdf',
            default => null,
        };
        if (! $kind) {
            return response()->json(['error' => 'Only DICOM (.dcm), JPEG, PNG, WebP or PDF files.'], 422);
        }
        $id = (string) Str::uuid();
        $path = 'radiographs/'.$horse->id.'/'.$set->id.'/'.$id.'.'.($kind === 'dicom' ? 'dcm' : $ext);
        RadiographFile::disk()->putFileAs(dirname($path), $file, basename($path));
        $rf = RadiographFile::create([
            'id' => $id, 'radiograph_set_id' => $set->id, 'original_name' => Str::limit(preg_replace('/[^\w.\- ]+/', '_', $file->getClientOriginalName()), 180, ''),
            'kind' => $kind, 'mime' => $kind === 'dicom' ? 'application/dicom' : ($file->getMimeType() ?: 'application/octet-stream'),
            'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'path' => $path,
            'position' => (int) $set->files()->max('position') + 1,
        ]);
        Audit::record('radiographs.file_added', $rf, null, ['set' => $set->id, 'kind' => $kind, 'size' => $rf->size], $horse->id);

        return response()->json(['ok' => true, 'id' => $rf->id, 'kind' => $kind, 'name' => $rf->original_name]);
    }

    public function updateAccess(Request $request, Horse $horse, RadiographSet $set)
    {
        $this->manageable($request, $horse, $set);
        $data = $request->validate(['access' => 'required|in:'.implode(',', array_keys(RadiographSet::ACCESS)),
            'sale_access_until' => 'nullable|required_if:access,sale|date|after_or_equal:today', 'in_reports' => 'nullable|boolean']);
        $set->forceFill(['access' => $data['access'], 'sale_access_until' => $data['access'] === 'sale' ? $data['sale_access_until'] : null, 'in_reports' => (bool) ($data['in_reports'] ?? false)])->save();
        Audit::changes('radiographs.access_changed', $set);

        return back()->with('status', 'Access saved.');
    }

    public function decide(Request $request, Horse $horse, RadiographSet $set, RadiographAccess $access)
    {
        $this->manageable($request, $horse, $set);
        abort_unless($access->radiograph_set_id === $set->id, 404);
        $data = $request->validate(['decision' => 'required|in:approved,denied']);
        $access->forceFill(['status' => $data['decision'], 'decided_at' => now(), 'expires_at' => $data['decision'] === 'approved' ? now()->addDays(60) : null])->save();
        Audit::record('radiographs.access_'.$data['decision'], $access, null, ['user_id' => $access->user_id], $horse->id);
        try {
            \Illuminate\Support\Facades\Mail::raw($data['decision'] === 'approved'
                ? "The owner of {$horse->displayName()} approved your request to view its radiographs:\n".route('radiographs.view', [$horse, $set])
                : "The owner of {$horse->displayName()} declined your request to view its radiographs.", fn ($m) => $m->to($access->user->email)->subject('Radiographs for '.$horse->displayName()));
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('status', $data['decision'] === 'approved' ? 'Approved for 60 days.' : 'Declined.');
    }

    // ---- Buyer side

    private function link(Request $request, Horse $horse): ?ShareLink
    {
        $token = $request->query('r');
        $link = $token ? ShareLink::where('token', $token)->first() : null;

        return $link && $link->horse_id === $horse->id && $link->isActive() ? $link : null;
    }

    public function view(Request $request, Horse $horse, RadiographSet $set)
    {
        abort_if($set->horse_id !== $horse->id, 404);
        $link = $this->link($request, $horse);
        $user = $request->user();
        if (! $set->viewableBy($user, $link)) {
            abort_if($set->access === 'private' && ! $horse->canBeViewedPrivatelyBy($user), 404);
            if (! $user) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return view('radiographs.locked', ['horse' => $horse, 'set' => $set, 'mine' => $user ? $set->access()->where('user_id', $user->id)->first() : null]);
        }
        $set->log('viewed', $user, $link);

        return view('radiographs.view', ['horse' => $horse, 'set' => $set->load('files'), 'q' => $link ? ['r' => $link->token] : []]);
    }

    public function requestAccess(Request $request, Horse $horse, RadiographSet $set)
    {
        abort_unless($set->horse_id === $horse->id && $set->access === 'on_request', 404);
        $data = $request->validate(['message' => 'nullable|string|max:1000']);
        $a = RadiographAccess::firstOrCreate(['radiograph_set_id' => $set->id, 'user_id' => $request->user()->id], ['status' => 'requested', 'message' => $data['message'] ?? null]);
        if ($a->wasRecentlyCreated) {
            Audit::record('radiographs.access_requested', $a, null, [], $horse->id);
            foreach ($horse->activeStewards()->with('user')->get() as $s) {
                try {
                    \Illuminate\Support\Facades\Mail::raw("{$request->user()->name} asked to view the radiographs for {$horse->displayName()}.\n".($a->message ? "\n\"{$a->message}\"\n" : '')."\nApprove or decline: ".route('radiographs.manage', [$horse, $set]), fn ($m) => $m->to($s->user->email)->subject('Radiograph request for '.$horse->displayName()));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return back()->with('status', 'Request sent to the owner. You\'ll get an email when they decide.');
    }

    public function file(Request $request, Horse $horse, RadiographSet $set, RadiographFile $file)
    {
        abort_unless($set->horse_id === $horse->id && $file->radiograph_set_id === $set->id, 404);
        $link = $this->link($request, $horse);
        abort_unless($set->viewableBy($request->user(), $link), 403);
        $download = $request->boolean('download') || $file->kind === 'dicom';
        $set->log($download ? 'downloaded' : 'opened', $request->user(), $link, $file->id);
        $name = Str::slug($horse->displayName()).'-'.($set->taken_on?->format('Y-m-d') ?? 'radiograph').'-'.$file->position.'.'.pathinfo($file->path, PATHINFO_EXTENSION);

        return RadiographFile::disk()->response($file->path, $name, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store'], $download ? 'attachment' : 'inline');
    }
}
