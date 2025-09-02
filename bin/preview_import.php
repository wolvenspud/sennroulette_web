<?php

// Dry-run importer: prints proposed rows, does NOT touch DB.

$path = '/var/www/sennroulette/import/menu.json';

$j = json_decode(file_get_contents($path), true);

if (!$j || !isset($j['menuItems'])) {

  fwrite(STDERR, "Could not read menuItems from $path\n");

  exit(1);

}



// Mapping: Android-style "drawable/foo_bar" -> "img/items/foo_bar.webp"

function propose_image_path(string $androidPath): string {

  $slug = preg_replace('#^drawable/#', '', strtolower($androidPath));

  return "img/items/{$slug}.webp";

}



// Map spice to 0..5 (take the max level mentioned)

function map_spice(array $item): int {

  if (!empty($item['spiceLevel']) && is_array($item['spiceLevel'])) {

    $map = ['mild'=>1,'medium'=>2,'hot'=>3,'thai_hot'=>5];

    $max = 0;

    foreach ($item['spiceLevel'] as $s) { $max = max($max, $map[strtolower($s)] ?? 0); }

    return $max;

  }

  if (!empty($item['spicy'])) return 3; // default “spicy” guess

  return 0;

}



$root = '/var/www/sennroulette/public';

printf("%-3s | %-30s | %-36s | %-5s | %s\n", 'ID', 'Name', 'Proposed image_path', 'Spice', 'Exists?');



$idx = 1;

foreach ($j['menuItems'] as $it) {

  $name = $it['name'] ?? '(no-name)';

  $imgRaw = $it['image'] ?? '';

  $imagePath = $imgRaw ? propose_image_path($imgRaw) : '';

  $spice = map_spice($it);

  $exists = $imagePath ? (file_exists("$root/$imagePath") ? 'yes' : 'NO') : 'NO';

  printf("%-3d | %-30s | %-36s | %-5d | %s\n", $idx++, $name, $imagePath, $spice, $exists);

}


