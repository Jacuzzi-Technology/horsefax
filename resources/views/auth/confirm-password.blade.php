<x-auth-card title="Confirm your password" lede="Please confirm your password to continue.">
    <form method="POST" action="{{ url('/user/confirm-password') }}" class="space-y-5">
        @csrf
        <x-input name="password" type="password" label="Password" required autocomplete="current-password" />
        <button class="btn-gold w-full">Confirm</button>
    </form>
</x-auth-card>
