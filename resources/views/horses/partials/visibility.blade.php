@php $field = $field ?? 'visibility'; $current = $item->{$field}; @endphp
@if ($canEdit)
    <form method="POST" action="{{ route('record.visibility', $horse) }}" class="inline-block">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="id" value="{{ $item->id }}">
        <label class="sr-only" for="vis-{{ $type }}-{{ $item->id }}">Who can see this</label>
        <select id="vis-{{ $type }}-{{ $item->id }}" name="visibility" data-autosubmit class="font-sans text-[11px] font-semibold tracking-[0.06em] uppercase border border-charcoal/15 rounded-full bg-cream px-2 py-1 cursor-pointer">
            @foreach (['private' => 'Private', 'shared' => 'Shared', 'public' => 'Public'] as $k => $l)<option value="{{ $k }}" @selected($current === $k)>{{ $l }}</option>@endforeach
        </select>
        <noscript><button class="font-sans text-[11px]">Save</button></noscript>
    </form>
@else
    <x-visibility :value="$current" />
@endif
