<x-layouts.site title="Account" robots="noindex, nofollow">
    <div class="wrap py-12 max-w-[760px] space-y-8">
        <h1 class="text-[38px]">Account</h1>

        <section class="card p-6">
            <h2 class="text-[24px]">Profile</h2>
            <form method="POST" action="{{ route('user-profile-information.update') }}" class="mt-4 space-y-4">
                @csrf @method('PUT')
                <x-input name="name" label="Name" :value="$user->name" required />
                <x-input name="email" type="email" label="Email" :value="$user->email" required />
                @if ($errors->updateProfileInformation->any())<p class="error">{{ $errors->updateProfileInformation->first() }}</p>@endif
                <button class="btn-forest btn-small">Save profile</button>
            </form>
        </section>

        <section class="card p-6">
            <h2 class="text-[24px]">Identity</h2>
            @if ($user->identity_verified_at)
                <p class="mt-2 text-[17px]">Verified as {{ $user->identity_verified_name }} on {{ $user->identity_verified_at->format('M j, Y') }}. Never shown publicly.</p>
            @elseif (\App\Models\IdentityCheck::where('user_id', $user->id)->where('status', 'pending')->exists())
                <p class="mt-2 text-[17px]">Your ID is with Horsefax staff.</p>
            @else
                <p class="mt-2 text-[17px]">Verifying your identity strengthens ownership claims. It's checked by staff and never shown publicly.</p>
                <a href="{{ route('identity') }}" class="btn-forest btn-small mt-3">Verify your identity</a>
            @endif
        </section>

        <section class="card p-6">
            <h2 class="text-[24px]">Password</h2>
            <form method="POST" action="{{ route('user-password.update') }}" class="mt-4 space-y-4">
                @csrf @method('PUT')
                <x-input name="current_password" type="password" label="Current password" required autocomplete="current-password" />
                <x-input name="password" type="password" label="New password" required autocomplete="new-password" />
                <x-input name="password_confirmation" type="password" label="Confirm new password" required autocomplete="new-password" />
                @if ($errors->updatePassword->any())<p class="error">{{ $errors->updatePassword->first() }}</p>@endif
                <button class="btn-forest btn-small">Change password</button>
            </form>
        </section>

        <section class="card p-6">
            <h2 class="text-[24px]">Two-factor sign-in</h2>
            @if (! $user->two_factor_secret)
                <p class="mt-2 text-[17px]">Add a code from an authenticator app when you sign in.{{ $user->isStaff() ? ' Required for staff pages.' : '' }}</p>
                <form method="POST" action="{{ url('/user/two-factor-authentication') }}" class="mt-4">@csrf<button class="btn-forest btn-small">Turn on</button></form>
            @elseif (! $user->two_factor_confirmed_at)
                <p class="mt-2 text-[17px]">Scan this code with your authenticator app, then enter the 6-digit code to finish.</p>
                <div class="mt-4 bg-white p-4 inline-block">{!! $user->twoFactorQrCodeSvg() !!}</div>
                <form method="POST" action="{{ url('/user/confirmed-two-factor-authentication') }}" class="mt-4 flex gap-2 items-end">
                    @csrf
                    <x-input name="code" label="Code" inputmode="numeric" autocomplete="one-time-code" required />
                    <button class="btn-gold btn-small">Confirm</button>
                </form>
                @if ($errors->confirmTwoFactorAuthentication->any())<p class="error">{{ $errors->confirmTwoFactorAuthentication->first() }}</p>@endif
            @else
                <p class="mt-2 text-[17px]">Two-factor sign-in is on.</p>
                <details class="mt-3"><summary class="cursor-pointer font-sans text-[13px] text-gold-dark">Show recovery codes</summary>
                    <pre class="mt-2 bg-cream p-3 font-mono text-[13px]">{{ implode("\n", $user->recoveryCodes()) }}</pre>
                </details>
                <form method="POST" action="{{ url('/user/two-factor-authentication') }}" class="mt-4" data-confirm="Turn off two-factor sign-in?">@csrf @method('DELETE')<button class="btn-ghost btn-small">Turn off</button></form>
            @endif
        </section>

        <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn-ghost">Sign out</button></form>
    </div>
</x-layouts.site>
