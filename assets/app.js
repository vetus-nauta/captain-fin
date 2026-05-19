const APP_VERSION = '2026.05.19-captain-fin-005';
const PUBLIC_WEB_APP_URL = 'https://brkovic.ltd/captain-fin/';
const DRIVE_FOLDER_URL = 'https://drive.google.com/drive/folders/1x9m41AUYPocx7H0UezF_lZnFvzWO54zQ?usp=sharing';
const $ = (id) => document.getElementById(id);
const money = (n) => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

let reports = [];
let selectedId = null;
let saveTimer = null;
let isSaving = false;
let isHydrating = false;

function today() {
  return new Date().toISOString().slice(0, 10);
}

async function api(action, options = {}) {
  const parts = String(action).split('&');
  const name = parts.shift();
  const query = parts.join('&');
  const url = `api/?action=${encodeURIComponent(name)}${query ? `&${query}` : ''}`;
  const res = await fetch(url, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    ...options
  });
  const data = await res.json();
  if (!res.ok || data.error) throw new Error(data.error || `Ошибка ${res.status}`);
  return data;
}

function blankReport() {
  clearTimeout(saveTimer);
  isHydrating = true;
  selectedId = null;
  $('reportDate').value = today();
  $('openingBalance').value = '';
  $('notes').value = '';
  $('submitted').checked = false;
  $('entries').innerHTML = '';
  addEntry('income');
  showEditor();
  isHydrating = false;
  updateAll();
  markClean();
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
  row.querySelectorAll('input,select').forEach((el) => el.addEventListener('input', handleEditorInput));
  row.querySelector('.remove').addEventListener('click', () => { row.remove(); handleEditorInput(); });
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
  const title = $('notes').value.trim().split('\n').find(Boolean) || $('reportDate').value || 'Новая запись';
  $('editorTitle').textContent = title.slice(0, 48);
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
  handleEditorInput();
  setStatus(`Добавлено строк: ${items.length}.`);
}

function renderList() {
  const q = $('search').value.toLowerCase().trim();
  const list = reports.filter((r) => !q || JSON.stringify(r).toLowerCase().includes(q));
  if (!list.length) {
    $('reportList').innerHTML = '<div class="empty-list">Нет записей. Нажмите +, чтобы начать новый отчет.</div>';
    return;
  }
  $('reportList').innerHTML = list.map((r) => {
    const c = r.computed || {};
    const active = r.id === selectedId ? 'active' : '';
    const submitted = r.submitted ? 'submitted' : '';
    return `<div class="report-item ${active} ${submitted}" data-id="${escapeAttr(r.id)}">
      <strong>${escapeAttr(r.report_date)}${r.submitted ? ' · сдано' : ''}</strong>
      <span>${escapeAttr((r.notes || '').slice(0, 72) || 'Без заметок')}</span>
      <span>остаток ${money(c.current)} / будущий ${money(c.future)}</span>
    </div>`;
  }).join('');
  document.querySelectorAll('.report-item').forEach((item) => {
    item.addEventListener('click', () => selectReport(item.dataset.id));
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
  clearTimeout(saveTimer);
  isHydrating = true;
  const report = await api(`report&id=${encodeURIComponent(id)}`);
  selectedId = report.id;
  $('reportDate').value = report.report_date;
  $('openingBalance').value = report.opening_balance;
  $('notes').value = report.notes || '';
  $('submitted').checked = Boolean(report.submitted);
  $('entries').innerHTML = '';
  report.entries.forEach((entry) => addEntry(entry.type, entry));
  if (!report.entries.length) addEntry('income');
  showEditor();
  isHydrating = false;
  updateAll();
  markClean();
  renderList();
}

async function saveReport() {
  if (isSaving) return;
  isSaving = true;
  $('saveState').textContent = 'Сохранение...';
  try {
    const saved = await api('save', { method: 'POST', body: JSON.stringify(collectReport()) });
    selectedId = saved.id;
    reports = await api('reports');
    await selectReport(selectedId);
    markClean();
    setStatus('Сохранено.');
  } catch (error) {
    $('saveState').textContent = 'Ошибка';
    throw error;
  } finally {
    isSaving = false;
  }
}

function markClean() {
  clearTimeout(saveTimer);
  $('saveState').textContent = 'Сохранено';
}

function handleEditorInput() {
  if (isHydrating) return;
  updateAll();
  scheduleAutosave();
}

function scheduleAutosave() {
  if (!$('appShell') || $('appShell').classList.contains('hidden')) return;
  if (!$('appShell').classList.contains('mobile-editor') && window.matchMedia('(max-width: 920px)').matches) return;
  clearTimeout(saveTimer);
  $('saveState').textContent = 'Есть изменения';
  saveTimer = setTimeout(() => saveReport().catch((error) => setStatus(error.message)), 1400);
}

async function saveAndShowList() {
  clearTimeout(saveTimer);
  if ($('saveState').textContent !== 'Сохранено') await saveReport();
  showList();
}

function showList() {
  $('appShell').classList.add('mobile-list');
  $('appShell').classList.remove('mobile-editor');
}

function showEditor() {
  $('appShell').classList.add('mobile-editor');
  $('appShell').classList.remove('mobile-list');
}

async function deleteReport() {
  if (!selectedId || !confirm('Удалить отчет?')) return;
  await api(`delete&id=${encodeURIComponent(selectedId)}`, { method: 'POST', body: '{}' });
  selectedId = null;
  reports = await api('reports');
  const current = reports.find((report) => !report.submitted);
  if (current) await selectReport(current.id);
  else blankReport();
  setStatus('Удалено.');
}

async function exportExcel() {
  clearTimeout(saveTimer);
  if (!selectedId || $('saveState').textContent !== 'Сохранено') await saveReport();
  const result = await api('export', { method: 'POST', body: JSON.stringify({ id: selectedId }) });
  setStatus(`Excel создан: ${result.path}`);
  if (result.url) window.open(result.url, '_blank', 'noopener,noreferrer');
}

function openShareSheet() {
  $('shareUrlText').textContent = PUBLIC_WEB_APP_URL;
  $('shareSheet').classList.remove('hidden');
  $('shareSheet').setAttribute('aria-hidden', 'false');
}

function closeShareSheet() {
  $('shareSheet').classList.add('hidden');
  $('shareSheet').setAttribute('aria-hidden', 'true');
}

async function copyPublicLink() {
  await navigator.clipboard.writeText(PUBLIC_WEB_APP_URL);
  closeShareSheet();
  setStatus('Реальная ссылка на web app скопирована.');
}

function openShareTarget(target) {
  const encodedUrl = encodeURIComponent(PUBLIC_WEB_APP_URL);
  const encodedText = encodeURIComponent('Captain Fin');
  const targets = {
    mail: `mailto:?subject=${encodedText}&body=${encodedUrl}`,
    whatsapp: `https://wa.me/?text=${encodedText}%20${encodedUrl}`,
    telegram: `https://t.me/share/url?url=${encodedUrl}&text=${encodedText}`,
    drive: DRIVE_FOLDER_URL
  };
  window.open(targets[target], '_blank', 'noopener,noreferrer');
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
  if (['reportDate', 'openingBalance', 'notes', 'submitted'].includes(event.target.id)) handleEditorInput();
});
document.querySelectorAll('[data-add]').forEach((button) => button.addEventListener('click', () => {
  addEntry(button.dataset.add);
  handleEditorInput();
}));
$('newReport').addEventListener('click', blankReport);
$('saveReport').addEventListener('click', () => saveReport().catch((error) => setStatus(error.message)));
$('backToList').addEventListener('click', () => saveAndShowList().catch((error) => setStatus(error.message)));
$('deleteReport').addEventListener('click', () => deleteReport().catch((error) => setStatus(error.message)));
$('exportExcel').addEventListener('click', () => exportExcel().catch((error) => setStatus(error.message)));
$('importSigned').addEventListener('click', importSignedInput);
$('shareWebApp').addEventListener('click', openShareSheet);
$('syncLocal').addEventListener('click', () => copyPublicLink().catch((error) => setStatus(error.message)));
$('closeShare').addEventListener('click', closeShareSheet);
$('closeShareBackdrop').addEventListener('click', closeShareSheet);
$('copyPublicLink').addEventListener('click', () => copyPublicLink().catch((error) => setStatus(error.message)));
$('shareMail').addEventListener('click', () => openShareTarget('mail'));
$('shareWhatsApp').addEventListener('click', () => openShareTarget('whatsapp'));
$('shareTelegram').addEventListener('click', () => openShareTarget('telegram'));
$('shareDrive').addEventListener('click', () => openShareTarget('drive'));
$('search').addEventListener('input', renderList);

checkAuth().catch((error) => setStatus(error.message));
