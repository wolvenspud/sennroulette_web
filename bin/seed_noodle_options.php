<?php

declare(strict_types=1);



$pdo = new PDO('sqlite:/var/www/sennroulette/storage/database.sqlite', null, null, [

  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

  PDO::ATTR_EMULATE_PREPARES => false,

]);

$pdo->exec('PRAGMA foreign_keys = ON');



$items = $pdo->query('SELECT id, name, min_spice, max_spice FROM items WHERE enabled=1 ORDER BY id');



$getOpt = $pdo->prepare('SELECT id FROM item_options WHERE item_id=? AND name=?');

$insOpt = $pdo->prepare('INSERT INTO item_options(item_id,name,type,required,min_select,max_select,sort_order)

                         VALUES (?,?, "choice", 1, 1, 1, 0)');

$delVals = $pdo->prepare('DELETE FROM item_option_values WHERE option_id=?'); // rebuild values cleanly

$insVal = $pdo->prepare('INSERT INTO item_option_values(option_id,name,enabled,sort_order) VALUES (?,?,1,?)');



$groupsCreated = 0; $valuesCreated = 0; $groupsUpdated = 0;



foreach ($items as $it) {

  $itemId = (int)$it['id'];

  $min = max(0, min(5, (int)$it['min_spice']));

  $max = max($min, min(5, (int)$it['max_spice']));



  // ensure group exists

  $getOpt->execute([$itemId, 'Spice Level']);

  $optId = $getOpt->fetchColumn();

  if (!$optId) {

    $insOpt->execute([$itemId, 'Spice Level']);

    $optId = (int)$pdo->lastInsertId();

    $groupsCreated++;

  } else {

    $optId = (int)$optId;

    $groupsUpdated++;

    $delVals->execute([$optId]); // rebuild to match new range

  }



  // add values min..max

  for ($n = $min; $n <= $max; $n++) {

    $insVal->execute([$optId, (string)$n, $n]);

    $valuesCreated++;

  }

}



echo "Spice groups created: $groupsCreated, updated: $groupsUpdated\n";

echo "Spice values created: $valuesCreated\n";


