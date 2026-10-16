<x-auth-card title="Two-factor sign-in" lede="Enter the 6-digit code from your authenticator app, or a recovery code.">
    <form method="POST" action="{{ url('/two-factor-challenge') }}" class="space-y-5">
        @csrf
        <x-input name="code" label="Authentication code" inputmode="numeric" autocomplete="one-time-code" autofocus />
        <x-input name="recovery_code" label="Or a recovery code" autocomplete="off" />
        <button class="btn-gold w-full">Continue</button>
    </form>
</x-auth-card>
