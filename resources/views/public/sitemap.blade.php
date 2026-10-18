{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($pages as $p)
    <url><loc>{{ url($p) }}</loc></url>
@endforeach
@foreach ($horses as $h)
    <url><loc>{{ route('horse.public', $h) }}</loc><lastmod>{{ $h->updated_at->toAtomString() }}</lastmod></url>
@endforeach
@foreach ($showRecords as $r)
    <url><loc>{{ route('show-records.show', $r) }}</loc><lastmod>{{ $r->updated_at->toAtomString() }}</lastmod></url>
@endforeach
</urlset>
