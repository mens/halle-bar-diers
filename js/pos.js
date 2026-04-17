// pos.js — Kantine POS frontend logica

let activeShift   = null;
let activeTabId   = null;
let drankData     = [];
let betalingTabId = null;

// ── INITIALISATIE ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  laadActieveShift();
});

async function laadActieveShift() {
  const res = await api('get_active_shift', {}, 'GET');
  if (res.ok && res.shift) {
    activeShift = res.shift;
    toonPosScherm();
  } else {
    toonStartScherm();
  }
}

// ── SHIFT BEHEER ──────────────────────────────────────────────
async function startShift() {
  const naam = document.getElementById('inp-verantwoordelijke').value.trim();
  const lijst = document.querySelector('input[name="prijslijst"]:checked')?.value || '1';
  if (!naam) { toast('Voer de naam van de verantwoordelijke in.', 'error'); return; }
  const res = await apiPost({ action: 'start_shift', verantwoordelijke: naam, prijslijst_id: lijst });
  if (res.ok) {
    toast('Shift gestart!', 'success');
    laadActieveShift();
  } else {
    toast(res.error, 'error');
  }
}

async function sluitShift() {
  const opmerking = document.getElementById('inp-opmerking').value.trim();
  const res = await apiPost({ action: 'close_shift', shift_id: activeShift.id, opmerking });
  if (res.ok) {
    closeModal('modal-close-shift');
    toast('Shift gesloten.', 'success');
    activeShift = null;
    activeTabId = null;
    setTimeout(() => { window.location.href = 'rapport.php'; }, 800);
  } else {
    toast(res.error, 'error');
  }
}

function openCloseShiftModal() {
  document.getElementById('inp-opmerking').value = '';
  openModal('modal-close-shift');
}

// ── SCHERM WISSELEN ───────────────────────────────────────────
function toonStartScherm() {
  document.getElementById('screen-no-shift').classList.remove('hidden');
  document.getElementById('screen-pos').classList.add('hidden');
  document.getElementById('shift-status').textContent = 'Geen actieve shift';
  document.getElementById('shift-status').className = 'shift-badge shift-none';
}

function toonPosScherm() {
  document.getElementById('screen-no-shift').classList.add('hidden');
  document.getElementById('screen-pos').classList.remove('hidden');
  const badge = document.getElementById('shift-status');
  badge.textContent = `${activeShift.verantwoordelijke} — ${activeShift.prijslijst_naam}`;
  badge.className = 'shift-badge shift-active';
  renderTabs(activeShift.tabs || []);
  laadDranken();
}

// ── TABS RENDER ───────────────────────────────────────────────
function renderTabs(tabs) {
  const list = document.getElementById('tabs-list');
  if (!tabs.length) {
    list.innerHTML = '<p style="color:var(--text-dim);font-size:13px;padding:8px;">Nog geen tabs.</p>';
    return;
  }
  list.innerHTML = tabs.map(t => `
    <div class="tab-item ${t.id == activeTabId ? 'tab-selected' : ''}" onclick="selectTab(${t.id})">
      <div class="tab-naam">${esc(t.naam)}</div>
      <div class="tab-subtotaal">€ ${parseFloat(t.subtotaal||0).toFixed(2)}</div>
    </div>
  `).join('');
}

async function refreshTabs() {
  const res = await api('get_active_shift', {}, 'GET');
  if (res.ok && res.shift) {
    activeShift = res.shift;
    renderTabs(res.shift.tabs || []);
  }
}

// ── TAB SELECTEREN ────────────────────────────────────────────
async function selectTab(tabId) {
  activeTabId = tabId;
  // Update visuele selectie
  document.querySelectorAll('.tab-item').forEach(el => el.classList.remove('tab-selected'));
  event.currentTarget?.classList.add('tab-selected');
  await renderTabDetail(tabId);
}

async function renderTabDetail(tabId) {
  const res = await api(`get_tab&tab_id=${tabId}`, {}, 'GET');
  if (!res.ok) { toast(res.error, 'error'); return; }
  const tab = res.tab;
  const totaal = parseFloat(tab.totaal || 0);
  const det = document.getElementById('tab-detail');

  det.innerHTML = `
    <div class="tab-detail-header">
      <span class="tab-detail-naam">${esc(tab.naam)}</span>
      <div class="tab-detail-actions">
        <button class="btn-sm btn-danger" onclick="verwijderTab(${tab.id})">🗑 Verwijder</button>
        <button class="btn-sm btn-green" onclick="openBetalingModal(${tab.id})">💳 Betalen</button>
      </div>
    </div>
    ${tab.regels.length ? `
    <div class="tab-regels">
      ${tab.regels.map(r => `
        <div class="tab-regel-row">
          <div>
            <div class="tab-regel-naam">${esc(r.drank_naam)}</div>
            <div class="tab-regel-prijs">€ ${parseFloat(r.prijs).toFixed(2)} / stuk</div>
          </div>
          <div class="tab-regel-controls">
            <button class="qty-btn" onclick="updateAantal(${r.id}, ${tab.id}, ${r.aantal - 1})">−</button>
            <span class="qty-num">${r.aantal}</span>
            <button class="qty-btn" onclick="updateAantal(${r.id}, ${tab.id}, ${r.aantal + 1})">+</button>
          </div>
          <div class="regel-subtotaal">€ ${(r.prijs * r.aantal).toFixed(2)}</div>
          <button class="btn-del-regel" onclick="verwijderRegel(${r.id}, ${tab.id})" title="Verwijderen">✕</button>
        </div>
      `).join('')}
    </div>` : `<div style="color:var(--text-dim);padding:20px;text-align:center;">Tab is leeg. Voeg dranken toe →</div>`}
    <div class="tab-totaal-bar">
      <span class="totaal-label">Totaal</span>
      <span class="totaal-bedrag">€ ${totaal.toFixed(2)}</span>
    </div>
  `;
}

// ── TAB AANMAKEN ──────────────────────────────────────────────
function openNewTabModal() {
  document.getElementById('inp-tab-naam').value = '';
  openModal('modal-new-tab');
  setTimeout(() => document.getElementById('inp-tab-naam').focus(), 50);
}

async function createTab() {
  const naam = document.getElementById('inp-tab-naam').value.trim();
  if (!naam) { toast('Voer een naam in.', 'error'); return; }
  const res = await apiPost({ action: 'open_tab', shift_id: activeShift.id, naam });
  if (res.ok) {
    closeModal('modal-new-tab');
    await refreshTabs();
    await selectTab(res.tab_id);
    // Selecteer visueel na refresh
    document.querySelectorAll('.tab-item').forEach(el => {
      if (el.onclick?.toString().includes(res.tab_id)) el.classList.add('tab-selected');
    });
  } else {
    toast(res.error, 'error');
  }
}

async function verwijderTab(tabId) {
  if (!confirm('Tab verwijderen met alle bestellingen?')) return;
  const res = await apiPost({ action: 'delete_tab', tab_id: tabId });
  if (res.ok) {
    activeTabId = null;
    document.getElementById('tab-detail').innerHTML = '<div class="empty-state"><p>👈 Selecteer of maak een tab aan</p></div>';
    await refreshTabs();
  }
}

// ── DRANKEN TOEVOEGEN ─────────────────────────────────────────
async function laadDranken() {
  const res = await api(`get_dranken&prijslijst_id=${activeShift.prijslijst_id}`, {}, 'GET');
  if (!res.ok) return;
  drankData = res.dranken;
  renderDranken(drankData);
  document.getElementById('prijslijst-badge').textContent = activeShift.prijslijst_naam;
}

function renderDranken(dranken) {
  const grid = document.getElementById('drinks-grid');
  // Groepeer per categorie
  const cats = {};
  dranken.forEach(d => {
    const c = d.categorie || 'Overig';
    if (!cats[c]) cats[c] = [];
    cats[c].push(d);
  });
  grid.innerHTML = Object.entries(cats).map(([cat, items]) => `
    <div class="drinks-categorie">
      <div class="drinks-cat-label">${esc(cat)}</div>
      <div class="drinks-buttons">
        ${items.map(d => `
          <button class="drink-btn" onclick="voegToeAanTab(${d.id})" ${!activeTabId ? 'title="Selecteer eerst een tab"' : ''}>
            <span class="drink-btn-naam">${esc(d.naam)}</span>
            <span class="drink-btn-prijs">€ ${parseFloat(d.prijs).toFixed(2)}</span>
          </button>
        `).join('')}
      </div>
    </div>
  `).join('');
}

async function voegToeAanTab(drankId) {
  if (!activeTabId) {
    toast('Selecteer eerst een tab!', 'error');
    return;
  }
  const res = await apiPost({ action: 'add_to_tab', tab_id: activeTabId, drank_id: drankId, aantal: 1 });
  if (res.ok) {
    await renderTabDetail(activeTabId);
    await refreshTabs();
    // Flash effect op drank knop
    const btns = document.querySelectorAll('.drink-btn');
    btns.forEach(btn => {
      if (btn.onclick?.toString().includes(drankId)) {
        btn.style.borderColor = 'var(--green)';
        setTimeout(() => btn.style.borderColor = '', 300);
      }
    });
  } else {
    toast(res.error, 'error');
  }
}

// ── REGEL BEHEER ──────────────────────────────────────────────
async function verwijderRegel(regelId, tabId) {
  const res = await apiPost({ action: 'remove_tab_regel', regel_id: regelId, tab_id: tabId });
  if (res.ok) {
    await renderTabDetail(tabId);
    await refreshTabs();
  }
}

async function updateAantal(regelId, tabId, nieuwAantal) {
  const res = await apiPost({ action: 'update_regel_aantal', regel_id: regelId, tab_id: tabId, aantal: nieuwAantal });
  if (res.ok) {
    await renderTabDetail(tabId);
    await refreshTabs();
  }
}

// ── BETALING ──────────────────────────────────────────────────
async function openBetalingModal(tabId) {
  betalingTabId = tabId;
  const res = await api(`get_tab&tab_id=${tabId}`, {}, 'GET');
  if (!res.ok) return;
  const tab = res.tab;
  const totaal = parseFloat(tab.totaal || 0);
  const overzicht = document.getElementById('betaling-overzicht');
  overzicht.innerHTML = tab.regels.map(r =>
    `<div class="regel"><span>${esc(r.drank_naam)} × ${r.aantal}</span><span>€ ${(r.prijs * r.aantal).toFixed(2)}</span></div>`
  ).join('') + `<div class="regel regel-total"><span>TOTAAL</span><span>€ ${totaal.toFixed(2)}</span></div>`;
  // Selecteer standaard cash
  document.querySelector('input[name="betaalwijze"][value="cash"]').checked = true;
  openModal('modal-betaling');
}

async function bevestigBetaling() {
  const betaalwijze = document.querySelector('input[name="betaalwijze"]:checked')?.value;
  if (!betaalwijze) { toast('Kies een betaalwijze.', 'error'); return; }
  const res = await apiPost({ action: 'betaal_tab', tab_id: betalingTabId, betaalwijze });
  if (res.ok) {
    closeModal('modal-betaling');
    activeTabId = null;
    document.getElementById('tab-detail').innerHTML = '<div class="empty-state"><p>👈 Selecteer of maak een tab aan</p></div>';
    toast(`Betaald via ${betaalwijze === 'cash' ? 'cash 💵' : 'Payconiq 📱'}`, 'success');
    await refreshTabs();
  } else {
    toast(res.error, 'error');
  }
}

// ── HELPERS ───────────────────────────────────────────────────
function esc(s) {
  const d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}

function openModal(id)  { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function toast(msg, type = 'success') {
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  t.textContent = msg;
  document.getElementById('toast-container').appendChild(t);
  setTimeout(() => t.remove(), 3000);
}

async function api(action, params = {}, method = 'POST') {
  const url = `api.php?action=${action}`;
  const opts = method === 'GET'
    ? { method: 'GET' }
    : {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(params).toString()
      };
  const r = await fetch(url, opts);
  return r.json();
}

async function apiPost(data) {
  return api('', data, 'POST');
}

// Sluit modals bij klik buiten modal-box
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal')) {
    e.target.classList.add('hidden');
  }
});

// Enter bevestigt modals
document.addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    if (!document.getElementById('modal-new-tab').classList.contains('hidden')) createTab();
  }
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal:not(.hidden)').forEach(m => m.classList.add('hidden'));
  }
});
