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
    $courseLabels[$course['slug']] = $course['label'];
}
$proteinLabels = [];
foreach ($proteins as $protein) {
    $proteinLabels[$protein['slug']] = $protein['label'];
}

$itemsStmt = $pdo->query('SELECT i.id, i.name, i.description, i.image_path, i.base_spice, c.slug AS course_slug, c.label AS course_label
    FROM items i
    INNER JOIN courses c ON c.id = i.course_id
    WHERE i.enabled = 1
    ORDER BY i.name');
$items = $itemsStmt->fetchAll();

$proteinRows = $pdo->query('SELECT iap.item_id, p.slug, p.label FROM item_allowed_proteins iap INNER JOIN proteins p ON p.id = iap.protein_id')->fetchAll();
$proteinMap = [];
foreach ($proteinRows as $row) {
    $proteinMap[(int)$row['item_id']][] = [
        'slug'  => $row['slug'],
        'label' => $row['label'],
    ];
}

$filteredItems = [];
foreach ($items as $item) {
    $itemId = (int)$item['id'];
    $itemProteins = $proteinMap[$itemId] ?? [];
    $itemProteinSlugs = array_column($itemProteins, 'slug');

    if (!in_array($item['course_slug'], $preferences['courses'], true)) {
        continue;
    }

    if (!empty($preferences['proteins'])) {
        if (empty($itemProteinSlugs)) {
            continue;
        }
        if (!array_intersect($itemProteinSlugs, $preferences['proteins'])) {
            continue;
        }
    }

    if ((int)$item['base_spice'] > (int)$preferences['max_spice']) {
        continue;
    }

    $item['proteins'] = $itemProteins;
    $filteredItems[] = $item;
}

$clientItems = [];
foreach ($filteredItems as $item) {
    $clientItems[] = [
        'name'        => $item['name'],
        'description' => $item['description'] ?? '',
        'courseLabel' => $item['course_label'],
        'courseSlug'  => $item['course_slug'],
        'proteins'    => $item['proteins'],
        'baseSpice'   => (int)$item['base_spice'],
        'imagePath'   => $item['image_path'] ?? null,
    ];
}

$itemsJson = json_encode($clientItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($itemsJson === false) {
    $itemsJson = '[]';
}

$selectedCourseLabels = array_map(fn ($slug) => $courseLabels[$slug] ?? $slug, $preferences['courses']);
$selectedProteinLabels = array_map(fn ($slug) => $proteinLabels[$slug] ?? $slug, $preferences['proteins']);

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
