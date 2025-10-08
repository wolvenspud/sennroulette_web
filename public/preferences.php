<?php

session_start();

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/preferences.php';

$userId = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
$courses = fetch_course_options($pdo);
$proteins = fetch_protein_options($pdo);
$current = load_preferences($pdo, $userId, $courses, $proteins);
$saved = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $prefs = [
        'courses'   => array_map('strval', $_POST['courses'] ?? []),
        'proteins'  => array_map('strval', $_POST['proteins'] ?? []),
        'max_spice' => $_POST['max_spice'] ?? $current['max_spice'],
    ];

    try {
        persist_preferences($pdo, $userId, $prefs, $courses, $proteins);
        $saved = true;
        $current = load_preferences($pdo, $userId, $courses, $proteins);
    } catch (Throwable $e) {
        $error = 'Unable to save your preferences right now. Please try again.';
    }
}

include __DIR__ . '/header.php';
?>
  <div class="preferences-page">
    <h1>Dish Preferences</h1>

    <p class="preferences-intro">
      Tailor the dishes that appear in the roulette. <?php if ($userId !== null): ?>Your choices are saved to your account.<?php else: ?>We save your selections in a cookie so they stick on this device.<?php endif; ?>
    </p>

    <?php if (!empty($saved)): ?>
      <div class="preferences-flash success">Preferences saved!</div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
      <div class="preferences-flash error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" class="preferences-form">
      <section>
        <h2>Courses</h2>
        <p>Select the types of dishes you want to see.</p>
        <div class="preferences-grid">
          <?php foreach ($courses as $course): ?>
            <?php $checked = in_array($course['slug'], $current['courses'], true); ?>
            <label class="pref-checkbox">
              <input type="checkbox" name="courses[]" value="<?= htmlspecialchars($course['slug']) ?>" <?= $checked ? 'checked' : '' ?>>
              <span><?= htmlspecialchars($course['label']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>

      <section>
        <h2>Proteins</h2>
        <p>We only show dishes that can be prepared with your selected proteins.</p>
        <div class="preferences-grid">
          <?php foreach ($proteins as $protein): ?>
            <?php $checked = in_array($protein['slug'], $current['proteins'], true); ?>
            <label class="pref-checkbox">
              <input type="checkbox" name="proteins[]" value="<?= htmlspecialchars($protein['slug']) ?>" <?= $checked ? 'checked' : '' ?>>
              <span><?= htmlspecialchars($protein['label']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>

      <section>
        <h2>Heat tolerance</h2>
        <label class="slider-label" for="max_spice">Maximum spice level: <strong><span id="spice-output"><?= (int)$current['max_spice'] ?></span></strong></label>
        <input id="max_spice" name="max_spice" type="range" min="0" max="5" step="1" value="<?= (int)$current['max_spice'] ?>">
        <div class="slider-scale">
          <span>0</span><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span>
        </div>
      </section>

      <div class="form-actions">
        <button type="submit">Save preferences</button>
      </div>
    </form>
  </div>

  <script>
    const spiceInput = document.getElementById('max_spice');
    const spiceOutput = document.getElementById('spice-output');
    if (spiceInput && spiceOutput) {
      spiceInput.addEventListener('input', () => {
        spiceOutput.textContent = spiceInput.value;
      });
    }
  </script>
<?php include __DIR__ . '/footer.php'; ?>
