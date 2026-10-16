<x-auth-card title="Reset your password" lede="We'll email you a link to choose a new one.">
    @if (session('status'))<p class="font-sans text-[14px] text-forest mb-4">{{ session('status') }}</p>@endif
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf
        <x-input name="email" type="email" label="Email" required />
        <button class="btn-gold w-full">Email reset link</button>
    </form>
</x-auth-card>
