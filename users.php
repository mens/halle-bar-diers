<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'db.php';
requireRole('write');

$user = getUser();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';
    $email = strtolower(trim($_POST['email'] ?? ''));
    $role  = $_POST['role'] ?? '';

    if ($formAction === 'save' && filter_var($email, FILTER_VALIDATE_EMAIL) && in_array($role, ['read', 'write'])) {
        $pdo->prepare("INSERT INTO user_roles (email, role) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE role = VALUES(role)")
            ->execute([$email, $role]);
    } elseif ($formAction === 'delete' && $email) {
        if ($email === $user['email']) {
            $flashError = 'Je kan je eigen account niet verwijderen.';
        } else {
            $pdo->prepare("DELETE FROM user_roles WHERE email = ?")->execute([$email]);
        }
    }
    if (!isset($flashError)) {
        header('Location: users.php');
        exit;
    }
}

$users = $pdo->query("SELECT email, role, created_at FROM user_roles ORDER BY role DESC, email")->fetchAll();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kantine — Gebruikers</title>
<link rel="stylesheet" href="css/pos.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700;900&family=Crimson+Pro:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Almendra:ital,wght@0,400;0,700;1,400;1,700&display=swap" rel="stylesheet">
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <span class="logo">🍺 HALLE-BAR-DIERS</span>
    <nav>
      <a href="index.php">Kassa</a>
      <a href="rapport.php">Rapporten</a>
      <a href="admin.php">Prijslijsten</a>
      <a href="users.php" class="nav-active">Gebruikers</a>
    </nav>
  </div>
  <div class="topbar-right">
    <span class="user-badge"><?= htmlspecialchars($user['name']) ?></span>
    <a href="logout.php" class="btn-logout">Uitloggen</a>
  </div>
</header>

<div class="admin-wrap">
  <div class="admin-header">
    <h1>Gebruikersbeheer</h1>
  </div>

  <?php if (isset($flashError)): ?>
    <p style="color:var(--red);margin-bottom:16px;"><?= htmlspecialchars($flashError) ?></p>
  <?php endif; ?>

  <div class="admin-table-wrap" style="margin-bottom:32px;">
    <table class="admin-table">
      <thead>
        <tr>
          <th>E-mail</th>
          <th>Rol</th>
          <th>Toegevoegd</th>
          <th>Acties</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="4" style="text-align:center;padding:20px;">Geen gebruikers.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['email']) ?></td>
          <td>
            <span class="badge <?= $u['role'] === 'write' ? 'badge-green' : 'badge-blue' ?>">
              <?= $u['role'] === 'write' ? 'Schrijven' : 'Lezen' ?>
            </span>
          </td>
          <td style="color:var(--text-dim);font-size:13px;"><?= htmlspecialchars($u['created_at']) ?></td>
          <td class="action-cell">
            <button class="btn-sm btn-secondary"
              onclick="openEditModal('<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>', '<?= $u['role'] ?>')">
              ✏️ Bewerken
            </button>
            <?php if ($u['email'] !== $user['email']): ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('Gebruiker <?= htmlspecialchars($u['email'], ENT_QUOTES) ?> verwijderen?')">
              <input type="hidden" name="form_action" value="delete">
              <input type="hidden" name="email" value="<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>">
              <button type="submit" class="btn-sm btn-danger">🗑 Verwijder</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="center-card" style="margin:0;max-width:460px;">
    <h3 id="user-modal-title" style="margin-bottom:20px;">Gebruiker toevoegen</h3>
    <form method="POST">
      <input type="hidden" name="form_action" value="save">
      <div class="form-group">
        <label>E-mailadres <span class="req">*</span></label>
        <input type="email" name="email" id="inp-email" placeholder="naam@<?= htmlspecialchars(GOOGLE_WORKSPACE_DOMAIN) ?>" required>
      </div>
      <div class="form-group">
        <label>Rol <span class="req">*</span></label>
        <select name="role" id="inp-role">
          <option value="read">Lezen — rapporten &amp; prijslijsten bekijken</option>
          <option value="write">Schrijven — rapporten verwijderen &amp; prijslijsten beheren</option>
        </select>
      </div>
      <div style="display:flex;gap:8px;margin-top:8px;">
        <button type="submit" class="btn-primary" style="flex:1;">Opslaan</button>
        <button type="button" id="btn-reset" class="btn-secondary" style="display:none;" onclick="resetForm()">Annuleren</button>
      </div>
    </form>
  </div>
</div>

<div id="toast-container"></div>

<script>
function openEditModal(email, role) {
  document.getElementById('user-modal-title').textContent = 'Gebruiker bewerken';
  const inp = document.getElementById('inp-email');
  inp.value = email;
  inp.readOnly = true;
  document.getElementById('inp-role').value = role;
  document.getElementById('btn-reset').style.display = '';
  inp.closest('.center-card').scrollIntoView({ behavior: 'smooth' });
}
function resetForm() {
  document.getElementById('user-modal-title').textContent = 'Gebruiker toevoegen';
  const inp = document.getElementById('inp-email');
  inp.value = '';
  inp.readOnly = false;
  document.getElementById('inp-role').value = 'read';
  document.getElementById('btn-reset').style.display = 'none';
}
function toast(msg, type='success') {
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  t.textContent = msg;
  document.getElementById('toast-container').appendChild(t);
  setTimeout(() => t.remove(), 3000);
}
</script>
</body>
</html>
