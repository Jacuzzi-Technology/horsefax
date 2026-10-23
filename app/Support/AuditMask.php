<?php

namespace App\Support;

use App\Models\DataSource;
use Illuminate\Support\Facades\DB;

/**
 * Richard, Oct 23 2026 (option B): the audit log is append-only, so entries that quote a source Horsefax may no longer use
 * are masked when shown, never edited. Masked: addresses on a retired (not_allowed) site, that site's name, and
 * "sale lot <id>" when the lot has since been deleted. The stored row is unchanged.
 */
class AuditMask
{
    public const WITHDRAWN = '[withdrawn source]';

    /** @var list<string> registrable domains of retired sites, e.g. premieronlinesales.com */
    private array $domains;

    /** @var list<string> */
    private array $names;

    /** @var array<string, bool> lot ids that still exist */
    private array $liveLots = [];

    public function __construct()
    {
        $retired = DataSource::where('permission', 'not_allowed')->get(['url', 'name']);
        $this->domains = $retired->map(function ($s) {
            $host = parse_url((string) $s->url, PHP_URL_HOST);

            return $host ? implode('.', array_slice(explode('.', strtolower($host)), -2)) : null;
        })->filter()->unique()->values()->all();
        $this->names = $retired->pluck('name')->filter()->sortByDesc(fn ($n) => mb_strlen($n))->values()->all();
    }

    /** Masks a page of audit rows (objects with before/after) in place and returns them. */
    public function rows(iterable $rows): iterable
    {
        $ids = [];
        foreach ($rows as $a) {
            foreach ([$a->before, $a->after] as $text) {
                preg_match_all('/sale lot ([0-9a-f-]{36})/i', (string) $text, $m);
                array_push($ids, ...$m[1]);
            }
        }
        if ($ids) {
            $this->liveLots = DB::table('sale_lots')->whereIn('id', array_unique($ids))->pluck('id')->flip()->map(fn () => true)->all();
        }
        foreach ($rows as $a) {
            $a->before = $this->text($a->before);
            $a->after = $this->text($a->after);
        }

        return $rows;
    }

    public function text(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return $json;
        }
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return $this->string($json);
        }
        $changed = false;
        array_walk_recursive($data, function (&$v) use (&$changed) {
            if (is_string($v) && ($masked = $this->string($v)) !== $v) {
                $v = $masked;
                $changed = true;
            }
        });

        return $changed ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $json;
    }

    private function string(string $v): string
    {
        foreach ($this->domains as $d) {
            $v = preg_replace('~https?://(?:[a-z0-9-]+\.)*'.preg_quote($d, '~').'\S*~i', self::WITHDRAWN, $v);
        }
        foreach ($this->names as $n) {
            $v = str_ireplace($n, self::WITHDRAWN, $v);
        }

        return preg_replace_callback('/sale lot ([0-9a-f-]{36})/i', fn ($m) => isset($this->liveLots[$m[1]]) ? $m[0] : 'sale lot '.self::WITHDRAWN, $v);
    }
}
