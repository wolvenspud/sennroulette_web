<?php

session_start();

if (empty($_SESSION['uid']) || empty($_SESSION['is_admin'])) {

    header("Location: /login.php"); exit;

}

require __DIR__ . '/../../src/db.php';



/* csrf + functions as before ... (unchanged) */

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function csrf_field(){ return '<input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['csrf']).'">'; }

function check_csrf(){ if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) { http_response_code(403); exit('bad csrf'); } }

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }



/* ... all your admin.php logic unchanged ... */



include __DIR__ . '/../header.php';

?>

<h1>Admin Panel</h1>

<?php if (!empty($_GET['ok'])): ?><p style="color:green">Saved.</p><?php endif; ?>



<!-- your edit/list logic as before -->



<?php include __DIR__ . '/../footer.php'; ?>


