<?php
declare(strict_types=1);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('captain_fin_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => $secure ? 'None' : 'Lax',
]);
session_start();

const APP_VERSION = '2026.05.19-captain-fin-006';
const AUTH_BASE = 'https://brkovic.ltd/api';
const STORAGE_DIR = __DIR__ . '/../storage';
const REPORTS_DIR = STORAGE_DIR . '/reports';
const EXPORTS_DIR = STORAGE_DIR . '/exports';
const DRIVE_FOLDER_ID = '1x9m41AUYPocx7H0UezF_lZnFvzWO54zQ';
const AUTH_COOKIE = 'captain_fin_auth';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    cors_headers();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): void {
    respond(['error' => $message], $status);
}

function input_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function cors_headers(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#', $origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
}

function is_local_request(): bool {
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

function auth_secret(): string {
    ensure_dirs();
    $path = STORAGE_DIR . '/.captain-fin-secret';
    if (!is_file($path)) {
        file_put_contents($path, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($path, 0600);
    }
    return trim((string) file_get_contents($path));
}

function set_local_auth_cookie(): void {
    $expires = time() + 60 * 60 * 24 * 30;
    $payload = (string) $expires;
    $sig = hash_hmac('sha256', $payload, auth_secret());
    setcookie(AUTH_COOKIE, $payload . '.' . $sig, [
        'expires' => $expires,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function has_local_auth_cookie(): bool {
    $raw = (string) ($_COOKIE[AUTH_COOKIE] ?? '');
    if (!str_contains($raw, '.')) return false;
    [$expires, $sig] = explode('.', $raw, 2);
    if (!ctype_digit($expires) || (int) $expires < time()) return false;
    $expected = hash_hmac('sha256', $expires, auth_secret());
    return hash_equals($expected, $sig);
}

function auth_request(string $route, string $method = 'GET', array $payload = []): array {
    $ch = curl_init(AUTH_BASE . $route);
    if (!$ch) fail('Auth unavailable', 502);

    $headers = ['Accept: application/json'];
    if (!empty($_SESSION['brkovic_live_cookie'])) {
        $headers[] = 'Cookie: ' . $_SESSION['brkovic_live_cookie'];
    }
    if ($method !== 'GET') {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $response = curl_exec($ch);
    if ($response === false) {
        $message = curl_error($ch) ?: 'Auth request failed';
        curl_close($ch);
        fail($message, 502);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $rawHeaders = substr((string) $response, 0, $headerSize);
    $body = substr((string) $response, $headerSize);
    curl_close($ch);

    foreach (preg_split('/\r\n|\n|\r/', $rawHeaders) as $line) {
        if (stripos($line, 'Set-Cookie:') !== 0) continue;
        $cookie = trim(substr($line, 11));
        $pair = explode(';', $cookie, 2)[0] ?? '';
        if (stripos($pair, 'ship_journal_admin=') === 0) {
            $_SESSION['brkovic_live_cookie'] = $pair;
        }
    }

    $data = json_decode($body, true);
    return ['status' => $status, 'data' => is_array($data) ? $data : []];
}

function authenticated(): bool {
    if (is_local_request()) return true;
    if (has_local_auth_cookie()) return true;
    if (empty($_SESSION['brkovic_live_cookie'])) return false;
    $me = auth_request('/auth/me');
    return (bool) ($me['data']['authenticated'] ?? false);
}

function require_auth(): void {
    if (!authenticated()) fail('Нужно войти в админку', 401);
}

function ensure_dirs(): void {
    foreach ([REPORTS_DIR, EXPORTS_DIR] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            fail('Не удалось создать папку хранения', 500);
        }
    }
}

function year_dir(string $base, string $date): string {
    $year = preg_match('/^\d{4}/', $date) ? substr($date, 0, 4) : date('Y');
    $dir = $base . '/' . $year;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

function report_path(string $id, string $date): string {
    return year_dir(REPORTS_DIR, $date) . '/' . basename($id) . '.json';
}

function read_report_file(string $path): ?array {
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? normalize_report($data) : null;
}

function all_reports(): array {
    ensure_dirs();
    $reports = [];
    foreach (glob(REPORTS_DIR . '/*/*.json') ?: [] as $path) {
        $report = read_report_file($path);
        if ($report) $reports[] = $report;
    }
    usort($reports, fn($a, $b) => strcmp($b['report_date'] . $b['id'], $a['report_date'] . $a['id']));
    return $reports;
}

function find_report(string $id): ?array {
    foreach (glob(REPORTS_DIR . '/*/' . basename($id) . '.json') ?: [] as $path) {
        return read_report_file($path);
    }
    return null;
}

function compute(array $entries, float $opening): array {
    $income = $expense = $upcoming = 0.0;
    foreach ($entries as $entry) {
        $amount = (float) ($entry['amount'] ?? 0);
        if (($entry['type'] ?? '') === 'income') $income += $amount;
        if (($entry['type'] ?? '') === 'expense') $expense += $amount;
        if (($entry['type'] ?? '') === 'upcoming') $upcoming += $amount;
    }
    $current = $opening + $income - $expense;
    return compact('income', 'expense', 'upcoming', 'current') + ['future' => $current - $upcoming];
}

function normalize_report(array $payload): array {
    $date = (string) ($payload['report_date'] ?? date('Y-m-d'));
    $entries = [];
    foreach (($payload['entries'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        $type = in_array($entry['type'] ?? '', ['income', 'expense', 'upcoming'], true) ? $entry['type'] : 'expense';
        $entries[] = [
            'type' => $type,
            'description' => trim((string) ($entry['description'] ?? '')),
            'amount' => (float) ($entry['amount'] ?? 0),
            'entry_date' => (string) ($entry['entry_date'] ?? ''),
        ];
    }
    $report = [
        'id' => (string) ($payload['id'] ?? ('cf-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)))),
        'report_date' => $date,
        'opening_balance' => (float) ($payload['opening_balance'] ?? 0),
        'notes' => trim((string) ($payload['notes'] ?? '')),
        'submitted' => !empty($payload['submitted']) ? 1 : 0,
        'entries' => $entries,
        'updated_at' => gmdate('c'),
        'app_version' => APP_VERSION,
    ];
    $report['computed'] = compute($entries, $report['opening_balance']);
    return $report;
}

function save_report(array $payload): array {
    ensure_dirs();
    $report = normalize_report($payload);
    foreach (glob(REPORTS_DIR . '/*/' . basename($report['id']) . '.json') ?: [] as $old) {
        if ($old !== report_path($report['id'], $report['report_date'])) @unlink($old);
    }
    file_put_contents(report_path($report['id'], $report['report_date']), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $report;
}

function delete_report(string $id): void {
    foreach (glob(REPORTS_DIR . '/*/' . basename($id) . '.json') ?: [] as $path) {
        @unlink($path);
    }
}

function xml_escape(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function make_xlsx(array $report): string {
    if (!class_exists('ZipArchive')) return make_excel_html($report);
    $dir = year_dir(EXPORTS_DIR, $report['report_date']);
    $path = $dir . '/report-' . $report['report_date'] . '-' . $report['id'] . '.xlsx';
    $rows = [
        ['Captain Fin', '', '', ''],
        ['Дата', $report['report_date'], '', ''],
        ['Остаток', $report['opening_balance'], '', ''],
        ['Пришло', $report['computed']['income'], '', ''],
        ['Ушло', -$report['computed']['expense'], '', ''],
        ['Будущий остаток', $report['computed']['future'], '', ''],
        [],
        ['Статья', 'Описание', 'Сумма', 'Дата'],
    ];
    $names = ['income' => 'Приход', 'expense' => 'Расход', 'upcoming' => 'Будущий расход'];
    foreach ($report['entries'] as $entry) {
        $amount = (float) $entry['amount'];
        if ($entry['type'] !== 'income') $amount = -$amount;
        $rows[] = [$names[$entry['type']] ?? $entry['type'], $entry['description'], $amount, $entry['entry_date']];
    }
    $sheetRows = '';
    foreach ($rows as $r => $row) {
        $sheetRows .= '<row r="' . ($r + 1) . '">';
        foreach ($row as $c => $value) {
            $ref = chr(65 + $c) . ($r + 1);
            if (is_int($value) || is_float($value)) {
                $sheetRows .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
            } else {
                $sheetRows .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . xml_escape($value) . '</t></is></c>';
            }
        }
        $sheetRows .= '</row>';
    }
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Отчет" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetRows . '</sheetData></worksheet>');
    $zip->close();
    duplicate_to_drive($path);
    return $path;
}

function make_excel_html(array $report): string {
    $dir = year_dir(EXPORTS_DIR, $report['report_date']);
    $path = $dir . '/report-' . $report['report_date'] . '-' . $report['id'] . '.xls';
    $names = ['income' => 'Приход', 'expense' => 'Расход', 'upcoming' => 'Будущий расход'];
    $rows = '';
    foreach ($report['entries'] as $entry) {
        $amount = (float) $entry['amount'];
        if ($entry['type'] !== 'income') $amount = -$amount;
        $rows .= '<tr><td>' . xml_escape($names[$entry['type']] ?? $entry['type']) . '</td><td>'
            . xml_escape($entry['description']) . '</td><td>' . $amount . '</td><td>'
            . xml_escape($entry['entry_date']) . '</td></tr>';
    }
    $html = '<html><head><meta charset="utf-8"></head><body>'
        . '<h1>Captain Fin</h1>'
        . '<table border="1">'
        . '<tr><th>Дата</th><td>' . xml_escape($report['report_date']) . '</td></tr>'
        . '<tr><th>Остаток</th><td>' . (float) $report['opening_balance'] . '</td></tr>'
        . '<tr><th>Пришло</th><td>' . (float) $report['computed']['income'] . '</td></tr>'
        . '<tr><th>Ушло</th><td>' . (float) $report['computed']['expense'] . '</td></tr>'
        . '<tr><th>Будущий остаток</th><td>' . (float) $report['computed']['future'] . '</td></tr>'
        . '</table><br><table border="1"><tr><th>Статья</th><th>Описание</th><th>Сумма</th><th>Дата</th></tr>'
        . $rows . '</table></body></html>';
    file_put_contents($path, $html, LOCK_EX);
    duplicate_to_drive($path);
    return $path;
}

function duplicate_to_drive(string $path): void {
    $rclone = trim((string) shell_exec('command -v rclone 2>/dev/null'));
    if ($rclone === '') return;
    $cmd = escapeshellcmd($rclone) . ' copy ' . escapeshellarg($path) . ' gdrive: --drive-root-folder-id ' . escapeshellarg(DRIVE_FOLDER_ID) . ' >/dev/null 2>&1 &';
    @exec($cmd);
}

$action = (string) ($_GET['action'] ?? 'me');
ensure_dirs();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    cors_headers();
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    exit;
}

if ($action === 'login') {
    $payload = input_json();
    $auth = auth_request('/auth/login', 'POST', ['email' => $payload['email'] ?? '', 'password' => $payload['password'] ?? '']);
    if (($auth['status'] ?? 500) >= 400) fail($auth['data']['error']['message'] ?? 'Не удалось войти', 401);
    set_local_auth_cookie();
    respond(['authenticated' => true]);
}

if ($action === 'me') respond(['authenticated' => authenticated(), 'version' => APP_VERSION]);

require_auth();

if ($action === 'reports') respond(all_reports());
if ($action === 'report') {
    $report = find_report((string) ($_GET['id'] ?? ''));
    $report ? respond($report) : fail('Отчет не найден', 404);
}
if ($action === 'save') respond(save_report(input_json()));
if ($action === 'delete') {
    delete_report((string) ($_GET['id'] ?? ''));
    respond(['deleted' => true]);
}
if ($action === 'export') {
    $payload = input_json();
    $report = find_report((string) ($payload['id'] ?? ''));
    if (!$report) fail('Отчет не найден', 404);
    $path = make_xlsx($report);
    $file = basename(dirname($path)) . '/' . basename($path);
    respond(['path' => './storage/exports/' . $file, 'url' => 'api/?action=download&file=' . rawurlencode($file)]);
}
if ($action === 'download') {
    $file = (string) ($_GET['file'] ?? '');
    if (!preg_match('#^\d{4}/[A-Za-z0-9._-]+\.(xlsx|xls)$#', $file)) fail('Некорректный файл', 400);
    $path = EXPORTS_DIR . '/' . $file;
    if (!is_file($path)) fail('Файл не найден', 404);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $type = $ext === 'xlsx'
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        : 'application/vnd.ms-excel';
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    readfile($path);
    exit;
}
if ($action === 'export-json') respond(['version' => APP_VERSION, 'reports' => all_reports()]);

fail('Не найдено', 404);
