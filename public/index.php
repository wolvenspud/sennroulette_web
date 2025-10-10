<?php

session_start();

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/preferences.php';

$userId = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
$courses = fetch_course_options($pdo);
$proteins = fetch_protein_options($pdo);
$preferences = load_preferences($pdo, $userId, $courses, $proteins);

if (!function_exists('slugify_item_key')) {
    function slugify_item_key($value)
    {
        $value = strtolower(trim((string)$value));
        $value = str_replace(['\\', '/'], '_', $value);
        $value = preg_replace('/[^a-z0-9_\-]+/i', '_', $value);
        return trim($value, '_-');
    }
}

if (!function_exists('resolve_item_image_url')) {
    /**
     * Resolve a usable web path for a dish image, tolerating legacy storage prefixes.
     */
    function resolve_item_image_url(array $item)
    {
        $publicRoot = __DIR__;
        $extensions = ['webp', 'jpg', 'jpeg', 'png'];
        $rawInputs = [];

        if (!empty($item['image_path'])) {
            $rawInputs[] = (string)$item['image_path'];
        }

        if (!empty($item['slug'])) {
            $rawInputs[] = (string)$item['slug'];
        }

        if (!empty($item['name'])) {
            $rawInputs[] = slugify_item_key($item['name']);
        }

        $seen = [];

        foreach ($rawInputs as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '' || isset($seen[$raw])) {
                continue;
            }
            $seen[$raw] = true;

            if (preg_match('#^https?://#i', $raw)) {
                return $raw;
            }

            $normalised = str_replace('\\', '/', $raw);
            $normalised = preg_replace('#^public/#i', '', $normalised);
            $normalised = preg_replace('#^/?storage/(?:app/)?public/#i', '', $normalised);
            $normalised = trim($normalised, '/');

            if ($normalised === '') {
                continue;
            }

            if (strpos($normalised, 'drawable/') === 0) {
                $normalised = substr($normalised, strlen('drawable/'));
            }

            $pathCandidate = $normalised;

            if (strpos($pathCandidate, 'img/') === 0) {
                $relativePath = '/' . $pathCandidate;
                $fsPath = $publicRoot . $relativePath;
                if (is_file($fsPath)) {
                    return $relativePath;
                }
                $pathCandidate = basename($pathCandidate);
            } elseif (strpos($pathCandidate, 'items/') === 0) {
                $relativePath = '/img/' . $pathCandidate;
                $fsPath = $publicRoot . $relativePath;
                if (is_file($fsPath)) {
                    return $relativePath;
                }
                $pathCandidate = basename($pathCandidate);
            } elseif (strpos($pathCandidate, 'images/') === 0) {
                $relativePath = '/img/' . $pathCandidate;
                $fsPath = $publicRoot . $relativePath;
                if (is_file($fsPath)) {
                    return $relativePath;
                }
                $pathCandidate = basename($pathCandidate);
            }

            if (preg_match('#\.(png|jpe?g|webp)$#i', $pathCandidate)) {
                $relativePath = '/' . $normalised;
                $fsPath = $publicRoot . $relativePath;
                if (is_file($fsPath)) {
                    return $relativePath;
                }
                $pathCandidate = pathinfo($pathCandidate, PATHINFO_FILENAME);
            }

            $slug = slugify_item_key($pathCandidate);
            if ($slug === '') {
                continue;
            }

            foreach ($extensions as $extension) {
                $relativePath = '/img/items/' . $slug . '.' . $extension;
                $fsPath = $publicRoot . $relativePath;
                if (is_file($fsPath)) {
                    return $relativePath;
                }
            }
        }

        return null;
    }
}

$courseLabels = [];
foreach ($courses as $course) {
    if (!isset($course['slug'])) {
        continue;
    }

    $slug = (string)$course['slug'];
    $label = isset($course['label']) && $course['label'] !== '' ? (string)$course['label'] : $slug;

    $courseLabels[$slug] = $label;
}

$proteinLabels = [];
foreach ($proteins as $protein) {
    if (!isset($protein['slug'])) {
        continue;
    }

    $slug = (string)$protein['slug'];
    $label = isset($protein['label']) && $protein['label'] !== '' ? (string)$protein['label'] : $slug;

    $proteinLabels[$slug] = $label;
}

$items = [];
$itemColumns = db_table_columns($pdo, 'items');

if (!empty($itemColumns)) {
    $selectParts = [
        in_array('id', $itemColumns, true) ? 'i.id' : 'i.rowid AS id',
        in_array('name', $itemColumns, true) ? 'i.name' : "'' AS name",
        in_array('description', $itemColumns, true) ? 'i.description' : "'' AS description",
        in_array('image_path', $itemColumns, true) ? 'i.image_path' : 'NULL AS image_path',
        in_array('noodle_type', $itemColumns, true) ? 'i.noodle_type' : "'' AS noodle_type",
        in_array('slug', $itemColumns, true) ? 'i.slug AS slug' : "'' AS slug",
    ];

    $hasBaseSpice = in_array('base_spice', $itemColumns, true);
    $hasMinSpice = in_array('min_spice', $itemColumns, true);
    $hasMaxSpice = in_array('max_spice', $itemColumns, true);

    if ($hasBaseSpice) {
        $selectParts[] = 'i.base_spice';
    } else {
        $selectParts[] = 'NULL AS base_spice';
    }

    if ($hasMinSpice) {
        $selectParts[] = 'i.min_spice';
    }

    if ($hasMaxSpice) {
        $selectParts[] = 'i.max_spice';
    }

    $hasCourseId = in_array('course_id', $itemColumns, true);
    $courseJoin = '';
    $courseSlugSelect = 'NULL AS course_slug';
    $courseLabelSelect = "'' AS course_label";

    if ($hasCourseId && db_table_exists($pdo, 'courses')) {
        $courseColumns = db_table_columns($pdo, 'courses');

        if (in_array('id', $courseColumns, true)) {
            if (in_array('slug', $courseColumns, true)) {
                $courseSlugSelect = 'c.slug AS course_slug';
            }

            if (in_array('label', $courseColumns, true)) {
                $courseLabelSelect = 'c.label AS course_label';
            }

            $courseJoin = ' INNER JOIN courses c ON c.id = i.course_id';
        }
    }

    $selectParts[] = $courseSlugSelect;
    $selectParts[] = $courseLabelSelect;

    $itemsSql = 'SELECT ' . implode(', ', $selectParts) . ' FROM items i' . $courseJoin;

    $conditions = [];
    if (in_array('enabled', $itemColumns, true)) {
        $conditions[] = 'i.enabled = 1';
    }

    if (!empty($conditions)) {
        $itemsSql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    if (in_array('name', $itemColumns, true)) {
        $itemsSql .= ' ORDER BY i.name';
    } else {
        $itemsSql .= ' ORDER BY id';
    }

    try {
        $itemsStmt = $pdo->query($itemsSql);
        if ($itemsStmt !== false) {
            $items = $itemsStmt->fetchAll();
        }
    } catch (Throwable $e) {
        $items = [];
    }
}

$proteinMap = [];
if (db_table_exists($pdo, 'item_allowed_proteins') && db_table_exists($pdo, 'proteins')) {
    try {
        $proteinStmt = $pdo->query('SELECT iap.item_id, p.slug, p.label FROM item_allowed_proteins iap INNER JOIN proteins p ON p.id = iap.protein_id');
        if ($proteinStmt !== false) {
            foreach ($proteinStmt->fetchAll() as $row) {
                if (empty($row['item_id']) || empty($row['slug'])) {
                    continue;
                }

                $proteinMap[(int)$row['item_id']][] = [
                    'slug'  => (string)$row['slug'],
                    'label' => isset($row['label']) && $row['label'] !== '' ? (string)$row['label'] : (string)$row['slug'],
                ];
            }
        }
    } catch (Throwable $e) {
        $proteinMap = [];
    }
}

$courseFilter = array_values(array_filter($preferences['courses'], static function ($value) {
    return $value !== null && $value !== '';
}));
$courseFilter = array_values(array_unique($courseFilter));
$proteinFilter = array_values(array_filter($preferences['proteins'], static function ($value) {
    return $value !== null && $value !== '';
}));
$proteinFilter = array_values(array_unique($proteinFilter));
$maxSpice = (int)$preferences['max_spice'];

$filteredItems = [];
foreach ($items as $item) {
    $itemId = isset($item['id']) ? (int)$item['id'] : null;
    $itemCourseSlug = isset($item['course_slug']) && $item['course_slug'] !== '' ? (string)$item['course_slug'] : null;

    if (!empty($courseFilter) && $itemCourseSlug !== null && !in_array($itemCourseSlug, $courseFilter, true)) {
        continue;
    }

    $itemProteinList = $itemId !== null && isset($proteinMap[$itemId]) ? $proteinMap[$itemId] : null;
    if (!empty($proteinFilter)) {
        if (is_array($itemProteinList) && !empty($itemProteinList)) {
            $itemProteinSlugs = array_map('strval', array_column($itemProteinList, 'slug'));
            if (!array_intersect($itemProteinSlugs, $proteinFilter)) {
                continue;
            }
        } elseif (is_array($itemProteinList) && empty($itemProteinList)) {
            continue;
        }
    }

    $itemSpice = null;
    if (isset($item['base_spice']) && is_numeric($item['base_spice'])) {
        $itemSpice = (int)$item['base_spice'];
    } elseif (isset($item['min_spice'], $item['max_spice']) && is_numeric($item['min_spice']) && is_numeric($item['max_spice'])) {
        $itemSpice = (int)round(((int)$item['min_spice'] + (int)$item['max_spice']) / 2);
    }

    if ($itemSpice !== null && $itemSpice > $maxSpice) {
        continue;
    }

    $normalisedProteins = [];
    if (is_array($itemProteinList)) {
        foreach ($itemProteinList as $protein) {
            if (empty($protein['slug'])) {
                continue;
            }

            $slug = (string)$protein['slug'];
            $normalisedProteins[] = [
                'slug'  => $slug,
                'label' => isset($protein['label']) && $protein['label'] !== '' ? (string)$protein['label'] : ($proteinLabels[$slug] ?? $slug),
            ];
        }
    }

    $courseLabel = 'Any course';
    if ($itemCourseSlug !== null && isset($courseLabels[$itemCourseSlug])) {
        $courseLabel = $courseLabels[$itemCourseSlug];
    } elseif (isset($item['course_label']) && $item['course_label'] !== '') {
        $courseLabel = (string)$item['course_label'];
    }

    $noodleType = '';
    if (isset($item['noodle_type']) && is_string($item['noodle_type'])) {
        $noodleType = trim((string)$item['noodle_type']);
    }

    $filteredItems[] = [
        'id'           => $itemId,
        'name'         => isset($item['name']) && $item['name'] !== '' ? (string)$item['name'] : 'Mystery dish',
        'description'  => isset($item['description']) ? (string)$item['description'] : '',
        'image_path'   => isset($item['image_path']) && $item['image_path'] !== '' ? (string)$item['image_path'] : null,
        'image_url'    => resolve_item_image_url([
            'image_path' => isset($item['image_path']) ? $item['image_path'] : null,
            'slug'       => isset($item['slug']) ? $item['slug'] : null,
            'name'       => isset($item['name']) ? $item['name'] : null,
        ]),
        'slug'         => isset($item['slug']) && $item['slug'] !== '' ? (string)$item['slug'] : null,
        'noodle_type'  => $noodleType,
        'course_slug'  => $itemCourseSlug,
        'course_label' => $courseLabel,
        'proteins'     => $normalisedProteins,
        'base_spice'   => $itemSpice !== null ? max(0, min(5, (int)$itemSpice)) : 0,
    ];
}

$itemOptionMap = [];

if (!empty($filteredItems) && db_table_exists($pdo, 'item_options') && db_table_exists($pdo, 'item_option_values')) {
    $ids = [];
    foreach ($filteredItems as $filtered) {
        if (!empty($filtered['id'])) {
            $ids[] = (int)$filtered['id'];
        }
    }

    if (!empty($ids)) {
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $optionsSql = 'SELECT io.item_id, io.id AS option_id, io.name AS option_name, io.type, io.required, io.min_select, io.max_select, '
            . 'io.sort_order, iov.name AS value_name, iov.sort_order AS value_order, iov.enabled '
            . 'FROM item_options io LEFT JOIN item_option_values iov ON iov.option_id = io.id '
            . 'WHERE io.item_id IN (' . $placeholders . ') '
            . 'ORDER BY io.sort_order ASC, io.id ASC, iov.sort_order ASC, iov.id ASC';

        try {
            $optionsStmt = $pdo->prepare($optionsSql);
            if ($optionsStmt !== false && $optionsStmt->execute($ids)) {
                foreach ($optionsStmt->fetchAll() as $row) {
                    if (empty($row['item_id']) || empty($row['option_id']) || empty($row['option_name'])) {
                        continue;
                    }

                    $itemId = (int)$row['item_id'];
                    $optionId = (int)$row['option_id'];
                    $optionName = (string)$row['option_name'];

                    if (stripos($optionName, 'spice') !== false) {
                        continue;
                    }

                    if (!isset($itemOptionMap[$itemId])) {
                        $itemOptionMap[$itemId] = [];
                    }

                    if (!isset($itemOptionMap[$itemId][$optionId])) {
                        $itemOptionMap[$itemId][$optionId] = [
                            'id'         => $optionId,
                            'name'       => $optionName,
                            'type'       => isset($row['type']) ? (string)$row['type'] : 'choice',
                            'required'   => !empty($row['required']),
                            'min_select' => isset($row['min_select']) ? (int)$row['min_select'] : 1,
                            'max_select' => isset($row['max_select']) ? (int)$row['max_select'] : 1,
                            'values'     => [],
                        ];
                    }

                    if (!isset($row['value_name']) || $row['value_name'] === null) {
                        continue;
                    }

                    if (isset($row['enabled']) && (int)$row['enabled'] === 0) {
                        continue;
                    }

                    $valueName = (string)$row['value_name'];

                    $itemOptionMap[$itemId][$optionId]['values'][] = $valueName;
                }
            }
        } catch (Throwable $e) {
            $itemOptionMap = [];
        }
    }
}

$clientItems = [];
foreach ($filteredItems as $item) {
    $clientProteins = [];
    foreach ($item['proteins'] as $protein) {
        if (empty($protein['slug'])) {
            continue;
        }

        $clientProteins[] = [
            'slug'  => (string)$protein['slug'],
            'label' => isset($protein['label']) && $protein['label'] !== '' ? (string)$protein['label'] : (string)$protein['slug'],
        ];
    }

    $matchedProteins = [];
    foreach ($clientProteins as $protein) {
        if (empty($proteinFilter) || in_array($protein['slug'], $proteinFilter, true)) {
            $matchedProteins[] = $protein;
        }
    }

    $imageUrl = null;
    if (!empty($item['image_url'])) {
        $imageUrl = (string)$item['image_url'];
    }

    $itemOptions = [];
    $noodleOptions = [];

    if (!empty($item['id']) && isset($itemOptionMap[$item['id']])) {
        foreach ($itemOptionMap[$item['id']] as $option) {
            if (empty($option['values'])) {
                continue;
            }

            $values = [];
            foreach ($option['values'] as $value) {
                $valueString = (string)$value;
                $values[] = [
                    'value' => $valueString,
                    'label' => ucwords(str_replace(['_', '-'], ' ', $valueString)),
                ];
            }

            if (empty($values)) {
                continue;
            }

            $optionName = (string)$option['name'];

            if (stripos($optionName, 'noodle') !== false) {
                $noodleOptions = $values;
            }

            $itemOptions[] = [
                'id'        => $option['id'],
                'name'      => $optionName,
                'type'      => (string)$option['type'],
                'required'  => !empty($option['required']),
                'minSelect' => isset($option['min_select']) ? (int)$option['min_select'] : 1,
                'maxSelect' => isset($option['max_select']) ? (int)$option['max_select'] : 1,
                'values'    => $values,
            ];
        }
    }

    if (empty($noodleOptions) && !empty($item['noodle_type'])) {
        $raw = (string)$item['noodle_type'];
        if ($raw !== '' && strtolower($raw) !== 'na') {
            $parts = preg_split('/[|,]/', $raw);
            if (is_array($parts)) {
                $seen = [];
                foreach ($parts as $part) {
                    $slug = trim((string)$part);
                    if ($slug === '' || isset($seen[$slug])) {
                        continue;
                    }
                    $seen[$slug] = true;
                    $noodleOptions[] = [
                        'value' => $slug,
                        'label' => ucwords(str_replace(['_', '-'], ' ', $slug)),
                    ];
                }
            }
        }
    }

    $hasNoodleGroup = false;
    foreach ($itemOptions as $existingOption) {
        if (stripos($existingOption['name'], 'noodle') !== false) {
            $hasNoodleGroup = true;
            break;
        }
    }

    if (!$hasNoodleGroup && !empty($noodleOptions)) {
        $itemOptions[] = [
            'id'        => 'noodle-' . ($item['id'] ?? ''),
            'name'      => 'Noodle choice',
            'type'      => 'choice',
            'required'  => true,
            'minSelect' => 1,
            'maxSelect' => 1,
            'values'    => $noodleOptions,
        ];
    }

    $clientItems[] = [
        'id'           => $item['id'],
        'name'        => $item['name'],
        'description' => $item['description'] ?? '',
        'courseLabel' => $item['course_label'],
        'courseSlug'  => $item['course_slug'] ?? '',
        'proteins'    => $clientProteins,
        'matchedProteins' => $matchedProteins,
        'baseSpice'   => (int)$item['base_spice'],
        'imagePath'   => $imageUrl,
        'slug'        => $item['slug'],
        'optionGroups' => $itemOptions,
    ];
}

$itemsJson = json_encode($clientItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($itemsJson === false) {
    $itemsJson = '[]';
}

$selectedCourseLabels = [];
if (!empty($courseFilter)) {
    foreach ($courseFilter as $slug) {
        $selectedCourseLabels[] = $courseLabels[$slug] ?? $slug;
    }
}
if (empty($selectedCourseLabels)) {
    $selectedCourseLabels[] = 'All courses';
}
$selectedCourseLabels = array_values(array_unique($selectedCourseLabels));

$selectedProteinLabels = [];
if (!empty($proteinFilter)) {
    foreach ($proteinFilter as $slug) {
        $selectedProteinLabels[] = $proteinLabels[$slug] ?? $slug;
    }
}
if (empty($selectedProteinLabels)) {
    $selectedProteinLabels[] = 'All proteins';
}
$selectedProteinLabels = array_values(array_unique($selectedProteinLabels));

include __DIR__ . '/header.php';
?>
  <div class="roulette-page">
    <div class="roulette-header">
      <h1>Spin the Senn Roulette</h1>
      <p>Press the button and let fate pick tonight&apos;s dish.</p>
      <ul class="preferences-summary">
        <li><strong>Courses</strong> <?= htmlspecialchars(implode(', ', $selectedCourseLabels)) ?></li>
        <li><strong>Proteins</strong> <?= htmlspecialchars(implode(', ', $selectedProteinLabels)) ?></li>
        <li><strong>Max spice</strong> <?= (int)$preferences['max_spice'] ?> / 5</li>
      </ul>
    </div>

    <?php if (empty($filteredItems)): ?>
      <div class="no-items-message">
        <p>No dishes match your current filters. Try <a href="/preferences.php">adjusting your preferences</a> to widen the pool.</p>
      </div>
    <?php else: ?>
      <div class="lootbox-wrapper">
        <div class="lootbox-window" id="lootbox-window">
          <div class="lootbox-strip" id="lootbox-strip">
            <?php for ($loop = 0; $loop < 3; $loop++): ?>
              <?php foreach ($filteredItems as $index => $item): ?>
                <?php
                  $cardImage = null;
                  if (!empty($item['image_url'])) {
                      $cardImage = (string)$item['image_url'];
                  }
                ?>
                <div class="lootbox-item" data-loop="<?= $loop ?>" data-index="<?= $index ?>">
                  <div class="lootbox-thumb">
                    <?php if ($cardImage !== null): ?>
                      <img src="<?= htmlspecialchars($cardImage) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                    <?php else: ?>
                      <div class="lootbox-thumb-fallback" aria-hidden="true"></div>
                    <?php endif; ?>
                  </div>
                  <div class="lootbox-item-text">
                    <strong><?= htmlspecialchars($item['name']) ?></strong>
                    <span><?= htmlspecialchars($item['course_label']) ?></span>
                    <div class="lootbox-item-roll" hidden></div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endfor; ?>
          </div>
          <div class="lootbox-marker"></div>
        </div>
        <button class="spin-button" id="spin-button">Spin the wheel</button>
      </div>

    <?php endif; ?>
  </div>

  <script>
    const lootboxItems = <?= $itemsJson ?>;

    function pickProtein(item) {
      var eligible = [];
      if (item && Array.isArray(item.matchedProteins) && item.matchedProteins.length > 0) {
        eligible = item.matchedProteins.slice();
      } else if (item && Array.isArray(item.proteins)) {
        eligible = item.proteins.slice();
      }
      if (!eligible.length) {
        return null;
      }
      var index = Math.floor(Math.random() * eligible.length);
      return eligible[index] || null;
    }

    function rollOptionSelections(optionGroups) {
      if (!Array.isArray(optionGroups)) {
        return [];
      }
      var selections = [];
      optionGroups.forEach(function (group) {
        if (!group || !Array.isArray(group.values) || group.values.length === 0) {
          return;
        }

        var values = group.values.slice();
        var min = typeof group.minSelect === 'number' ? group.minSelect : (group.required ? 1 : 0);
        var max = typeof group.maxSelect === 'number' && group.maxSelect > 0 ? group.maxSelect : values.length;

        if (min < 0) {
          min = 0;
        }
        if (max < min) {
          max = min;
        }
        if (max > values.length) {
          max = values.length;
        }
        if (min > values.length) {
          min = values.length;
        }

        var count = min;
        if (max > min) {
          count = min + Math.floor(Math.random() * (max - min + 1));
        }
        if (count === 0 && group.required && values.length > 0) {
          count = 1;
        }

        var pool = values.slice();
        var picks = [];
        while (picks.length < count && pool.length > 0) {
          var idx = Math.floor(Math.random() * pool.length);
          var choice = pool.splice(idx, 1)[0];
          if (choice) {
            picks.push(choice);
          }
        }

        if (!picks.length && group.required && values.length > 0) {
          picks.push(values[0]);
        }

        if (picks.length) {
          selections.push({
            name: group.name || 'Option',
            values: picks
          });
        }
      });

      return selections;
    }

    function rollItemDetails(item) {
      var protein = pickProtein(item);
      var options = rollOptionSelections(item ? item.optionGroups : []);

      return {
        protein: protein,
        options: options
      };
    }

    document.addEventListener('DOMContentLoaded', function () {
      var spinButton = document.getElementById('spin-button');
      var strip = document.getElementById('lootbox-strip');
      var windowEl = document.getElementById('lootbox-window');
      if (!spinButton || !strip || !windowEl || lootboxItems.length === 0) {
        if (spinButton) {
          spinButton.disabled = true;
        }
        return;
      }

      var activeCard = null;
      var totalItems = lootboxItems.length;
      var currentLoop = 1;

      function clearCardRoll(card) {
        if (!card) {
          return;
        }
        var rollEl = card.querySelector('.lootbox-item-roll');
        if (rollEl) {
          rollEl.textContent = '';
          rollEl.setAttribute('hidden', 'hidden');
        }
      }

      function resetCardState(card) {
        if (!card) {
          return;
        }
        card.classList.remove('active');
        clearCardRoll(card);
      }

      function applyRollToCard(card, item) {
        if (!card || !item) {
          return;
        }
        var rollEl = card.querySelector('.lootbox-item-roll');
        if (!rollEl) {
          return;
        }
        var roll = rollItemDetails(item);
        var parts = [];
        if (roll && roll.protein) {
          var proteinLabel = roll.protein.label || roll.protein.value || '';
          if (proteinLabel) {
            parts.push('Protein: ' + proteinLabel);
          }
        }
        if (roll && Array.isArray(roll.options)) {
          roll.options.forEach(function (selection) {
            if (!selection || !selection.name || !selection.values || !selection.values.length) {
              return;
            }
            var valueText = selection.values
              .map(function (value) {
                if (!value) {
                  return '';
                }
                return value.label || value.value || '';
              })
              .filter(function (value) {
                return value !== '';
              })
              .join(', ');
            if (!valueText) {
              return;
            }
            parts.push(selection.name + ': ' + valueText);
          });
        }
        if (!parts.length) {
          rollEl.textContent = '';
          rollEl.setAttribute('hidden', 'hidden');
          return;
        }
        if (parts.length > 3) {
          parts = parts.slice(0, 3);
        }
        rollEl.textContent = parts.join(' • ');
        rollEl.removeAttribute('hidden');
      }

      function deactivateCard(card) {
        if (!card) {
          return;
        }
        card.classList.remove('active');
        clearCardRoll(card);
      }

      var templateCards = Array.prototype.map.call(
        strip.querySelectorAll('.lootbox-item[data-loop="0"]'),
        function (card) {
          var clone = card.cloneNode(true);
          resetCardState(clone);
          return clone;
        }
      );
      if (templateCards.length === 0) {
        templateCards = Array.prototype.map.call(
          strip.querySelectorAll('.lootbox-item[data-index]'),
          function (card) {
            var clone = card.cloneNode(true);
            clone.setAttribute('data-loop', '0');
            var originalIndex = card.getAttribute('data-index');
            if (originalIndex !== null) {
              clone.setAttribute('data-index', originalIndex);
            }
            resetCardState(clone);
            return clone;
          }
        );
      }
      templateCards.forEach(resetCardState);

      var lowestLoop = 0;
      var highestLoop = 0;

      function recomputeLoopBounds() {
        var cards = strip.querySelectorAll('.lootbox-item');
        if (cards.length === 0) {
          lowestLoop = 0;
          highestLoop = 0;
          return;
        }
        var minLoop = Number.POSITIVE_INFINITY;
        var maxLoop = Number.NEGATIVE_INFINITY;
        for (var i = 0; i < cards.length; i++) {
          var loopValue = parseInt(cards[i].getAttribute('data-loop') || '0', 10);
          if (loopValue < minLoop) {
            minLoop = loopValue;
          }
          if (loopValue > maxLoop) {
            maxLoop = loopValue;
          }
        }
        if (!isFinite(minLoop) || !isFinite(maxLoop)) {
          lowestLoop = 0;
          highestLoop = 0;
          return;
        }
        lowestLoop = minLoop;
        highestLoop = maxLoop;
      }

      function addLoop(loopIndex) {
        if (!templateCards.length) {
          return;
        }
        var fragment = document.createDocumentFragment();
        for (var j = 0; j < templateCards.length; j++) {
          var template = templateCards[j];
          var clone = template.cloneNode(true);
          var originalIndex = template.getAttribute('data-index');
          clone.setAttribute('data-loop', String(loopIndex));
          if (originalIndex !== null) {
            clone.setAttribute('data-index', originalIndex);
          } else {
            clone.removeAttribute('data-index');
          }
          resetCardState(clone);
          fragment.appendChild(clone);
        }
        strip.appendChild(fragment);
        highestLoop = loopIndex;
      }

      function ensureLoop(loopIndex) {
        if (!templateCards.length) {
          return;
        }
        while (highestLoop < loopIndex) {
          addLoop(highestLoop + 1);
        }
      }

      function reindexLoops(delta) {
        if (!delta) {
          return;
        }
        var cards = strip.querySelectorAll('.lootbox-item');
        for (var i = 0; i < cards.length; i++) {
          var card = cards[i];
          var loopValue = parseInt(card.getAttribute('data-loop') || '0', 10);
          card.setAttribute('data-loop', String(loopValue - delta));
        }
        recomputeLoopBounds();
      }

      function pruneLoops() {
        var cards = strip.querySelectorAll('.lootbox-item');
        var removed = false;
        for (var i = 0; i < cards.length; i++) {
          var loopValue = parseInt(cards[i].getAttribute('data-loop') || '0', 10);
          if (loopValue < 0 || loopValue > 3) {
            if (cards[i].parentNode) {
              cards[i].parentNode.removeChild(cards[i]);
            }
            removed = true;
          }
        }
        if (removed) {
          recomputeLoopBounds();
        }
      }

      function getCard(loopIndex, itemIndex) {
        return strip.querySelector('.lootbox-item[data-loop="' + loopIndex + '"][data-index="' + itemIndex + '"]');
      }

      function centerCard(loopIndex, itemIndex, animate, duration) {
        if (animate === void 0) {
          animate = false;
        }
        if (duration === void 0) {
          duration = 2200;
        }
        var card = getCard(loopIndex, itemIndex);
        if (!card) {
          return null;
        }
        var offset = card.offsetLeft + card.offsetWidth / 2 - windowEl.offsetWidth / 2;
        if (!animate) {
          strip.style.transition = 'none';
        } else {
          strip.style.transition = 'transform ' + duration + 'ms cubic-bezier(0.2, 0.8, 0.1, 1)';
        }
        strip.style.transform = 'translateX(' + (-offset) + 'px)';
        return card;
      }

      function normaliseAfterSpin() {
        var delta = currentLoop - 1;
        if (delta !== 0) {
          reindexLoops(delta);
          currentLoop = 1;
        }
        pruneLoops();
        ensureLoop(3);
        recomputeLoopBounds();

        if (activeCard && activeCard.isConnected) {
          var activeIndex = activeCard.getAttribute('data-index');
          if (activeIndex !== null) {
            centerCard(currentLoop, parseInt(activeIndex, 10) || 0, false, 0);
          }
        }
      }

      recomputeLoopBounds();
      if (templateCards.length && highestLoop < 2) {
        ensureLoop(2);
        recomputeLoopBounds();
      }

      // Prime the strip so the first item is centred.
      var initialCard = centerCard(currentLoop, 0, false, 0);
      activeCard = initialCard;
      if (initialCard) {
        initialCard.classList.add('active');
        applyRollToCard(initialCard, lootboxItems[0]);
      }

      spinButton.addEventListener('click', function () {
        if (spinButton.disabled) {
          return;
        }
        spinButton.disabled = true;

        var targetIndex = Math.floor(Math.random() * lootboxItems.length);
        var minCards = Math.max(6, totalItems * 2);
        var loopsNeeded = Math.max(2, Math.ceil(minCards / Math.max(totalItems, 1)));
        var extraLoops = Math.floor(Math.random() * 3);
        var passes = loopsNeeded + extraLoops;
        var targetLoop = currentLoop + passes;

        ensureLoop(targetLoop + 1);
        recomputeLoopBounds();
        if (targetLoop > highestLoop) {
          targetLoop = highestLoop;
        }
        if (targetLoop <= currentLoop) {
          targetLoop = currentLoop + 1;
          if (targetLoop > highestLoop) {
            targetLoop = highestLoop;
          }
        }

        var travelStartLoop = currentLoop;
        if (getCard(currentLoop - 1, targetIndex)) {
          travelStartLoop = currentLoop - 1;
        }

        var effectivePasses = Math.max(1, targetLoop - travelStartLoop);
        var duration = 1600 + effectivePasses * 240 + Math.floor(Math.random() * 360);

        strip.style.transition = 'none';
        var startCard = centerCard(travelStartLoop, targetIndex, false, 0);
        if (!startCard) {
          spinButton.disabled = false;
          return;
        }
        void strip.offsetWidth;

        var landingCard = centerCard(targetLoop, targetIndex, true, duration);
        if (!landingCard) {
          spinButton.disabled = false;
          return;
        }

        setTimeout(function () {
          if (activeCard) {
            deactivateCard(activeCard);
          }
          landingCard.classList.add('active');
          applyRollToCard(landingCard, lootboxItems[targetIndex]);
          activeCard = landingCard;
          currentLoop = targetLoop;
          spinButton.disabled = false;
          normaliseAfterSpin();
        }, duration + 120);
      });
    });
  </script>
<?php include __DIR__ . '/footer.php'; ?>
