<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Real Google and Bing crawlers, told apart from scrapers that borrow their names (most "Googlebot" requests in the
 * server logs are vulnerability scanners). A request counts only when its user agent names the crawler AND its IP
 * reverse-resolves to the crawler's domain AND that name resolves back to the same IP (the check Google and Bing
 * document). Results are cached for a day per IP.
 */
class SearchBots
{
    private const AGENTS = '/Googlebot|Google-InspectionTool|GoogleOther|Storebot-Google|AdsBot-Google|bingbot|BingPreview/i';
    private const DOMAINS = ['.googlebot.com', '.google.com', '.googleusercontent.com', '.search.msn.com'];

    /** @var \Closure(string): array{host: ?string, ips: list<string>}|null resolver override for tests */
    public static ?\Closure $resolver = null;

    public static function verified(Request $r): bool
    {
        if (! preg_match(self::AGENTS, (string) $r->userAgent())) {
            return false;
        }
        $ip = (string) $r->ip();

        return Cache::remember('searchbot:'.$ip, now()->addDay(), function () use ($ip) {
            ['host' => $host, 'ips' => $ips] = self::$resolver ? (self::$resolver)($ip) : self::resolve($ip);
            if (! $host) {
                return false;
            }
            $host = strtolower(rtrim($host, '.'));
            foreach (self::DOMAINS as $d) {
                if (str_ends_with($host, $d)) {
                    return in_array($ip, $ips, true);
                }
            }

            return false;
        });
    }

    private static function resolve(string $ip): array
    {
        $host = @gethostbyaddr($ip);
        if (! $host || $host === $ip) {
            return ['host' => null, 'ips' => []];
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $rec) {
            $ips[] = $rec['ip'] ?? $rec['ipv6'] ?? null;
        }

        return ['host' => $host, 'ips' => array_values(array_filter($ips))];
    }
}
