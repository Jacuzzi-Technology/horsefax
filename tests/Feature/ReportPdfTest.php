<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Models\Horse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One-click PDF downloads: the page the reader may see, as a file; signed-out visitors are sent to sign in. */
class ReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private function fakeChrome(): string
    {
        // Stands in for headless Chrome: writes a tiny PDF to the --print-to-pdf path and keeps the HTML it was given.
        $dir = sys_get_temp_dir().'/hf-fake-chrome-'.uniqid();
        mkdir($dir);
        $script = $dir.'/chrome';
        file_put_contents($script, "#!/bin/sh\nfor a in \"\$@\"; do case \$a in --print-to-pdf=*) out=\${a#--print-to-pdf=};; file://*) src=\${a#file://};; esac; done\ncp \"\$src\" $dir/last.html\nprintf '%%PDF-1.4 fake' > \"\$out\"\n");
        chmod($script, 0755);
        config(['horsefax.chrome' => $script]);

        return $dir;
    }

    public function test_signed_in_readers_download_the_record_as_a_pdf(): void
    {
        $dir = $this->fakeChrome();
        $sys = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => 'Sample Download', 'birth_precision' => 'unknown']);
        $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $h->created_by = $sys->id;
        $h->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true])->save();

        $this->get(route('horse.pdf', $h))->assertRedirect(route('login'));

        $res = $this->actingAs($this->reader())->get(route('horse.pdf', $h).'?view=timeline')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('Horsefax-'.$h->horsefax_id.'-sample-download-life-timeline.pdf', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $html = file_get_contents($dir.'/last.html');
        $this->assertStringContainsString('<base href=', $html);
        $this->assertStringContainsString('Life timeline', $html);

        $this->get(route('sample.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->reader())->get(route('horse.public', $h))->assertSee('Download PDF')->assertSee(route('horse.pdf', $h));
    }

    public function test_without_chrome_the_download_says_to_use_print(): void
    {
        config(['horsefax.chrome' => '/nonexistent/chrome']);
        $this->get(route('sample.pdf'))->assertStatus(503);
    }
}
