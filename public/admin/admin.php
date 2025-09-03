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
            $noodle  = $_POST['noodle_type'] ?? 'na';
            if ($name === '' || $slug === '') throw new RuntimeException('Name and course are required.');
            if ($min < 0 || $min > 5 || $max < 0 || $max > 5 || $min > $max) throw new RuntimeException('Spice range must be 0..5 and min ≤ max.');
            if (!in_array($noodle, ['na','flat_rice','thin_rice','egg_noodle'], true)) throw new RuntimeException('Invalid noodle option.');
            $c = $pdo->prepare('SELECT id FROM courses WHERE slug = ?');
            $c->execute([$slug]);
            $course = $c->fetch();
            if (!$course) throw new RuntimeException('Course not found.');
            $stmt = $pdo->prepare('INSERT INTO items (name, course_id, min_spice, max_spice, enabled, noodle_type) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $course['id'], $min, $max, $enabled, $noodle]);
            header("Location: /admin/admin.php?tab=items&ok=1"); exit;

        } elseif ($action === 'update_item') {
            $id      = (int)($_POST['id'] ?? 0);
            $name    = trim($_POST['name'] ?? '');
            $slug    = $_POST['course_slug'] ?? '';
            $min     = (int)($_POST['min_spice'] ?? 0);
            $max     = (int)($_POST['max_spice'] ?? 5);
            $enabled = !empty($_POST['enabled']) ? 1 : 0;
            $noodle  = $_POST['noodle_type'] ?? 'na';
            $proteinsNA   = !empty($_POST['proteins_na']);
            $proteinSlugs = $_POST['proteins'] ?? [];
            if ($id <= 0 || $name === '') throw new RuntimeException('Invalid item update.');
            if ($min < 0 || $min > 5 || $max < 0 || $max > 5 || $min > $max) throw new RuntimeException('Spice range must be 0..5 and min ≤ max.');
            if (!in_array($noodle, ['na','flat_rice','thin_rice','egg_noodle'], true)) throw new RuntimeException('Invalid noodle option.');

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
          $noodleOptions = function($cur) {
              $opts = ['na'=>'N/A','flat_rice'=>'Flat rice','thin_rice'=>'Thin rice','egg_noodle'=>'Egg noodle'];
              $out = '';
              foreach ($opts as $v=>$lab) {
                  $sel = ($cur === $v) ? ' selected' : '';
                  $out .= '<option value="'.$v.'"'.$sel.'>'.$lab.'</option>';
              }
              return $out;
          };
        ?>

        <form method="post" class="admin-form" style="margin-bottom:1rem;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_item">
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
          <label>Noodles</label>
          <select name="noodle_type" required>
            <?= $noodleOptions('na') ?>
          </select>
          <label class="inline">
            <input type="checkbox" name="enabled" value="1" checked> Enabled
          </label>
          <button type="submit">Add Item</button>
        </form>

        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr><th>ID</th><th>Item</th><th>Delete</th></tr>
            </thead>
            <tbody>
            <?php
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
                $getAllowedProteinSlugs->execute([(int)$it['id']]);
                $allowed = array_column($getAllowedProteinSlugs->fetchAll(), 'slug');
                $isPANa = count($allowed) === 0;
                $summary = $isPANa ? 'N/A' : implode(', ', array_map(function($s) use ($proteins){
                  foreach ($proteins as $p) if ($p['slug']===$s) return $p['label'] ?? $s;
                  return $s;
                }, $allowed));
            ?>
              <tr>
                <td><?= (int)$it['id'] ?></td>
                <td>
                  <form method="post" class="row-form item-row" data-item-id="<?= (int)$it['id'] ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_item">
                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                    <label>Name</label>
                    <input type="text" name="name" value="<?= e($it['name']) ?>">
                    <label>Course</label>
                    <select name="course_slug">
                      <?= $courseOptions($it['course_slug']) ?>
                    </select>
                    <label>Spice (min..max)</label>
                    <span class="nowrap">
                      <select name="min_spice"><?= $spiceOptions((int)($it['min_spice'] ?? 0)) ?></select>
                      <span>to</span>
                      <select name="max_spice"><?= $spiceOptions((int)($it['max_spice'] ?? 5)) ?></select>
                    </span>
                    <label>Noodles</label>
                    <select name="noodle_type">
                      <?= $noodleOptions($it['noodle_type'] ?? 'na') ?>
                    </select>
                    <label class="inline">
                      <input type="checkbox" name="enabled" value="1" <?= (int)$it['enabled'] ? 'checked' : '' ?>> Enabled
                    </label>
                    <details class="dropdown protein-group">
                      <summary>Proteins: <span class="summary-text"><?= e($summary) ?></span></summary>
                      <div class="dropdown-panel">
                        <label class="inline">
                          <input type="checkbox" class="protein-na" name="proteins_na" value="1" <?= $isPANa ? 'checked' : '' ?>> N/A
                        </label>
                        <hr style="border-color:rgba(255,255,255,0.15);">
                        <?php foreach ($proteins as $p): ?>
                          <label class="inline">
                            <input type="checkbox" class="protein-opt" name="proteins[]" value="<?= e($p['slug']) ?>" <?= in_array($p['slug'], $allowed, true) ? 'checked' : '' ?>> <?= e($p['label'] ?? $p['slug']) ?>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </details>
                    <button type="submit">Save</button>
                  </form>
                </td>
                <td class="delete-cell">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_item">
                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                    <button type="submit" onclick="return confirm('Delete this item?')">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
  </div>
</div>

<script>
document.addEventListener('change', function(e) {
  // Protein N/A behavior
  const na = e.target.closest('.protein-group') ? e.target.closest('.protein-group').querySelector('.protein-na') : null;
  if (e.target.classList.contains('protein-na')) {
    const group = e.target.closest('.protein-group');
    const opts = group.querySelectorAll('.protein-opt');
    if (e.target.checked) {
      summary.textContent = 'N/A';
    } else {
      const labels = [];
      group.querySelectorAll('.protein-opt:checked').forEach(cb => {
        const text = cb.parentElement.textContent.trim();
        labels.push(text);
      });
      summary.textContent = labels.length ? labels.join(', ') : 'None';
    }
  }
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>