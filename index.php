<?php
require_once 'auth.php';
$user    = getUser();
$isWrite = hasRole('write');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Kantine POS</title>
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
      <a href="index.php" class="nav-active">Kassa</a>
      <?php if ($user): ?>
      <a href="rapport.php">Rapporten</a>
      <a href="admin.php">Prijslijsten</a>
      <?php if ($isWrite): ?><a href="users.php">Gebruikers</a><?php endif; ?>
      <?php endif; ?>
    </nav>
  </div>
  <div id="shift-status" class="shift-badge shift-none">Geen actieve shift</div>
  <div class="topbar-right">
    <?php if ($user): ?>
    <span class="user-badge"><?= htmlspecialchars($user['name']) ?></span>
    <a href="logout.php" class="btn-logout">Uitloggen</a>
    <?php else: ?>
    <a href="login.php" class="btn-logout">Inloggen</a>
    <?php endif; ?>
  </div>
</header>

<!-- GEEN SHIFT: start scherm -->
<div id="screen-no-shift" class="screen">
  <div class="center-card">
    <h1>🍺</h1>
    <h2>De Hallebardiers - Nieuwe Shift Starten</h2>
    <?php if ($user): ?>
    <p style="color:var(--text-dim);margin-bottom:20px;">
      Gestart als <strong style="color:var(--accent)"><?= htmlspecialchars($user['name']) ?></strong>
    </p>
    <?php else: ?>
    <div class="form-group">
      <label>Voornaam <span class="req">*</span></label>
      <input type="text" id="inp-voornaam" placeholder="Voornaam" autocomplete="given-name">
    </div>
    <div class="form-group">
      <label>Achternaam <span class="req">*</span></label>
      <input type="text" id="inp-achternaam" placeholder="Achternaam" autocomplete="family-name">
    </div>
    <div class="form-group">
      <label>Wachtwoord shift <span class="req">*</span></label>
      <input type="password" id="inp-shift-password" placeholder="Kies een wachtwoord voor deze shift" autocomplete="new-password">
    </div>
    <?php endif; ?>
    <div class="form-group">
      <label>Prijslijst</label>
      <div class="radio-group">
        <label class="radio-card">
          <input type="radio" name="prijslijst" value="1" checked>
          <span>🏋️ Training</span>
        </label>
        <label class="radio-card">
          <input type="radio" name="prijslijst" value="2">
          <span>🎉 Evenement</span>
        </label>
      </div>
    </div>
    <button class="btn-primary btn-xl" onclick="startShift()">Shift Starten</button>
  </div>
</div>

<!-- WACHTWOORD: unlock scherm -->
<div id="screen-unlock-shift" class="screen hidden">
  <div class="center-card">
    <h1>🔒</h1>
    <h2>Shift Ontgrendelen</h2>
    <p style="color:var(--text-dim);margin-bottom:20px;">Er is een actieve shift. Voer het wachtwoord in om toegang te krijgen.</p>
    <div class="form-group">
      <label>Wachtwoord <span class="req">*</span></label>
      <input type="password" id="inp-unlock-password" placeholder="Shift wachtwoord" autocomplete="current-password">
    </div>
    <button class="btn-primary btn-xl" onclick="unlockShift()">Ontgrendelen</button>
  </div>
</div>

<!-- ACTIEVE SHIFT: POS scherm -->
<div id="screen-pos" class="screen hidden">
  <div class="pos-layout">

    <!-- Linker kolom: tabs -->
    <aside class="tabs-panel">
      <div class="panel-header">
        <h3>Tabs</h3>
        <button class="btn-sm btn-green" onclick="openNewTabModal()">+ Nieuwe tab</button>
      </div>
      <div id="tabs-list" class="tabs-list"></div>
      <div class="shift-footer" style="display:flex;flex-direction:column;gap:6px;">
        <button class="btn-sm btn-primary" onclick="openDirectSaleModal()">⚡ Directe verkoop</button>
        <button class="btn-sm btn-danger" onclick="openCloseShiftModal()">Shift Sluiten</button>
      </div>
    </aside>

    <!-- Midden: actieve tab detail -->
    <main class="tab-detail" id="tab-detail">
      <div class="empty-state">
        <p>👈 Selecteer of maak een tab aan</p>
      </div>
    </main>

    <!-- Rechter kolom: drankenkaart -->
    <aside class="drinks-panel" id="drinks-panel">
      <div class="panel-header">
        <h3>Dranken</h3>
        <span id="prijslijst-badge" class="badge-list"></span>
      </div>
      <div id="drinks-grid" class="drinks-grid"></div>
    </aside>

  </div>
</div>

<!-- Modal: directe verkoop -->
<div id="modal-direct-sale" class="modal hidden">
  <div class="modal-box modal-xl">
    <h3>⚡ Directe Verkoop</h3>
    <div class="direct-sale-layout">
      <div class="ds-drinks">
        <div id="ds-drinks-grid" class="drinks-grid"></div>
      </div>
      <div class="ds-order">
        <div class="ds-order-label">Bestelling</div>
        <div id="ds-order-items" class="ds-order-items">
          <p style="color:var(--text-dim);font-size:14px;padding:16px 0;text-align:center;">Voeg dranken toe ←</p>
        </div>
        <div id="ds-totaal" class="ds-totaal">€ 0.00</div>
        <div style="margin-bottom:14px;">
          <div style="font-size:12px;font-weight:600;letter-spacing:.8px;text-transform:uppercase;color:var(--text-dim);margin-bottom:8px;">Betaalwijze</div>
          <div class="radio-group">
            <label class="radio-card">
              <input type="radio" name="ds-betaalwijze" value="cash" checked>
              <span>💵 Cash</span>
            </label>
            <label class="radio-card">
              <input type="radio" name="ds-betaalwijze" value="payconiq">
              <span>📱 Payconiq</span>
            </label>
          </div>
        </div>
        <button id="btn-ds-pay" class="btn-primary btn-xl" onclick="confirmDirectSale()" disabled>✓ Betalen</button>
      </div>
    </div>
    <div style="text-align:right;margin-top:16px;">
      <button class="btn-secondary" onclick="closeModal('modal-direct-sale')">Annuleer</button>
    </div>
  </div>
</div>

<!-- Modal: nieuwe tab -->
<div id="modal-new-tab" class="modal hidden">
  <div class="modal-box">
    <h3>Nieuwe Tab</h3>
    <div class="form-group">
      <label>Naam klant / tafel</label>
      <input type="text" id="inp-tab-naam" placeholder="bv. Jan, Tafel 3" autocomplete="off">
    </div>
    <div class="modal-actions">
      <button class="btn-secondary" onclick="closeModal('modal-new-tab')">Annuleer</button>
      <button class="btn-primary" onclick="createTab()">Aanmaken</button>
    </div>
  </div>
</div>

<!-- Modal: betaling -->
<div id="modal-betaling" class="modal hidden">
  <div class="modal-box">
    <h3>Tab Afsluiten</h3>
    <div id="betaling-overzicht" class="betaling-overzicht"></div>
    <p class="betaling-vraag">Betaalwijze:</p>
    <div class="radio-group">
      <label class="radio-card">
        <input type="radio" name="betaalwijze" value="cash" checked>
        <span>💵 Cash</span>
      </label>
      <label class="radio-card">
        <input type="radio" name="betaalwijze" value="payconiq">
        <span>📱 Payconiq</span>
      </label>
    </div>
    <div class="modal-actions">
      <button class="btn-secondary" onclick="closeModal('modal-betaling')">Annuleer</button>
      <button class="btn-primary btn-xl" onclick="confirmPayment()">✓ Betaling Bevestigen</button>
    </div>
  </div>
</div>

<!-- Modal: shift sluiten -->
<div id="modal-close-shift" class="modal hidden">
  <div class="modal-box">
    <h3>Shift Sluiten</h3>
    <p>Voeg eventueel een opmerking toe:</p>
    <textarea id="inp-opmerking" rows="4" placeholder="Opmerkingen voor deze shift..."></textarea>
    <div class="modal-actions">
      <button class="btn-secondary" onclick="closeModal('modal-close-shift')">Annuleer</button>
      <button class="btn-danger" onclick="closeShift()">Shift Sluiten</button>
    </div>
  </div>
</div>

<!-- Toast notificaties -->
<div id="toast-container"></div>

<script>
const PAGE_USER = <?= json_encode($user ? ['name' => $user['name'], 'write' => $isWrite] : null) ?>;
</script>
<script src="js/pos.js"></script>
</body>
</html>
