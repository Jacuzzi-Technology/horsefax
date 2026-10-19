<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\ImportHomdHorse;
use App\Models\Horse;
use App\Models\PedigreeEntry;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Horse of My Dreams listings into Horsefax records (fictional horses). */
class HomdImportTest extends TestCase
{
    use RefreshDatabase;

    private function listing(array $over = []): array
    {
        $fields = array_fill_keys(array_values(ImportHomdHorse::PEDIGREE), '');

        return array_merge($fields, [
            'id' => 1, 'barn_name' => 'Frankie', 'url' => 'https://www.horseofmydreams.com/horses/sample-frankie/', 'listed_on' => '2026-04-10',
            'registered_name' => 'SAMPLE CUSTOMS', 'horse-year-foaled' => '2022', 'horse-height' => '15.0h', 'horse-color' => 'Buckskin', 'horse-breed' => 'AQHA',
            'horse-gender' => 'Gelding', 'horse-price' => '65,000', 'price-display' => 'Show Price', 'horse-video' => 'https://youtu.be/abcdefghijk',
            'horse-description' => 'Sample description of a finished reiner.', 'also-registered-as' => '',
            'parent-a' => 'SAMPLE SPOOKS', 'parent-b' => 'SAMPLE JEWELS', 'grandparent-a' => 'SAMPLE GUN',
            'categories' => ['SOLD - Western Horses'], 'breeds' => ['AQHA - Quarter Horse'],
            'photos' => ['https://www.horseofmydreams.com/wp-content/uploads/sample-1.jpg'],
        ], $over);
    }

    private function jpeg(): string
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($img);

        return ob_get_clean();
    }

    public function test_a_listing_fills_the_matching_record_with_pedigree_listing_photos_and_video(): void
    {
        Http::fake(['*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
        $system = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => 'Sample Customs', 'birth_precision' => 'unknown']);
        $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $h->created_by = $system->id;
        $h->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true])->save();
        // A same-named horse with a different sire is never touched.
        $other = new Horse(['registered_name' => 'Sample Customs', 'birth_precision' => 'unknown']);
        $other->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $other->created_by = $system->id;
        $other->forceFill(['source' => 'results'])->save();
        PedigreeEntry::create(['horse_id' => $other->id, 'position' => 's', 'name' => 'Sample Someone Else', 'visibility' => 'public', 'added_by' => $system->id, 'source_type' => 'historical']);

        $this->assertSame('updated', app(ImportHomdHorse::class)($this->listing(), true, 0));
        $h->refresh();
        $this->assertSame(['gelding', 'Buckskin', 'Quarter Horse', 2022, 'Frankie'], [$h->sex, $h->color, $h->breed, $h->birth_year, $h->barn_name]);
        $this->assertSame(15.0, (float) $h->height_hands);
        $this->assertSame(['d' => 'Sample Jewels', 's' => 'Sample Spooks', 'ss' => 'Sample Gun'], $h->pedigree()->orderBy('position')->pluck('name', 'position')->all());
        $this->assertSame(0, $other->events()->count());
        $this->assertSame(2, $h->media()->where('visibility', 'public')->where('rights_attested', true)->count()); // photo + video
        $this->assertSame('photo-1.jpg', $h->media()->where('kind', 'photo')->first()->document->original_name);
        $this->assertSame('private', $h->events()->where('category', 'training')->value('visibility'));
        $lot = SaleLot::where('horse_id', $h->id)->sole();
        $this->assertSame(['asking', 6500000, 'held'], [$lot->status, $lot->price_cents, $lot->review_status]);

        $this->actingAs($this->reader())->get(route('horse.public', $h))->assertOk()->assertSee('Sold by Horse of My Dreams')->assertSee('See the listing')
            ->assertDontSee('Sample description')->assertDontSee('$65,000');

        // Rerun: nothing is added twice.
        $this->assertSame('skipped_done', app(ImportHomdHorse::class)($this->listing(), true, 0));
        $this->assertSame(2, $h->media()->count());

        // Once asking prices are published, the held price goes on the record with its label.
        config(['horsefax.published_sale_kinds' => ['reported_sale', 'asking']]);
        app(\App\Actions\Record\MatchSaleLots::class)();
        $this->assertSame('Advertised asking price — $65,000', $h->events()->where('result', 'asking')->sole()->priceLine());
    }

    public function test_a_listing_without_a_registered_name_becomes_a_barn_name_record(): void
    {
        Http::fake(['*' => Http::response($this->jpeg(), 200)]);
        $this->assertSame('created', app(ImportHomdHorse::class)($this->listing(['registered_name' => '', 'barn_name' => 'Skeeterbite aka "Bugsy"', 'url' => 'https://www.horseofmydreams.com/horses/x/']), false, 0));
        $h = Horse::where('barn_name', 'Skeeterbite')->sole();
        $this->assertNull($h->registered_name);
        $this->assertSame('published', $h->source);
        $this->assertSame(3, $h->pedigree()->count());
    }

    public function test_an_archived_listing_splits_the_nickname_and_reads_copied_photos(): void
    {
        $photo = tempnam(sys_get_temp_dir(), 'hf').'.jpg';
        file_put_contents($photo, $this->jpeg());
        $url = 'homd-archive://homd-2017/3348/sample-boston-bay';
        $this->assertSame('created', app(ImportHomdHorse::class)($this->listing([
            'registered_name' => 'Sample’s Boston Bay aka”Beijing”', 'barn_name' => '', 'url' => $url, 'photos' => [$photo],
            'horse-video' => '', 'horse-description' => "Description\r\n\r\nSample text.", 'parent-a' => '', 'parent-b' => '', 'grandparent-a' => '',
        ]), true, 0));
        $h = Horse::where('registered_name', "Sample's Boston Bay")->sole();
        $this->assertSame('Beijing', $h->barn_name);
        $this->assertSame(1, $h->media()->where('kind', 'photo')->count());
        $listing = $h->events()->where('category', 'ownership')->sole();
        $this->assertStringContainsString('archived Horse of My Dreams listing', $listing->details);
        $this->assertNull($listing->sourceUrl());
        $this->assertStringStartsWith('Sample text.', $h->events()->where('category', 'training')->value('details'));
    }
}
