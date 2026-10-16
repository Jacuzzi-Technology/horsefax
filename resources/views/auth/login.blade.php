<x-auth-card title="Sign in">
    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf
        <x-input name="email" type="email" label="Email" required autofocus autocomplete="email" />
        <x-input name="password" type="password" label="Password" required autocomplete="current-password" />
        <label class="flex items-center gap-2 font-sans text-[14px]"><input type="checkbox" name="remember" class="accent-gold"> Keep me signed in</label>
        <button class="btn-gold w-full">Sign in</button>
        <div class="flex justify-between font-sans text-[13px]">
            <a href="{{ route('password.request') }}" class="text-gold-dark">Forgot password?</a>
            <a href="{{ route('register') }}" class="text-gold-dark">Create an account</a>
        </div>
    </form>
</x-auth-card>
