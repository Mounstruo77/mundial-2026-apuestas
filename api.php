<?php
/**
 * Mundial 2026 — Apuestas · Backend de sincronización
 * --------------------------------------------------------------
 * Guarda el estado compartido (jugadores, montos, apuestas, resultados)
 * en un archivo JSON en el servidor, para que todos vean lo mismo.
 *
 * - El estado se guarda FUERA de la carpeta web cuando es posible.
 * - El PIN de administrador y los códigos de jugador se guardan hasheados.
 * - Un usuario solo puede escribir SU propia predicción.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

// ── Ubicación del archivo de datos ───────────────────────────────
// Intentamos guardarlo fuera de public_html (no accesible por web).
$DIR = getenv('MW26_DATA') ?: (dirname(__DIR__, 2) . '/mw26_data');
if (!@is_dir($DIR)) { @mkdir($DIR, 0775, true); }
if (!is_dir($DIR) || !is_writable($DIR)) {
  // Fallback: carpeta local ./data (protegida con .htaccess más abajo)
  $DIR = __DIR__ . '/data';
  if (!@is_dir($DIR)) { @mkdir($DIR, 0775, true); }
  // .htaccess de respaldo (sirve en Apache; en nginx se ignora)
  $ht = $DIR . '/.htaccess';
  if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\nDeny from all\n"); }
}
$FILE = $DIR . '/state.json';

// ── Seguridad: IP del cliente y rate limiting ────────────────────
function clientIp(): string {
  // Detrás de nginx/proxy la IP real suele venir en estas cabeceras
  foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
    if (!empty($_SERVER[$h])) {
      $ip = trim(explode(',', $_SERVER[$h])[0]);
      if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
  }
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Limita acciones por IP. Devuelve true si se permite, false si excede.
function rateAllow(string $dir, string $bucket, int $max, int $window): bool {
  $file = $dir . '/rl_' . preg_replace('/[^a-z0-9]/i', '', $bucket) . '.json';
  $fp = @fopen($file, 'c+');
  if (!$fp) return true; // si no se puede registrar, no bloqueamos (fail-open)
  $allowed = true;
  if (flock($fp, LOCK_EX)) {
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data)) $data = [];
    $ip = clientIp();
    $now = time();
    $list = array_values(array_filter($data[$ip] ?? [], fn($t) => is_int($t) && $t > $now - $window));
    $allowed = count($list) < $max;
    if ($allowed) $list[] = $now;
    $data[$ip] = $list;
    // Poda para que el archivo no crezca sin control
    if (count($data) > 800) {
      foreach ($data as $k => $v) {
        $data[$k] = array_values(array_filter((array)$v, fn($t) => is_int($t) && $t > $now - 3600));
        if (!$data[$k]) unset($data[$k]);
      }
    }
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp); flock($fp, LOCK_UN);
  }
  fclose($fp);
  return $allowed;
}
function tooMany() { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'rate_limit', 'msg' => 'Demasiados intentos, espera un momento.']); exit; }

// ── Helpers ──────────────────────────────────────────────────────
function blankState(): array {
  return [
    'players'      => [],
    'groupPreds'   => new stdClass(),
    'groupResults' => new stdClass(),
    'koPreds'      => new stdClass(),
    'koResults'    => new stdClass(),
    'firstBetAt'   => new stdClass(),
    'adminHash'    => null,
    'updatedAt'    => 0,
  ];
}

function loadState(string $FILE): array {
  if (!file_exists($FILE)) return blankState();
  $raw = @file_get_contents($FILE);
  if ($raw === false || $raw === '') return blankState();
  $j = json_decode($raw, true);
  if (!is_array($j)) return blankState();
  foreach (['players','groupPreds','groupResults','koPreds','koResults'] as $k) {
    if (!isset($j[$k])) $j[$k] = ($k === 'players') ? [] : [];
  }
  return $j;
}

function saveState(string $FILE, array $s): bool {
  $s['updatedAt'] = (int) round(microtime(true) * 1000);
  // Forzamos que los diccionarios vacíos se serialicen como {} y no como []
  foreach (['groupPreds','groupResults','koPreds','koResults'] as $k) {
    if (empty($s[$k])) $s[$k] = new stdClass();
  }
  $json = json_encode($s, JSON_UNESCAPED_UNICODE);
  if ($json === false) return false;
  $fp = @fopen($FILE, 'c+');
  if (!$fp) return false;
  $ok = false;
  if (flock($fp, LOCK_EX)) {
    ftruncate($fp, 0);
    rewind($fp);
    $ok = (fwrite($fp, $json) !== false);
    fflush($fp);
    flock($fp, LOCK_UN);
  }
  fclose($fp);
  return $ok;
}

function h(string $p): string { return hash('sha256', 'mw26::' . $p); }

function out(array $payload): void {
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

// Versión pública: sin hashes secretos; los códigos se reducen a "tiene/no tiene"
function publicState(array $s): array {
  $players = [];
  foreach (($s['players'] ?? []) as $p) {
    if (!is_array($p)) continue;
    $players[] = [
      'id'      => (string)($p['id'] ?? ''),
      'name'    => (string)($p['name'] ?? ''),
      'amount'  => (int)($p['amount'] ?? 10000),
      'hasCode' => !empty($p['codeHash']),
      'status'  => (string)($p['status'] ?? 'approved'),
    ];
  }
  return [
    'players'      => $players,
    'groupPreds'   => $s['groupPreds']   ?? new stdClass(),
    'groupResults' => $s['groupResults'] ?? new stdClass(),
    'koPreds'      => $s['koPreds']      ?? new stdClass(),
    'koResults'    => $s['koResults']    ?? new stdClass(),
    'firstBetAt'   => $s['firstBetAt']  ?? new stdClass(),
    'hasAdmin'     => !empty($s['adminHash']),
    'updatedAt'    => (int)($s['updatedAt'] ?? 0),
  ];
}

function findPlayer(array $s, string $id): ?array {
  foreach (($s['players'] ?? []) as $p) {
    if (is_array($p) && (string)($p['id'] ?? '') === $id) return $p;
  }
  return null;
}

function checkAdmin(array $s, string $pin): bool {
  return !empty($s['adminHash']) && hash_equals((string)$s['adminHash'], h($pin));
}

// Horario de partidos: matchId => 'YYYY-MM-DDTHH:mm' (hora de México, UTC-6)
$SCHEDULE = json_decode(@file_get_contents(__DIR__ . '/schedule.json'), true);
if (!is_array($SCHEDULE)) $SCHEDULE = [];

// ¿Ya empezó el partido? (si no conocemos el horario, no bloqueamos)
function matchStarted(array $sched, string $matchId): bool {
  if (!isset($sched[$matchId])) return false;
  try {
    $kick = new DateTime($sched[$matchId] . ':00-06:00'); // hora de México
    return (new DateTime('now')) >= $kick;
  } catch (Exception $e) { return false; }
}

// ── Router ───────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  // Límite suave de lectura por IP (evita scraping agresivo)
  if (!rateAllow($DIR, 'get', 240, 60)) tooMany();
  out(publicState(loadState($FILE)));
}

if ($method !== 'POST') {
  http_response_code(405);
  out(['ok' => false, 'error' => 'método no permitido']);
}

// Límite global de escritura por IP
if (!rateAllow($DIR, 'post', 120, 60)) tooMany();

// Límite de tamaño del cuerpo (anti-DoS de memoria)
$raw = file_get_contents('php://input', false, null, 0, 120000);
if (strlen($raw) >= 120000) { http_response_code(413); out(['ok' => false, 'error' => 'payload_grande']); }
$body = json_decode($raw, true);
if (!is_array($body)) {
  http_response_code(400);
  out(['ok' => false, 'error' => 'json inválido']);
}

$op = (string)($body['op'] ?? '');

// Límites específicos por operación sensible (anti fuerza bruta / spam)
if (in_array($op, ['login', 'verifyAdmin', 'claimAdmin'], true)) {
  // intentos de adivinar PIN/clave
  if (!rateAllow($DIR, 'auth', 25, 600)) tooMany();   // 25 / 10 min
}
if ($op === 'register') {
  if (!rateAllow($DIR, 'register', 8, 3600)) tooMany(); // 8 registros / hora
}

$state = loadState($FILE);

switch ($op) {

  // Reclamar el rol admin la primera vez (si aún no hay PIN)
  case 'claimAdmin': {
    $pin = (string)($body['pin'] ?? '');
    if (strlen($pin) < 4) out(['ok' => false, 'error' => 'pin_corto']);
    if (!empty($state['adminHash'])) out(['ok' => false, 'error' => 'ya_existe']);
    $state['adminHash'] = h($pin);
    saveState($FILE, $state);
    out(['ok' => true]);
  }

  // Verificar PIN admin (para entrar en modo admin)
  case 'verifyAdmin': {
    $pin = (string)($body['pin'] ?? '');
    out(['ok' => checkAdmin($state, $pin)]);
  }

  // Un usuario se registra solo (queda PENDIENTE de aprobación) con su propia clave
  case 'register': {
    $name = mb_substr(preg_replace('/[<>"\'&\x00-\x1F\x7F]/u', '', (string)($body['name'] ?? '')), 0, 40);
    $name = trim($name);
    $amount = (int)($body['amount'] ?? 10000);
    $code = (string)($body['code'] ?? '');
    if ($name === '') out(['ok' => false, 'error' => 'nombre']);
    if (strlen($code) < 4) out(['ok' => false, 'error' => 'clave_corta']);
    if ($amount < 10000) $amount = 10000;
    if ($amount > 100000000) $amount = 100000000;
    if (count($state['players'] ?? []) >= 100) out(['ok' => false, 'error' => 'lleno']);
    // id único
    $id = substr(bin2hex(random_bytes(6)), 0, 7);
    $state['players'][] = [
      'id' => $id, 'name' => $name, 'amount' => $amount,
      'codeHash' => h($code), 'status' => 'pending',
    ];
    saveState($FILE, $state);
    out(['ok' => true, 'playerId' => $id, 'state' => publicState($state)]);
  }

  // Admin aprueba un registro pendiente
  case 'approve': {
    $pin = (string)($body['pin'] ?? '');
    if (!checkAdmin($state, $pin)) { http_response_code(403); out(['ok' => false, 'error' => 'pin']); }
    $id = (string)($body['playerId'] ?? '');
    $found = false;
    foreach ($state['players'] as &$p) {
      if (is_array($p) && (string)($p['id'] ?? '') === $id) { $p['status'] = 'approved'; $found = true; break; }
    }
    unset($p);
    if (!$found) out(['ok' => false, 'error' => 'no_existe']);
    saveState($FILE, $state);
    out(['ok' => true, 'state' => publicState($state)]);
  }

  // Admin elimina/rechaza un jugador (y sus predicciones)
  case 'removePlayer': {
    $pin = (string)($body['pin'] ?? '');
    if (!checkAdmin($state, $pin)) { http_response_code(403); out(['ok' => false, 'error' => 'pin']); }
    $id = (string)($body['playerId'] ?? '');
    $state['players'] = array_values(array_filter($state['players'], function ($p) use ($id) {
      return !(is_array($p) && (string)($p['id'] ?? '') === $id);
    }));
    foreach (['groupPreds','koPreds'] as $pk) {
      if (!isset($state[$pk]) || !is_array($state[$pk])) continue;
      foreach ($state[$pk] as $mid => $byPlayer) {
        if (is_array($byPlayer) && isset($byPlayer[$id])) unset($state[$pk][$mid][$id]);
      }
    }
    saveState($FILE, $state);
    out(['ok' => true, 'state' => publicState($state)]);
  }

  // Admin ajusta el monto de un jugador
  case 'setAmount': {
    $pin = (string)($body['pin'] ?? '');
    if (!checkAdmin($state, $pin)) { http_response_code(403); out(['ok' => false, 'error' => 'pin']); }
    $id = (string)($body['playerId'] ?? '');
    $amount = (int)($body['amount'] ?? 10000);
    if ($amount < 10000) $amount = 10000;
    if ($amount > 100000000) $amount = 100000000;
    foreach ($state['players'] as &$p) {
      if (is_array($p) && (string)($p['id'] ?? '') === $id) { $p['amount'] = $amount; break; }
    }
    unset($p);
    saveState($FILE, $state);
    out(['ok' => true, 'state' => publicState($state)]);
  }

  // Admin guarda resultados (no toca jugadores ni predicciones)
  case 'results': {
    $pin = (string)($body['pin'] ?? '');
    if (!checkAdmin($state, $pin)) { http_response_code(403); out(['ok' => false, 'error' => 'pin']); }
    if (isset($body['groupResults']) && is_array($body['groupResults'])) $state['groupResults'] = $body['groupResults'];
    if (isset($body['koResults'])    && is_array($body['koResults']))    $state['koResults']    = $body['koResults'];
    saveState($FILE, $state);
    out(['ok' => true, 'state' => publicState($state)]);
  }

  // Admin reinicia todo
  case 'reset': {
    $pin = (string)($body['pin'] ?? '');
    if (!checkAdmin($state, $pin)) { http_response_code(403); out(['ok' => false, 'error' => 'pin']); }
    $fresh = blankState();
    $fresh['adminHash'] = $state['adminHash']; // conservamos el PIN admin
    saveState($FILE, $fresh);
    out(['ok' => true, 'state' => publicState($fresh)]);
  }

  // Login de un usuario: valida nombre + código
  case 'login': {
    $id   = (string)($body['playerId'] ?? '');
    $code = (string)($body['code'] ?? '');
    $player = findPlayer($state, $id);
    if (!$player) out(['ok' => false, 'error' => 'no_existe']);
    if (($player['status'] ?? 'approved') !== 'approved') out(['ok' => false, 'error' => 'pendiente']);
    if (empty($player['codeHash'])) out(['ok' => true]); // jugador sin código
    out(['ok' => hash_equals((string)$player['codeHash'], h($code))]);
  }

  // Un usuario guarda SU predicción (solo la suya)
  case 'pred': {
    $id    = (string)($body['playerId'] ?? '');
    $code  = (string)($body['code'] ?? '');
    $scope = ((string)($body['scope'] ?? '') === 'ko') ? 'koPreds' : 'groupPreds';
    $mid   = (string)($body['matchId'] ?? '');
    $val   = (string)($body['value'] ?? '');

    $player = findPlayer($state, $id);
    if (!$player) { http_response_code(403); out(['ok' => false, 'error' => 'jugador']); }
    if (($player['status'] ?? 'approved') !== 'approved') { http_response_code(403); out(['ok' => false, 'error' => 'pendiente']); }
    if (!empty($player['codeHash']) && !hash_equals((string)$player['codeHash'], h($code))) {
      http_response_code(403); out(['ok' => false, 'error' => 'codigo']);
    }
    if ($mid === '') { http_response_code(400); out(['ok' => false, 'error' => 'match']); }
    // Regla del juego: no se puede crear/modificar la predicción una vez empezado el partido
    if (matchStarted($SCHEDULE, $mid)) { http_response_code(403); out(['ok' => false, 'error' => 'cerrado']); }

    if (!isset($state[$scope]) || !is_array($state[$scope])) $state[$scope] = [];
    if (!isset($state[$scope][$mid]) || !is_array($state[$scope][$mid])) $state[$scope][$mid] = [];
    if ($val === '') unset($state[$scope][$mid][$id]);
    else $state[$scope][$mid][$id] = $val;

    // Registrar timestamp de primera apuesta (para desempate en Score Apuestas)
    if ($val !== '') {
      if (!isset($state['firstBetAt']) || !is_array($state['firstBetAt'])) $state['firstBetAt'] = [];
      if (!isset($state['firstBetAt'][$id])) {
        $state['firstBetAt'][$id] = (int) round(microtime(true) * 1000);
      }
    }

    saveState($FILE, $state);
    out(['ok' => true, 'updatedAt' => $state['updatedAt'] ?? 0]);
  }

  // Admin guarda la API key de API-Football en el servidor
  case 'setApiKey': {
    $pin = (string)($body['pin'] ?? '');
    if (!checkAdmin($state, $pin)) { http_response_code(403); out(['ok' => false, 'error' => 'pin']); }
    $key = trim((string)($body['key'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9]{20,64}$/', $key)) out(['ok' => false, 'error' => 'key_invalida']);
    $keyFile = $DIR . '/apifootball.key';
    if (@file_put_contents($keyFile, $key) === false) out(['ok' => false, 'error' => 'write']);
    out(['ok' => true]);
  }

  default:
    http_response_code(400);
    out(['ok' => false, 'error' => 'op_desconocida']);
}
