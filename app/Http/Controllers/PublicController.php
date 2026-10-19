<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\HorseIdentifier;
use App\Models\ReviewItem;
use App\Models\ShareLink;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\Record;
use App\Support\Views;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicController extends Controller
{
    public function home()
    {
        // The size of the database, refreshed hourly (counting millions of rows on every visit would be slow).
        $stats = \Illuminate\Support\Facades\Cache::remember('home.stats', 3600, fn () => [
            'horses' => Horse::whereNull('merged_into_id')->count(),
            'results' => \App\Models\ShowAppearance::count(),
            'sales' => \App\Models\SaleLot::whereNotIn('status', ['catalog', 'withdrawn'])->count(),
        ]);

        return view('public.home', ['stats' => $stats]);
    }

    public function page(string $page)
    {
        return view('public.'.$page);
    }

    public function sample()
    {
        return view('public.horse', \App\Support\SampleReport::data());
    }

    /**
     * One-click PDFs: the same page the reader may already see, rendered to a file (App\Support\ReportPdf). Signed-out
     * visitors only ever get the summary on screen, so their PDF request sends them to sign in.
     */
    public function horsePdf(Request $request, Horse $horse)
    {
        if (! $request->user()) {
            $request->session()->put('url.intended', route('horse.public', $horse));

            return redirect()->route('login');
        }

        return $this->pdf($this->horse($request, $horse), $horse, $request);
    }

    public function reportPdf(Request $request, string $token)
    {
        $link = ShareLink::where('token', $token)->first();
        abort_unless($link && $link->isActive(), 404);

        return $this->pdf($this->report($token), $link->horse, $request);
    }

    public function samplePdf(Request $request)
    {
        $data = \App\Support\SampleReport::data();

        return $this->pdf(view('public.horse', $data), $data['horse'], $request);
    }

    private function pdf($view, Horse $horse, Request $request)
    {
        if (! $view instanceof \Illuminate\View\View || $view->name() !== 'public.horse') {
            return $view; // a redirect, the summary or an error page: nothing to print
        }
        abort_unless(\App\Support\ReportPdf::available(), 503, 'PDF downloads are not available right now. Use Print instead.');
        $pdf = \App\Support\ReportPdf::fromHtml($view->with('pdfMode', true)->render());
        abort_unless($pdf, 503, 'The PDF could not be made right now. Use Print instead.');
        \App\Support\Audit::record('record.pdf_downloaded', $horse, null, ['view' => $request->query('view') === 'timeline' ? 'timeline' : 'report'], $horse->id ?? null);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.\App\Support\ReportPdf::filename($horse, $request->query('view') === 'timeline').'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * As-you-type suggestions under the search box: up to 8 searchable horses whose registered or barn name contains
     * the text, names starting with it first. Only what search already shows (name, foal year, breed, sire, years shown).
     */
    public function suggest(Request $request)
    {
        $q = mb_strtolower(trim(mb_substr((string) $request->query('q'), 0, 60)));
        if (mb_strlen($q) < 3) {
            return response()->json([]); // three letters before suggesting: two match too many of millions of names
        }
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $horses = Horse::where(fn ($w) => $w->where('listed', true)->orWhere('public_summary', true))
            ->where(fn ($w) => $w->whereRaw('lower(registered_name) like ?', ['%'.$like.'%'])->orWhereRaw('lower(barn_name) like ?', ['%'.$like.'%']))
            ->orderByRaw('(lower(registered_name) like ?) desc, (lower(registered_name) like ?) desc, length(registered_name), registered_name', [$like.'%', '% '.$like.'%'])
            ->limit(8)->get(['id', 'horsefax_id', 'registered_name', 'barn_name', 'breed', 'birth_year', 'birth_precision', 'birth_date']);
        $sires = \App\Models\PedigreeEntry::whereIn('horse_id', $horses->pluck('id'))->where('position', 's')->where('visibility', 'public')
            ->whereNull('superseded_by')->pluck('name', 'horse_id');
        // Years a horse was shown, from published results attached to it: tells same-named horses apart.
        $shown = \App\Models\ShowRecord::whereIn('horse_id', $horses->pluck('id'))
            ->selectRaw('horse_id, min(first_year) a, max(last_year) b')->groupBy('horse_id')->get()->keyBy('horse_id');

        return response()->json($horses->map(fn ($h) => [
            'name' => $h->displayName(),
            'detail' => collect([
                $h->birth_year ? (string) $h->birth_year : null, $h->breed, isset($sires[$h->id]) ? 'by '.$sires[$h->id] : null,
                ($y = $shown[$h->id] ?? null) && $y->a ? 'shown '.($y->a == $y->b ? $y->a : $y->a.'–'.$y->b) : null,
            ])->filter()->join(' · '),
            'url' => route('horse.public', $h),
        ])->values());
    }

    /** Narrowing search: see App\Support\HorseSearch. */
    public function search(Request $request)
    {
        $c = \App\Support\HorseSearch::criteria($request->query() + ['name' => $request->query('q')]);
        if (isset($c['name']) && ($direct = \App\Support\HorseSearch::byHorsefaxId($c['name']))) {
            return redirect()->route('horse.public', $direct);
        }
        // One box for everything (Richard, Oct 19 2026): a registration or microchip number typed where the name goes
        // finds the horse by that number (exact match only); if no horse has it, the text is searched as a name.
        if (isset($c['name']) && count($c) === 1 && strlen(preg_replace('/\D/', '', $c['name'])) >= 5) {
            $number = \App\Support\Record::normalizeIdentifier($c['name']);
            $ids = strlen($number) >= 5 && strlen($number) <= 23 ? \App\Models\HorseIdentifier::whereIn('kind', ['registration', 'microchip'])->whereNull('superseded_by')
                ->where('value_normalized', $number)->pluck('horse_id')->unique() : collect();
            $found = $ids->isEmpty() ? collect() : Horse::whereIn('id', $ids)->whereNull('merged_into_id')->where(fn ($w) => $w->where('listed', true)->orWhere('public_summary', true))->get();
            if ($found->count() === 1) {
                return redirect()->route('horse.public', $found->first());
            }
            if ($found->count() > 1) {
                return view('public.search', ['c' => $c, 'count' => $found->count(), 'results' => $found->load('pedigree'), 'started' => true, 'showRecords' => collect()]);
            }
        }
        $count = 0;
        $results = collect();
        if (\App\Support\HorseSearch::hasStart($c)) {
            $query = \App\Support\HorseSearch::query($c);
            // Counting stops at 1,001: past that, "over 1,000" is all a person needs to know to add details.
            $count = DB::query()->fromSub((clone $query)->select('horses.id')->limit(1001), 'c')->count();
            if ($count <= \App\Support\HorseSearch::MAX_LIST) {
                $results = $query->with('pedigree')->orderBy('registered_name')->orderBy('barn_name')->get();
            }
        }

        // Names found in published show results, not yet matched to a horse record.
        $showRecords = collect();
        if (isset($c['name']) && mb_strlen($c['name']) >= 3) {
            $showRecords = \App\Models\ShowRecord::whereNull('horse_id')
                ->where('name_key', 'like', '%'.\App\Models\ShowRecord::key($c['name']).'%')
                ->orderByRaw('CASE WHEN name_key = ? THEN 0 ELSE 1 END', [\App\Models\ShowRecord::key($c['name'])])
                ->orderByDesc('appearances')->limit(10)->get();
        }

        return view('public.search', ['c' => $c, 'count' => $count, 'results' => $results, 'started' => \App\Support\HorseSearch::hasStart($c), 'showRecords' => $showRecords]);
    }

    public function horse(Request $request, Horse $horse)
    {
        if ($horse->merged_into_id && ($kept = Horse::find($horse->merged_into_id))) {
            return redirect()->route('horse.public', $kept, 301); // a misspelled duplicate, merged by staff
        }
        $preview = ! $horse->public_summary;
        if ($preview && ! $horse->canBeViewedPrivatelyBy($request->user())) {
            abort_unless($horse->listed, 404);

            // Listed but not published: basic identity only, and a way for the owner to claim it.
            return view('public.listing', ['horse' => $horse]);
        }

        // Signed-out visitors see a summary and an invitation to a free account (Richard, Oct 18 2026); after signing
        // up or in they come back to this record.
        if (! $request->user()) {
            $request->session()->put('url.intended', route('horse.public', $horse));
            $events = Views::events($horse, 'public');

            return view('public.summary', ['horse' => $horse, 'counts' => [
                'results' => $events->where('category', 'competition')->count(),
                'sales' => $events->where('category', 'ownership')->count(),
                'pedigree' => Views::pedigree($horse, 'public')->isNotEmpty(),
                'photos' => ($media = Views::media($horse, 'public'))->where('kind', 'photo')->count(),
                'videos' => $media->whereIn('kind', ['video', 'video_link'])->count(),
                'radiographs' => \App\Models\RadiographSet::where('horse_id', $horse->id)->where('access', '!=', 'private')->has('files')->count(),
            ]]);
        }

        return view('public.horse', [
            'horse' => $horse,
            'preview' => $preview,
            'audience' => 'public',
            'link' => null,
            'identifiers' => Views::identifiers($horse, 'public'),
            'events' => Views::events($horse, 'public'),
            'documents' => Views::documents($horse, 'public'),
            'names' => Views::names($horse, 'public'),
            'ownership' => collect(),
            'photo' => Views::photo($horse, 'public'),
            'pedigree' => Views::pedigree($horse, 'public'),
            'offspring' => Views::offspring($horse, 'public'),
            'media' => Views::media($horse, 'public'),
            'facts' => Views::facts($horse),
        ]);
    }

    public function report(string $token)
    {
        $link = ShareLink::where('token', $token)->first();
        if (! $link || ! $link->isActive()) {
            return response()->view('public.report-gone', [], $link ? 410 : 404);
        }
        DB::table('share_links')->where('id', $link->id)->update(['view_count' => DB::raw('view_count + 1'), 'last_viewed_at' => now()]);
        $horse = $link->horse;

        return view('public.horse', [
            'horse' => $horse,
            'preview' => false,
            'audience' => 'report',
            'link' => $link,
            'identifiers' => Views::identifiers($horse, 'report', $link),
            'events' => Views::events($horse, 'report', $link),
            'documents' => Views::documents($horse, 'report', $link),
            'names' => Views::names($horse, 'report'),
            'ownership' => Views::ownershipHistory($horse, 'report', $link),
            'photo' => Views::photo($horse, 'report'),
            'pedigree' => Views::pedigree($horse, 'report', $link),
            'offspring' => Views::offspring($horse, 'report', $link),
            'media' => Views::media($horse, 'report', $link),
            'facts' => Views::facts($horse),
        ]);
    }

    public function contact()
    {
        return view('public.contact');
    }

    public function sendContact(Request $request)
    {
        if ($request->filled('website')) { // honeypot
            return back()->with('status', 'Thank you. We will be in touch.');
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:200',
            'topic' => 'required|in:general,records,corrections,professionals,press,privacy',
            'message' => 'required|string|max:5000',
        ]);
        $message = ContactMessage::create($data);
        Notify::staff('New Horsefax contact message ('.$data['topic'].')', "From: {$data['name']} <{$data['email']}>\n\n{$data['message']}");

        return redirect()->route('contact')->with('status', 'Thank you. Your message reached the Horsefax team (reference #'.$message->id.').');
    }

    /**
     * Partnership inquiries from the Partners page: into the same staff inbox as contact messages, with the
     * organization's details kept alongside.
     */
    public function sendPartnerInquiry(Request $request)
    {
        if ($request->filled('website_url')) { // honeypot
            return redirect()->route('partners')->with('status', 'Thank you. We will be in touch.');
        }
        $data = $request->validate([
            'organization' => 'required|string|max:200',
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:200',
            'website' => 'nullable|string|max:300',
            'interest' => 'required|in:'.implode(',', array_keys(self::PARTNER_INTERESTS)),
            'record_count' => 'nullable|string|max:60',
            'message' => 'required|string|max:5000',
        ]);
        $details = array_filter([
            'organization' => $data['organization'],
            'website' => $data['website'] ?? null,
            'interest' => self::PARTNER_INTERESTS[$data['interest']],
            'records' => $data['record_count'] ?? null,
        ]);
        $message = ContactMessage::create([
            'name' => $data['name'], 'email' => $data['email'], 'topic' => 'partnership',
            'message' => $data['message'], 'details' => $details,
        ]);
        Notify::staff('New Horsefax partnership inquiry: '.$data['organization'],
            collect($details)->map(fn ($v, $k) => ucfirst($k).': '.$v)->join("\n")."\nFrom: {$data['name']} <{$data['email']}>\n\n{$data['message']}");

        return redirect()->route('partners')->with('status', 'Thank you. Your inquiry reached the Horsefax team (reference #'.$message->id.').');
    }

    public const PARTNER_INTERESTS = [
        'records' => 'Contribute historical records',
        'reports' => 'Offer Horsefax reports to customers',
        'both' => 'Both / integrated partnership',
        'unsure' => 'Not sure yet',
    ];

    /** Anyone can ask for a correction. It goes to staff; nothing on the record changes until they rule on it. */
    public function reportCorrection(Request $request, Horse $horse)
    {
        if ($request->filled('website')) {
            return back()->with('status', 'Thank you. Your request was received.');
        }
        $data = $request->validate([
            'name' => $request->user() ? 'nullable' : 'required|string|max:120',
            'email' => $request->user() ? 'nullable' : 'required|email|max:200',
            'subject' => 'nullable|string|max:80',
            'statement' => 'required|string|min:10|max:5000',
        ]);

        [$type, $id] = array_pad(explode(':', (string) ($data['subject'] ?? '')), 2, null);
        $subjectType = null;
        $subjectId = null;
        if ($type === 'event' && HorseEvent::where('horse_id', $horse->id)->whereKey($id)->exists()) {
            [$subjectType, $subjectId] = ['HorseEvent', $id];
        } elseif ($type === 'identifier' && HorseIdentifier::where('horse_id', $horse->id)->whereKey($id)->exists()) {
            [$subjectType, $subjectId] = ['HorseIdentifier', $id];
        }

        $item = ReviewItem::create([
            'kind' => 'dispute',
            'horse_id' => $horse->id,
            'submitted_by' => $request->user()?->id,
            'submitter_name' => $data['name'] ?? $request->user()?->name,
            'submitter_email' => $data['email'] ?? $request->user()?->email,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'statement' => $data['statement'],
        ]);
        Audit::record('dispute.submitted', $item, null, ['subject_type' => $subjectType, 'subject_id' => $subjectId], $horse->id);
        Notify::staff('Horsefax correction request for '.$horse->horsefax_id, $data['statement']);

        return back()->with('status', 'Thank you. Your correction request is with the Horsefax team. Nothing on the record changes until it has been reviewed.');
    }

    public function robots()
    {
        $body = app()->isProduction()
            ? "# Horsefax information may not be scraped, data-mined or compiled into a database without\n"
                ."# written permission (see ".url('/terms')."). Partners: ask us about the data license API.\n\n"
                ."# AI training crawlers: not permitted\n"
                ."User-agent: GPTBot\nUser-agent: ClaudeBot\nUser-agent: CCBot\nUser-agent: Google-Extended\nUser-agent: Applebot-Extended\n"
                ."User-agent: Bytespider\nUser-agent: meta-externalagent\nUser-agent: Amazonbot\nUser-agent: cohere-training-data-crawler\nDisallow: /\n\n"
                ."# Bulk SEO/data crawlers\n"
                ."User-agent: SemrushBot\nUser-agent: AhrefsBot\nUser-agent: MJ12bot\nUser-agent: DotBot\nUser-agent: PetalBot\nDisallow: /\n\n"
                ."User-agent: *\nDisallow: /report/\nDisallow: /files/\nDisallow: /dashboard\nDisallow: /horses/\nDisallow: /admin\nDisallow: /account\nDisallow: /search\nDisallow: /api/\n\nSitemap: ".url('/sitemap.xml')."\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }

    public function sitemap()
    {
        $pages = ['/', '/how-it-works', '/for-owners', '/for-professionals', '/partners', '/sample-report', '/about', '/contact', '/record-corrections', '/privacy', '/terms'];
        $horses = Horse::where('public_summary', true)->orderBy('updated_at', 'desc')->limit(5000)->get(['horsefax_id', 'updated_at']);
        $showRecords = \App\Models\ShowRecord::whereNull('horse_id')->orderByDesc('appearances')->limit(40000)->get(['id', 'updated_at']);

        return response()->view('public.sitemap', compact('pages', 'horses', 'showRecords'), 200, ['Content-Type' => 'application/xml']);
    }
}
