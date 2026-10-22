<?php
/**
 * Read-only export of the older Horse of My Dreams sites kept on SiteGround (Richard, Oct 18 2026), in the shape
 * hf:import-homd reads. Plain PHP + mysqli (the old WordPress can't load on the server's PHP):
 *   php homd-archive-export.php <wordpress dir> <label> [current-site-export.json] > archive.json
 * Horses already on the current site (same name and foal year) are left out. Leaves out "sold to", prices aren't
 * published anywhere, and photo captions, titles and filenames are never exported (paths only, for copying).
 */
[$_, $dir, $label] = $argv + [null, null, null];
$current = isset($argv[3]) ? json_decode(file_get_contents($argv[3]), true) : [];
$cfg = file_get_contents($dir.'/wp-config.php');
$c = fn ($k) => preg_match("/define\(\s*'$k'\s*,\s*'([^']*)'/", $cfg, $m) ? $m[1] : null;
preg_match('/\$table_prefix\s*=\s*\'([^\']+)\'/', $cfg, $p);
$t = $p[1] ?? 'wp_';
$db = new mysqli($c('DB_HOST') ?: 'localhost', $c('DB_USER'), $c('DB_PASSWORD'), $c('DB_NAME'));
$db->set_charset('utf8mb4');
$q = fn ($sql) => $db->query($sql)->fetch_all(MYSQLI_ASSOC);
$key = fn ($s) => preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $s));

$have = [];
foreach ($current as $h) {
    foreach ([$h['registered_name'], $h['barn_name']] as $n) {
        if ($n) {
            $have[$key($n).'|'.$h['horse-year-foaled']] = true;
        }
    }
}
$file = function (int $id) use ($q, $t) {
    $r = $q("select meta_value from {$t}postmeta where post_id=$id and meta_key='_wp_attached_file' limit 1");
    return $r[0]['meta_value'] ?? null;
};
$out = [];
foreach ($q("select ID, post_title, post_name, post_date, post_content from {$t}posts where post_type='horse' and post_status='publish' order by ID") as $p) {
    $meta = [];
    foreach ($q("select meta_key, meta_value from {$t}postmeta where post_id={$p['ID']}") as $m) {
        $meta[$m['meta_key']] ??= $m['meta_value'];
    }
    $name = trim(html_entity_decode($meta['horse_name'] ?? $p['post_title'], ENT_QUOTES));
    $year = trim((string) ($meta['year_foaled'] ?? ''));
    if (isset($have[$key($name).'|'.$year]) || isset($have[$key($p['post_title']).'|'.$year])) {
        continue;
    }
    $terms = array_column($q("select t.name from {$t}term_relationships r join {$t}term_taxonomy x on x.term_taxonomy_id=r.term_taxonomy_id join {$t}terms t on t.term_id=x.term_id where r.object_id={$p['ID']}"), 'name');

    // Photos: gallery ids in the page, attachments of the post, the featured image, carousel sources, dfiFeatured.
    $ids = [];
    if (preg_match_all('/gallery_ids="([\d,]+)"/', $p['post_content'], $g)) {
        foreach ($g[1] as $list) {
            $ids = array_merge($ids, array_map('intval', explode(',', $list)));
        }
    }
    if (! empty($meta['_thumbnail_id'])) {
        array_unshift($ids, (int) $meta['_thumbnail_id']);
    }
    if (preg_match_all('/\[supercarousel id=(\d+)\]/', $p['post_content'], $sc)) {
        foreach ($sc[1] as $cid) {
            $s = $q("select meta_value from {$t}postmeta where post_id=$cid and meta_key='supersettings' limit 1")[0]['meta_value'] ?? '';
            if (preg_match('/"source":"image:([\d,]+)"/', $s, $src)) {
                $ids = array_merge($ids, array_map('intval', explode(',', $src[1])));
            }
        }
    }
    foreach ($q("select ID from {$t}posts where post_parent={$p['ID']} and post_type='attachment' and post_mime_type like 'image/%' order by menu_order, ID") as $a) {
        $ids[] = (int) $a['ID'];
    }
    $photos = [];
    foreach (array_unique(array_filter($ids)) as $id) {
        if ($f = $file($id)) {
            $photos[] = $f;
        }
    }
    foreach (@unserialize($meta['dfiFeatured'] ?? '') ?: [] as $pair) {
        $full = trim(explode(',', (string) $pair)[1] ?? '');
        if ($full !== '') {
            $photos[] = ltrim($full, '/');
        }
    }
    $photos = array_values(array_unique($photos));
    $photos = array_values(array_filter($photos, fn ($f) => is_file($dir.'/wp-content/uploads/'.$f)));

    // The seller's text: the page's text blocks, without the builder shortcodes or the terms-and-conditions toggle.
    $content = preg_replace('/\[et_pb_toggle.*?\[\/et_pb_toggle\]/s', '', $p['post_content']);
    $text = trim(preg_replace(['/\[[^\]]*\]/', '/[ \t]+/', '/\n{3,}/'], ['', ' ', "\n\n"], html_entity_decode(strip_tags(str_replace(['</p>', '<br />', '<br>'], "\n", $content)), ENT_QUOTES)));
    $price = trim((string) ($meta['horse_price'] ?? ''));
    $sold = stripos($price, 'sold') !== false || preg_match('/^\s*SOLD/i', $text) || array_filter($terms, fn ($x) => stripos($x, 'sold') !== false);
    $words = preg_split('/\s+/', $name);

    $out[] = [
        'id' => (int) $p['ID'], 'archive' => $label, 'barn_name' => count($words) > 1 ? '' : $name,
        'url' => 'homd-archive://'.$label.'/'.$p['ID'].'/'.$p['post_name'], 'listed_on' => substr($p['post_date'], 0, 10),
        'registered_name' => count($words) > 1 ? $name : '', 'horse-year-foaled' => $year,
        'horse-height' => ($meta['height'] ?? '') !== '' ? $meta['height'].'h' : '', 'horse-color' => $meta['horse_color'] ?? '',
        'horse-breed' => $meta['horse_breed'] ?? '', 'horse-gender' => $meta['horse_gender'] ?? '',
        'horse-price' => preg_match('/\d/', $price) ? $price : '', 'price-display' => '', 'horse-video' => '',
        'horse-description' => preg_replace('/^\s*SOLD!?\s*/i', '', $text), 'also-registered-as' => '',
        'categories' => $sold ? ['SOLD (archive)'] : [], 'breeds' => [], 'photos' => $photos,
        'parent-a' => '', 'parent-b' => '', 'grandparent-a' => '', 'grandparent-b' => '', 'grandparent-c' => '', 'grandparent-d' => '',
        'great-grandparent-a' => '', 'great-grandparent-b' => '', 'great-grandparent-c' => '', 'great-grandparent-d' => '',
        'great-grandparent-e' => '', 'great-grandparent-f' => '', 'great-grandparent-g' => '', 'great-grandparent-h' => '',
    ];
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
