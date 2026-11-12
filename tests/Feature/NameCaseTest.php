<?php

namespace Tests\Feature;

use App\Harvest\NrchaHarvester;
use App\Models\Horse;
use App\Support\NameCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Initials in registered names stay in capitals; European particles stay as written (all names fictional). */
class NameCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_caps_names_keep_their_initials(): void
    {
        $this->assertSame('E.L. Sample Tremor', NrchaHarvester::display('E.L. SAMPLE TREMOR'));
        $this->assertSame("J.D.'s Sample Marlene", NrchaHarvester::display("J.D.'S SAMPLE MARLENE"));
        $this->assertSame("BP's Sample Megan", NrchaHarvester::display("BP'S SAMPLE MEGAN"));
        $this->assertSame('JM Sample Chex', NrchaHarvester::display('JM SAMPLE CHEX'));
        $this->assertSame("Smith's Sample Bar", NrchaHarvester::display("SMITH'S SAMPLE BAR"));
        $this->assertSame('Mr Sample Gun', NrchaHarvester::display('MR SAMPLE GUN'));
        $this->assertSame('Already Mixed Case', NrchaHarvester::display('Already Mixed Case'));
    }

    public function test_repair_rules(): void
    {
        $this->assertSame('E.L. Tremor', NameCase::repair('E.l. Tremor'));
        $this->assertSame("J.D.'s Marlene", NameCase::repair("J.d.'s Marlene"));
        $this->assertSame("BP's Megan", NameCase::repair("Bp's Megan"));
        $this->assertSame('Kickin J.D. Bar', NameCase::repair('Kickin J.d. Bar'));
        $this->assertSame("Smith's Bar", NameCase::repair("Smith's Bar"));
        $this->assertSame("Mr's Pick", NameCase::repair("Mr's Pick"));
        // European register: initials only at the start or before 's; particles never.
        $this->assertSame('R.F. Illuminata', NameCase::repair('R.f. Illuminata', false, false));
        $this->assertSame("E.K.P.'s Krista", NameCase::repair("E.k.p.'s Krista", false, false));
        $this->assertSame('Miesje V.d. Ellekamp', NameCase::repair('Miesje V.d. Ellekamp', false, false));
        $this->assertSame('Jolet V.d.buitenweg', NameCase::repair('Jolet V.d.buitenweg', false, false));
        $this->assertSame('Iagonom Du Marais E.d.', NameCase::repair('Iagonom Du Marais E.d.', false, false));
    }

    public function test_command_renames_imported_records_but_not_owner_ones(): void
    {
        $by = \App\Models\User::factory()->create()->id;
        $mk = fn ($id, $name, $source, $status) => Horse::forceCreate(['horsefax_id' => $id, 'registered_name' => $name, 'birth_precision' => 'unknown', 'source' => $source, 'identity_status' => $status, 'created_by' => $by]);
        $imported = $mk('HF-TEST-0001', 'E.l. Sample Tremor', 'published', 'unverified');
        $european = $mk('HF-TEST-0002', 'Sample V.d. Hoeve', 'ifce', 'unverified');
        $verified = $mk('HF-TEST-0003', 'J.d. Sample Verified', 'published', 'verified');

        $this->artisan('hf:fix-name-casing', ['--dry-run' => true])->expectsOutputToContain('"horses_renamed":1')->assertSuccessful();
        $this->assertSame('E.l. Sample Tremor', $imported->fresh()->registered_name); // dry run changes nothing

        $this->artisan('hf:fix-name-casing')->expectsOutputToContain('"skipped_owner_or_verified":1')->assertSuccessful();
        $this->assertSame('E.L. Sample Tremor', $imported->fresh()->registered_name);
        $this->assertSame('Sample V.d. Hoeve', $european->fresh()->registered_name);
        $this->assertSame('J.d. Sample Verified', $verified->fresh()->registered_name);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('audit_log')->where('action', 'horse.renamed_casing')->count());
        $this->artisan('hf:fix-name-casing')->expectsOutputToContain('"horses_renamed":0')->assertSuccessful(); // idempotent
    }
}
