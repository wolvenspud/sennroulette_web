<?php
session_start();

if (empty($_SESSION['uid']) || empty($_SESSION['is_admin'])) {
    header("Location: /login.php"); exit;
}

require __DIR__ . '/../../src/db.php';

// Ensure items.noodle_type, items.min_spice, items.max_spice exist (safe no-ops if present)
try {
    $cols = $pdo->query('PRAGMA table_info(items)')->fetchAll();
    $has = ['noodle_type'=>false,'min_spice'=>false,'max_spice'=>false,'base_spice'=>false];
    foreach ($cols as $c) {
        $n = $c['name'] ?? '';
        if (isset($has[$n])) $has[$n] = true;
    }
    if (!$has['noodle_type']) {
        $pdo->exec("ALTER TABLE items ADD COLUMN noodle_type TEXT NOT NULL DEFAULT 'na'");
    }
    $justAddedMin = false; $justAddedMax = false;
    if (!$has['min_spice']) { $pdo->exec("ALTER TABLE items ADD COLUMN min_spice INTEGER NOT NULL DEFAULT 0"); $justAddedMin = true; }
    if (!$has['max_spice']) { $pdo->exec("ALTER TABLE items ADD COLUMN max_spice INTEGER NOT NULL DEFAULT 5"); $justAddedMax = true; }
    // If we just introduced min/max and base_spice exists, seed min/max from base_spice
    if (($justAddedMin || $justAddedMax) && $has['base_spice']) {
        $pdo->exec("UPDATE items SET min_spice = base_spice, max_spice = base_spice");
    }
} catch (Throwable $t) {
    // ignore
}

/* csrf + helpers */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function csrf_field(){ return '<input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8').'">'; }
function check_csrf(){ if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) { http_response_code(403); exit('bad csrf'); } }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$tab = $_GET['tab'] ?? 'users';
$ok  = !empty($_GET['ok']);
$err = null;

$noodleChoices = [
    'na'         => 'N/A',
    'flat_rice'  => 'Flat rice',
    'thin_rice'  => 'Thin rice',
    'egg_noodle' => 'Egg noodle',
];

/* POST actions (PRG) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'toggle_admin') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid user id.');
            if ($id === (int)$_SESSION['uid']) throw new RuntimeException('Cannot change your own admin flag here.');
            $stmt = $pdo->prepare('UPDATE users SET is_admin = CASE is_admin WHEN 1 THEN 0 ELSE 1 END WHERE id = ?');
            $stmt->execute([$id]);
            header("Location: /admin/admin.php?tab=users&ok=1"); exit;
        } elseif ($action === 'delete_user') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid user id.');
            if ($id === (int)$_SESSION['uid']) throw new RuntimeException('Cannot delete the currently logged-in user.');
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$id]);
            header("Location: /admin/admin.php?tab=users&ok=1"); exit;
        } elseif ($action === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            $p  = $_POST['password'] ?? '';
            if ($id <= 0 || $p === '') throw new RuntimeException('Invalid request.');
            $hash = password_hash($p, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([$hash, $id]);
            header("Location: /admin/admin.php?tab=users&ok=1"); exit;
        } elseif ($action === 'add_item') {
            $name    = trim($_POST['name'] ?? '');
            $slug    = $_POST['course_slug'] ?? '';
            $min     = (int)($_POST['min_spice'] ?? 0);
            $max     = (int)($_POST['max_spice'] ?? 5);
            $enabled = !empty($_POST['enabled']) ? 1 : 0;
            $noodlesNA = !empty($_POST['noodles_na']);
            $noodleSlugs = $_POST['noodles'] ?? [];
            if (!is_array($noodleSlugs)) {
                $noodleSlugs = [];
            }
            if ($name === '' || $slug === '') throw new RuntimeException('Name and course are required.');
            if ($min < 0 || $min > 5 || $max < 0 || $max > 5 || $min > $max) throw new RuntimeException('Spice range must be 0..5 and min ≤ max.');
            $validNoodleSlugs = array_diff(array_keys($noodleChoices), ['na']);
            $selectedNoodles = [];
            foreach ($noodleSlugs as $slugValue) {
                $slugValue = (string)$slugValue;
                if (!in_array($slugValue, $validNoodleSlugs, true)) {
                    throw new RuntimeException('Invalid noodle option.');
                }
                if (!in_array($slugValue, $selectedNoodles, true)) {
                    $selectedNoodles[] = $slugValue;
                }
            }
            $noodle = 'na';
            if (!$noodlesNA && !empty($selectedNoodles)) {
                $noodle = implode('|', $selectedNoodles);
            }
            $c = $pdo->prepare('SELECT id FROM courses WHERE slug = ?');
            $c->execute([$slug]);
            $course = $c->fetch();
            if (!$course) throw new RuntimeException('Course not found.');
            $stmt = $pdo->prepare('INSERT INTO items (name, course_id, min_spice, max_spice, enabled, noodle_type) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $course['id'], $min, $max, $enabled, $noodle]);
            header("Location: /admin/admin.php?tab=items&ok=1"); exit;

        } elseif ($action === 'bulk_update_items') {
            $itemPayloads = $_POST['items'] ?? [];
            if (!is_array($itemPayloads)) {
                throw new RuntimeException('Invalid submission payload.');
            }

            if (empty($itemPayloads)) {
                header("Location: /admin/admin.php?tab=items&ok=1"); exit;
            }

            $validNoodleSlugs = array_diff(array_keys($noodleChoices), ['na']);

            $courseMap = [];
            $courseStmt = $pdo->query('SELECT id, slug FROM courses');
            if ($courseStmt instanceof \PDOStatement) {
                foreach ($courseStmt->fetchAll() as $row) {
                    $slug = (string)($row['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $courseMap[$slug] = (int)$row['id'];
                }
            }

            $proteinRows = [];
            try {
                $proteinRows = $pdo->query('SELECT id, slug FROM proteins')->fetchAll();
            } catch (Throwable $proteinError) {
                $proteinRows = [];
            }
            $proteinMap = [];
            foreach ($proteinRows as $row) {
                $slug = (string)($row['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $proteinMap[$slug] = (int)$row['id'];
            }

            $updateStmt = $pdo->prepare('UPDATE items SET name = ?, course_id = ?, min_spice = ?, max_spice = ?, enabled = ?, noodle_type = ? WHERE id = ?');
            $deleteProteinsStmt = $pdo->prepare('DELETE FROM item_allowed_proteins WHERE item_id = ?');
            $insertProteinStmt = $pdo->prepare('INSERT INTO item_allowed_proteins (item_id, protein_id) VALUES (?, ?)');

            $pdo->beginTransaction();
            try {
                foreach ($itemPayloads as $key => $payload) {
                    if (!is_array($payload)) {
                        continue;
                    }

                    $id = isset($payload['id']) ? (int)$payload['id'] : (int)$key;
                    if ($id <= 0) {
                        continue;
                    }

                    $name = trim((string)($payload['name'] ?? ''));
                    $slug = (string)($payload['course_slug'] ?? '');
                    $min  = (int)($payload['min_spice'] ?? 0);
                    $max  = (int)($payload['max_spice'] ?? 5);
                    $enabled = !empty($payload['enabled']) ? 1 : 0;
                    $noodlesNA = !empty($payload['noodles_na']);
                    $noodleSlugs = $payload['noodles'] ?? [];
                    if (!is_array($noodleSlugs)) {
                        $noodleSlugs = [];
                    }
                    $proteinsNA = !empty($payload['proteins_na']);
                    $proteinSlugs = $payload['proteins'] ?? [];
                    if (!is_array($proteinSlugs)) {
                        $proteinSlugs = [];
                    }

                    if ($name === '') {
                        throw new RuntimeException('Name is required for item #' . $id . '.');
                    }
                    if ($slug === '' || !isset($courseMap[$slug])) {
                        throw new RuntimeException('Course not found for item #' . $id . '.');
                    }
                    if ($min < 0 || $min > 5 || $max < 0 || $max > 5 || $min > $max) {
                        throw new RuntimeException('Spice range must be 0..5 and min ≤ max for item #' . $id . '.');
                    }

                    $selectedNoodles = [];
                    foreach ($noodleSlugs as $slugValue) {
                        $slugValue = (string)$slugValue;
                        if (!in_array($slugValue, $validNoodleSlugs, true)) {
                            throw new RuntimeException('Invalid noodle option for item #' . $id . '.');
                        }
                        if (!in_array($slugValue, $selectedNoodles, true)) {
                            $selectedNoodles[] = $slugValue;
                        }
                    }

                    $noodle = 'na';
                    if (!$noodlesNA && !empty($selectedNoodles)) {
                        $noodle = implode('|', $selectedNoodles);
                    }

                    $selectedProteins = [];
                    if (!$proteinsNA) {
                        foreach ($proteinSlugs as $proteinSlug) {
                            $proteinSlug = (string)$proteinSlug;
                            if ($proteinSlug === '') {
                                continue;
                            }
                            if (!isset($proteinMap[$proteinSlug])) {
                                throw new RuntimeException('Invalid protein option for item #' . $id . '.');
                            }
                            if (!in_array($proteinSlug, $selectedProteins, true)) {
                                $selectedProteins[] = $proteinSlug;
                            }
                        }
                    }

                    $updateStmt->execute([
                        $name,
                        $courseMap[$slug],
                        $min,
                        $max,
                        $enabled,
                        $noodle,
                        $id,
                    ]);

                    $deleteProteinsStmt->execute([$id]);
                    if (!$proteinsNA && !empty($selectedProteins)) {
                        foreach ($selectedProteins as $proteinSlug) {
                            $insertProteinStmt->execute([$id, $proteinMap[$proteinSlug]]);
                        }
                    }
                }

                $pdo->commit();
            } catch (Throwable $inner) {
                $pdo->rollBack();
                throw $inner;
            }

            header("Location: /admin/admin.php?tab=items&ok=1"); exit;

        } elseif ($action === 'update_item') {
            $id      = (int)($_POST['id'] ?? 0);
            $name    = trim($_POST['name'] ?? '');
            $slug    = $_POST['course_slug'] ?? '';
            $min     = (int)($_POST['min_spice'] ?? 0);
            $max     = (int)($_POST['max_spice'] ?? 5);
            $enabled = !empty($_POST['enabled']) ? 1 : 0;
            $noodlesNA = !empty($_POST['noodles_na']);
            $noodleSlugs = $_POST['noodles'] ?? [];
            if (!is_array($noodleSlugs)) {
                $noodleSlugs = [];
            }
            $proteinsNA   = !empty($_POST['proteins_na']);
            $proteinSlugs = $_POST['proteins'] ?? [];
            if ($id <= 0 || $name === '') throw new RuntimeException('Invalid item update.');
            if ($min < 0 || $min > 5 || $max < 0 || $max > 5 || $min > $max) throw new RuntimeException('Spice range must be 0..5 and min ≤ max.');
            $validNoodleSlugs = array_diff(array_keys($noodleChoices), ['na']);
            $selectedNoodles = [];
            foreach ($noodleSlugs as $slugValue) {
                $slugValue = (string)$slugValue;
                if (!in_array($slugValue, $validNoodleSlugs, true)) {
                    throw new RuntimeException('Invalid noodle option.');
                }
                if (!in_array($slugValue, $selectedNoodles, true)) {
                    $selectedNoodles[] = $slugValue;
                }
            }
            $noodle = 'na';
            if (!$noodlesNA && !empty($selectedNoodles)) {
                $noodle = implode('|', $selectedNoodles);
            }

            $pdo->beginTransaction();
            try {
                $c = $pdo->prepare('SELECT id FROM courses WHERE slug = ?');
                $c->execute([$slug]);
                $course = $c->fetch();
                if (!$course) throw new RuntimeException('Course not found.');

                $stmt = $pdo->prepare('UPDATE items SET name = ?, course_id = ?, min_spice = ?, max_spice = ?, enabled = ?, noodle_type = ? WHERE id = ?');
                $stmt->execute([$name, $course['id'], $min, $max, $enabled, $noodle, $id]);

                // Update allowed proteins
                $pdo->prepare('DELETE FROM item_allowed_proteins WHERE item_id = ?')->execute([$id]);
                if (!$proteinsNA && is_array($proteinSlugs) && count($proteinSlugs) > 0) {
                    $in  = implode(',', array_fill(0, count($proteinSlugs), '?'));
                    $qp  = $pdo->prepare("SELECT id FROM proteins WHERE slug IN ($in)");
                    $qp->execute(array_values($proteinSlugs));
                    $ids = array_column($qp->fetchAll(), 'id');
                    if ($ids) {
                        $qi = $pdo->prepare('INSERT INTO item_allowed_proteins (item_id, protein_id) VALUES (?, ?)');
                        foreach ($ids as $pid) { $qi->execute([$id, $pid]); }
                    }
                }

                $pdo->commit();
            } catch (Throwable $inner) {
                $pdo->rollBack();
                throw $inner;
            }
            header("Location: /admin/admin.php?tab=items&ok=1"); exit;
        } elseif ($action === 'toggle_item') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid item id.');
            $stmt = $pdo->prepare('UPDATE items SET enabled = CASE enabled WHEN 1 THEN 0 ELSE 1 END WHERE id = ?');
            $stmt->execute([$id]);
            header("Location: /admin/admin.php?tab=items&ok=1"); exit;
        } elseif ($action === 'delete_item') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid item id.');
            $stmt = $pdo->prepare('DELETE FROM items WHERE id = ?');
            $stmt->execute([$id]);
            header("Location: /admin/admin.php?tab=items&ok=1"); exit;
        }
    } catch (Throwable $t) {
        $err = $t->getMessage();
    }
}

include __DIR__ . '/../header.php';
?>
<div class="admin-container">
  <div class="admin-box">
    <h1 class="page-title">Admin Panel</h1>

    <nav class="tabbar">
      <button type="button" onclick="location.href='/admin/admin.php?tab=users'">Users</button>
      <button type="button" onclick="location.href='/admin/admin.php?tab=items'">Menu Items</button>
    </nav>

    <?php if ($ok): ?>
      <p class="admin-ok">Saved.</p>
    <?php endif; ?>
    <?php if (!empty($err)): ?>
      <p class="error"><?= e($err) ?></p>
    <?php endif; ?>

    <?php if ($tab === 'users'): ?>
      <section>
        <h2>Users</h2>
        <table class="admin-table">
          <thead>
            <tr><th>ID</th><th>Username</th><th>Admin</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php
            try {
                $rows = $pdo->query('SELECT id, username, is_admin FROM users ORDER BY id DESC')->fetchAll();
            } catch (Throwable $t) {
                $rows = [];
                echo '<tr><td colspan="4">DB error loading users.</td></tr>';
            }
            foreach ($rows as $r):
          ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= e($r['username']) ?></td>
              <td><?= (int)$r['is_admin'] ? 'yes' : 'no' ?></td>
              <td class="admin-actions">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_admin">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit"><?= (int)$r['is_admin'] ? 'Demote' : 'Promote' ?></button>
                </form>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_user">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" onclick="return confirm('Delete this user?')">Delete</button>
                </form>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reset_password">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="password" name="password" placeholder="New password" required>
                  <button type="submit">Reset</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php else: ?>
      <section>
        <h2>Menu Items</h2>

        <?php
          try {
              $courses  = $pdo->query('SELECT id, slug, label FROM courses ORDER BY id')->fetchAll();
              $proteins = $pdo->query('SELECT id, slug, label FROM proteins ORDER BY id')->fetchAll();
          } catch (Throwable $t) {
              $courses = []; $proteins = [];
              echo '<p class="error">DB error loading lookups.</p>';
          }
          $courseOptions = function($currentSlug) use ($courses) {
              $out = '';
              foreach ($courses as $c) {
                  $slug = $c['slug']; $label = $c['label'] ?? $slug;
                  $sel = ($slug === $currentSlug) ? ' selected' : '';
                  $out .= '<option value="'.e($slug).'"'.$sel.'>'.e($label).'</option>';
              }
              return $out;
          };
          $spiceOptions = function($cur) {
              $out = '';
              for ($i=0; $i<=5; $i++) {
                  $sel = ($i == $cur) ? ' selected' : '';
                  $out .= '<option value="'.$i.'"'.$sel.'>'.$i.'</option>';
              }
              return $out;
          };
        ?>

        <form method="post" class="admin-form" style="margin-bottom:1rem;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_item">
          <label class="inline enabled-toggle">
            <input type="checkbox" name="enabled" value="1" checked> Enabled
          </label>
          <label>Name</label>
          <input type="text" name="name" required>
          <label>Course</label>
          <select name="course_slug" required>
            <option value="">Select…</option>
            <?= $courseOptions('') ?>
          </select>
          <label>Spice (min..max)</label>
          <span class="nowrap">
            <select name="min_spice"><?= $spiceOptions(0) ?></select>
            <span>to</span>
            <select name="max_spice"><?= $spiceOptions(5) ?></select>
          </span>
          <details class="dropdown noodle-group options-group">
            <summary>Noodles: <span class="summary-text">N/A</span></summary>
            <div class="dropdown-panel">
              <label class="inline">
                <input type="checkbox" class="noodle-na" name="noodles_na" value="1" checked> N/A
              </label>
              <hr style="border-color:rgba(255,255,255,0.15);">
              <?php foreach ($noodleChoices as $slug => $label): if ($slug === 'na') continue; ?>
                <label class="inline">
                  <input type="checkbox" class="noodle-opt" name="noodles[]" value="<?= e($slug) ?>" disabled> <?= e($label) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </details>
          <button type="submit">Add Item</button>
        </form>

        <form method="post" class="admin-items-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="bulk_update_items">
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead>
                <tr><th>ID</th><th>Item</th><th>Delete</th></tr>
              </thead>
              <tbody>
              <?php
                $deleteForms = [];
                try {
                  $items = $pdo->query(
                    'SELECT i.id, i.name, i.min_spice, i.max_spice, i.enabled, i.noodle_type, c.label AS course_label, c.slug AS course_slug
                     FROM items i JOIN courses c ON c.id = i.course_id
                     ORDER BY i.id ASC' // ascending as requested
                  )->fetchAll();
                } catch (Throwable $t) {
                  $items = [];
                  echo '<tr><td colspan="3">DB error loading items.</td></tr>';
                }
                $getAllowedProteinSlugs = $pdo->prepare(
                  'SELECT p.slug FROM item_allowed_proteins ap JOIN proteins p ON p.id = ap.protein_id WHERE ap.item_id = ?'
                );
                foreach ($items as $it):
                  $deleteFormId = 'delete-item-' . (int)$it['id'];
                  $deleteForms[] = '<form method="post" id="'.e($deleteFormId).'" style="display:none;">'
                    . csrf_field()
                    . '<input type="hidden" name="action" value="delete_item">'
                    . '<input type="hidden" name="id" value="'.(int)$it['id'].'">'
                    . '</form>';
                  $getAllowedProteinSlugs->execute([(int)$it['id']]);
                  $allowed = array_column($getAllowedProteinSlugs->fetchAll(), 'slug');
                  $isPANa = count($allowed) === 0;
                  $summary = $isPANa ? 'N/A' : implode(', ', array_map(function($s) use ($proteins){
                    foreach ($proteins as $p) if ($p['slug']===$s) return $p['label'] ?? $s;
                    return $s;
                  }, $allowed));

                  $storedNoodles = (string)($it['noodle_type'] ?? '');
                  $selectedNoodles = [];
                  if ($storedNoodles !== '' && strtolower($storedNoodles) !== 'na') {
                      $parts = preg_split('/[|,]/', $storedNoodles);
                      if (is_array($parts)) {
                          foreach ($parts as $part) {
                              $slugValue = trim((string)$part);
                              if ($slugValue === '' || isset($selectedNoodles[$slugValue])) {
                                  continue;
                              }
                              if (!array_key_exists($slugValue, $noodleChoices)) {
                                  continue;
                              }
                              $selectedNoodles[$slugValue] = $noodleChoices[$slugValue];
                          }
                      }
                  }
                  $isNoodleNA = empty($selectedNoodles);
                  $noodleSummary = $isNoodleNA ? 'N/A' : implode(', ', array_values($selectedNoodles));
              ?>
                <tr>
                  <td><?= (int)$it['id'] ?></td>
                  <td>
                    <div class="row-form item-row" data-item-id="<?= (int)$it['id'] ?>">
                      <input type="hidden" name="items[<?= (int)$it['id'] ?>][id]" value="<?= (int)$it['id'] ?>">
                      <label class="inline enabled-toggle">
                        <input type="checkbox" name="items[<?= (int)$it['id'] ?>][enabled]" value="1" <?= (int)$it['enabled'] ? 'checked' : '' ?>> Enabled
                      </label>
                      <input type="text" name="items[<?= (int)$it['id'] ?>][name]" value="<?= e($it['name']) ?>" placeholder="Name">
                      <label>Course</label>
                      <select name="items[<?= (int)$it['id'] ?>][course_slug]">
                        <?= $courseOptions($it['course_slug']) ?>
                      </select>
                      <label>Spice (min..max)</label>
                      <span class="nowrap">
                        <select name="items[<?= (int)$it['id'] ?>][min_spice]"><?= $spiceOptions((int)($it['min_spice'] ?? 0)) ?></select>
                        <span>to</span>
                        <select name="items[<?= (int)$it['id'] ?>][max_spice]"><?= $spiceOptions((int)($it['max_spice'] ?? 5)) ?></select>
                      </span>
                      <details class="dropdown noodle-group options-group">
                        <summary>Noodles: <span class="summary-text"><?= e($noodleSummary) ?></span></summary>
                        <div class="dropdown-panel">
                          <label class="inline">
                            <input type="checkbox" class="noodle-na" name="items[<?= (int)$it['id'] ?>][noodles_na]" value="1" <?= $isNoodleNA ? 'checked' : '' ?>> N/A
                          </label>
                          <hr style="border-color:rgba(255,255,255,0.15);">
                          <?php foreach ($noodleChoices as $slug => $label): if ($slug === 'na') continue; ?>
                            <label class="inline">
                              <input type="checkbox" class="noodle-opt" name="items[<?= (int)$it['id'] ?>][noodles][]" value="<?= e($slug) ?>" <?= isset($selectedNoodles[$slug]) ? 'checked' : '' ?> <?= $isNoodleNA ? 'disabled' : '' ?>> <?= e($label) ?>
                            </label>
                          <?php endforeach; ?>
                        </div>
                      </details>
                      <details class="dropdown protein-group options-group">
                        <summary>Proteins: <span class="summary-text"><?= e($summary) ?></span></summary>
                        <div class="dropdown-panel">
                          <label class="inline">
                            <input type="checkbox" class="protein-na" name="items[<?= (int)$it['id'] ?>][proteins_na]" value="1" <?= $isPANa ? 'checked' : '' ?>> N/A
                          </label>
                          <hr style="border-color:rgba(255,255,255,0.15);">
                          <?php foreach ($proteins as $p): ?>
                            <label class="inline">
                              <input type="checkbox" class="protein-opt" name="items[<?= (int)$it['id'] ?>][proteins][]" value="<?= e($p['slug']) ?>" <?= in_array($p['slug'], $allowed, true) ? 'checked' : '' ?>> <?= e($p['label'] ?? $p['slug']) ?>
                            </label>
                          <?php endforeach; ?>
                        </div>
                      </details>
                    </div>
                  </td>
                  <td class="delete-cell">
                    <button type="submit" form="<?= e($deleteFormId) ?>" onclick="return confirm('Delete this item?')">Delete</button>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="admin-items-actions">
            <button type="submit">Save all changes</button>
          </div>
        </form>
        <?php foreach ($deleteForms as $formHtml) { echo $formHtml; } ?>
      </section>
    <?php endif; ?>
  </div>
</div>

<script>
function refreshOptionGroup(group, optionClass, naClass) {
  if (!group) return;
  const summary = group.querySelector('.summary-text');
  const naBox = group.querySelector('.' + naClass);
  if (!summary || !naBox) return;

  const options = group.querySelectorAll('.' + optionClass);

  if (naBox.checked) {
    options.forEach(function (cb) {
      cb.checked = false;
      cb.disabled = true;
    });
    summary.textContent = 'N/A';
    return;
  }

  options.forEach(function (cb) {
    cb.disabled = false;
  });

  const labels = [];
  options.forEach(function (cb) {
    if (!cb.checked) return;
    const text = cb.parentElement ? cb.parentElement.textContent.trim() : '';
    if (text) labels.push(text);
  });

  summary.textContent = labels.length ? labels.join(', ') : 'None';
}

document.addEventListener('change', function (event) {
  if (event.target.classList.contains('protein-na') || event.target.classList.contains('protein-opt')) {
    const group = event.target.closest('.protein-group');
    if (!group) return;
    if (event.target.classList.contains('protein-opt') && event.target.checked) {
      const na = group.querySelector('.protein-na');
      if (na) na.checked = false;
    }
    refreshOptionGroup(group, 'protein-opt', 'protein-na');
  }

  if (event.target.classList.contains('noodle-na') || event.target.classList.contains('noodle-opt')) {
    const group = event.target.closest('.noodle-group');
    if (!group) return;
    if (event.target.classList.contains('noodle-opt') && event.target.checked) {
      const na = group.querySelector('.noodle-na');
      if (na) na.checked = false;
    }
    refreshOptionGroup(group, 'noodle-opt', 'noodle-na');
  }
});

document.querySelectorAll('.protein-group').forEach(function (group) {
  refreshOptionGroup(group, 'protein-opt', 'protein-na');
});

document.querySelectorAll('.noodle-group').forEach(function (group) {
  refreshOptionGroup(group, 'noodle-opt', 'noodle-na');
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>
