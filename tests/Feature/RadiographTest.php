<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Models\RadiographAccess;
use App\Models\RadiographSet;
use App\Models\RadiographView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Radiograph sets: owner uploads; buyers view on request, during sale access, or not at all; every view logged. */
class RadiographTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_uploads_and_buyers_see_them_only_as_the_owner_allows(): void
    {
        Storage::fake('local');
        Mail::fake();
        $owner = User::factory()->create();
        $h = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Rads', 'birth_precision' => 'unknown']);
        $h->forceFill(['public_summary' => true, 'listed' => true])->save();

        $this->actingAs($owner)->post(route('radiographs.store', $h), ['taken_on' => '2026-03-02', 'purpose' => 'pre_purchase', 'clinic' => 'Sample Equine Clinic', 'veterinarian' => 'Dr. Sample'])->assertRedirect();
        $set = RadiographSet::sole();
        $up = fn ($file) => $this->actingAs($owner)->post(route('radiographs.upload', [$h, $set]), ['file' => $file], ['Accept' => 'application/json']);
        $up(UploadedFile::fake()->image('front-feet.jpg', 800, 600))->assertOk()->assertJson(['kind' => 'image']);
        $dicom = UploadedFile::fake()->createWithContent('scan.dcm', str_repeat("\0", 128).'DICM'.str_repeat("\0", 64));
        $up($dicom)->assertOk()->assertJson(['kind' => 'dicom']);
        $up(UploadedFile::fake()->createWithContent('notes.txt', 'hello'))->assertStatus(422);
        $this->assertSame(2, $set->files()->count());
        $image = $set->files()->where('kind', 'image')->first();

        $buyer = User::factory()->create(['name' => 'Sample Buyer']);
        // Private: not even listed.
        $this->actingAs($buyer)->get(route('radiographs.view', [$h, $set]))->assertNotFound();
        $this->actingAs($buyer)->get(route('horse.public', $h))->assertDontSee('Radiographs · ');

        // On request: the buyer asks, the owner approves, the buyer views; the view is logged.
        $this->actingAs($owner)->post(route('radiographs.access', [$h, $set]), ['access' => 'on_request']);
        $this->actingAs($buyer)->get(route('horse.public', $h))->assertSee('Request access');
        $this->actingAs($buyer)->get(route('radiographs.view', [$h, $set]))->assertOk()->assertSee('Request access');
        $this->actingAs($buyer)->get(route('radiographs.file', [$h, $set, $image]))->assertForbidden();
        $this->actingAs($buyer)->post(route('radiographs.request', [$h, $set]), ['message' => 'My vet would like to see them.']);
        $req = RadiographAccess::sole();
        $this->actingAs($owner)->post(route('radiographs.decide', [$h, $set, $req]), ['decision' => 'approved']);
        $this->actingAs($buyer)->get(route('radiographs.view', [$h, $set]))->assertOk()->assertSee('Dr. Sample, Sample Equine Clinic');
        $this->actingAs($buyer)->get(route('radiographs.file', [$h, $set, $image]))->assertOk();
        $this->assertSame(['viewed', 'opened'], RadiographView::orderBy('id')->pluck('action')->all());
        $this->actingAs($owner)->get(route('radiographs.manage', [$h, $set]))->assertOk()->assertSee('Sample Buyer');

        // Sale access: any signed-in buyer until the date; signed-out visitors are asked to sign in.
        $this->actingAs($owner)->post(route('radiographs.access', [$h, $set]), ['access' => 'sale', 'sale_access_until' => now()->addDays(10)->toDateString()]);
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('radiographs.view', [$h, $set]))->assertOk()->assertSee('Open image 1');
        auth()->logout();
        $this->get(route('radiographs.view', [$h, $set]))->assertOk()->assertSee('Create a free account');
        $this->get(route('radiographs.file', [$h, $set, $image]))->assertForbidden();
    }
}
