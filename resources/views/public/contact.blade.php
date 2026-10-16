<x-layouts.site title="Contact" description="Contact the Horsefax team.">
    <x-page-header eyebrow="Contact" title="Talk to the Horsefax team." />
    <div class="wrap py-14 max-w-[720px]">
        <form method="POST" action="{{ route('contact.send') }}" class="card p-6 sm:p-8 space-y-5">
            @csrf
            <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off">
            <div class="grid sm:grid-cols-2 gap-5">
                <x-input name="name" label="Name" required />
                <x-input name="email" type="email" label="Email" required />
            </div>
            <div>
                <label class="field-label" for="topic">Topic</label>
                <select id="topic" name="topic" class="field">
                    @foreach (['general' => 'General question', 'records' => 'Help with a record', 'corrections' => 'A correction or dispute', 'professionals' => 'Professional contributors', 'press' => 'Press or partnership', 'privacy' => 'Privacy request'] as $k => $v)
                        <option value="{{ $k }}" @selected(old('topic') === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="message">Message</label>
                <textarea id="message" name="message" rows="6" class="field" required>{{ old('message') }}</textarea>
                @error('message')<p class="error">{{ $message }}</p>@enderror
            </div>
            <button class="btn-gold">Send message</button>
        </form>
    </div>
</x-layouts.site>
