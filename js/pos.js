// pos.js — Kantine POS frontend

let activeShift   = null;
let activeTabId   = null;
let drinkData     = [];
let paymentTabId  = null;
let pendingShiftId = null; // shift ID shown on the unlock screen

// ── INIT ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadActiveShift();
});

async function loadActiveShift() {
  const res = await api('get_active_shift', {}, 'GET');
  if (res.ok && res.shift) {
    if (res.shift.needs_password) {
      pendingShiftId = res.shift.id;
      showUnlockScreen();
    } else {
      activeShift = res.shift;
      showPosScreen();
    }
  } else {
    showStartScreen();
  }
}

// ── SHIFT BEHEER ──────────────────────────────────────────────
async function startShift() {
  const lijst = document.querySelector('input[name="prijslijst"]:checked')?.value || '1';
  const data  = { action: 'start_shift', prijslijst_id: lijst };

  // Guest mode: name + password fields are present in the DOM
  const voornaamEl = document.getElementById('inp-voornaam');
  if (voornaamEl) {
    data.voornaam   = voornaamEl.value.trim();
    data.achternaam = document.getElementById('inp-achternaam').value.trim();
    data.password   = document.getElementById('inp-shift-password').value;
    if (!data.voornaam || !data.achternaam) {
      toast('Voer voornaam en achternaam in.', 'error');
      return;
    }
    if (!data.password) {
      toast('Kies een wachtwoord voor de shift.', 'error');
      return;
    }
  }

  const res = await apiPost(data);
  if (res.ok) {
    toast('Shift gestart!', 'success');
    loadActiveShift();
  } else {
    toast(res.error, 'error');
  }
}

async function unlockShift() {
  const password = document.getElementById('inp-unlock-password').value;
  if (!password) { toast('Voer het wachtwoord in.', 'error'); return; }
  const res = await apiPost({ action: 'unlock_shift', shift_id: pendingShiftId, password });
  if (res.ok) {
    pendingShiftId = null;
    await loadActiveShift();
  } else {
    toast(res.error, 'error');
  }
}

async function closeShift() {
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

// ── SCREEN SWITCHING ──────────────────────────────────────────
function showStartScreen() {
  document.getElementById('screen-no-shift').classList.remove('hidden');
  document.getElementById('screen-unlock-shift').classList.add('hidden');
  document.getElementById('screen-pos').classList.add('hidden');
  document.getElementById('shift-status').textContent = 'Geen actieve shift';
  document.getElementById('shift-status').className = 'shift-badge shift-none';
}

function showUnlockScreen() {
  document.getElementById('screen-no-shift').classList.add('hidden');
  document.getElementById('screen-unlock-shift').classList.remove('hidden');
  document.getElementById('screen-pos').classList.add('hidden');
  document.getElementById('shift-status').textContent = 'Shift vergrendeld 🔒';
  document.getElementById('shift-status').className = 'shift-badge shift-none';
  setTimeout(() => document.getElementById('inp-unlock-password')?.focus(), 50);
}

function showPosScreen() {
  document.getElementById('screen-no-shift').classList.add('hidden');
  document.getElementById('screen-unlock-shift').classList.add('hidden');
  document.getElementById('screen-pos').classList.remove('hidden');
  const badge = document.getElementById('shift-status');
  badge.textContent = `${activeShift.verantwoordelijke} — ${activeShift.prijslijst_naam}`;
  badge.className = 'shift-badge shift-active';
  renderTabs(activeShift.tabs || []);
  loadDrinks();
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
  if (res.ok && res.shift && !res.shift.needs_password) {
    activeShift = res.shift;
    renderTabs(res.shift.tabs || []);
  }
}

// ── TAB SELECTEREN ────────────────────────────────────────────
async function selectTab(tabId) {
  activeTabId = tabId;
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
        <button class="btn-sm btn-danger" onclick="deleteTab(${tab.id})">🗑 Verwijder</button>
        <button class="btn-sm btn-green" onclick="openPaymentModal(${tab.id})">💳 Betalen</button>
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
            <button class="qty-btn" onclick="updateQuantity(${r.id}, ${tab.id}, ${r.aantal - 1})">−</button>
            <span class="qty-num">${r.aantal}</span>
            <button class="qty-btn" onclick="updateQuantity(${r.id}, ${tab.id}, ${r.aantal + 1})">+</button>
          </div>
          <div class="regel-subtotaal">€ ${(r.prijs * r.aantal).toFixed(2)}</div>
          <button class="btn-del-regel" onclick="deleteLineItem(${r.id}, ${tab.id})" title="Verwijderen">✕</button>
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
    document.querySelectorAll('.tab-item').forEach(el => {
      if (el.onclick?.toString().includes(res.tab_id)) el.classList.add('tab-selected');
    });
  } else {
    toast(res.error, 'error');
  }
}

async function deleteTab(tabId) {
  if (!confirm('Tab verwijderen met alle bestellingen?')) return;
  const res = await apiPost({ action: 'delete_tab', tab_id: tabId });
  if (res.ok) {
    activeTabId = null;
    document.getElementById('tab-detail').innerHTML = '<div class="empty-state"><p>👈 Selecteer of maak een tab aan</p></div>';
    await refreshTabs();
  }
}

// ── DRINKS ────────────────────────────────────────────────────
async function loadDrinks() {
  const res = await api(`get_dranken&prijslijst_id=${activeShift.prijslijst_id}`, {}, 'GET');
  if (!res.ok) return;
  drinkData = res.dranken;
  renderDrinks(drinkData);
  document.getElementById('prijslijst-badge').textContent = activeShift.prijslijst_naam;
}

function renderDrinks(drinks) {
  const grid = document.getElementById('drinks-grid');
  const cats = {};
  drinks.forEach(d => {
    const c = d.categorie || 'Overig';
    if (!cats[c]) cats[c] = [];
    cats[c].push(d);
  });
  grid.innerHTML = Object.entries(cats).map(([cat, items]) => `
    <div class="drinks-categorie">
      <div class="drinks-cat-label">${esc(cat)}</div>
      <div class="drinks-buttons">
        ${items.map(d => `
          <button class="drink-btn" onclick="addToTab(${d.id})" ${!activeTabId ? 'title="Selecteer eerst een tab"' : ''}>
            <span class="drink-btn-naam">${esc(d.naam)}</span>
            <span class="drink-btn-prijs">€ ${parseFloat(d.prijs).toFixed(2)}</span>
          </button>
        `).join('')}
      </div>
    </div>
  `).join('');
}

async function addToTab(drinkId) {
  if (!activeTabId) {
    toast('Selecteer eerst een tab!', 'error');
    return;
  }
  const res = await apiPost({ action: 'add_to_tab', tab_id: activeTabId, drank_id: drinkId, aantal: 1 });
  if (res.ok) {
    await renderTabDetail(activeTabId);
    await refreshTabs();
    const btns = document.querySelectorAll('.drink-btn');
    btns.forEach(btn => {
      if (btn.onclick?.toString().includes(drinkId)) {
        btn.style.borderColor = 'var(--green)';
        setTimeout(() => btn.style.borderColor = '', 300);
      }
    });
  } else {
    toast(res.error, 'error');
  }
}

// ── DIRECTE VERKOOP ───────────────────────────────────────────
let directSaleCart = {}; // drank_id → {naam, prijs, aantal}

function openDirectSaleModal() {
  directSaleCart = {};
  renderDirectSaleDrinks();
  renderDirectSaleCart();
  openModal('modal-direct-sale');
}

function renderDirectSaleDrinks() {
  const grid = document.getElementById('ds-drinks-grid');
  const cats = {};
  drinkData.forEach(d => {
    const c = d.categorie || 'Overig';
    if (!cats[c]) cats[c] = [];
    cats[c].push(d);
  });
  grid.innerHTML = Object.entries(cats).map(([cat, items]) => `
    <div class="drinks-categorie">
      <div class="drinks-cat-label">${esc(cat)}</div>
      <div class="drinks-buttons">
        ${items.map(d => `
          <button class="drink-btn" onclick="dsAddDrink(${d.id})">
            <span class="drink-btn-naam">${esc(d.naam)}</span>
            <span class="drink-btn-prijs">€ ${parseFloat(d.prijs).toFixed(2)}</span>
          </button>
        `).join('')}
      </div>
    </div>
  `).join('');
}

function dsAddDrink(id) {
  const drink = drinkData.find(d => d.id === id);
  if (!drink) return;
  if (directSaleCart[id]) {
    directSaleCart[id].aantal++;
  } else {
    directSaleCart[id] = { naam: drink.naam, prijs: parseFloat(drink.prijs), aantal: 1 };
  }
  renderDirectSaleCart();
}

function dsUpdateQuantity(id, delta) {
  if (!directSaleCart[id]) return;
  directSaleCart[id].aantal += delta;
  if (directSaleCart[id].aantal <= 0) delete directSaleCart[id];
  renderDirectSaleCart();
}

function renderDirectSaleCart() {
  const items = Object.entries(directSaleCart);
  const totaal = items.reduce((sum, [, i]) => sum + i.prijs * i.aantal, 0);

  document.getElementById('ds-order-items').innerHTML = items.length
    ? items.map(([id, item]) => `
        <div class="ds-order-item">
          <div class="ds-order-item-info">
            <div class="ds-order-item-naam">${esc(item.naam)}</div>
            <div class="ds-order-item-prijs">€ ${item.prijs.toFixed(2)} × ${item.aantal}</div>
          </div>
          <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
            <span style="font-weight:600;min-width:44px;text-align:right;">€ ${(item.prijs * item.aantal).toFixed(2)}</span>
            <div class="tab-regel-controls">
              <button class="qty-btn" onclick="dsUpdateQuantity(${id}, -1)">−</button>
              <span class="qty-num">${item.aantal}</span>
              <button class="qty-btn" onclick="dsUpdateQuantity(${id}, 1)">+</button>
            </div>
          </div>
        </div>
      `).join('')
    : '<p style="color:var(--text-dim);font-size:14px;padding:16px 0;text-align:center;">Voeg dranken toe ←</p>';

  document.getElementById('ds-totaal').textContent = `€ ${totaal.toFixed(2)}`;
  document.getElementById('btn-ds-pay').disabled = items.length === 0;
}

async function confirmDirectSale() {
  const items = Object.entries(directSaleCart);
  if (!items.length) return;
  const betaalwijze = document.querySelector('input[name="ds-betaalwijze"]:checked')?.value;
  const payload = items.map(([id, item]) => ({ drank_id: parseInt(id), aantal: item.aantal }));
  const res = await apiPost({
    action: 'direct_sale',
    shift_id: activeShift.id,
    betaalwijze,
    items: JSON.stringify(payload)
  });
  if (res.ok) {
    closeModal('modal-direct-sale');
    const label = betaalwijze === 'cash' ? 'cash 💵' : 'Payconiq 📱';
    toast(`Verkoop € ${parseFloat(res.totaal).toFixed(2)} — ${label}`, 'success');
  } else {
    toast(res.error, 'error');
  }
}

// ── LINE ITEMS ────────────────────────────────────────────────
async function deleteLineItem(lineItemId, tabId) {
  const res = await apiPost({ action: 'remove_tab_regel', regel_id: lineItemId, tab_id: tabId });
  if (res.ok) {
    await renderTabDetail(tabId);
    await refreshTabs();
  }
}

async function updateQuantity(lineItemId, tabId, newQuantity) {
  const res = await apiPost({ action: 'update_regel_aantal', regel_id: lineItemId, tab_id: tabId, aantal: newQuantity });
  if (res.ok) {
    await renderTabDetail(tabId);
    await refreshTabs();
  }
}

// ── PAYMENT ───────────────────────────────────────────────────
async function openPaymentModal(tabId) {
  paymentTabId = tabId;
  const res = await api(`get_tab&tab_id=${tabId}`, {}, 'GET');
  if (!res.ok) return;
  const tab = res.tab;
  const totaal = parseFloat(tab.totaal || 0);
  const overzicht = document.getElementById('betaling-overzicht');
  overzicht.innerHTML = tab.regels.map(r =>
    `<div class="regel"><span>${esc(r.drank_naam)} × ${r.aantal}</span><span>€ ${(r.prijs * r.aantal).toFixed(2)}</span></div>`
  ).join('') + `<div class="regel regel-total"><span>TOTAAL</span><span>€ ${totaal.toFixed(2)}</span></div>`;
  document.querySelector('input[name="betaalwijze"][value="cash"]').checked = true;
  openModal('modal-betaling');
}

async function confirmPayment() {
  const betaalwijze = document.querySelector('input[name="betaalwijze"]:checked')?.value;
  if (!betaalwijze) { toast('Kies een betaalwijze.', 'error'); return; }
  const res = await apiPost({ action: 'betaal_tab', tab_id: paymentTabId, betaalwijze });
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

document.addEventListener('click', e => {
  if (e.target.classList.contains('modal')) {
    e.target.classList.add('hidden');
  }
});

document.addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    if (!document.getElementById('modal-new-tab').classList.contains('hidden')) createTab();
    if (!document.getElementById('screen-unlock-shift').classList.contains('hidden')) unlockShift();
  }
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal:not(.hidden)').forEach(m => m.classList.add('hidden'));
  }
});
