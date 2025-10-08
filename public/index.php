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

    $filteredItems[] = [
        'id'           => $itemId,
        'name'         => isset($item['name']) && $item['name'] !== '' ? (string)$item['name'] : 'Mystery dish',
        'description'  => isset($item['description']) ? (string)$item['description'] : '',
        'image_path'   => isset($item['image_path']) && $item['image_path'] !== '' ? (string)$item['image_path'] : null,
        'course_slug'  => $itemCourseSlug,
        'course_label' => $courseLabel,
        'proteins'     => $normalisedProteins,
        'base_spice'   => $itemSpice !== null ? max(0, min(5, (int)$itemSpice)) : 0,
    ];
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

    $clientItems[] = [
        'name'        => $item['name'],
        'description' => $item['description'] ?? '',
        'courseLabel' => $item['course_label'],
        'courseSlug'  => $item['course_slug'] ?? '',
        'proteins'    => $clientProteins,
        'baseSpice'   => (int)$item['base_spice'],
        'imagePath'   => $item['image_path'] ?? null,
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
                <div class="lootbox-item" data-loop="<?= $loop ?>" data-index="<?= $index ?>">
                  <strong><?= htmlspecialchars($item['name']) ?></strong>
                  <span><?= htmlspecialchars($item['course_label']) ?></span>
                </div>
              <?php endforeach; ?>
            <?php endfor; ?>
          </div>
          <div class="lootbox-marker"></div>
        </div>
        <button class="spin-button" id="spin-button">Spin the wheel</button>
      </div>

      <div class="lootbox-result" id="lootbox-result">
        <h2 id="lootbox-result-title">Ready when you are</h2>
        <div class="lootbox-meta" id="lootbox-result-meta"></div>
        <p class="lootbox-description" id="lootbox-result-description">Hit the button to let the carousel choose a dish.</p>
      </div>
    <?php endif; ?>
  </div>

  <script>
    const lootboxItems = <?= $itemsJson ?>;

    function formatProteins(proteins) {
      if (!Array.isArray(proteins) || proteins.length === 0) {
        return 'Flexible protein';
      }
      return proteins.map((p) => p.label).join(', ');
    }

    document.addEventListener('DOMContentLoaded', () => {
      const spinButton = document.getElementById('spin-button');
      const strip = document.getElementById('lootbox-strip');
      const windowEl = document.getElementById('lootbox-window');
      const resultTitle = document.getElementById('lootbox-result-title');
      const resultMeta = document.getElementById('lootbox-result-meta');
      const resultDescription = document.getElementById('lootbox-result-description');

      if (!spinButton || !strip || !windowEl || lootboxItems.length === 0) {
        if (spinButton) {
          spinButton.disabled = true;
        }
        return;
      }

      let activeCard = null;

      const centerCard = (loopIndex, itemIndex, animate = false, duration = 2200) => {
        const selector = `.lootbox-item[data-loop="${loopIndex}"][data-index="${itemIndex}"]`;
        const card = strip.querySelector(selector);
        if (!card) {
          return null;
        }
        const offset = card.offsetLeft + card.offsetWidth / 2 - windowEl.offsetWidth / 2;
        if (!animate) {
          strip.style.transition = 'none';
        } else {
          strip.style.transition = `transform ${duration}ms cubic-bezier(0.2, 0.8, 0.1, 1)`;
        }
        strip.style.transform = `translateX(${-offset}px)`;
        return card;
      };

      const updateResult = (item) => {
        if (!item) {
          return;
        }
        if (resultTitle) {
          resultTitle.textContent = item.name;
        }
        if (resultDescription) {
          resultDescription.textContent = item.description || 'No description available yet.';
        }
        if (resultMeta) {
          resultMeta.innerHTML = '';
          const metaBits = [
            `${item.courseLabel}`,
            `Spice: ${item.baseSpice}/5`,
            `Proteins: ${formatProteins(item.proteins)}`
          ];
          metaBits.forEach((text) => {
            const badge = document.createElement('span');
            badge.textContent = text;
            resultMeta.appendChild(badge);
          });
        }
      };

      // Prime the strip so the first item is centred.
      const initialCard = centerCard(1, 0, false, 0);
      activeCard = initialCard;
      if (initialCard) {
        initialCard.classList.add('active');
        updateResult(lootboxItems[0]);
      }

      spinButton.addEventListener('click', () => {
        if (spinButton.disabled) {
          return;
        }
        spinButton.disabled = true;

        const targetIndex = Math.floor(Math.random() * lootboxItems.length);
        const loopTarget = lootboxItems.length > 1 ? 2 : 1;
        const duration = 2100 + Math.floor(Math.random() * 500);

        // Reset to the first loop for a consistent start.
        strip.style.transition = 'none';
        centerCard(0, targetIndex, false, 0);
        // Force repaint so the transition kicks in on the next frame.
        void strip.offsetWidth;

        const landingCard = centerCard(loopTarget, targetIndex, true, duration);
        if (!landingCard) {
          spinButton.disabled = false;
          return;
        }

        setTimeout(() => {
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
