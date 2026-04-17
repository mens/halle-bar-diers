<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kantine — Beheer</title>
<link rel="stylesheet" href="css/pos.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700;900&family=Crimson+Pro:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <span class="logo">🍺 HALLE-BAR-DIERS</span>
    <nav>
      <a href="index.php">Kassa</a>
      <a href="rapport.php">Rapporten</a>
      <a href="admin.php" class="nav-active">Beheer</a>
    </nav>
  </div>
</header>

<div class="admin-wrap">
  <div class="admin-header">
    <h1>Drankenbeheer</h1>
    <button class="btn-primary" onclick="openDrankModal()">+ Nieuwe drank</button>
  </div>

  <div class="admin-table-wrap">
    <table class="admin-table" id="dranken-table">
      <thead>
        <tr>
          <th>Naam</th>
          <th>Categorie</th>
          <th>Volgorde</th>
          <th>💰 Training</th>
          <th>💰 Evenement</th>
          <th>Actief</th>
          <th>Acties</th>
        </tr>
      </thead>
      <tbody id="dranken-tbody">
        <tr><td colspan="7" style="text-align:center;padding:20px;">Laden...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: drank bewerken/aanmaken -->
<div id="modal-drank" class="modal hidden">
  <div class="modal-box modal-wide">
    <h3 id="modal-drank-title">Drank Toevoegen</h3>
    <input type="hidden" id="drank-id" value="0">
    <div class="form-grid">
      <div class="form-group">
        <label>Naam <span class="req">*</span></label>
        <input type="text" id="drank-naam" placeholder="bv. Pils, Cola, Koffie">
      </div>
      <div class="form-group">
        <label>Categorie</label>
        <input type="text" id="drank-categorie" placeholder="bv. Bier, Frisdrank, Warme dranken" list="categorie-list">
        <datalist id="categorie-list">
          <option value="Bier">
          <option value="Frisdrank">
          <option value="Warme dranken">
          <option value="Sterke dranken">
          <option value="Snacks">
          <option value="Overig">
        </datalist>
      </div>
      <div class="form-group">
        <label>Volgorde (lager = eerst)</label>
        <input type="number" id="drank-volgorde" value="0" min="0">
      </div>
      <div class="form-group">
        <label>Prijs Training (€)</label>
        <input type="number" id="drank-prijs-1" step="0.10" min="0" value="0.00">
      </div>
      <div class="form-group">
        <label>Prijs Evenement (€)</label>
        <input type="number" id="drank-prijs-2" step="0.10" min="0" value="0.00">
      </div>
      <div class="form-group">
        <label>Actief</label>
        <select id="drank-actief">
          <option value="1">Ja</option>
          <option value="0">Nee</option>
        </select>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn-secondary" onclick="closeModal('modal-drank')">Annuleer</button>
      <button class="btn-primary" onclick="saveDrank()">Opslaan</button>
    </div>
  </div>
</div>

<div id="toast-container"></div>

<script>
// Laad alle dranken
async function laadDranken() {
  const res = await api('get_alle_dranken', {}, 'GET');
  const tbody = document.getElementById('dranken-tbody');
  if (!res.ok || !res.dranken.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;">Geen dranken gevonden.</td></tr>';
    return;
  }
  tbody.innerHTML = res.dranken.map(d => `
    <tr class="${d.actief ? '' : 'row-inactive'}">
      <td><strong>${esc(d.naam)}</strong></td>
      <td>${esc(d.categorie || '—')}</td>
      <td>${d.volgorde}</td>
      <td class="price-cell">${d.prijs_training ? '€ ' + parseFloat(d.prijs_training).toFixed(2) : '—'}</td>
      <td class="price-cell">${d.prijs_event   ? '€ ' + parseFloat(d.prijs_event).toFixed(2)   : '—'}</td>
      <td><span class="badge ${d.actief ? 'badge-green' : 'badge-red'}">${d.actief ? 'Ja' : 'Nee'}</span></td>
      <td class="action-cell">
        <button class="btn-sm btn-secondary" onclick="openDrankModal(${JSON.stringify(d).replace(/"/g,'&quot;')})">✏️ Bewerken</button>
        ${d.actief ? `<button class="btn-sm btn-danger" onclick="deactiveerDrank(${d.id})">🚫 Deactiveer</button>` : ''}
      </td>
    </tr>
  `).join('');
}

function openDrankModal(d = null) {
  document.getElementById('drank-id').value      = d ? d.id : 0;
  document.getElementById('drank-naam').value     = d ? d.naam : '';
  document.getElementById('drank-categorie').value= d ? d.categorie : '';
  document.getElementById('drank-volgorde').value = d ? d.volgorde : 0;
  document.getElementById('drank-prijs-1').value  = d && d.prijs_training ? parseFloat(d.prijs_training).toFixed(2) : '0.00';
  document.getElementById('drank-prijs-2').value  = d && d.prijs_event    ? parseFloat(d.prijs_event).toFixed(2)    : '0.00';
  document.getElementById('drank-actief').value   = d ? d.actief : 1;
  document.getElementById('modal-drank-title').textContent = d ? 'Drank Bewerken' : 'Drank Toevoegen';
  openModal('modal-drank');
}

async function saveDrank() {
  const data = {
    action:     'save_drank',
    id:         document.getElementById('drank-id').value,
    naam:       document.getElementById('drank-naam').value,
    categorie:  document.getElementById('drank-categorie').value,
    volgorde:   document.getElementById('drank-volgorde').value,
    prijs_1:    document.getElementById('drank-prijs-1').value,
    prijs_2:    document.getElementById('drank-prijs-2').value,
    actief:     document.getElementById('drank-actief').value,
  };
  const res = await api('', data, 'POST');
  if (res.ok) {
    closeModal('modal-drank');
    toast('Drank opgeslagen ✓', 'success');
    laadDranken();
  } else {
    toast(res.error, 'error');
  }
}

async function deactiveerDrank(id) {
  if (!confirm('Drank deactiveren?')) return;
  const res = await apiPost({ action: 'delete_drank', id });
  if (res.ok) { toast('Drank gedeactiveerd', 'success'); laadDranken(); }
}

// Shared helpers (duplicated for standalone page)
function esc(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function openModal(id)  { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function toast(msg, type='success') {
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  t.textContent = msg;
  document.getElementById('toast-container').appendChild(t);
  setTimeout(() => t.remove(), 3000);
}
async function api(action, params={}, method='POST') {
  const url = action ? `api.php?action=${action}` : 'api.php';
  const opts = method === 'GET'
    ? { method: 'GET' }
    : { method: 'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams(params).toString() };
  const r = await fetch(url, opts);
  return r.json();
}
async function apiPost(data) {
  return api('', data, 'POST');
}

laadDranken();
</script>
</body>
</html>
