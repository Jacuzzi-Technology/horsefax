<x-auth-card title="Create your account" lede="Then start your horse's record. Everything you add is private until you choose to share it.">
    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf
        <x-input name="name" label="Your name" required autocomplete="name" />
        <x-input name="email" type="email" label="Email" required autocomplete="email" />
        <x-input name="password" type="password" label="Password" required autocomplete="new-password" hint="At least 10 characters." />
        <x-input name="password_confirmation" type="password" label="Confirm password" required autocomplete="new-password" />
        <label class="flex items-start gap-2 font-sans text-[14px]"><input type="checkbox" name="terms" value="1" class="accent-gold mt-1" @checked(old('terms'))> <span>I am 18 or older and agree to the <a href="{{ route('terms') }}" target="_blank" class="text-gold-dark">Terms of Use</a> and <a href="{{ route('privacy') }}" target="_blank" class="text-gold-dark">Privacy Policy</a>.</span></label>
        @error('terms')<p class="error">{{ $message }}</p>@enderror
        <button class="btn-gold w-full">Create account</button>
        <p class="font-sans text-[13px] text-center">Already have an account? <a href="{{ route('login') }}" class="text-gold-dark">Sign in</a></p>
    </form>
</x-auth-card>
