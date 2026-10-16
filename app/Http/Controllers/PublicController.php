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
        return view('public.home');
    }

    public function page(string $page)
    {
        return view('public.'.$page);
    }

    public function sample()
    {
        return view('public.horse', \App\Support\SampleReport::data());
    }

    /** Narrowing search: see App\Support\HorseSearch. */
    public function search(Request $request)
    {
        $c = \App\Support\HorseSearch::criteria($request->query() + ['name' => $request->query('q')]);
        if (isset($c['name']) && ($direct = \App\Support\HorseSearch::byHorsefaxId($c['name']))) {
            return redirect()->route('horse.public', $direct);
        }
        $count = 0;
        $results = collect();
        if (\App\Support\HorseSearch::hasStart($c)) {
            $query = \App\Support\HorseSearch::query($c);
            $count = (clone $query)->count();
            if ($count <= \App\Support\HorseSearch::MAX_LIST) {
                $results = $query->with('pedigree')->orderBy('registered_name')->orderBy('barn_name')->get();
            }
        }

        return view('public.search', ['c' => $c, 'count' => $count, 'results' => $results, 'started' => \App\Support\HorseSearch::hasStart($c)]);
    }

    public function horse(Request $request, Horse $horse)
    {
        $preview = ! $horse->public_summary;
        if ($preview && ! $horse->canBeViewedPrivatelyBy($request->user())) {
            abort_unless($horse->listed, 404);

            // Listed but not published: basic identity only, and a way for the owner to claim it.
            return view('public.listing', ['horse' => $horse]);
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
            ? "User-agent: *\nDisallow: /report/\nDisallow: /files/\nDisallow: /dashboard\nDisallow: /horses/\nDisallow: /admin\nDisallow: /account\n\nSitemap: ".url('/sitemap.xml')."\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }

    public function sitemap()
    {
        $pages = ['/', '/how-it-works', '/for-owners', '/for-professionals', '/sample-report', '/about', '/contact', '/record-corrections', '/privacy', '/terms'];
        $horses = Horse::where('public_summary', true)->orderBy('updated_at', 'desc')->limit(5000)->get(['horsefax_id', 'updated_at']);

        return response()->view('public.sitemap', compact('pages', 'horses'), 200, ['Content-Type' => 'application/xml']);
    }
}
