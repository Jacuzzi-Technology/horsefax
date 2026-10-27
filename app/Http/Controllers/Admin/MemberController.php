<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Plans;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Staff: who has signed up, what they use, and their plan (Free for everyone until paid plans exist). */
class MemberController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $plan = array_key_exists((string) $request->query('plan'), Plans::all()) ? $request->query('plan') : null;
        $members = User::query()
            ->where('email', 'not like', '%.invalid') // the system account that owns imported records
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', '%'.$q.'%')->orWhere('email', 'ilike', '%'.$q.'%')))
            ->when($plan, fn ($w) => $w->where('plan', $plan))
            ->withCount([
                'stewardships as horses_count' => fn ($s) => $s->where('status', '!=', 'ended'),
                'contributions as contributing_count',
            ])
            ->orderByDesc('created_at')->paginate(50)->withQueryString();
        $base = User::where('email', 'not like', '%.invalid');
        $stats = [
            'total' => (clone $base)->count(),
            'week' => (clone $base)->where('created_at', '>=', now()->subDays(7))->count(),
            'month' => (clone $base)->where('created_at', '>=', now()->subDays(30))->count(),
            'plans' => (clone $base)->selectRaw('plan, count(*) n')->groupBy('plan')->pluck('n', 'plan'),
        ];

        return view('admin.members', compact('members', 'stats', 'q', 'plan'));
    }

    public function show(User $user)
    {
        return view('admin.member', [
            'member' => $user,
            'horses' => $user->horses()->orderBy('registered_name')->limit(200)->get(),
            'contributing' => $user->contributions()->with('horse')->get(),
            'activity' => DB::table('audit_log')->where('actor_id', $user->id)->orderByDesc('created_at')->limit(25)->get(),
        ]);
    }

    /** Staff put an account on a plan by hand (a comp or a test). No payment is involved. */
    public function plan(Request $request, User $user)
    {
        $data = $request->validate([
            'plan' => 'required|in:'.implode(',', array_keys(Plans::all())),
            'ends_on' => 'nullable|date|after:today',
            'note' => 'required|string|max:500',
        ]);
        $before = $user->only(['plan', 'plan_source', 'plan_ends_at']);
        $user->forceFill([
            'plan' => $data['plan'], 'plan_source' => $data['plan'] === config('plans.default') ? 'default' : 'staff',
            'plan_started_at' => now(), 'plan_ends_at' => $data['ends_on'] ?? null,
        ])->save();
        Audit::record('member.plan_changed', $user, $before, $user->only(['plan', 'plan_source', 'plan_ends_at']) + ['note' => $data['note']], null, $request->user()->id);

        return back()->with('status', $user->name.' is now on '.Plans::all()[$data['plan']]['name'].'.');
    }
}
