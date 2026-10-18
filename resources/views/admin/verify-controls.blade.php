{{-- Staff: verify one item (method, scope, recheck date) or withdraw its verification. --}}
@php use App\Support\Record; $isVerified = $type === 'horse' ? $item->identity_status === 'verified' : $item->status === 'verified'; @endphp
<details class="inline-block align-top">
    <summary class="cursor-pointer font-sans text-[12px] text-gold-dark">{{ $isVerified ? 'Re-verify / withdraw' : 'Verify' }}</summary>
    <div class="mt-2 p-3 border border-line rounded-[3px] bg-white w-[min(520px,85vw)] space-y-3">
        <form method="POST" action="{{ route('admin.verify', [$type, $item->getKey()]) }}" class="space-y-2">
            @csrf
            <label class="field-label">How it was checked</label>
            <select name="method" class="field text-[14px]">@foreach (Record::VERIFICATION_METHODS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <label class="field-label">What it covers (shown publicly)</label>
            <textarea name="scope" rows="3" class="field text-[14px]" required>{{ Record::defaultScope($item) }}</textarea>
            @if ($type !== 'horse')
                <label class="field-label">Source</label>
                <select name="source_type" class="field text-[14px]">@foreach (Record::SOURCES as $k => $l)<option value="{{ $k }}" @selected(($item->source_type ?? 'owner_reported') === $k)>{{ $l }}</option>@endforeach</select>
            @endif
            <div class="grid grid-cols-2 gap-2">
                <div><label class="field-label">Recheck by (optional)</label><input type="date" name="recheck_on" class="field text-[14px]"></div>
                <div><label class="field-label">Internal note</label><input name="note" class="field text-[14px]" placeholder="Source checked, reference"></div>
            </div>
            <button class="btn-forest btn-small">Mark Horsefax Verified</button>
        </form>
        @if ($isVerified)
            <form method="POST" action="{{ route('admin.withdraw', [$type, $item->getKey()]) }}" class="space-y-2 border-t border-line pt-3" data-confirm="Withdraw this verification?">
                @csrf
                <input name="reason" class="field text-[14px]" placeholder="Reason (kept in the log)" required>
                <select name="status" class="field text-[14px]"><option value="unverified">Back to not verified</option><option value="disputed">Mark disputed</option></select>
                <button class="btn-ghost btn-small">Withdraw verification</button>
            </form>
        @endif
    </div>
</details>
