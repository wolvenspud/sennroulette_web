<?php

declare(strict_types=1);



$pdo = new PDO('sqlite:/var/www/sennroulette/storage/database.sqlite', null, null, [

  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

  PDO::ATTR_EMULATE_PREPARES => false,

]);

$pdo->exec('PRAGMA foreign_keys = ON');



$JSON     = '/var/www/sennroulette/import/menu.json';

$PUBROOT  = '/var/www/sennroulette/public';



$data = json_decode(@file_get_contents($JSON), true);

if (!$data || !isset($data['menuItems'])) {

  fwrite(STDERR, "Could not read menuItems from $JSON\n");

  exit(1);

}



/* --- EDIT THIS LIST to mark appetisers by *exact* item name --- */

$APPETISERS = [

  // 'look chin ping',

  // 'kanom guichai',

];



/* helpers */

function course_slug_for(string $name, array $apps): string {

  return in_array(strtolower($name), $apps, true) ? 'appetisers' : 'mains';

}

function propose_image_path(?string $androidPath): ?string {

  if (!$androidPath) return null;

  $slug = preg_replace('#^drawable/#', '', strtolower($androidPath));

  return "img/items/{$slug}.webp";

}

function map_spice(array $it): int {

  if (!empty($it['spiceLevel']) && is_array($it['spiceLevel'])) {

    $map = ['mild'=>1,'medium'=>2,'hot'=>3,'thai_hot'=>5];

    $max = 0;

    foreach ($it['spiceLevel'] as $s) $max = max($max, $map[strtolower($s)] ?? 0);

    return $max;

  }

  return !empty($it['spicy']) ? 3 : 0;

}

function map_protein_slug(string $p): ?string {

  $p = strtolower($p);

  if ($p === 'chicken') return 'chicken';

  if ($p === 'beef') return 'beef';

  if ($p === 'pork' || $p === 'dry_pork' || $p === 'soup_pork') return 'pork';

  if ($p === 'vegan') return 'vegan';

  if ($p === 'vegetarian' || $p === 'tofu' || $p === 'dry_vegetarian' || $p === 'soup_vegetarian') return 'vegetarian';

  if ($p === 'seafood' || $p === 'prawn' || $p === 'fish') return 'seafood';

  return null;

}



/* lookup ids */

$getCourse = $pdo->prepare('SELECT id FROM courses WHERE slug=?');

$getProt   = $pdo->prepare('SELECT id FROM proteins WHERE slug=?');



/* upsert item (SQLite UPSERT by unique name) */

$upsertItem = $pdo->prepare(

  'INSERT INTO items(name, description, course_id, base_spice, image_path, enabled)

   VALUES (:name,:desc,:course,:spice,:img,1)

   ON CONFLICT(name) DO UPDATE SET

     description=excluded.description,

     course_id=excluded.course_id,

     base_spice=excluded.base_spice,

     image_path=excluded.image_path,

     updated_at=CURRENT_TIMESTAMP'

);



/* fetch id after upsert */

$getItemId = $pdo->prepare('SELECT id FROM items WHERE name=?');



/* replace proteins for an item */

$delItemProt = $pdo->prepare('DELETE FROM item_allowed_proteins WHERE item_id=?');

$addItemProt = $pdo->prepare('INSERT OR IGNORE INTO item_allowed_proteins(item_id, protein_id) VALUES (?,?)');



$inserted=0; $updated=0; $skippedNoImage=0; $linked=0;

$pdo->beginTransaction();

try {

  foreach ($data['menuItems'] as $it) {

    $name = trim((string)($it['name'] ?? ''));

    if ($name === '') continue;


    $courseSlug = course_slug_for($name, array_map('strtolower', $APPETISERS));


    $getCourse->execute([$courseSlug]);

    $courseId = (int)$getCourse->fetchColumn();

    if (!$courseId) throw new RuntimeException("Course slug missing: $courseSlug");



    $imgPath = propose_image_path($it['image'] ?? null);

    $spice   = map_spice($it);

    $desc    = isset($it['description']) ? trim((string)$it['description']) : null;



    // require image file to exist (keeps DB clean)

    if (!$imgPath || !file_exists("$PUBROOT/$imgPath")) { $skippedNoImage++; continue; }



    // UPSERT item

    $upsertItem->execute([

      ':name'=>$name, ':desc'=>$desc, ':course'=>$courseId, ':spice'=>$spice, ':img'=>$imgPath

    ]);

    $getItemId->execute([$name]);

    $itemId = (int)$getItemId->fetchColumn();



    // count insert vs update (rowCount on UPSERT can be tricky; re-check existence)

    if ($upsertItem->rowCount() > 0) { /* could be insert or update */ }

    // rough heuristic: treat new rows as inserted if they didn't exist before – skip complexity; optional



    // Replace protein links

    $delItemProt->execute([$itemId]);

    $seen = [];



    if (!empty($it['protein']) && is_array($it['protein'])) {

      foreach ($it['protein'] as $p) {

        $slug = map_protein_slug((string)$p);

        if ($slug && empty($seen[$slug])) {

          $getProt->execute([$slug]);

          if ($pid = $getProt->fetchColumn()) {

            $addItemProt->execute([$itemId, (int)$pid]);

            $linked++;

          }

          $seen[$slug] = true;

        }

      }

    } else {

      // fallback: if JSON marks vegetarian=true, allow vegetarian (optionally vegan too)

      if (!empty($it['vegetarian'])) {

        $getProt->execute(['vegetarian']); if ($pid=$getProt->fetchColumn()) { $addItemProt->execute([$itemId,(int)$pid]); $linked++; }

        // If you want to also allow vegan automatically, uncomment:

        // $getProt->execute(['vegan']); if ($pid=$getProt->fetchColumn()) { $addItemProt->execute([$itemId,(int)$pid]); $linked++; }

      }

    }

  }

  $pdo->commit();

} catch (Throwable $e) {

  $pdo->rollBack();

  fwrite(STDERR, "Import failed: " . $e->getMessage() . "\n");

  exit(1);

}



echo "Done.\n";

echo "Skipped (no image): $skippedNoImage\n";

echo "Protein links created: $linked\n";


