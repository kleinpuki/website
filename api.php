<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); 


ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS'])) {
    ini_set('session.cookie_secure', 1);
}
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Permissions-Policy: camera=(self), microphone=(self), display-capture=(self)');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

$dataFile = __DIR__ . '/smp_data.json';
$chatFile = __DIR__ . '/smp_chat.json';
$typingFile = __DIR__ . '/smp_typing.json';
$talkFile = __DIR__ . '/smp_talk.json';       
$signalFile = __DIR__ . '/smp_signal.json';
$bansFile = __DIR__ . '/smp_bans.json';
$MAX_PAYLOAD_BYTES = 5 * 1024 * 1024;   



function readJsonFile($path, $fallback) {
    if (!file_exists($path)) return $fallback;
    $raw = file_get_contents($path);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function writeJsonFile($path, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) return false;
    $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $oldMode = file_exists($path) ? (@fileperms($path) & 0777) : null;
    $bytes = @file_put_contents($tmp, $json, LOCK_EX);
    if ($bytes === false || $bytes !== strlen($json)) { @unlink($tmp); return false; }
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    if ($oldMode !== null) @chmod($path, $oldMode);
    return true;
}

function isLoggedIn() {
    return isset($_SESSION['admin_user']) && !empty($_SESSION['admin_user']);
}

function currentUser() {
    return $_SESSION['admin_user'] ?? null;
}

function requireAuth() {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Nicht eingeloggt."]);
        exit;
    }
}






function stripSecrets($data, $loggedIn) {
    if (isset($data['users']) && is_array($data['users'])) {
        foreach ($data['users'] as &$u) {
            unset($u['pass']);
            unset($u['passHash']);
        }
        unset($u);
    }
    if (!$loggedIn) {
        unset($data['keys']);
        unset($data['auditLogs']);
        unset($data['users']); 
    }
    return $data;
}


function migratePasswordsIfNeeded(&$data) {
    if (!isset($data['users']) || !is_array($data['users'])) return false;
    $changed = false;
    foreach ($data['users'] as &$u) {
        if (isset($u['pass']) && !isset($u['passHash'])) {
            $u['passHash'] = password_hash($u['pass'], PASSWORD_DEFAULT);
            unset($u['pass']);
            $changed = true;
        } elseif (isset($u['pass']) && isset($u['passHash'])) {
            unset($u['pass']);
            $changed = true;
        }
    }
    unset($u);
    return $changed;
}



$defaultData = [
    "keys" => [["code" => "SMP-KEY-1882", "creator" => "Niklas1882", "status" => "Aktiv"]],
    "team" => [["name" => "Niklas1882", "role" => "Owner"]],
    "users" => [["username" => "Niklas1882", "passHash" => password_hash("admin", PASSWORD_DEFAULT), "isOwner" => true, "permissions" => ["keys" => true, "team" => true, "news" => true, "rankDelete" => true, "config" => true]]],
    "news" => [["title" => "Server Start", "category" => "Update", "text" => "Online!", "date" => "21.08.2026"]],
    "gallery" => [
        ["url" => "spawn.jpg", "title" => "Der Spawn"]
    ],
    "serverIp" => "kumpelsmp600.mcsh.io",
    "joinSteps" => [
        ["title" => "Schritt 1: Minecraft starten", "desc" => "Öffne deine Minecraft Java Edition (Server Version 1.21.11 geht aber auch mit anderer)."],
        ["title" => "Schritt 2: Multiplayer auswählen", "desc" => "Klicke im Hauptmenü auf Multiplayer (Mehrspieler)."],
        ["title" => "Schritt 3: Server hinzufügen", "desc" => "Klicke unten auf Server hinzufügen. Gib als Servername z.B. Kumpel SMP und als Serveradresse kumpelsmp600.mcsh.io ein."],
        ["title" => "Schritt 4: Einloggen & Spaß haben!", "desc" => "Klicke auf den Server und trete bei. Wir freuen uns auf dich!"]
    ],
    "auditLogs" => [],
    "activeTalkUsers" => [],
    "siteNotice" => ["enabled" => false, "type" => "info", "title" => "", "text" => ""],
    "maintenance" => ["enabled" => false, "title" => "Wartungsarbeiten", "text" => "Die Website wird gerade aktualisiert. Bitte später erneut versuchen."]
];

if (!is_writable(__DIR__)) {
    echo json_encode(["status" => "error", "message" => "Der Ordner hat keine Schreibrechte (Chmod 755/777 setzen!)"]);
    exit;
}

$data = readJsonFile($dataFile, $defaultData);
if (!file_exists($dataFile)) {
    writeJsonFile($dataFile, $data);
}


if (migratePasswordsIfNeeded($data)) {
    writeJsonFile($dataFile, $data);
}

if (!file_exists($chatFile)) {
    writeJsonFile($chatFile, []);
}

if (!file_exists($typingFile)) {
    writeJsonFile($typingFile, []);
}

if (!file_exists($talkFile)) {
    writeJsonFile($talkFile, []);
}

if (!file_exists($signalFile)) {
    writeJsonFile($signalFile, []);
}
if (!file_exists($bansFile)) {
    writeJsonFile($bansFile, ['users'=>[], 'ips'=>[]]);
}





function clientIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function safeTextLimit($text, $max) {
    $text = (string)$text;
    return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
}

function readJsonBody() {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > $GLOBALS['MAX_PAYLOAD_BYTES']) {
        http_response_code(413);
        echo json_encode(['status'=>'error','message'=>'Anfrage ist zu groß.']);
        exit;
    }
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Ungültiger JSON-Inhalt.']);
        exit;
    }
    return $data;
}

function banState($bans) {
    return [
        'users' => is_array($bans['users'] ?? null) ? $bans['users'] : [],
        'ips' => is_array($bans['ips'] ?? null) ? $bans['ips'] : []
    ];
}

function isIpBanned($ip, $bans) {
    return $ip !== '' && in_array($ip, $bans['ips'], true);
}

function isUserBanned($username, $bans) {
    return $username !== '' && in_array($username, $bans['users'], true);
}

function requirePost() {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405); header('Allow: POST');
        echo json_encode(["status"=>"error","message"=>"Diese Aktion benötigt POST."]); exit;
    }
}

function requireSameOrigin() {
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $hostHeader = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $forwardedHost = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    $allowedHosts = [];
    foreach ([$hostHeader, $forwardedHost] as $candidate) {
        if ($candidate === '') continue;
        foreach (explode(',', $candidate) as $part) {
            $part = trim($part);
            $parsed = parse_url('http://' . $part);
            if (!empty($parsed['host'])) $allowedHosts[] = strtolower($parsed['host']);
        }
    }
    $allowedHosts = array_values(array_unique($allowedHosts));
    if ($origin !== '') {
        $parsed = parse_url($origin);
        $originHost = strtolower((string)($parsed['host'] ?? ''));
        if ($originHost === '' || (!empty($allowedHosts) && !in_array($originHost, $allowedHosts, true))) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Ungültige Anfragequelle."]);
            exit;
        }
    } elseif (!empty($_SERVER['HTTP_REFERER'])) {
        $refHost = strtolower((string)(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST) ?? ''));
        if ($refHost !== '' && !empty($allowedHosts) && !in_array($refHost, $allowedHosts, true)) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Ungültige Anfragequelle."]);
            exit;
        }
    }
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$writeActions = ['login','logout','change_password','register','generate_key','save_main','send_chat','typing','talk_join','talk_leave','talk_update','signal_send','signal_poll','arena_upload','arena_delete','clear_chat','clear_audit','save_team','ban_user','unban_user','ban_ip','unban_ip'];
if (in_array($action, $writeActions, true)) {
    requirePost();
    requireSameOrigin();
}



if (isLoggedIn()) {
    $bans = banState(readJsonFile($bansFile, ['users'=>[], 'ips'=>[]]));
    $me = currentUser();
    if (isUserBanned($me, $bans) || isIpBanned(clientIp(), $bans)) {
        $_SESSION = [];
        session_destroy();
        http_response_code(403);
        echo json_encode(['status'=>'error','message'=>'Zugriff gesperrt.']);
        exit;
    }
}

if ($action === 'get_main') {
    $data = readJsonFile($dataFile, $defaultData);
    $loggedIn = isLoggedIn();
    $public = stripSecrets($data, $loggedIn);
    $public['siteNotice'] = $data['siteNotice'] ?? ['enabled'=>false,'type'=>'info','title'=>'','text'=>''];
    $public['maintenance'] = $data['maintenance'] ?? ['enabled'=>false,'title'=>'Wartungsarbeiten','text'=>'Die Website wird gerade aktualisiert. Bitte später erneut versuchen.'];
    $public['loggedIn'] = $loggedIn;
    if ($loggedIn) {
        $public['currentUser'] = currentUser();
    }
    echo json_encode($public);
    exit;
}

if ($action === 'get_team') {
    $data = readJsonFile($dataFile, $defaultData);
    $team = is_array($data['team'] ?? null) ? $data['team'] : [];
    echo json_encode(['status'=>'success','team'=>$team], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'save_team') {
    requireAuth();
    $rawInput = file_get_contents('php://input');
    if (strlen($rawInput) > $MAX_PAYLOAD_BYTES) {
        http_response_code(413);
        echo json_encode(['status'=>'error','message'=>'Datenmenge zu groß.']);
        exit;
    }
    $input = json_decode($rawInput, true);
    if (!is_array($input) || !isset($input['team'])) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Ungültige Teamdaten.']);
        exit;
    }
    $existing = readJsonFile($dataFile, $defaultData);
    $me = currentUser();
    $isOwner = ($me === 'Niklas1882');
    foreach (($existing['users'] ?? []) as $u) {
        if (($u['username'] ?? '') === $me && !empty($u['isOwner'])) $isOwner = true;
    }
    $perms = [];
    foreach (($existing['users'] ?? []) as $u) {
        if (($u['username'] ?? '') === $me) $perms = $u['permissions'] ?? [];
    }
    if (!$isOwner && empty($perms['team'])) {
        http_response_code(403);
        echo json_encode(['status'=>'error','message'=>'Keine Berechtigung für Team-Änderungen.']);
        exit;
    }
    if (!is_array($input['team']) || count($input['team']) > 50) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Maximal 50 Teammitglieder erlaubt.']);
        exit;
    }
    $allowedRoles = ['Owner','Co Owner','Developer','Admin','Moderator','Supporter','Test Supporter','Builder'];
    $seen = [];
    $team = [];
    foreach ($input['team'] as $member) {
        if (!is_array($member)) {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Ungültiges Teammitglied.']);
            exit;
        }
        $name = trim((string)($member['name'] ?? ''));
        $role = trim((string)($member['role'] ?? ''));
        if ($role === 'Co-Owner') $role = 'Co Owner';
        if ($role === 'Mod') $role = 'Moderator';
        if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $name) || !in_array($role, $allowedRoles, true)) {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Ungültiger Minecraft-Name oder Rang.']);
            exit;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Doppelte Teammitglieder sind nicht erlaubt.']);
            exit;
        }
        $seen[$key] = true;
        $team[] = ['name'=>$name,'role'=>$role];
    }
    $existing['team'] = $team;
    if (writeJsonFile($dataFile, $existing) === false) {
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'Team konnte nicht gespeichert werden.']);
        exit;
    }
    echo json_encode(['status'=>'success','team'=>$team], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'get_chat') {
    requireAuth();
    $messages = readJsonFile($chatFile, []);
    if (!is_array($messages)) $messages = [];
    echo json_encode(array_values($messages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}



if ($action === 'login') {
    $input = readJsonBody();
    $user = isset($input['username']) ? trim((string)$input['username']) : '';
    $pass = isset($input['password']) ? $input['password'] : '';

    
    if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
    if (!isset($_SESSION['login_attempt_window'])) $_SESSION['login_attempt_window'] = time();
    if (time() - $_SESSION['login_attempt_window'] > 300) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_attempt_window'] = time();
    }
    if ($_SESSION['login_attempts'] >= 10) {
        http_response_code(429);
        echo json_encode(["status" => "error", "message" => "Zu viele Versuche. Bitte später erneut versuchen."]);
        exit;
    }

    $data = readJsonFile($dataFile, $defaultData);
    $bans = banState(readJsonFile($bansFile, ['users'=>[], 'ips'=>[]]));
    $ip = clientIp();
    if (isIpBanned($ip, $bans)) {
        http_response_code(403);
        echo json_encode(['status'=>'error','message'=>'Diese IP-Adresse ist gesperrt.']);
        exit;
    }
    if (isUserBanned($user, $bans)) {
        http_response_code(403);
        echo json_encode(['status'=>'error','message'=>'Dieser Nutzer ist gesperrt.']);
        exit;
    }
    $found = null;
    foreach (($data['users'] ?? []) as $u) {
        if (($u['username'] ?? null) === $user) { $found = $u; break; }
    }

    if ($found && isset($found['passHash']) && password_verify($pass, $found['passHash'])) {
        $_SESSION['admin_user'] = $user;
        $_SESSION['login_attempts'] = 0;
        session_regenerate_id(true);
        echo json_encode(["status" => "success", "username" => $user]);
    } else {
        $_SESSION['login_attempts']++;
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Falsche Zugangsdaten."]);
    }
    exit;
}

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    echo json_encode(["status" => "success"]);
    exit;
}

if ($action === 'change_password') {
    requireAuth();
    $input = json_decode(file_get_contents('php://input'), true);
    $current = isset($input['currentPassword']) ? $input['currentPassword'] : '';
    $new = isset($input['newPassword']) ? $input['newPassword'] : '';

    if (strlen($new) < 8) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Neues Passwort muss mindestens 8 Zeichen haben."]);
        exit;
    }

    $me = currentUser();
    $data = readJsonFile($dataFile, $defaultData);
    $userIndex = null;
    foreach (($data['users'] ?? []) as $i => $u) {
        if (($u['username'] ?? null) === $me) { $userIndex = $i; break; }
    }
    if ($userIndex === null || !isset($data['users'][$userIndex]['passHash']) ||
        !password_verify($current, $data['users'][$userIndex]['passHash'])) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Aktuelles Passwort ist falsch."]);
        exit;
    }

    $data['users'][$userIndex]['passHash'] = password_hash($new, PASSWORD_DEFAULT);
    if (!writeJsonFile($dataFile, $data)) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Passwort konnte nicht gespeichert werden."]);
        exit;
    }
    echo json_encode(["status" => "success"]);
    exit;
}

if ($action === 'register') {
    $input = readJsonBody();
    $key = isset($input['key']) ? trim((string)$input['key']) : '';
    $user = isset($input['username']) ? trim($input['username']) : '';
    $pass = isset($input['password']) ? $input['password'] : '';

    if (!$key || !$user || !$pass) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Alle Felder ausfüllen!"]);
        exit;
    }
    if (strcasecmp($user, 'Niklas1882') === 0) {
        http_response_code(400); echo json_encode(["status"=>"error","message"=>"Dieser Benutzername ist reserviert."]); exit;
    }
    if (!preg_match('/^[a-zA-Z0-9_]{3,16}$/', $user)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Benutzername darf nur Buchstaben, Zahlen und _ enthalten (3-16 Zeichen)."]);
        exit;
    }
    if (strlen($pass) < 8) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Passwort muss mindestens 8 Zeichen haben."]);
        exit;
    }

    $data = readJsonFile($dataFile, $defaultData);
    $keyIndex = null;
    foreach (($data['keys'] ?? []) as $i => $k) {
        if (($k['code'] ?? null) === $key && ($k['status'] ?? null) === 'Aktiv') { $keyIndex = $i; break; }
    }
    if ($keyIndex === null) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Ungültiger Key!"]);
        exit;
    }
    foreach (($data['users'] ?? []) as $u) {
        if (($u['username'] ?? null) === $user) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Benutzername vergeben!"]);
            exit;
        }
    }

    $data['keys'][$keyIndex]['status'] = 'Verbraucht';
    $data['users'][] = [
        "username" => $user,
        "passHash" => password_hash($pass, PASSWORD_DEFAULT),
        "displayName" => $user,
        "bio" => "Admin",
        "permissions" => []
    ];
    if (!writeJsonFile($dataFile, $data)) {
        http_response_code(500); echo json_encode(["status"=>"error","message"=>"Registrierung konnte nicht gespeichert werden."]); exit;
    }
    echo json_encode(["status" => "success"]);
    exit;
}



function sanitizeTextDeep($value) {
    if (is_string($value)) {
        
        $value = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $value);
        return $value;
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[$k] = sanitizeTextDeep($v);
        }
        return $value;
    }
    return $value;
}










if ($action === 'generate_key') {
    requireAuth();
    $me = currentUser();
    $isOwner = ($me === 'Niklas1882');
    $data = readJsonFile($dataFile, $defaultData);
    $myPerms = [];
    foreach (($data['users'] ?? []) as $u) {
        if (($u['username'] ?? null) === $me) { $myPerms = $u['permissions'] ?? []; break; }
    }
    if (!$isOwner && !(isset($myPerms['keys']) && $myPerms['keys'] === true)) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Keine Berechtigung für Key-Erstellung."]);
        exit;
    }

    $activeCount = 0;
    foreach (($data['keys'] ?? []) as $k) {
        if (($k['status'] ?? '') === 'Aktiv') $activeCount++;
    }
    if ($activeCount >= 5) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Es können maximal 5 aktive Admin-Keys gleichzeitig existieren!"]);
        exit;
    }

    
    $code = 'SMP-KEY-' . strtoupper(bin2hex(random_bytes(4)));
    $data['keys'][] = ["code" => $code, "creator" => $me, "status" => "Aktiv"];
    $data['auditLogs'][] = [
        "time" => date('j.n.Y, H:i:s'),
        "user" => $me,
        "action" => "Key generiert: $code"
    ];
    if (!writeJsonFile($dataFile, $data)) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Key konnte nicht gespeichert werden."]);
        exit;
    }
    echo json_encode(["status" => "success", "code" => $code]);
    exit;
}





$arenaDir = __DIR__ . DIRECTORY_SEPARATOR . 'arena';
function requireArenaPermission() {
    requireAuth();
    $me = currentUser();
    if ($me === 'Niklas1882') return;
    $data = readJsonFile($GLOBALS['dataFile'], $GLOBALS['defaultData']);
    foreach (($data['users'] ?? []) as $u) {
        if (($u['username'] ?? '') === $me && !empty($u['permissions']['config'])) return;
    }
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Keine Berechtigung für Arena-Dateien.']);
    exit;
}
function arenaSafeName($name) {
    $name = basename((string)$name);
    if ($name === '' || strlen($name) > 180) return false;
    if (preg_match('/[\\\/\\\x00-\\\x1F]/', $name)) return false;
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]*\\.png(?:\\.mcmeta)?$/i', $name)) return false;
    return $name;
}
function arenaSizeHuman($bytes) {
    $units = ['B','KB','MB']; $i=0; $n=(float)$bytes;
    while ($n >= 1024 && $i < count($units)-1) { $n/=1024; $i++; }
    return ($i === 0 ? (string)(int)$n : number_format($n, 1, ',', '.')) . ' ' . $units[$i];
}
function arenaEnsureDir($dir) {
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        http_response_code(500); echo json_encode(['status'=>'error','message'=>'Arena-Ordner konnte nicht erstellt werden.']); exit;
    }
}

if ($action === 'arena_list') {
    requireArenaPermission();
    arenaEnsureDir($arenaDir);
    $files = [];
    foreach (scandir($arenaDir) ?: [] as $name) {
        $safe = arenaSafeName($name);
        if ($safe === false) continue;
        $path = $arenaDir . DIRECTORY_SEPARATOR . $safe;
        if (!is_file($path)) continue;
        $files[] = [
            'name' => $safe,
            'type' => preg_match('/\.png\.mcmeta$/i', $safe) ? 'mcmeta' : 'png',
            'size' => filesize($path),
            'sizeHuman' => arenaSizeHuman(filesize($path)),
            'modified' => date('d.m.Y H:i', filemtime($path))
        ];
    }
    usort($files, fn($a,$b) => strcasecmp($a['name'],$b['name']));
    echo json_encode(['status'=>'success','files'=>$files]);
    exit;
}

if ($action === 'arena_upload') {
    requireArenaPermission();
    arenaEnsureDir($arenaDir);
    $maxPng = 10 * 1024 * 1024;
    $maxMcmeta = 512 * 1024;
    $uploaded = [];

    $handleUpload = function($field, $expected) use (&$uploaded, $arenaDir, $maxPng, $maxMcmeta) {
        if (!isset($_FILES[$field])) return;
        $f = $_FILES[$field];
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload für ' . $field . ' fehlgeschlagen.');
        }
        $name = arenaSafeName($f['name'] ?? '');
        if ($name === false) throw new RuntimeException('Ungültiger Dateiname. Erlaubt sind PNG und PNG.MCMETA.');
        $size = (int)($f['size'] ?? 0);
        $limit = $field === 'png' ? $maxPng : $maxMcmeta;
        if ($size <= 0 || $size > $limit) throw new RuntimeException('Datei zu groß oder leer.');
        $tmp = $f['tmp_name'] ?? '';
        if (!is_uploaded_file($tmp)) throw new RuntimeException('Ungültiger Upload.');

        if ($field === 'png') {
            if (!preg_match('/\.png$/i', $name) || preg_match('/\.png\.mcmeta$/i', $name)) {
                throw new RuntimeException('Die PNG-Datei muss auf .png enden.');
            }
            $info = @getimagesize($tmp);
            if (!$info || ($info['mime'] ?? '') !== 'image/png') {
                throw new RuntimeException('Die Datei ist keine gültige PNG-Datei.');
            }
        } else {
            if (!preg_match('/\.png\.mcmeta$/i', $name)) {
                throw new RuntimeException('Die MCMETA-Datei muss auf .png.mcmeta enden.');
            }
            $raw = file_get_contents($tmp);
            $json = json_decode($raw, true);
            if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Die PNG.MCMETA-Datei enthält kein gültiges JSON.');
            }
            if (strlen($raw) > $maxMcmeta) throw new RuntimeException('PNG.MCMETA ist zu groß.');
        }

        $target = $arenaDir . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($tmp, $target)) throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        @chmod($target, 0644);
        $uploaded[] = $name;
    };

    try {
        $handleUpload('png', 'png');
        $handleUpload('mcmeta', 'mcmeta');
    } catch (Throwable $e) {
        foreach ($uploaded as $name) @unlink($arenaDir . DIRECTORY_SEPARATOR . $name);
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        exit;
    }
    echo json_encode(['status'=>'success','message'=>count($uploaded) . ' Datei(en) hochgeladen.','files'=>$uploaded]);
    exit;
}

if ($action === 'arena_delete') {
    requireArenaPermission();
    arenaEnsureDir($arenaDir);
    $input = readJsonBody();
    $name = arenaSafeName($input['name'] ?? '');
    if ($name === false) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Ungültiger Dateiname.']); exit; }
    $path = $arenaDir . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Datei nicht gefunden.']); exit; }
    if (!unlink($path)) { http_response_code(500); echo json_encode(['status'=>'error','message'=>'Datei konnte nicht gelöscht werden.']); exit; }
    echo json_encode(['status'=>'success','message'=>'Datei gelöscht.']);
    exit;
}

function requireOwner() {
    requireAuth();
    $me = currentUser();
    $data = readJsonFile($GLOBALS['dataFile'], $GLOBALS['defaultData']);
    foreach (($data['users'] ?? []) as $u) {
        if (($u['username'] ?? '') === $me && !empty($u['isOwner'])) return;
    }
    if ($me === 'Niklas1882') return;
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Nur der Owner darf diese Aktion ausführen.']);
    exit;
}

if ($action === 'get_bans') {
    requireOwner();
    $bans = banState(readJsonFile($bansFile, ['users'=>[], 'ips'=>[]]));
    echo json_encode(['status'=>'success','users'=>$bans['users'],'ips'=>$bans['ips']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if (in_array($action, ['ban_user','unban_user','ban_ip','unban_ip'], true)) {
    requireOwner();
    $input = readJsonBody();
    $value = trim((string)($input['value'] ?? ''));
    $bans = banState(readJsonFile($bansFile, ['users'=>[], 'ips'=>[]]));
    if ($action === 'ban_user' || $action === 'unban_user') {
        if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $value)) {
            http_response_code(400); echo json_encode(['status'=>'error','message'=>'Ungültiger Minecraft-Nutzername.']); exit;
        }
        if ($value === 'Niklas1882') { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Der Owner kann nicht gesperrt werden.']); exit; }
        if ($action === 'ban_user' && !in_array($value, $bans['users'], true)) $bans['users'][] = $value;
        if ($action === 'unban_user') $bans['users'] = array_values(array_diff($bans['users'], [$value]));
        $message = $action === 'ban_user' ? 'Nutzer gesperrt.' : 'Nutzersperre entfernt.';
    } else {
        if (!filter_var($value, FILTER_VALIDATE_IP)) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Ungültige IP-Adresse.']); exit; }
        if ($action === 'ban_ip' && !in_array($value, $bans['ips'], true)) $bans['ips'][] = $value;
        if ($action === 'unban_ip') $bans['ips'] = array_values(array_diff($bans['ips'], [$value]));
        $message = $action === 'ban_ip' ? 'IP-Adresse gesperrt.' : 'IP-Sperre entfernt.';
    }
    $bans['users'] = array_values(array_unique(array_slice($bans['users'], 0, 200)));
    $bans['ips'] = array_values(array_unique(array_slice($bans['ips'], 0, 200)));
    if (!writeJsonFile($bansFile, $bans)) { http_response_code(500); echo json_encode(['status'=>'error','message'=>'Sperrliste konnte nicht gespeichert werden.']); exit; }
    $data = readJsonFile($dataFile, $defaultData);
    $data['auditLogs'] = $data['auditLogs'] ?? [];
    $data['auditLogs'][] = ['time'=>date('j.n.Y, H:i:s'),'user'=>currentUser(),'action'=>$message.' '.$value];
    $data['auditLogs'] = array_slice($data['auditLogs'], -100);
    writeJsonFile($dataFile, $data);
    echo json_encode(['status'=>'success','message'=>$message,'users'=>$bans['users'],'ips'=>$bans['ips']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'clear_chat') {
    requireAuth();
    $me = currentUser();
    $dataForRole = readJsonFile($dataFile, $defaultData);
    $isOwner = ($me === 'Niklas1882');
    foreach (($dataForRole['users'] ?? []) as $u) {
        if (($u['username'] ?? '') === $me && !empty($u['isOwner'])) { $isOwner = true; break; }
    }
    if (!$isOwner) {
        http_response_code(403);
        echo json_encode(["status"=>"error","message"=>"Nur der Owner darf den Team-Chat leeren."]);
        exit;
    }
    if (!writeJsonFile($chatFile, [])) {
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>"Chat konnte nicht geleert werden."]);
        exit;
    }
    $dataForRole['auditLogs'] = $dataForRole['auditLogs'] ?? [];
    $dataForRole['auditLogs'][] = ['time'=>date('j.n.Y, H:i:s'),'user'=>$me,'action'=>'Team-Chat geleert'];
    $dataForRole['auditLogs'] = array_slice($dataForRole['auditLogs'], -100);
    writeJsonFile($dataFile, $dataForRole);
    echo json_encode(["status"=>"success","message"=>"Team-Chat wurde geleert."]);
    exit;
}

if ($action === 'clear_audit') {
    requireAuth();
    $me = currentUser();
    $dataForRole = readJsonFile($dataFile, $defaultData);
    $isOwner = ($me === 'Niklas1882');
    foreach (($dataForRole['users'] ?? []) as $u) {
        if (($u['username'] ?? '') === $me && !empty($u['isOwner'])) { $isOwner = true; break; }
    }
    if (!$isOwner) {
        http_response_code(403);
        echo json_encode(["status"=>"error","message"=>"Nur der Owner darf das Protokoll leeren."]);
        exit;
    }
    $dataForRole['auditLogs'] = [];
    if (!writeJsonFile($dataFile, $dataForRole)) {
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>"Protokoll konnte nicht geleert werden."]);
        exit;
    }
    echo json_encode(["status"=>"success","message"=>"Protokoll wurde geleert."]);
    exit;
}

if ($action === 'save_main') {
    requireAuth();
    $input = readJsonBody();
    $input = sanitizeTextDeep($input);

    
    
    $existingForPerm = readJsonFile($dataFile, $defaultData);
    $me = currentUser();
    $isOwner = ($me === 'Niklas1882');
    $myPerms = [];
    foreach (($existingForPerm['users'] ?? []) as $u) {
        if (($u['username'] ?? null) === $me) { $myPerms = $u['permissions'] ?? []; if (!empty($u['isOwner'])) $isOwner = true; break; }
    }
    function hasPerm($isOwner, $myPerms, $type) {
        return $isOwner || (isset($myPerms[$type]) && $myPerms[$type] === true);
    }

    if (isset($input['team']) && json_encode($input['team']) !== json_encode($existingForPerm['team'] ?? []) && !hasPerm($isOwner, $myPerms, 'team')) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Keine Berechtigung für Team-Änderungen."]);
        exit;
    }
    if (isset($input['news']) && json_encode($input['news']) !== json_encode($existingForPerm['news'] ?? []) && !hasPerm($isOwner, $myPerms, 'news')) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Keine Berechtigung für News-Änderungen."]);
        exit;
    }
    if (isset($input['gallery']) && json_encode($input['gallery']) !== json_encode($existingForPerm['gallery'] ?? []) && !hasPerm($isOwner, $myPerms, 'news')) {
        http_response_code(403); echo json_encode(["status"=>"error","message"=>"Keine Berechtigung für Galerie-Änderungen."]); exit;
    }
    if ((isset($input['serverIp']) && $input['serverIp'] !== ($existingForPerm['serverIp'] ?? '')) ||
        (isset($input['joinSteps']) && json_encode($input['joinSteps']) !== json_encode($existingForPerm['joinSteps'] ?? []))) {
        if (!hasPerm($isOwner, $myPerms, 'config')) {
            http_response_code(403); echo json_encode(["status"=>"error","message"=>"Keine Berechtigung für Konfigurationsänderungen."]); exit;
        }
    }
    if (isset($input['maintenance']) && json_encode($input['maintenance']) !== json_encode($existingForPerm['maintenance'] ?? ['enabled'=>false,'title'=>'Wartungsarbeiten','text'=>'']) && !hasPerm($isOwner, $myPerms, 'config')) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Keine Berechtigung für den Wartungsmodus."]);
        exit;
    }
    if (isset($input['siteNotice']) && json_encode($input['siteNotice']) !== json_encode($existingForPerm['siteNotice'] ?? ['enabled'=>false,'type'=>'info','title'=>'','text'=>'']) && !hasPerm($isOwner, $myPerms, 'news')) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Keine Berechtigung für Website-Hinweise."]);
        exit;
    }
    if (isset($input['keys']) && json_encode($input['keys']) !== json_encode($existingForPerm['keys'] ?? []) && !hasPerm($isOwner, $myPerms, 'keys')) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Keine Berechtigung für Key-Änderungen."]);
        exit;
    }
    
    
    if (isset($input['users']) && is_array($input['users']) && !$isOwner) {
        $oldUsers = $existingForPerm['users'] ?? [];
        $oldByName = [];
        foreach ($oldUsers as $u) {
            if (isset($u['username'])) $oldByName[$u['username']] = $u;
        }
        $submittedOwn = null;
        foreach ($input['users'] as $u) {
            if (($u['username'] ?? '') === $me) { $submittedOwn = $u; break; }
        }
        if (!$submittedOwn) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Nutzerkonten dürfen nicht entfernt werden."]);
            exit;
        }
        $allowedProfile = ['displayName','status','contact','bio','bioPublic','avatar','badgeColor','chatSound'];
        $safeOwn = $oldByName[$me];
        foreach ($allowedProfile as $field) {
            if (array_key_exists($field, $submittedOwn)) $safeOwn[$field] = $submittedOwn[$field];
        }
        $input['users'] = [];
        foreach ($oldUsers as $u) {
            $input['users'][] = (($u['username'] ?? '') === $me) ? $safeOwn : $u;
        }
    }

    
    
    $existing = readJsonFile($dataFile, $defaultData);
    $existingHashes = [];
    foreach (($existing['users'] ?? []) as $u) {
        if (isset($u['username'])) $existingHashes[$u['username']] = $u['passHash'] ?? null;
    }
    if (isset($input['users']) && is_array($input['users'])) {
        foreach ($input['users'] as &$u) {
            unset($u['pass']); 
            if (!isset($u['passHash']) && isset($u['username']) && isset($existingHashes[$u['username']])) {
                $u['passHash'] = $existingHashes[$u['username']];
            }
        }
        unset($u);
    }

    
    
    
    
    
    $input['auditLogs'] = $existing['auditLogs'] ?? [];
    $auditAction = trim((string)($_SERVER['HTTP_X_ADMIN_ACTION'] ?? 'Daten aktualisiert'));
    if ($auditAction !== '') {
        $input['auditLogs'][] = ['time' => date('j.n.Y, H:i:s'), 'user' => $me, 'action' => safeTextLimit($auditAction, 120)];
        if (count($input['auditLogs']) > 100) $input['auditLogs'] = array_slice($input['auditLogs'], -100);
    }

    
    if (isset($input['siteNotice'])) {
        $notice = is_array($input['siteNotice']) ? $input['siteNotice'] : [];
        $type = in_array(($notice['type'] ?? 'info'), ['info','success','warning','danger'], true) ? $notice['type'] : 'info';
        $input['siteNotice'] = [
            'enabled' => !empty($notice['enabled']),
            'type' => $type,
            'title' => safeTextLimit(trim((string)($notice['title'] ?? '')), 80),
            'text' => safeTextLimit(trim((string)($notice['text'] ?? '')), 500)
        ];
    }

    if (isset($input['maintenance'])) {
        $m = is_array($input['maintenance']) ? $input['maintenance'] : [];
        $input['maintenance'] = [
            'enabled' => !empty($m['enabled']),
            'title' => safeTextLimit(trim((string)($m['title'] ?? 'Wartungsarbeiten')), 80),
            'text' => safeTextLimit(trim((string)($m['text'] ?? 'Die Website wird gerade aktualisiert. Bitte später erneut versuchen.')), 500)
        ];
    }

    if (isset($input['serverIp'])) {
        $serverIp = trim((string)$input['serverIp']);
        if ($serverIp === '' || strlen($serverIp) > 255 || !preg_match('/^[a-zA-Z0-9._:-]+$/', $serverIp)) {
            http_response_code(400); echo json_encode(["status"=>"error","message"=>"Ungültige Serveradresse."]); exit;
        }
        $input['serverIp'] = $serverIp;
    }
    if (isset($input['joinSteps'])) {
        if (!is_array($input['joinSteps']) || count($input['joinSteps']) > 20) {
            http_response_code(400); echo json_encode(["status"=>"error","message"=>"Ungültige Join-Schritte."]); exit;
        }
        foreach ($input['joinSteps'] as &$step) {
            if (!is_array($step)) $step = [];
            $step = ['title'=>safeTextLimit(trim((string)($step['title'] ?? '')),120),
                     'desc'=>safeTextLimit(trim((string)($step['desc'] ?? '')),500)];
        }
        unset($step);
    }

    
    if (isset($input['team'])) {
        if (!is_array($input['team']) || count($input['team']) > 50) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Ungültige Teamdaten."]);
            exit;
        }
        $allowedRoles = ['Owner','Co Owner','Developer','Admin','Moderator','Supporter','Test Supporter','Builder'];
        $seenTeamNames = [];
        $cleanTeam = [];
        foreach ($input['team'] as $member) {
            if (!is_array($member)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Ungültiges Teammitglied."]);
                exit;
            }
            $name = trim((string)($member['name'] ?? ''));
            $role = trim((string)($member['role'] ?? ''));
            if ($role === 'Co-Owner') $role = 'Co Owner';
            if ($role === 'Mod') $role = 'Moderator';
            if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $name) || !in_array($role, $allowedRoles, true)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Ungültiges Teammitglied oder ungültiger Rang."]);
                exit;
            }
            $key = strtolower($name);
            if (isset($seenTeamNames[$key])) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Doppelte Teammitglieder sind nicht erlaubt."]);
                exit;
            }
            $seenTeamNames[$key] = true;
            $cleanTeam[] = ['name' => $name, 'role' => $role];
        }
        $input['team'] = $cleanTeam;
    }

    if (isset($input['keys']) && is_array($input['keys'])) {
        $activeCount = 0;
        foreach ($input['keys'] as $k) {
            if (($k['status'] ?? '') === 'Aktiv') $activeCount++;
        }
        if ($activeCount > 5) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Es können maximal 5 aktive Admin-Keys gleichzeitig existieren!"]);
            exit;
        }
    }

    $merged = $existing;
    $allowedTopLevel = ['keys','team','users','news','gallery','serverIp','joinSteps','siteNotice','maintenance'];
    foreach ($allowedTopLevel as $field) {
        if (array_key_exists($field, $input)) $merged[$field] = $input[$field];
    }
    if (array_key_exists('auditLogs', $input) && is_array($input['auditLogs'])) $merged['auditLogs'] = $input['auditLogs'];

    $ownerIndex = null;
    foreach (($merged['users'] ?? []) as $idx => $u) {
        if (($u['username'] ?? '') === 'Niklas1882') { $ownerIndex = $idx; break; }
    }
    if ($ownerIndex === null) {
        $merged['users'][] = [
            'username' => 'Niklas1882',
            'passHash' => password_hash('admin', PASSWORD_DEFAULT),
            'displayName' => 'Niklas1882',
            'isOwner' => true,
            'permissions' => ['keys'=>true,'team'=>true,'news'=>true,'rankDelete'=>true,'config'=>true]
        ];
    } else {
        $merged['users'][$ownerIndex]['username'] = 'Niklas1882';
        $merged['users'][$ownerIndex]['isOwner'] = true;
        if (empty($merged['users'][$ownerIndex]['passHash'])) {
            $merged['users'][$ownerIndex]['passHash'] = password_hash('admin', PASSWORD_DEFAULT);
        }
        $merged['users'][$ownerIndex]['permissions'] = array_merge(
            ['keys'=>true,'team'=>true,'news'=>true,'rankDelete'=>true,'config'=>true],
            is_array($merged['users'][$ownerIndex]['permissions'] ?? null) ? $merged['users'][$ownerIndex]['permissions'] : []
        );
    }

    $result = writeJsonFile($dataFile, $merged);
    if ($result !== false) {
        echo json_encode(["status" => "success"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Daten konnten nicht gespeichert werden."]);
    }
    exit;
}

if ($action === 'send_chat') {
    requireAuth();
    $input = readJsonBody();
    $text = isset($input['text']) ? trim((string)$input['text']) : '';
    if ($text !== '') {
        
        $me = currentUser();
        $now = time();
        if (isset($_SESSION['last_chat_message']) && ($now - (int)$_SESSION['last_chat_message']) < 1) {
            http_response_code(429);
            echo json_encode(["status" => "error", "message" => "Bitte kurz warten."]);
            exit;
        }
        $_SESSION['last_chat_message'] = $now;
        $chatData = readJsonFile($chatFile, []);

        $chatData[] = [
            "user" => substr($me, 0, 50),
            "text" => safeTextLimit($text, 1000),
            "time" => date('H:i')
        ];

        if (count($chatData) > 50) { array_shift($chatData); }

        if (!writeJsonFile($chatFile, $chatData)) {
            http_response_code(500);
            echo json_encode(['status'=>'error','message'=>'Chatdatei konnte nicht gespeichert werden. Prüfe Schreibrechte von smp_chat.json.']);
            exit;
        }

        $typingData = readJsonFile($typingFile, []);
        unset($typingData[$me]);
        writeJsonFile($typingFile, $typingData);

        echo json_encode(["status" => "success"]);
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "user und text erforderlich."]);
    }
    exit;
}

if ($action === 'typing') {
    requireAuth();
    $me = currentUser();
    $typingData = readJsonFile($typingFile, []);

    
    $now = time();
    foreach ($typingData as $uname => $ts) {
        if ($now - $ts > 5) unset($typingData[$uname]);
    }

    $input = readJsonBody();
    $mode = $input['mode'] ?? 'update'; 

    if ($mode === 'update') {
        $isTyping = isset($input['isTyping']) ? (bool)$input['isTyping'] : true;
        if ($isTyping) {
            $typingData[$me] = $now;
        } else {
            unset($typingData[$me]);
        }
        writeJsonFile($typingFile, $typingData);
    }

    
    unset($typingData[$me]);
    echo json_encode(["status" => "success", "typingUsers" => array_keys($typingData)]);
    exit;
}




function cleanupStaleTalkUsers(&$talkData) {
    $now = time();
    foreach ($talkData as $uname => $entry) {
        
        
        if (!isset($entry['lastSeen']) || $now - $entry['lastSeen'] > 10) {
            unset($talkData[$uname]);
        }
    }
}

if ($action === 'talk_join') {
    requireAuth();
    $me = currentUser();
    $talkData = readJsonFile($talkFile, []);
    cleanupStaleTalkUsers($talkData);

    $talkData[$me] = [
        "muted" => false,
        "deafened" => false,
        "camActive" => false,
        "isSpeaking" => false,
        "lastSeen" => time(),
        "joinedAt" => time()
    ];
    if (!writeJsonFile($talkFile, $talkData)) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Talk-Status konnte nicht gespeichert werden."]);
        exit;
    }
    echo json_encode(["status" => "success", "participants" => $talkData]);
    exit;
}

if ($action === 'talk_leave') {
    requireAuth();
    $me = currentUser();
    $talkData = readJsonFile($talkFile, []);
    unset($talkData[$me]);
    writeJsonFile($talkFile, $talkData);

    
    $signalData = readJsonFile($signalFile, []);
    unset($signalData[$me]);
    foreach ($signalData as $recipient => &$queue) {
        $queue = array_values(array_filter($queue, function($msg) use ($me) {
            return ($msg['from'] ?? null) !== $me;
        }));
    }
    unset($queue);
    writeJsonFile($signalFile, $signalData);

    echo json_encode(["status" => "success"]);
    exit;
}

if ($action === 'talk_update') {
    requireAuth();
    $me = currentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    $talkData = readJsonFile($talkFile, []);
    cleanupStaleTalkUsers($talkData);

    if (!isset($talkData[$me])) {
        
        
        $talkData[$me] = ["muted" => false, "deafened" => false, "camActive" => false, "isSpeaking" => false, "joinedAt" => time()];
    }
    $talkData[$me]['muted'] = isset($input['muted']) ? (bool)$input['muted'] : $talkData[$me]['muted'];
    $talkData[$me]['deafened'] = isset($input['deafened']) ? (bool)$input['deafened'] : $talkData[$me]['deafened'];
    $talkData[$me]['camActive'] = isset($input['camActive']) ? (bool)$input['camActive'] : $talkData[$me]['camActive'];
    $talkData[$me]['isSpeaking'] = isset($input['isSpeaking']) ? (bool)$input['isSpeaking'] : $talkData[$me]['isSpeaking'];
    $talkData[$me]['lastSeen'] = time();

    writeJsonFile($talkFile, $talkData);
    echo json_encode(["status" => "success", "participants" => $talkData]);
    exit;
}

if ($action === 'talk_list') {
    requireAuth();
    $talkData = readJsonFile($talkFile, []);
    cleanupStaleTalkUsers($talkData);
    echo json_encode(["status" => "success", "participants" => $talkData]);
    exit;
}




if ($action === 'signal_send') {
    requireAuth();
    $me = currentUser();
    $rawInput = file_get_contents('php://input');
    if (strlen($rawInput) > 100 * 1024) { 
        http_response_code(413);
        echo json_encode(["status" => "error", "message" => "Signaling-Nachricht zu groß."]);
        exit;
    }
    $input = json_decode($rawInput, true);
    $to = isset($input['to']) ? trim($input['to']) : '';
    $type = isset($input['type']) ? trim($input['type']) : '';
    $payload = $input['payload'] ?? null;

    $allowedTypes = ['offer', 'answer', 'ice-candidate', 'leave'];
    if (!$to || $to === $me || !in_array($type, $allowedTypes, true) || $payload === null) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Ungültige Signaling-Nachricht."]);
        exit;
    }

    
    $usersData = readJsonFile($dataFile, $defaultData);
    $knownUsers = array_column($usersData['users'] ?? [], 'username');
    if (!in_array($to, $knownUsers, true)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Unbekannter Empfänger."]);
        exit;
    }

    $signalData = readJsonFile($signalFile, []);
    if (!isset($signalData[$to])) $signalData[$to] = [];

    
    
    $signalData[$to][] = ["from" => $me, "type" => $type, "payload" => $payload, "ts" => time()];
    if (count($signalData[$to]) > 200) {
        $signalData[$to] = array_slice($signalData[$to], -200);
    }

    if (!writeJsonFile($signalFile, $signalData)) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Talk-Signalisierung konnte nicht gespeichert werden."]);
        exit;
    }
    echo json_encode(["status" => "success"]);
    exit;
}

if ($action === 'signal_poll') {
    requireAuth();
    $me = currentUser();
    $signalData = readJsonFile($signalFile, []);
    $myMessages = $signalData[$me] ?? [];

    
    if (!empty($myMessages)) {
        unset($signalData[$me]);
        writeJsonFile($signalFile, $signalData);
    }

    echo json_encode(["status" => "success", "messages" => $myMessages]);
    exit;
}

http_response_code(404);
echo json_encode(["status" => "error", "message" => "Unbekannte Aktion."]);
