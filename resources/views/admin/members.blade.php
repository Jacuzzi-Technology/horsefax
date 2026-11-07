@php use App\Support\Plans; @endphp
<x-layouts.site title="Staff: members" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        <h1 class="text-[30px]">Members</h1>
        <p class="font-sans text-[14px] mt-2">{{ number_format($stats['total']) }} accounts · {{ number_format($stats['week']) }} joined in the last 7 days · {{ number_format($stats['month']) }} in the last 30
            @foreach (Plans::all() as $k => $p) · {{ $p['name'] }}: {{ number_format($stats['plans'][$k] ?? 0) }}@endforeach</p>
        <p class="hint text-[13px] mt-1">Everyone is on Free; paid plans aren't on sale yet. Staff can put an account on a plan by hand from its page (a comp or a test).</p>
        @if (session('status'))<p class="mt-4 font-sans text-[14px] text-forest">{{ session('status') }}</p>@endif
        <form method="GET" class="flex flex-wrap gap-2 max-w-[720px] mt-5">
            <input name="q" value="{{ $q }}" class="field flex-1 min-w-[220px]" placeholder="Name or email">
            <select name="plan" class="field w-auto"><option value="">All plans</option>@foreach (Plans::all() as $k => $p)<option value="{{ $k }}" @selected($plan === $k)>{{ $p['name'] }}</option>@endforeach</select>
            <button class="btn-forest btn-small">Search</button>
        </form>
        <table class="table-hf mt-5">
            <thead><tr><th>Member</th><th>Joined</th><th>Last sign-in</th><th>Plan</th><th>Horses</th><th>Contributing to</th><th>Account</th></tr></thead>
            <tbody>
            @forelse ($members as $m)
                <tr>
                    <td><a href="{{ route('admin.member', $m) }}" class="text-ink">{{ $m->name }}</a><br><span class="font-sans text-[12px] text-muted">{{ $m->email }}</span></td>
                    <td class="font-sans text-[13px]">{{ $m->created_at->format('M j, Y') }}</td>
                    <td class="font-sans text-[13px]">{{ $m->last_login_at?->diffForHumans() ?? '—' }}</td>
                    <td class="font-sans text-[13px]">{{ Plans::name($m) }}{{ $m->plan_source === 'staff' ? ' (set by staff)' : '' }}</td>
                    <td class="font-sans text-[13px]">{{ $m->horses_count }}</td>
                    <td class="font-sans text-[13px]">{{ $m->contributing_count }}</td>
                    <td class="font-sans text-[12px]">{{ $m->email_verified_at ? 'Email verified' : 'Email not verified' }}{{ $m->two_factor_confirmed_at ? ' · 2FA' : '' }}{{ $m->is_staff ? ' · Staff' : '' }}{{ $m->standing !== 'good' ? ' · '.ucfirst($m->standing) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="hint">No members match.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-6">{{ $members->links() }}</div>
    </div>
</x-layouts.site>
