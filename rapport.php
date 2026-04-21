<?php
require_once 'auth.php';
requireRole('read');
$user     = getUser();
$canWrite = hasRole('write');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kantine — Rapporten</title>
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
      <a href="rapport.php" class="nav-active">Rapporten</a>
      <a href="admin.php">Prijslijsten</a>
      <?php if ($canWrite): ?><a href="users.php">Gebruikers</a><?php endif; ?>
    </nav>
  </div>
  <div class="topbar-right">
    <span class="user-badge"><?= htmlspecialchars($user['name']) ?></span>
    <a href="logout.php" class="btn-logout">Uitloggen</a>
  </div>
</header>

<div class="rapport-wrap">
  <div class="rapport-left">
    <h2>Shifts</h2>
    <div id="shifts-lijst">Laden...</div>
  </div>
  <div class="rapport-right" id="rapport-detail">
    <div class="empty-state"><p>← Selecteer een shift</p></div>
  </div>
</div>

<div id="toast-container"></div>

<script>
const CAN_WRITE = <?= $canWrite ? 'true' : 'false' ?>;

async function loadShifts() {
  const res = await api('get_shifts_lijst', {}, 'GET');
  const cont = document.getElementById('shifts-lijst');
  if (!res.shifts || !res.shifts.length) {
    cont.innerHTML = '<p class="dim">Geen shifts gevonden.</p>';
    return;
  }
  cont.innerHTML = res.shifts.map(s => `
    <div class="shift-row ${s.gesloten ? '' : 'shift-open-row'}" onclick="loadReport(${s.id})">
      <div class="shift-row-top">
        <strong>${esc(s.verantwoordelijke)}</strong>
        <div style="display:flex;align-items:center;gap:6px;">
          <span class="badge ${s.gesloten ? 'badge-gray' : 'badge-green'}">${s.gesloten ? 'Gesloten' : 'Open'}</span>
          ${CAN_WRITE ? `<button class="btn-icon-danger" onclick="deleteShift(event, ${s.id})" title="Shift verwijderen">🗑</button>` : ''}
        </div>
      </div>
      <div class="shift-row-meta">
        🕐 ${fmtDt(s.begintijd)} &nbsp;|&nbsp; 🏷️ ${esc(s.prijslijst)}
      </div>
      <div class="shift-row-totals">
        💶 €${parseFloat(s.omzet).toFixed(2)} &nbsp;·&nbsp; ${s.aantal_tabs} tabs
      </div>
    </div>
  `).join('');
}

async function loadReport(shift_id) {
  document.getElementById('rapport-detail').innerHTML = '<div class="empty-state"><p>Laden...</p></div>';
  const res = await api(`get_shift_rapport&shift_id=${shift_id}`, {}, 'GET');
  if (!res.ok) { toast(res.error, 'error'); return; }
  const s = res.shift;

  const cash     = res.financieel.find(f => f.betaalwijze === 'cash')     || {bedrag:0, tabs:0};
  const payconiq = res.financieel.find(f => f.betaalwijze === 'payconiq') || {bedrag:0, tabs:0};
  const gratis   = res.financieel.find(f => f.betaalwijze === 'gratis')   || {bedrag:0, tabs:0, redenen: null};
  const totaal   = parseFloat(cash.bedrag||0) + parseFloat(payconiq.bedrag||0);

  const gratisRedenen = gratis.redenen ? JSON.parse(gratis.redenen) : [];
  const gratisBox = parseFloat(gratis.bedrag||0) > 0 ? `
    <div class="money-box money-gratis">
      <div class="money-label">🎁 Gratis</div>
      <div class="money-amount">€ ${parseFloat(gratis.bedrag||0).toFixed(2)}</div>
      <div class="money-tabs">${gratis.tabs} tabs</div>
    </div>` : '';
  const gratisRedenenBlock = gratisRedenen.length ? `
    <div class="rapport-gratis-redenen">
      <strong>🎁 Redenen gratis</strong>
      <ul>${gratisRedenen.map(r => `<li>${esc(r)}</li>`).join('')}</ul>
    </div>` : '';

  document.getElementById('rapport-detail').innerHTML = `
    <div class="rapport-card">
      <div class="rapport-topinfo">
        <div>
          <h2>${esc(s.verantwoordelijke)}</h2>
          <span class="dim">${fmtDt(s.begintijd)} ${s.eindtijd ? '→ ' + fmtDt(s.eindtijd) : '(nog open)'}</span>
        </div>
        <span class="badge badge-blue">${esc(s.prijslijst_naam)}</span>
      </div>

      <div class="rapport-money">
        <div class="money-box money-cash">
          <div class="money-label">💵 Cash</div>
          <div class="money-amount">€ ${parseFloat(cash.bedrag||0).toFixed(2)}</div>
          <div class="money-tabs">${cash.tabs} tabs</div>
        </div>
        <div class="money-box money-payconiq">
          <div class="money-label">📱 Payconiq</div>
          <div class="money-amount">€ ${parseFloat(payconiq.bedrag||0).toFixed(2)}</div>
          <div class="money-tabs">${payconiq.tabs} tabs</div>
        </div>
        ${gratisBox}
        <div class="money-box money-total">
          <div class="money-label">TOTAAL ONTVANGEN</div>
          <div class="money-amount">€ ${totaal.toFixed(2)}</div>
        </div>
      </div>

      ${gratisRedenenBlock}
      ${s.opmerking ? `<div class="rapport-opmerking"><strong>📝 Opmerking:</strong> ${esc(s.opmerking)}</div>` : ''}

      <h3>Verkoop per drank</h3>
      ${res.verkoop.length ? `
      <table class="rapport-table">
        <thead><tr><th>Drank</th><th>Stuks</th><th>Omzet</th></tr></thead>
        <tbody>
          ${res.verkoop.map(v => `
            <tr>
              <td class="left">${esc(v.drank_naam)}</td>
              <td class="center">${v.totaal_stuks}</td>
              <td class="price-cell">€ ${parseFloat(v.totaal_bedrag).toFixed(2)}</td>
            </tr>
          `).join('')}
        </tbody>
        <tfoot><tr><th colspan="2">Totaal</th><th class="price-cell">€ ${totaal.toFixed(2)}</th></tr></tfoot>
      </table>` : '<p class="dim">Geen verkopen geregistreerd.</p>'}
    </div>
  `;
}

async function deleteShift(event, shiftId) {
  event.stopPropagation();
  if (!confirm('Shift en alle bijhorende data definitief verwijderen?')) return;
  const res = await apiPost({ action: 'delete_shift', shift_id: shiftId });
  if (res.ok) {
    toast('Shift verwijderd.', 'success');
    document.getElementById('rapport-detail').innerHTML = '<div class="empty-state"><p>← Selecteer een shift</p></div>';
    loadShifts();
  } else {
    toast(res.error, 'error');
  }
}

function fmtDt(s) {
  if (!s) return '';
  const d = new Date(s);
  return d.toLocaleDateString('nl-BE', {day:'2-digit',month:'2-digit',year:'numeric'})
       + ' ' + d.toLocaleTimeString('nl-BE', {hour:'2-digit',minute:'2-digit'});
}
function esc(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function toast(msg, type='success') {
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  t.textContent = msg;
  document.getElementById('toast-container').appendChild(t);
  setTimeout(() => t.remove(), 3000);
}
async function api(action, params={}, method='POST') {
  const url = `api.php?action=${action}`;
  const opts = method === 'GET'
    ? { method: 'GET' }
    : { method: 'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams(params).toString() };
  const r = await fetch(url, opts);
  if (r.status === 401) { window.location.href = '/login.php'; return {}; }
  return r.json();
}
async function apiPost(data) { return api('', data, 'POST'); }

loadShifts();
</script>
</body>
</html>
