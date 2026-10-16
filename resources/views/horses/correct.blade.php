<x-layouts.site title="Correct an entry" robots="noindex, nofollow">
    <div class="wrap py-12 max-w-[900px]">
        <a href="{{ route('horses.show', ['horse' => $horse, 'tab' => 'history']) }}" class="font-sans text-[13px] text-gold-dark">← History</a>
        <h1 class="text-[34px] mt-3">Correct "{{ $event->title }}"</h1>
        <p class="hint">Your correction becomes the current entry. The original is kept, marked superseded, in the record history.</p>
        @if ($errors->any())<p class="error mt-3">{{ $errors->first() }}</p>@endif
        <div class="mt-6">@include('horses.partials.event-form', ['horse' => $horse, 'event' => $event, 'action' => route('record.events.correct.store', [$horse, $event]), 'button' => 'Save correction'])</div>
    </div>
</x-layouts.site>
