<x-auth-card title="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-input name="email" type="email" label="Email" :value="$request->email" required />
        <x-input name="password" type="password" label="New password" required autocomplete="new-password" />
        <x-input name="password_confirmation" type="password" label="Confirm new password" required autocomplete="new-password" />
        <button class="btn-gold w-full">Save password</button>
    </form>
</x-auth-card>
