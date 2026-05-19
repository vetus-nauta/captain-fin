const APP_VERSION = '2026.05.19-captain-fin-001';
const $ = (id) => document.getElementById(id);
const money = (n) => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

let reports = [];
let selectedId = null;

function today() {
  return new Date().toISOString().slice(0, 10);
}

async function api(action, options = {}) {
  const res = await fetch(`api/?action=${encodeURIComponent(action)}`, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    ...options
  });
  const data = await res.json();
  if (!res.ok || data.error) throw new Error(data.error || `Ошибка ${res.status}`);
  return data;
}

function blankReport() {
  selectedId = null;
  $('reportDate').value = today();
  $('openingBalance').value = '';
  $('notes').value = '';
  $('submitted').checked = false;
  $('entries').innerHTML = '';
  addEntry('income');
  updateAll();
  renderList();
}

function escapeAttr(value) {
  return String(value ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
}

function addEntry(type = 'income', entry = {}) {
  const row = document.createElement('div');
  row.className = 'entry';
  row.innerHTML = `
    <select class="type">
      <option value="income">Приход</option>
      <option value="expense">Расход</option>
      <option value="upcoming">Будущий расход</option>
    </select>
    <input class="description" placeholder="Описание" value="${escapeAttr(entry.description || '')}">
    <input class="amount" type="number" step="0.01" placeholder="0.00" value="${entry.amount ?? ''}">
    <input class="entryDate" type="date" value="${entry.entry_date || ''}">
    <button class="danger remove" title="Удалить">×</button>
  `;
  row.querySelector('.type').value = entry.type || type;
  row.querySelectorAll('input,select').forEach((el) => el.addEventListener('input', updateAll));
  row.querySelector('.remove').addEventListener('click', () => { row.remove(); updateAll(); });
  $('entries').appendChild(row);
  updateAll();
}

function collectReport() {
  const entries = [...$('entries').querySelectorAll('.entry')].map((row) => ({
    type: row.querySelector('.type').value,
    description: row.querySelector('.description').value.trim(),
    amount: Number(row.querySelector('.amount').value || 0),
    entry_date: row.querySelector('.entryDate').value
  })).filter((entry) => entry.description || entry.amount || entry.entry_date);
  return {
    id: selectedId,
    report_date: $('reportDate').value || today(),
    opening_balance: Number($('openingBalance').value || 0),
    notes: $('notes').value.trim(),
    submitted: $('submitted').checked,
    entries
  };
}

function compute(report = collectReport()) {
  const income = report.entries.filter((e) => e.type === 'income').reduce((sum, e) => sum + Number(e.amount || 0), 0);
  const expense = report.entries.filter((e) => e.type === 'expense').reduce((sum, e) => sum + Number(e.amount || 0), 0);
  const upcoming = report.entries.filter((e) => e.type === 'upcoming').reduce((sum, e) => sum + Number(e.amount || 0), 0);
  const current = Number(report.opening_balance || 0) + income - expense;
  return { income, expense, upcoming, current, future: current - upcoming };
}

function updateAll() {
  const c = compute();
  $('metrics').innerHTML = [
    ['Пришло', c.income],
    ['Ушло', c.expense],
    ['Остаток', c.current],
    ['Предстоящие', c.upcoming],
    ['Будущий остаток', c.future]
  ].map(([label, value]) => `<div class="metric"><span>${label}</span><strong>${money(value)}</strong></div>`).join('');
}

function parseSignedItems(text) {
  const parts = String(text || '').replace(/\r/g, '\n').replace(/;/g, '\n')
    .split(/\n|,(?=\s*[+-]\s*\d)/g).map((part) => part.trim()).filter(Boolean);
  const re = /^([+-])\s*((?:\d{1,3}(?:[ .]\d{3})+|\d+)(?:[,.]\d+)?)\s*(.*)$/;
  const items = [];
  for (const part of parts) {
    const match = part.match(re);
    if (!match) continue;
    const amount = Math.abs(Number(match[2].replace(/\s/g, '').replace(/(?<=\d)\.(?=\d{3}(?:\D|$))/g, '').replace(',', '.')));
    if (!Number.isFinite(amount) || amount <= 0) continue;
    items.push({
      type: match[1] === '+' ? 'income' : 'expense',
      amount,
      description: (match[3] || '').trim(),
      entry_date: $('reportDate').value || today()
    });
  }
  return items;
}

function importSignedInput() {
  const items = parseSignedItems($('notes').value);
  if (!items.length) return setStatus('Не нашел суммы со знаком + или -.');
  const onlyBlank = [...$('entries').querySelectorAll('.entry')].length === 1 && !collectReport().entries.length;
  if (onlyBlank) $('entries').innerHTML = '';
  items.forEach((item) => addEntry(item.type, item));
  setStatus(`Добавлено строк: ${items.length}.`);
}

function renderList() {
  const q = $('search').value.toLowerCase().trim();
  const list = reports.filter((r) => !q || JSON.stringify(r).toLowerCase().includes(q));
  $('reportList').innerHTML = list.map((r) => {
    const c = r.computed || {};
    const active = r.id === selectedId ? 'active' : '';
    const submitted = r.submitted ? 'submitted' : '';
    return `<div class="report-item ${active} ${submitted}" data-id="${r.id}">
      <strong>${r.report_date}${r.submitted ? ' · сдано' : ''}</strong>
      <span>${(r.notes || '').slice(0, 72) || 'Без заметок'}</span>
      <span>остаток ${money(c.current)} / будущий ${money(c.future)}</span>
    </div>`;
  }).join('');
  document.querySelectorAll('.report-item').forEach((item) => {
    item.addEventListener('click', () => selectReport(Number(item.dataset.id)));
  });
}

async function loadReports() {
  reports = await api('reports');
  renderList();
  const current = reports.find((report) => !report.submitted) || null;
  if (current) await selectReport(current.id);
  else blankReport();
}

async function selectReport(id) {
  const report = await api(`report&id=${id}`);
  selectedId = report.id;
  $('reportDate').value = report.report_date;
  $('openingBalance').value = report.opening_balance;
  $('notes').value = report.notes || '';
  $('submitted').checked = Boolean(report.submitted);
  $('entries').innerHTML = '';
  report.entries.forEach((entry) => addEntry(entry.type, entry));
  if (!report.entries.length) addEntry('income');
  updateAll();
  renderList();
}

async function saveReport() {
  const saved = await api('save', { method: 'POST', body: JSON.stringify(collectReport()) });
  selectedId = saved.id;
  reports = await api('reports');
  await selectReport(selectedId);
  setStatus('Сохранено.');
}

async function deleteReport() {
  if (!selectedId || !confirm('Удалить отчет?')) return;
  await api(`delete&id=${selectedId}`, { method: 'POST', body: '{}' });
  selectedId = null;
  reports = await api('reports');
  const current = reports.find((report) => !report.submitted);
  if (current) await selectReport(current.id);
  else blankReport();
  setStatus('Удалено.');
}

async function exportExcel() {
  if (!selectedId) await saveReport();
  const result = await api('export', { method: 'POST', body: JSON.stringify({ id: selectedId }) });
  setStatus(`Excel создан: ${result.path}`);
}

async function shareUrl(url = location.href) {
  if (navigator.share) {
    await navigator.share({ title: 'Captain Fin', url });
  } else {
    await navigator.clipboard.writeText(url);
    setStatus('Ссылка скопирована.');
  }
}

function setStatus(text) {
  $('status').textContent = text;
  $('loginStatus').textContent = text;
  setTimeout(() => {
    if ($('status').textContent === text) $('status').textContent = '';
    if ($('loginStatus').textContent === text) $('loginStatus').textContent = '';
  }, 6500);
}

async function checkAuth() {
  $('versionBadge').textContent = APP_VERSION;
  const me = await api('me');
  if (me.authenticated) {
    $('guestScreen').classList.add('hidden');
    $('appShell').classList.remove('hidden');
    await loadReports();
  } else {
    $('guestScreen').classList.remove('hidden');
    $('appShell').classList.add('hidden');
  }
}

$('loginForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  try {
    await api('login', {
      method: 'POST',
      body: JSON.stringify({ email: $('loginEmail').value.trim(), password: $('loginPassword').value })
    });
    await checkAuth();
  } catch (error) {
    setStatus(error.message);
  }
});

document.addEventListener('input', (event) => {
  if (['reportDate', 'openingBalance', 'notes', 'submitted'].includes(event.target.id)) updateAll();
});
document.querySelectorAll('[data-add]').forEach((button) => button.addEventListener('click', () => addEntry(button.dataset.add)));
$('newReport').addEventListener('click', blankReport);
$('saveReport').addEventListener('click', () => saveReport().catch((error) => setStatus(error.message)));
$('deleteReport').addEventListener('click', () => deleteReport().catch((error) => setStatus(error.message)));
$('exportExcel').addEventListener('click', () => exportExcel().catch((error) => setStatus(error.message)));
$('importSigned').addEventListener('click', importSignedInput);
$('shareWebApp').addEventListener('click', () => shareUrl().catch((error) => setStatus(error.message)));
$('shareCurrent').addEventListener('click', () => shareUrl(location.href).catch((error) => setStatus(error.message)));
$('syncLocal').addEventListener('click', () => shareUrl(`${location.origin}${location.pathname.replace(/\/$/, '')}/api/?action=export-json`).catch((error) => setStatus(error.message)));
$('search').addEventListener('input', renderList);

checkAuth().catch((error) => setStatus(error.message));

