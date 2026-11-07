@php use App\Support\Plans; @endphp
<x-layouts.site title="Staff: member" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        <p class="font-sans text-[13px]"><a href="{{ route('admin.members') }}" class="text-gold-dark">← Members</a></p>
        <h1 class="text-[30px] mt-2">{{ $member->name }}</h1>
        <p class="font-sans text-[14px] mt-1">{{ $member->email }} · joined {{ $member->created_at->format('M j, Y') }} · last sign-in {{ $member->last_login_at?->format('M j, Y g:i a') ?? 'not recorded yet' }}</p>
        <p class="font-sans text-[13px] text-muted mt-1">{{ $member->email_verified_at ? 'Email verified' : 'Email not verified' }} · {{ $member->two_factor_confirmed_at ? 'Two-factor on' : 'Two-factor off' }}{{ $member->is_staff ? ' · Staff' : '' }} · standing: {{ $member->standing }}{{ $member->credential_role ? ' · verified '.$member->credential_role : '' }}</p>
        @if (session('status'))<p class="mt-4 font-sans text-[14px] text-forest">{{ session('status') }}</p>@endif
        @if ($errors->any())<p class="error mt-4">{{ $errors->first() }}</p>@endif

        <div class="grid lg:grid-cols-2 gap-6 mt-6">
            <div class="card p-5">
                <h2 class="text-[22px]">Plan</h2>
                <p class="mt-2 text-[17px]">{{ Plans::name($member) }}{{ $member->plan_source === 'staff' ? ', set by staff' : '' }}{{ $member->plan_ends_at ? ' until '.$member->plan_ends_at->format('M j, Y') : '' }}</p>
                <p class="hint text-[13px] mt-1">Includes: {{ collect(config('plans.plans.'.Plans::key($member).'.features'))->map(fn ($f) => config('plans.features.'.$f))->join('; ') }}.</p>
                <form method="POST" action="{{ route('admin.member.plan', $member) }}" class="mt-4 space-y-3">
                    @csrf
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div><label class="field-label" for="plan">Change plan (no payment)</label>
                            <select id="plan" name="plan" class="field">@foreach (Plans::all() as $k => $p)<option value="{{ $k }}" @selected($member->plan === $k)>{{ $p['name'] }}</option>@endforeach</select></div>
                        <div><label class="field-label" for="ends_on">Ends on (optional)</label><input id="ends_on" name="ends_on" type="date" class="field"></div>
                    </div>
                    <div><label class="field-label" for="note">Why (kept in the log)</label><input id="note" name="note" class="field" required placeholder="e.g. Comp for the AQHA pilot"></div>
                    <button class="btn-forest btn-small">Save plan</button>
                </form>
            </div>
            <div class="card p-5">
                <h2 class="text-[22px]">Horses ({{ $horses->count() }})</h2>
                <ul class="mt-2 font-sans text-[14px] space-y-1">
                    @forelse ($horses as $h)<li><a href="{{ route('admin.horse', $h) }}" class="text-gold-dark">{{ $h->displayName() }}</a> <span class="text-muted">{{ $h->horsefax_id }}</span></li>@empty<li class="hint">None.</li>@endforelse
                </ul>
                @if ($contributing->isNotEmpty())
                    <h3 class="text-[18px] mt-4">Contributing to</h3>
                    <ul class="mt-1 font-sans text-[14px] space-y-1">@foreach ($contributing as $c)<li>{{ $c->horse?->displayName() }} <span class="text-muted">as {{ $c->roleLabel() }}</span></li>@endforeach</ul>
                @endif
            </div>
        </div>

        <div class="card p-5 mt-6">
            <h2 class="text-[22px]">Recent activity</h2>
            <table class="table-hf mt-3">
                <tbody>@forelse ($activity as $a)<tr><td class="font-sans text-[13px] w-[180px]">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('M j, Y g:i a') }}</td><td class="font-sans text-[13px]">{{ str_replace(['.', '_'], [' · ', ' '], $a->action) }}</td></tr>@empty<tr><td class="hint">Nothing yet.</td></tr>@endforelse</tbody>
            </table>
        </div>
    </div>
</x-layouts.site>
