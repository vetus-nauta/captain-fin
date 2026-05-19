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

const APP_VERSION = '2026.05.19-captain-fin-008';
const AUTH_BASE = 'https://brkovic.ltd/api';
const STORAGE_DIR = __DIR__ . '/../storage';
const REPORTS_DIR = STORAGE_DIR . '/reports';
const EXPORTS_DIR = STORAGE_DIR . '/exports';
const TRASH_DIR = STORAGE_DIR . '/trash';
const ATTACHMENTS_DIR = STORAGE_DIR . '/attachments';
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
    foreach ([REPORTS_DIR, EXPORTS_DIR, TRASH_DIR, ATTACHMENTS_DIR] as $dir) {
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

function safe_file_name(string $name): string {
    $name = preg_replace('/[^\pL\pN._ -]+/u', '_', basename($name)) ?: 'attachment';
    return trim($name, " .\t\n\r\0\x0B") ?: 'attachment';
}

function attachment_dir(string $id, string $date): string {
    $dir = year_dir(ATTACHMENTS_DIR, $date) . '/' . basename($id);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

function list_attachments(string $id, string $date): array {
    $dir = year_dir(ATTACHMENTS_DIR, $date) . '/' . basename($id);
    $items = [];
    foreach (glob($dir . '/*') ?: [] as $path) {
        if (!is_file($path)) continue;
        $name = basename($path);
        $items[] = [
            'name' => $name,
            'size' => filesize($path) ?: 0,
            'updated_at' => gmdate('c', filemtime($path) ?: time()),
            'url' => 'api/?action=attachment&id=' . rawurlencode($id) . '&file=' . rawurlencode($name),
        ];
    }
    usort($items, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $items;
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

function archived_reports(): array {
    ensure_dirs();
    $reports = [];
    foreach (glob(TRASH_DIR . '/*/*.json') ?: [] as $path) {
        $report = read_report_file($path);
        if (!$report) continue;
        $report['deleted_file'] = basename($path);
        $reports[] = $report;
    }
    usort($reports, fn($a, $b) => strcmp((string) ($b['deleted_at'] ?? '') . $b['id'], (string) ($a['deleted_at'] ?? '') . $a['id']));
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
        'deleted_at' => (string) ($payload['deleted_at'] ?? ''),
        'entries' => $entries,
        'updated_at' => gmdate('c'),
        'app_version' => APP_VERSION,
    ];
    $report['computed'] = compute($entries, $report['opening_balance']);
    $report['attachments'] = list_attachments($report['id'], $report['report_date']);
    return $report;
}

function save_report(array $payload): array {
    ensure_dirs();
    $report = normalize_report($payload);
    foreach (glob(REPORTS_DIR . '/*/' . basename($report['id']) . '.json') ?: [] as $old) {
        if ($old !== report_path($report['id'], $report['report_date'])) @unlink($old);
    }
    $path = report_path($report['id'], $report['report_date']);
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if (!empty($report['submitted'])) {
        duplicate_to_drive($path);
    }
    return $report;
}

function delete_report(string $id): void {
    foreach (glob(REPORTS_DIR . '/*/' . basename($id) . '.json') ?: [] as $path) {
        $report = read_report_file($path);
        $date = $report['report_date'] ?? date('Y-m-d');
        $report['deleted_at'] = gmdate('c');
        $trash = year_dir(TRASH_DIR, (string) $date) . '/' . gmdate('Ymd-His') . '-' . basename($id) . '.json';
        file_put_contents($trash, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @unlink($path);
    }
}

function restore_report(string $id): ?array {
    foreach (glob(TRASH_DIR . '/*/*-' . basename($id) . '.json') ?: [] as $path) {
        $report = read_report_file($path);
        if (!$report) continue;
        $report['deleted_at'] = '';
        file_put_contents(report_path($report['id'], $report['report_date']), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @unlink($path);
        return $report;
    }
    return null;
}

function upload_attachment(string $id): array {
    $report = find_report($id);
    if (!$report) fail('Отчет не найден', 404);
    if (empty($_FILES['attachment']) || !is_uploaded_file($_FILES['attachment']['tmp_name'])) fail('Файл не получен', 400);
    if ((int) ($_FILES['attachment']['size'] ?? 0) > 25 * 1024 * 1024) fail('Файл больше 25 МБ', 413);
    $name = safe_file_name((string) ($_FILES['attachment']['name'] ?? 'attachment'));
    $target = attachment_dir($report['id'], $report['report_date']) . '/' . $name;
    if (is_file($target)) {
        $info = pathinfo($name);
        $base = $info['filename'] ?? 'attachment';
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        $target = attachment_dir($report['id'], $report['report_date']) . '/' . $base . '-' . date('His') . $ext;
    }
    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $target)) fail('Не удалось сохранить вложение', 500);
    if (!empty($report['submitted'])) duplicate_to_drive($target);
    return list_attachments($report['id'], $report['report_date']);
}

function summary_reports(?string $from, ?string $to): array {
    $items = array_values(array_filter(all_reports(), function ($report) use ($from, $to) {
        if (empty($report['submitted'])) return false;
        $date = (string) $report['report_date'];
        if ($from && $date < $from) return false;
        if ($to && $date > $to) return false;
        return true;
    }));
    $totals = ['count' => count($items), 'opening' => 0.0, 'income' => 0.0, 'expense' => 0.0, 'upcoming' => 0.0, 'current' => 0.0, 'future' => 0.0];
    foreach ($items as $report) {
        $totals['opening'] += (float) $report['opening_balance'];
        foreach (['income', 'expense', 'upcoming', 'current', 'future'] as $key) {
            $totals[$key] += (float) ($report['computed'][$key] ?? 0);
        }
    }
    return ['from' => $from, 'to' => $to, 'totals' => $totals, 'reports' => $items];
}

function xml_escape(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_cell(string $ref, mixed $value, int $style = 0): string {
    $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';
    if (is_int($value) || is_float($value)) {
        return '<c r="' . $ref . '"' . $styleAttr . '><v>' . $value . '</v></c>';
    }
    return '<c r="' . $ref . '"' . $styleAttr . ' t="inlineStr"><is><t>' . xml_escape($value) . '</t></is></c>';
}

function xlsx_row(int $row, array $cells, ?float $height = null): string {
    $heightAttr = $height ? ' ht="' . $height . '" customHeight="1"' : '';
    $xml = '<row r="' . $row . '"' . $heightAttr . '>';
    foreach ($cells as $cell) {
        [$col, $value, $style] = $cell + [null, null, 0];
        $xml .= xlsx_cell($col . $row, $value, (int) $style);
    }
    return $xml . '</row>';
}

function xlsx_styles(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
        . '<fonts count="4">'
        . '<font><sz val="11"/><color rgb="FF111827"/><name val="Arial"/></font>'
        . '<font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF111827"/><name val="Arial"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
        . '</fonts>'
        . '<fills count="11">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF111827"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD1FAE5"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF065F46"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF9FAFB"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2"><border/><border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right><top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="18">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="2" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="2" fillId="5" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="2" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="2" fillId="7" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="3" fillId="8" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right" vertical="top"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment horizontal="center" vertical="top"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="5" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="top"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="top"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="7" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="top"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="10" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function make_xlsx(array $report): string {
    if (!class_exists('ZipArchive')) return make_excel_html($report);
    $dir = year_dir(EXPORTS_DIR, $report['report_date']);
    $path = $dir . '/report-' . $report['report_date'] . '-' . $report['id'] . '.xlsx';
    $generated = date('Y-m-d H:i');
    $current = (float) $report['computed']['current'];
    $future = (float) $report['computed']['future'];
    $notes = trim((string) ($report['notes'] ?? ''));
    $sheetRows = '';
    $sheetRows .= xlsx_row(1, [['A', 'CAPTAIN FIN · ФИНАНСОВЫЙ ОТЧЕТ', 1]], 28);
    $sheetRows .= xlsx_row(3, [['A', 'Дата отчета', 2], ['B', $report['report_date'], 3], ['D', 'Сформировано', 2], ['E', $generated, 3]]);
    $sheetRows .= xlsx_row(5, [['A', 'БЫЛО', 4], ['B', 'ПРИХОД', 4], ['C', 'РАСХОД', 4], ['D', 'СТАЛО', 4], ['E', 'БУДЕТ', 4]]);
    $sheetRows .= xlsx_row(6, [
        ['A', (float) $report['opening_balance'], 5],
        ['B', (float) $report['computed']['income'], 6],
        ['C', -(float) $report['computed']['expense'], 7],
        ['D', $current, 9],
        ['E', $future, 8],
    ], 26);
    $sheetRows .= xlsx_row(8, [['A', 'Строки отчета', 4], ['B', '', 4], ['C', '', 4], ['D', '', 4], ['E', '', 4]]);
    $sheetRows .= xlsx_row(9, [['A', 'Тип', 10], ['B', 'Описание', 10], ['C', 'Сумма', 10], ['D', 'Дата', 10], ['E', 'Комментарий', 10]]);
    $names = ['income' => 'Приход', 'expense' => 'Расход', 'upcoming' => 'Будущий расход'];
    $entryRow = 10;
    foreach ($report['entries'] as $entry) {
        $amount = (float) $entry['amount'];
        if ($entry['type'] !== 'income') $amount = -$amount;
        $moneyStyle = $entry['type'] === 'income' ? 14 : ($entry['type'] === 'upcoming' ? 16 : 15);
        $sheetRows .= xlsx_row($entryRow, [
            ['A', $names[$entry['type']] ?? $entry['type'], 11],
            ['B', $entry['description'], 11],
            ['C', $amount, $moneyStyle],
            ['D', $entry['entry_date'], 13],
            ['E', '', 11],
        ], 22);
        $entryRow++;
    }
    $noteRow = max($entryRow + 1, 12);
    $sheetRows .= xlsx_row($noteRow, [['A', 'Заметки', 10], ['B', '', 10], ['C', '', 10], ['D', '', 10], ['E', '', 10]]);
    $sheetRows .= xlsx_row($noteRow + 1, [['A', $notes !== '' ? $notes : 'Без заметок', 17]], 70);
    $dimensionEnd = 'E' . ($noteRow + 1);
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<dimension ref="A1:' . $dimensionEnd . '"/>'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>'
        . '<cols><col min="1" max="1" width="18" customWidth="1"/><col min="2" max="2" width="42" customWidth="1"/><col min="3" max="3" width="16" customWidth="1"/><col min="4" max="4" width="16" customWidth="1"/><col min="5" max="5" width="24" customWidth="1"/></cols>'
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '<mergeCells count="3"><mergeCell ref="A1:E1"/><mergeCell ref="A8:E8"/><mergeCell ref="A' . ($noteRow + 1) . ':E' . ($noteRow + 1) . '"/></mergeCells>'
        . '<printOptions horizontalCentered="1"/>'
        . '<pageMargins left="0.35" right="0.35" top="0.55" bottom="0.55" header="0.2" footer="0.2"/>'
        . '<pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Отчет" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', xlsx_styles());
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();
    duplicate_to_drive($path);
    return $path;
}

function make_excel_html(array $report): string {
    $dir = year_dir(EXPORTS_DIR, $report['report_date']);
    $path = $dir . '/report-' . $report['report_date'] . '-' . $report['id'] . '.xls';
    $names = ['income' => 'Приход', 'expense' => 'Расход', 'upcoming' => 'Будущий расход'];
    $rowColors = ['income' => '#dcfce7', 'expense' => '#fee2e2', 'upcoming' => '#fef3c7'];
    $rows = '';
    foreach ($report['entries'] as $entry) {
        $amount = (float) $entry['amount'];
        if ($entry['type'] !== 'income') $amount = -$amount;
        $bg = $rowColors[$entry['type']] ?? '#ffffff';
        $rows .= '<tr><td>' . xml_escape($names[$entry['type']] ?? $entry['type']) . '</td><td>'
            . xml_escape($entry['description']) . '</td><td style="background:' . $bg . ';text-align:right">' . $amount . '</td><td>'
            . xml_escape($entry['entry_date']) . '</td><td></td></tr>';
    }
    $html = '<html><head><meta charset="utf-8"><style>'
        . '@page{size:A4;margin:12mm}body{font-family:Arial,sans-serif;color:#111827}table{border-collapse:collapse;width:100%}td,th{border:1px solid #d1d5db;padding:8px}th{background:#111827;color:#fff}.title{background:#111827;color:#fff;font-size:22px;font-weight:700;text-align:center}.meta{background:#f3f4f6;font-weight:700}.money{text-align:right;font-weight:700}.before{background:#dbeafe}.income{background:#d1fae5}.expense{background:#fee2e2}.future{background:#fef3c7}.after{background:#065f46;color:#fff}.notes{height:80px;vertical-align:top;background:#f9fafb}'
        . '</style></head><body>'
        . '<table><tr><td colspan="5" class="title">CAPTAIN FIN · ФИНАНСОВЫЙ ОТЧЕТ</td></tr>'
        . '<tr><td class="meta">Дата отчета</td><td>' . xml_escape($report['report_date']) . '</td><td></td><td class="meta">Сформировано</td><td>' . date('Y-m-d H:i') . '</td></tr>'
        . '<tr><th>БЫЛО</th><th>ПРИХОД</th><th>РАСХОД</th><th>СТАЛО</th><th>БУДЕТ</th></tr>'
        . '<tr><td class="money before">' . (float) $report['opening_balance'] . '</td><td class="money income">' . (float) $report['computed']['income'] . '</td><td class="money expense">' . (-(float) $report['computed']['expense']) . '</td><td class="money after">' . (float) $report['computed']['current'] . '</td><td class="money future">' . (float) $report['computed']['future'] . '</td></tr>'
        . '</table><br><table><tr><th>Тип</th><th>Описание</th><th>Сумма</th><th>Дата</th><th>Комментарий</th></tr>'
        . $rows . '</table><br><table><tr><th colspan="5">Заметки</th></tr><tr><td colspan="5" class="notes">' . nl2br(xml_escape($report['notes'] ?? '')) . '</td></tr></table></body></html>';
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
if ($action === 'archived') respond(archived_reports());
if ($action === 'report') {
    $report = find_report((string) ($_GET['id'] ?? ''));
    $report ? respond($report) : fail('Отчет не найден', 404);
}
if ($action === 'save') respond(save_report(input_json()));
if ($action === 'delete') {
    delete_report((string) ($_GET['id'] ?? ''));
    respond(['archived' => true]);
}
if ($action === 'restore') {
    $report = restore_report((string) ($_GET['id'] ?? ''));
    $report ? respond($report) : fail('Архивная запись не найдена', 404);
}
if ($action === 'upload') {
    respond(['attachments' => upload_attachment((string) ($_GET['id'] ?? ''))]);
}
if ($action === 'attachment') {
    $report = find_report((string) ($_GET['id'] ?? ''));
    if (!$report) fail('Отчет не найден', 404);
    $file = safe_file_name((string) ($_GET['file'] ?? ''));
    $path = year_dir(ATTACHMENTS_DIR, $report['report_date']) . '/' . basename($report['id']) . '/' . $file;
    if (!is_file($path)) fail('Вложение не найдено', 404);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    readfile($path);
    exit;
}
if ($action === 'summary') {
    $from = trim((string) ($_GET['from'] ?? '')) ?: null;
    $to = trim((string) ($_GET['to'] ?? '')) ?: null;
    respond(summary_reports($from, $to));
}
if ($action === 'storage-info') {
    respond([
        'server_root' => STORAGE_DIR,
        'reports' => REPORTS_DIR . '/YYYY/*.json',
        'deleted_archive' => TRASH_DIR . '/YYYY/*.json',
        'attachments' => ATTACHMENTS_DIR . '/YYYY/report-id/*',
        'exports' => EXPORTS_DIR . '/YYYY/*.xlsx',
        'drive_folder_id' => DRIVE_FOLDER_ID,
        'drive_url' => 'https://drive.google.com/drive/folders/' . DRIVE_FOLDER_ID,
    ]);
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
