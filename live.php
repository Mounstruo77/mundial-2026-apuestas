<?php
/**
 * live.php — Panel de partido en vivo (proxy a API-Football con caché)
 * --------------------------------------------------------------------
 * Empareja un partido de la app con el fixture real de API-Football y
 * devuelve marcador, estado/minuto, alineaciones (con formación/grid),
 * timeline de eventos y estadísticas, en un formato compacto.
 *
 * La API key se lee de live-config.php (no versionado).
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

const API_BASE = 'https://v3.football.api-sports.io';
const WC_LEAGUE = 1;
const WC_SEASON = 2026;

// ── Carpeta de caché (fuera del web root si se puede) ────────────────
$DIR = getenv('MW26_DATA') ?: (dirname(__DIR__, 2) . '/mw26_data');
if (!@is_dir($DIR)) { @mkdir($DIR, 0775, true); }
if (!is_dir($DIR) || !is_writable($DIR)) { $DIR = __DIR__ . '/live_cache'; @mkdir($DIR, 0775, true); }
$CACHE = $DIR . '/live_cache';
if (!@is_dir($CACHE)) { @mkdir($CACHE, 0775, true); }

function out($p) { echo json_encode($p, JSON_UNESCAPED_UNICODE); exit; }

// ── API key: primero un archivo FUERA del web root; si no, live-config.php ──
$KEY = '';
$keyFile = $DIR . '/apifootball.key';
if (is_file($keyFile)) { $KEY = trim((string)@file_get_contents($keyFile)); }
if ($KEY === '') { $k = @include __DIR__ . '/live-config.php'; if (is_string($k)) $KEY = trim($k); }
if ($KEY === '') { out(['ok' => false, 'error' => 'no_key', 'msg' => 'Falta la API key (apifootball.key)']); }

// ── Rate limit por IP (evita agotar la cuota de la API) ──────────────
function clientIp(): string {
  foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
    if (!empty($_SERVER[$h])) {
      $ip = trim(explode(',', $_SERVER[$h])[0]);
      if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
  }
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function rateAllow(string $dir, string $bucket, int $max, int $window): bool {
  $file = $dir . '/rl_' . preg_replace('/[^a-z0-9]/i', '', $bucket) . '.json';
  $fp = @fopen($file, 'c+'); if (!$fp) return true;
  $allowed = true;
  if (flock($fp, LOCK_EX)) {
    $data = json_decode(stream_get_contents($fp) ?: '[]', true); if (!is_array($data)) $data = [];
    $ip = clientIp(); $now = time();
    $list = array_values(array_filter($data[$ip] ?? [], fn($t) => is_int($t) && $t > $now - $window));
    $allowed = count($list) < $max;
    if ($allowed) $list[] = $now;
    $data[$ip] = $list;
    if (count($data) > 800) { foreach ($data as $k => $v) { $data[$k] = array_values(array_filter((array)$v, fn($t) => is_int($t) && $t > $now - 3600)); if (!$data[$k]) unset($data[$k]); } }
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($data)); fflush($fp); flock($fp, LOCK_UN);
  }
  fclose($fp); return $allowed;
}
if (!rateAllow($DIR, 'live', 90, 60)) { http_response_code(429); out(['ok' => false, 'error' => 'rate_limit']); }

function apiGet(string $path, string $key) {
  $ch = curl_init(API_BASE . $path);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTPHEADER => ["x-apisports-key: $key"],
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code !== 200 || $body === false) return null;
  $j = json_decode($body, true);
  return is_array($j) ? $j : null;
}

// Lee/escribe caché en archivo con TTL
function cacheGet(string $file, int $ttl) {
  if (!is_file($file)) return null;
  if ((time() - filemtime($file)) > $ttl) return null;
  $j = json_decode((string)@file_get_contents($file), true);
  return is_array($j) ? $j : null;
}
function cachePut(string $file, $data) { @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE)); }

// Normaliza un nombre de equipo para comparar
function norm(string $s): string {
  $s = mb_strtolower(trim($s), 'UTF-8');
  $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u','ç'=>'c','ã'=>'a','â'=>'a','ê'=>'e','ô'=>'o','õ'=>'o','ı'=>'i','ş'=>'s','ğ'=>'g']);
  return preg_replace('/[^a-z0-9]/', '', $s) ?: '';
}

// Lista de fixtures del Mundial (cacheada 6h; se refresca para captar fases KO)
function fixturesList(string $key, string $cache) {
  $file = $cache . '/wc_fixtures.json';
  $cached = cacheGet($file, 21600);
  if ($cached !== null) return $cached;
  $resp = apiGet('/fixtures?league=' . WC_LEAGUE . '&season=' . WC_SEASON, $key);
  $list = $resp['response'] ?? null;
  if (is_array($list)) { cachePut($file, $list); return $list; }
  // si falla, usa lo viejo aunque esté vencido
  $old = json_decode((string)@file_get_contents($file), true);
  return is_array($old) ? $old : [];
}

// Encuentra el fixture id por par de equipos (y como respaldo, por hora exacta)
function findFixtureId(array $fixtures, string $homeApi, string $awayApi, ?int $kickoffUtc): ?int {
  $want = [norm($homeApi), norm($awayApi)]; sort($want);
  $timeMatches = [];
  foreach ($fixtures as $f) {
    $teams = [norm($f['teams']['home']['name'] ?? ''), norm($f['teams']['away']['name'] ?? '')]; sort($teams);
    if ($teams === $want) return (int)$f['fixture']['id'];
    if ($kickoffUtc !== null) {
      $futc = strtotime($f['fixture']['date'] ?? '');
      if ($futc && abs($futc - $kickoffUtc) <= 60) $timeMatches[] = $f;
    }
  }
  if (count($timeMatches) === 1) return (int)$timeMatches[0]['fixture']['id'];
  return null;
}

// Normaliza el fixture completo a un formato compacto para el cliente
function normalizeFixture(array $r): array {
  $fx = $r['fixture'] ?? [];
  $teams = $r['teams'] ?? [];
  $goals = $r['goals'] ?? [];
  $homeId = $teams['home']['id'] ?? null;
  $side = fn($tid) => ($tid === $homeId ? 'home' : 'away');

  $lineups = null;
  if (!empty($r['lineups'])) {
    $lineups = [];
    foreach ($r['lineups'] as $lu) {
      $s = $side($lu['team']['id'] ?? null);
      $lineups[$s] = [
        'team' => $lu['team']['name'] ?? '',
        'formation' => $lu['formation'] ?? '',
        'coach' => $lu['coach']['name'] ?? '',
        'startXI' => array_map(function ($p) {
          $pl = $p['player'] ?? [];
          return ['name'=>$pl['name'] ?? '', 'number'=>$pl['number'] ?? null, 'pos'=>$pl['pos'] ?? '', 'grid'=>$pl['grid'] ?? null];
        }, $lu['startXI'] ?? []),
      ];
    }
  }

  $events = [];
  foreach ($r['events'] ?? [] as $e) {
    $events[] = [
      'minute' => $e['time']['elapsed'] ?? null,
      'extra'  => $e['time']['extra'] ?? null,
      'side'   => $side($e['team']['id'] ?? null),
      'type'   => $e['type'] ?? '',
      'detail' => $e['detail'] ?? '',
      'player' => $e['player']['name'] ?? '',
      'assist' => $e['assist']['name'] ?? null,
    ];
  }

  $stats = ['home' => [], 'away' => []];
  foreach ($r['statistics'] ?? [] as $st) {
    $s = $side($st['team']['id'] ?? null);
    foreach ($st['statistics'] ?? [] as $row) {
      $stats[$s][$row['type']] = $row['value'];
    }
  }

  return [
    'fixtureId' => $fx['id'] ?? null,
    'status' => [
      'short' => $fx['status']['short'] ?? '',
      'long'  => $fx['status']['long'] ?? '',
      'elapsed' => $fx['status']['elapsed'] ?? null,
    ],
    'teams' => [
      'home' => ['name' => $teams['home']['name'] ?? '', 'logo' => $teams['home']['logo'] ?? ''],
      'away' => ['name' => $teams['away']['name'] ?? '', 'logo' => $teams['away']['logo'] ?? ''],
    ],
    'score' => ['home' => $goals['home'] ?? null, 'away' => $goals['away'] ?? null],
    'lineups' => $lineups,
    'events' => $events,
    'stats' => $stats,
  ];
}

// TTL de caché según el estado del partido
function ttlForStatus(string $short): int {
  $live = ['1H','2H','HT','ET','BT','P','LIVE','INT'];
  $done = ['FT','AET','PEN','PST','CANC','ABD','AWD','WO'];
  if (in_array($short, $live, true)) return 25;     // en vivo: refresco rápido
  if (in_array($short, $done, true)) return 86400;  // terminado: no cambia
  return 600;                                        // por jugarse
}

// ── Router ───────────────────────────────────────────────────────────
$fixtures = fixturesList($KEY, $CACHE);
$wcIds = array_flip(array_map(fn($f) => (int)($f['fixture']['id'] ?? 0), $fixtures));

$fixtureId = isset($_GET['fixture']) ? (int)$_GET['fixture'] : 0;

if ($fixtureId > 0) {
  // Solo se permiten fixtures del Mundial (evita usar la cuota para cualquier partido)
  if (!isset($wcIds[$fixtureId])) out(['ok' => false, 'error' => 'fixture_no_permitido', 'found' => false]);
} else {
  // Emparejar por equipos (+ hora opcional). Límites de longitud anti-abuso.
  $homeApi = mb_substr((string)($_GET['home'] ?? ''), 0, 40);
  $awayApi = mb_substr((string)($_GET['away'] ?? ''), 0, 40);
  $kickoff = mb_substr((string)($_GET['kickoff'] ?? ''), 0, 20); // 'YYYY-MM-DDTHH:mm'
  if ($homeApi === '' || $awayApi === '') out(['ok' => false, 'error' => 'params']);
  $kickoffUtc = null;
  if ($kickoff !== '') { $t = strtotime($kickoff . ':00-06:00'); if ($t) $kickoffUtc = $t; }
  $fixtureId = findFixtureId($fixtures, $homeApi, $awayApi, $kickoffUtc) ?? 0;
  if ($fixtureId <= 0) out(['ok' => true, 'found' => false]);
}

// Datos del fixture (cacheados según estado)
$file = $CACHE . '/fx_' . $fixtureId . '.json';
// Para decidir TTL leemos primero un posible caché; si está fresco lo usamos
$cached = null;
if (is_file($file)) {
  $maybe = json_decode((string)@file_get_contents($file), true);
  if (is_array($maybe)) {
    $ttl = ttlForStatus($maybe['status']['short'] ?? '');
    if ((time() - filemtime($file)) <= $ttl) $cached = $maybe;
  }
}
if ($cached !== null) out(['ok' => true, 'found' => true, 'cached' => true, 'data' => $cached]);

$resp = apiGet('/fixtures?id=' . $fixtureId, $KEY);
$r = $resp['response'][0] ?? null;
if (!is_array($r)) out(['ok' => false, 'error' => 'api', 'found' => true]);
$data = normalizeFixture($r);
cachePut($file, $data);
out(['ok' => true, 'found' => true, 'cached' => false, 'data' => $data]);
