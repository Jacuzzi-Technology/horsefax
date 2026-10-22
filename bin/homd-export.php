<?php
/**
 * Read-only export of Horse of My Dreams horses for Horsefax (Richard, Oct 18 2026). Run on the HOMD server:
 *   sudo -u homd wp eval-file homd-export.php > homd-horses.json
 * Published horses only. Leaves out the buyer's name (sold-to), vet exams and radiographs, and photo captions,
 * titles and filenames (no photographer credit may travel with a photo).
 */
$fields = ['registered_name', 'horse-year-foaled', 'horse-height', 'horse-color', 'horse-breed', 'horse-gender', 'horse-price',
    'price-display', 'horse-video', 'horse-description', 'also-registered-as',
    'parent-a', 'parent-b', 'grandparent-a', 'grandparent-b', 'grandparent-c', 'grandparent-d',
    'great-grandparent-a', 'great-grandparent-b', 'great-grandparent-c', 'great-grandparent-d',
    'great-grandparent-e', 'great-grandparent-f', 'great-grandparent-g', 'great-grandparent-h'];
$out = [];
foreach (get_posts(['post_type' => 'horses', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC']) as $p) {
    $h = ['id' => $p->ID, 'barn_name' => $p->post_title, 'url' => get_permalink($p), 'listed_on' => substr($p->post_date, 0, 10)];
    foreach ($fields as $f) {
        $h[$f] = trim((string) get_post_meta($p->ID, $f, true));
    }
    $h['categories'] = wp_get_post_terms($p->ID, 'horse_category', ['fields' => 'names']);
    $h['breeds'] = wp_get_post_terms($p->ID, 'horse_breed', ['fields' => 'names']);
    $ids = array_filter(array_map('intval', (array) get_post_meta($p->ID, 'horse-gallery', true)));
    if ($thumb = get_post_thumbnail_id($p->ID)) {
        array_unshift($ids, (int) $thumb);
    }
    $h['photos'] = array_values(array_unique(array_filter(array_map(fn ($id) => wp_get_attachment_image_url($id, 'large'), array_unique($ids)))));
    $out[] = $h;
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
