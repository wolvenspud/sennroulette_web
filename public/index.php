<?php

session_start();

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/preferences.php';

$userId = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
$courses = fetch_course_options($pdo);
$proteins = fetch_protein_options($pdo);
$preferences = load_preferences($pdo, $userId, $courses, $proteins);

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
    if (!empty($item['image_path'])) {
        $path = (string)$item['image_path'];
        if ($path !== '') {
            if ($path[0] !== '/') {
                $path = '/' . ltrim($path, '/');
            }
            $imageUrl = $path;
        }
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
                  if (!empty($item['image_path'])) {
                      $path = (string)$item['image_path'];
                      if ($path !== '') {
                          if ($path[0] !== '/') {
                              $path = '/' . ltrim($path, '/');
                          }
                          $cardImage = $path;
                      }
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
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endfor; ?>
          </div>
          <div class="lootbox-marker"></div>
        </div>
        <button class="spin-button" id="spin-button">Spin the wheel</button>
      </div>

      <div class="lootbox-result" id="lootbox-result">
        <div class="lootbox-result-media">
          <img id="lootbox-result-image" alt="" hidden>
          <div class="lootbox-result-placeholder" id="lootbox-result-placeholder" aria-hidden="true"></div>
        </div>
        <div class="lootbox-result-content">
          <h2 id="lootbox-result-title">Ready when you are</h2>
          <div class="lootbox-meta" id="lootbox-result-meta"></div>
          <div class="lootbox-options" id="lootbox-result-options" hidden></div>
          <p class="lootbox-description" id="lootbox-result-description">Hit the button to let the carousel choose a dish.</p>
        </div>
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
      var resultTitle = document.getElementById('lootbox-result-title');
      var resultMeta = document.getElementById('lootbox-result-meta');
      var resultDescription = document.getElementById('lootbox-result-description');
      var resultImage = document.getElementById('lootbox-result-image');
      var resultPlaceholder = document.getElementById('lootbox-result-placeholder');
      var resultOptions = document.getElementById('lootbox-result-options');

      if (!spinButton || !strip || !windowEl || lootboxItems.length === 0) {
        if (spinButton) {
          spinButton.disabled = true;
        }
        return;
      }

      var activeCard = null;

      function centerCard(loopIndex, itemIndex, animate, duration) {
        if (animate === void 0) {
          animate = false;
        }
        if (duration === void 0) {
          duration = 2200;
        }
        var selector = '.lootbox-item[data-loop="' + loopIndex + '"][data-index="' + itemIndex + '"]';
        var card = strip.querySelector(selector);
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

      function updateResult(item) {
        if (!item) {
          return;
        }

        var rolled = rollItemDetails(item);

        if (resultTitle) {
          resultTitle.textContent = item.name;
        }

        if (resultDescription) {
          resultDescription.textContent = item.description || 'No description available yet.';
        }

        if (resultImage) {
          if (item.imagePath) {
            resultImage.src = item.imagePath;
            resultImage.alt = item.name;
            resultImage.removeAttribute('hidden');
            if (resultPlaceholder) {
              resultPlaceholder.setAttribute('hidden', 'hidden');
            }
          } else {
            resultImage.setAttribute('hidden', 'hidden');
            resultImage.removeAttribute('src');
            resultImage.alt = '';
            if (resultPlaceholder) {
              resultPlaceholder.removeAttribute('hidden');
            }
          }
        }

        if (resultMeta) {
          resultMeta.innerHTML = '';
          var badges = [item.courseLabel, 'Spice: ' + item.baseSpice + '/5'];

          if (rolled.protein) {
            badges.push('Protein: ' + rolled.protein.label);
          }

          if (Array.isArray(rolled.options)) {
            rolled.options.forEach(function (selection) {
              if (selection && selection.name && selection.values && selection.values.length) {
                var joined = selection.values.map(function (value) {
                  return value.label || value.value || '';
                }).join(', ');
                if (/noodle/i.test(selection.name)) {
                  badges.push(selection.name + ': ' + joined);
                }
              }
            });
          }

          badges.forEach(function (text) {
            if (!text) {
              return;
            }
            var badge = document.createElement('span');
            badge.textContent = text;
            resultMeta.appendChild(badge);
          });
        }

        if (resultOptions) {
          resultOptions.innerHTML = '';

          if (Array.isArray(rolled.options) && rolled.options.length) {
            rolled.options.forEach(function (selection) {
              if (!selection || !selection.name || !selection.values || !selection.values.length) {
                return;
              }

              var row = document.createElement('div');
              row.className = 'lootbox-option-row';

              var nameEl = document.createElement('span');
              nameEl.className = 'lootbox-option-name';
              nameEl.textContent = selection.name;

              var valueEl = document.createElement('span');
              valueEl.className = 'lootbox-option-value';
              valueEl.textContent = selection.values.map(function (value) {
                return value.label || value.value || '';
              }).join(', ');

              row.appendChild(nameEl);
              row.appendChild(valueEl);
              resultOptions.appendChild(row);
            });

            resultOptions.removeAttribute('hidden');
          } else {
            resultOptions.setAttribute('hidden', 'hidden');
          }
        }
      }

      // Prime the strip so the first item is centred.
      var initialCard = centerCard(1, 0, false, 0);
      activeCard = initialCard;
      if (initialCard) {
        initialCard.classList.add('active');
        updateResult(lootboxItems[0]);
      }

      spinButton.addEventListener('click', function () {
        if (spinButton.disabled) {
          return;
        }
        spinButton.disabled = true;

        var targetIndex = Math.floor(Math.random() * lootboxItems.length);
        var loopTarget = lootboxItems.length > 1 ? 2 : 1;
        var duration = 2100 + Math.floor(Math.random() * 500);

        strip.style.transition = 'none';
        centerCard(0, targetIndex, false, 0);
        void strip.offsetWidth;

        var landingCard = centerCard(loopTarget, targetIndex, true, duration);
        if (!landingCard) {
          spinButton.disabled = false;
          return;
        }

        setTimeout(function () {
          if (activeCard) {
            activeCard.classList.remove('active');
          }
          landingCard.classList.add('active');
          activeCard = landingCard;
          updateResult(lootboxItems[targetIndex]);
          spinButton.disabled = false;
        }, duration + 120);
      });
    });
  </script>
<?php include __DIR__ . '/footer.php'; ?>
