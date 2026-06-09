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

// ── Helpers ──────────────────────────────────────────────────────
function blankState(): array {
  return [
    'players'      => [],
    'groupPreds'   => new stdClass(),
    'groupResults' => new stdClass(),
    'koPreds'      => new stdClass(),
    'koResults'    => new stdClass(),
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
    'koPreds'      => $s['koPreds']       ?? new stdClass(),
    'koResults'    => $s['koResults']     ?? new stdClass(),
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

// ── Router ───────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  out(publicState(loadState($FILE)));
}

if ($method !== 'POST') {
  http_response_code(405);
  out(['ok' => false, 'error' => 'método no permitido']);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
  http_response_code(400);
  out(['ok' => false, 'error' => 'json inválido']);
}

$op = (string)($body['op'] ?? '');
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

    if (!isset($state[$scope]) || !is_array($state[$scope])) $state[$scope] = [];
    if (!isset($state[$scope][$mid]) || !is_array($state[$scope][$mid])) $state[$scope][$mid] = [];
    if ($val === '') unset($state[$scope][$mid][$id]);
    else $state[$scope][$mid][$id] = $val;

    saveState($FILE, $state);
    out(['ok' => true, 'updatedAt' => $state['updatedAt'] ?? 0]);
  }

  default:
    http_response_code(400);
    out(['ok' => false, 'error' => 'op_desconocida']);
}
