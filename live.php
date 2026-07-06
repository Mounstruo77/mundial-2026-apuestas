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
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
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
  $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u','ç'=>'c','ã'=>'a','â'=>'a','ê'=>'e','ô'=>'o','õ'=>'o','ı'=>'i','ş'=>'s','ğ'=>'g','ć'=>'c','č'=>'c','š'=>'s','ž'=>'z','đ'=>'d','ř'=>'r','ě'=>'e','ń'=>'n','ä'=>'a','ö'=>'o']);
  return preg_replace('/[^a-z0-9]/', '', $s) ?: '';
}

// Alias de nombres mal formados por API-Football para casos conocidos donde el
// nombre completo no comparte ningún token entre fuentes (ej. topscorers reporta
// "Erling Braut" sin apellido, y fixtures/players no trae ID para reconciliar).
// Clave = nombre normalizado tal como llega de la API; valor = nombre canónico.
const PLAYER_NAME_ALIASES = [
  'erlingbraut' => 'Erling Haaland',
  'ehaaland'    => 'Erling Haaland',
];
function canonName(string $name): string {
  $k = norm($name);
  return PLAYER_NAME_ALIASES[$k] ?? $name;
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

// Estadísticas reales por jugador y partido (goles/asistencias/atajadas/minutos).
// No depende de que alguien abra el visor en vivo: recorre TODOS los partidos
// terminados del Mundial y trae su box score. Se cachea para siempre por fixture
// (un partido FT no cambia), así que tras la primera pasada no vuelve a gastar cuota.
function collectFinishedFixturePlayers(array $fixtures, string $key, string $cache, int $maxNewCalls = 15): array {
  $out = [];
  $newCalls = 0;
  // Partidos terminados más RECIENTES primero: si hay un backlog grande de partidos
  // sin cachear, el cupo limitado de llamadas nuevas debe priorizar lo último jugado
  // (lo que a los usuarios les importa ver reflejado ya), no ir en orden cronológico.
  $finished = array_filter($fixtures, fn($f) => in_array($f['fixture']['status']['short'] ?? '', ['FT', 'AET', 'PEN'], true));
  usort($finished, fn($a, $b) => strtotime($b['fixture']['date'] ?? '') <=> strtotime($a['fixture']['date'] ?? ''));
  foreach ($finished as $f) {
    $fid = (int)($f['fixture']['id'] ?? 0);
    if ($fid <= 0) continue;
    $pf = $cache . '/fxplayers_' . $fid . '.json';
    $players = null;
    if (is_file($pf)) {
      $players = json_decode((string)@file_get_contents($pf), true);
    } elseif ($newCalls < $maxNewCalls) {
      $pr = apiGet('/fixtures/players?fixture=' . $fid, $key);
      $players = $pr['response'] ?? [];
      if ($players) cachePut($pf, $players);
      $newCalls++;
    }
    foreach ($players ?: [] as $teamBlock) {
      $teamName = $teamBlock['team']['name'] ?? '';
      foreach ($teamBlock['players'] ?? [] as $pw) {
        $pl = $pw['player'] ?? [];
        $s  = $pw['statistics'][0] ?? [];
        $name = canonName(trim($pl['name'] ?? ''));
        if (!$name) continue;
        // Clave por ID numérico de jugador (estable entre partidos) con fallback a
        // nombre+equipo. El "name" que devuelve este endpoint varía de formato entre
        // partidos para el mismo jugador (ej. "E. Haaland" vs "Erling Braut Haaland"),
        // así que agrupar solo por nombre fragmenta sus stats en entradas duplicadas.
        $pid = (int)($pl['id'] ?? 0);
        $k = $pid > 0 ? ('id:' . $pid) : (norm($name) . '|' . norm($teamName));
        if (!isset($out[$k])) {
          $out[$k] = ['id'=>$pid,'name'=>$name,'team'=>$teamName,'photo'=>$pl['photo'] ?? '',
                      'goals'=>0,'assists'=>0,'saves'=>0,'conceded'=>0,'games'=>[]];
        }
        // Prefiere el nombre completo más largo/informativo si aparece en otro partido
        if (strlen($name) > strlen($out[$k]['name'])) $out[$k]['name'] = $name;
        $out[$k]['goals']    += (int)($s['goals']['total'] ?? 0);
        $out[$k]['assists']  += (int)($s['goals']['assists'] ?? 0);
        $out[$k]['saves']    += (int)($s['goals']['saves'] ?? 0);
        $out[$k]['conceded'] += (int)($s['goals']['conceded'] ?? 0);
        $minutes = (int)($s['games']['minutes'] ?? 0);
        if ($minutes > 0) $out[$k]['games'][$fid] = true;
        if (empty($out[$k]['photo']) && !empty($pl['photo'])) $out[$k]['photo'] = $pl['photo'];
      }
    }
  }
  return $out;
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

  $playerNumMap = [];
  $lineups = null;
  if (!empty($r['lineups'])) {
    $lineups = [];
    foreach ($r['lineups'] as $lu) {
      $s = $side($lu['team']['id'] ?? null);
      foreach (array_merge($lu['startXI'] ?? [], $lu['substitutes'] ?? []) as $p) {
        $pid = $p['player']['id'] ?? null;
        $num = $p['player']['number'] ?? null;
        if ($pid !== null) $playerNumMap[$pid] = $num;
      }
      $lineups[$s] = [
        'team' => $lu['team']['name'] ?? '',
        'formation' => $lu['formation'] ?? '',
        'coach' => $lu['coach']['name'] ?? '',
        'colors' => $lu['team']['colors']['player'] ?? null,
        'startXI' => array_map(function ($p) {
          $pl = $p['player'] ?? [];
          return ['name'=>$pl['name'] ?? '', 'number'=>$pl['number'] ?? null, 'pos'=>$pl['pos'] ?? '', 'grid'=>$pl['grid'] ?? null];
        }, $lu['startXI'] ?? []),
      ];
    }
  }

  $events = [];
  foreach ($r['events'] ?? [] as $e) {
    $pid = $e['player']['id'] ?? null;
    $aid = $e['assist']['id'] ?? null;
    $events[] = [
      'minute' => $e['time']['elapsed'] ?? null,
      'extra'  => $e['time']['extra'] ?? null,
      'side'   => $side($e['team']['id'] ?? null),
      'type'   => $e['type'] ?? '',
      'detail' => $e['detail'] ?? '',
      'player'        => $e['player']['name'] ?? '',
      'player_number' => $pid !== null ? ($playerNumMap[$pid] ?? null) : null,
      'assist'        => $e['assist']['name'] ?? null,
      'assist_number' => $aid !== null ? ($playerNumMap[$aid] ?? null) : null,
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

// ── Lista compacta de resultados (para sincronizar marcadores) ───────
// Cache corto (120 s) porque los marcadores cambian durante los partidos.
if (isset($_GET['list'])) {
  $file = $CACHE . '/wc_results.json';
  $cached = cacheGet($file, 120);
  if ($cached !== null) out(['ok' => true, 'cached' => true, 'matches' => $cached]);
  $resp = apiGet('/fixtures?league=' . WC_LEAGUE . '&season=' . WC_SEASON, $KEY);
  $list = [];
  foreach (($resp['response'] ?? []) as $f) {
    $list[] = [
      'home'   => $f['teams']['home']['name'] ?? '',
      'away'   => $f['teams']['away']['name'] ?? '',
      'status' => $f['fixture']['status']['short'] ?? '',
      'gh'     => $f['goals']['home'],
      'ga'     => $f['goals']['away'],
      'ph'     => $f['score']['penalty']['home'] ?? null,
      'pa'     => $f['score']['penalty']['away'] ?? null,
    ];
  }
  if ($list) {
    cachePut($file, $list);
    // Si hay partidos terminados, invalidar caché de stats para que se actualicen
    // con los goles reales. Solo si el caché tiene >5 min (evita llamadas excesivas a API).
    $hasFT = (bool)array_filter($list, fn($m) => in_array($m['status'] ?? '', ['FT','AET','PEN']));
    if ($hasFT) {
      $scFile = $CACHE . '/wc_scorers.json';
      $gkFile = $CACHE . '/wc_keepers.json';
      if (is_file($scFile) && (time() - filemtime($scFile)) > 300) @unlink($scFile);
      if (is_file($gkFile) && (time() - filemtime($gkFile)) > 300) @unlink($gkFile);
      // wc_fixtures.json (usado por collectFinishedFixturePlayers para detectar qué
      // partidos ya son FT) tiene TTL de 6h — sin esto, un partido recién terminado
      // podía tardar hasta 6h en aparecer en el box score de goleadores/arqueros.
      $fxListFile = $CACHE . '/wc_fixtures.json';
      if (is_file($fxListFile) && (time() - filemtime($fxListFile)) > 300) @unlink($fxListFile);
    }
    out(['ok' => true, 'cached' => false, 'matches' => $list]);
  }
  $old = json_decode((string)@file_get_contents($file), true);
  out(['ok' => (bool)$old, 'cached' => true, 'matches' => is_array($old) ? $old : []]);
}

// ── Top goleadores del Mundial (cache 10 min) ────────────────────────
if (isset($_GET['scorers'])) {
  $cacheFile = $CACHE . '/wc_scorers.json';
  $cached = cacheGet($cacheFile, 600);
  if (is_array($cached) && $cached && !array_filter($cached, function($p) {
    $n = $p['name'] ?? '';
    return (bool)preg_match('/^[A-Z][a-z]?\.\s/', $n) || str_word_count($n) > 2;
  })) {
    out(['ok' => true, 'cached' => true, 'scorers' => $cached]);
  }

  // Fuente 1: API-Football topscorers (fotos + datos de PJ/asistencias)
  $resp = apiGet('/players/topscorers?league=' . WC_LEAGUE . '&season=' . WC_SEASON, $KEY);
  $list = [];
  foreach (($resp['response'] ?? []) as $item) {
    $p = $item['player'] ?? [];
    $s = $item['statistics'][0] ?? [];
    $apiName = trim($p['name'] ?? '');
    $first   = trim($p['firstname'] ?? '');
    $last    = trim($p['lastname'] ?? '');
    if ($apiName && !preg_match('/^[A-Z][a-z]?\.\s/', $apiName)) {
      $displayName = $apiName;
    } elseif ($first && $last) {
      $displayName = explode(' ', $first)[0] . ' ' . explode(' ', $last)[0];
    } else {
      $displayName = $apiName;
    }
    $displayName = canonName($displayName);
    $list[] = [
      'id'      => (int)($p['id'] ?? 0),
      'name'    => $displayName,
      'photo'   => $p['photo'] ?? '',
      'team'    => $s['team']['name'] ?? '',
      'goals'   => (int)($s['goals']['total'] ?? 0),
      'assists' => (int)($s['goals']['assists'] ?? 0),
      'games'   => (int)($s['games']['appearences'] ?? 0),
    ];
  }

  // Fuente 2: eventos de fx_*.json cacheados (más actualizados que la API de topscorers)
  $fxGoals = []; $fxAssists = []; $fxGames = [];
  foreach (glob($CACHE . '/fx_*.json') ?: [] as $fxFile) {
    $fx = json_decode((string)@file_get_contents($fxFile), true);
    if (!is_array($fx) || !in_array($fx['status']['short'] ?? '', ['FT','AET','PEN'])) continue;
    // Compatibilidad: teams puede ser string (nuevo) o array con 'name' (viejo)
    $teamH = is_string($fx['teams']['home'] ?? '') ? ($fx['teams']['home'] ?? '') : ($fx['teams']['home']['name'] ?? '');
    $teamA = is_string($fx['teams']['away'] ?? '') ? ($fx['teams']['away'] ?? '') : ($fx['teams']['away']['name'] ?? '');
    $fxTeams = ['home' => $teamH, 'away' => $teamA];
    $fid = (string)($fx['fixtureId'] ?? basename($fxFile, '.json'));
    foreach ($fx['events'] ?? [] as $ev) {
      if (($ev['type'] ?? '') !== 'Goal') continue;
      if (in_array($ev['detail'] ?? '', ['Own Goal', 'Missed Penalty'])) continue;
      $pl   = canonName(trim($ev['player'] ?? ''));
      $team = $fxTeams[$ev['side'] ?? ''] ?? '';
      if (!$pl || !$team) continue;
      $key = $pl . '|' . $team;
      $fxGoals[$key]  = ($fxGoals[$key] ?? 0) + 1;
      $fxGames[$key][$fid] = true;
      $ast = trim($ev['assist'] ?? '');
      if ($ast) {
        $akey = $ast . '|' . $team;
        $fxAssists[$akey] = ($fxAssists[$akey] ?? 0) + 1;
        $fxGames[$akey][$fid] = true;
      }
    }
  }

  // Índices para el merge (por ID, por nombre completo, y por apellido+equipo)
  $apiById   = []; // id => idx
  $apiByName = []; // norm(full_name) => idx
  $apiByLast = []; // norm(last_word)|norm(team) => idx
  foreach ($list as $idx => $p) {
    if (!empty($p['id'])) $apiById[(int)$p['id']] = $idx;
    $apiByName[norm($p['name'])] = $idx;
    $pLast = mb_strtolower(strpos($p['name'],' ')!==false ? trim((string)strrchr($p['name'],' ')) : $p['name']);
    $apiByLast[norm($pLast).'|'.norm($p['team'])] = $idx;
  }

  // Merge: actualizar o añadir desde datos de fx
  foreach ($fxGoals as $key => $goals) {
    [$pl, $team] = explode('|', $key, 2);
    $plNorm = norm($pl);
    $plLast = mb_strtolower(strpos($pl,' ')!==false ? trim((string)strrchr($pl,' ')) : $pl);
    $lastKey = norm($plLast).'|'.norm($team);
    // Buscar en API por nombre completo o por apellido+equipo (para nombres abreviados tipo "L. Messi")
    $idx = $apiByName[$plNorm] ?? $apiByLast[$lastKey] ?? null;
    if ($idx !== null) {
      if ($goals > $list[$idx]['goals']) {
        $list[$idx]['goals']   = $goals;
        $list[$idx]['assists'] = max($list[$idx]['assists'], $fxAssists[$key] ?? 0);
        $list[$idx]['games']   = max($list[$idx]['games'], count($fxGames[$key] ?? []));
      }
    } else {
      $newIdx = count($list);
      $list[] = ['name'=>$pl,'photo'=>'','team'=>$team,'goals'=>$goals,
                 'assists'=>($fxAssists[$key] ?? 0),'games'=>count($fxGames[$key] ?? [])];
      $apiByName[$plNorm] = $newIdx;
      $apiByLast[$lastKey] = $newIdx;
    }
  }

  // Fuente 3: box score real (/fixtures/players) de todos los partidos FT del Mundial.
  // No depende de que alguien haya abierto el visor en vivo — cierra el hueco que
  // dejaba la Fuente 2 y evita que el marcador se vea "estancado".
  $fpFixtures = fixturesList($KEY, $CACHE);
  $fpStats = collectFinishedFixturePlayers($fpFixtures, $KEY, $CACHE);
  foreach ($fpStats as $key => $st) {
    if ($st['goals'] <= 0 && $st['assists'] <= 0) continue;
    $plNorm = norm($st['name']);
    $plLast = mb_strtolower(strpos($st['name'],' ')!==false ? trim((string)strrchr($st['name'],' ')) : $st['name']);
    $lastKey = norm($plLast).'|'.norm($st['team']);
    // ID primero (estable entre partidos); nombre solo como respaldo
    $idx = (!empty($st['id']) ? ($apiById[$st['id']] ?? null) : null) ?? $apiByName[$plNorm] ?? $apiByLast[$lastKey] ?? null;
    if ($idx !== null) {
      if ($st['goals'] > $list[$idx]['goals']) {
        $list[$idx]['goals']   = $st['goals'];
        $list[$idx]['assists'] = max($list[$idx]['assists'], $st['assists']);
        $list[$idx]['games']   = max($list[$idx]['games'], count($st['games']));
        if (empty($list[$idx]['photo']) && !empty($st['photo'])) $list[$idx]['photo'] = $st['photo'];
      }
    } else {
      $newIdx = count($list);
      $list[] = ['id'=>$st['id'] ?? 0,'name'=>$st['name'],'photo'=>$st['photo'],'team'=>$st['team'],'goals'=>$st['goals'],
                 'assists'=>$st['assists'],'games'=>count($st['games'])];
      if (!empty($st['id'])) $apiById[$st['id']] = $newIdx;
      $apiByName[$plNorm] = $newIdx;
      $apiByLast[$lastKey] = $newIdx;
    }
  }

  usort($list, fn($a, $b) => $b['goals'] !== $a['goals'] ? $b['goals'] - $a['goals'] : $b['assists'] - $a['assists']);
  $list = array_slice($list, 0, 10);

  // Enriquecer fotos de jugadores que no las tienen (desde squads, igual que arqueros)
  $needsPhoto = array_filter($list, fn($p) => empty($p['photo']));
  if ($needsPhoto) {
    $allFx = fixturesList($KEY, $CACHE);
    $teamIds = [];
    foreach ($allFx as $f) {
      foreach (['home','away'] as $s) {
        $n = $f['teams'][$s]['name'] ?? '';
        $i = (int)($f['teams'][$s]['id'] ?? 0);
        if ($n && $i) $teamIds[$n] = $i;
      }
    }
    $newCalls = 0;
    foreach ($list as &$p) {
      if (!empty($p['photo'])) continue;
      $tid = $teamIds[$p['team']] ?? 0;
      if (!$tid) continue;
      $sf = $CACHE . '/squad_' . $tid . '.json';
      $squad = cacheGet($sf, 86400);
      if ($squad === null && $newCalls < 5) {
        $sr = apiGet('/players/squads?team=' . $tid, $KEY);
        $squad = $sr['response'][0]['players'] ?? [];
        if ($squad) cachePut($sf, $squad);
        $newCalls++;
      }
      $pHasSpace = strpos($p['name'], ' ') !== false;
      $pLast  = mb_strtolower($pHasSpace ? trim((string)strrchr($p['name'], ' ')) : $p['name']);
      $pFirst = mb_strtolower($pHasSpace ? trim((string)strstr($p['name'], ' ', true)) : $p['name']);
      foreach ($squad ?: [] as $sq) {
        $sqn = $sq['name'] ?? '';
        $pho = $sq['photo'] ?? '';
        if (!$pho) continue;
        $sqHas = strpos($sqn, ' ') !== false;
        $sqLast  = mb_strtolower($sqHas ? trim((string)strrchr($sqn, ' ')) : $sqn);
        $sqFirst = mb_strtolower($sqHas ? trim((string)strstr($sqn, ' ', true)) : $sqn);
        if (norm($sqn) === norm($p['name'])
         || norm($sqLast) === norm($pLast)
         || ($pFirst && strlen($pFirst) > 2 && $sqFirst === $pFirst)) {
          $p['photo'] = $pho; break;
        }
      }
    }
    unset($p);
  }

  if ($list) { cachePut($cacheFile, $list); out(['ok' => true, 'cached' => false, 'scorers' => $list]); }
  $old = json_decode((string)@file_get_contents($cacheFile), true);
  out(['ok' => (bool)$old, 'cached' => true, 'scorers' => is_array($old) ? $old : []]);
}

// ── Top arqueros del Mundial por atajadas (cache 10 min) ─────────────
if (isset($_GET['keepers'])) {
  $cacheFile = $CACHE . '/wc_keepers.json';
  $cached = cacheGet($cacheFile, 600);
  // Servir caché solo si todos los items ya tienen foto
  if (is_array($cached) && $cached && !array_filter($cached, fn($k) => empty($k['photo']))) {
    out(['ok' => true, 'cached' => true, 'keepers' => $cached]);
  }

  $list = [];

  // Fuente 1: API-Football players endpoint (varios valores de position)
  foreach (['Goalkeeper', 'G', 'GK'] as $pos) {
    $resp = apiGet('/players?league=' . WC_LEAGUE . '&season=' . WC_SEASON . '&position=' . $pos . '&page=1', $KEY);
    if (!is_array($resp['response'] ?? null) || !count($resp['response'])) continue;
    foreach ($resp['response'] as $item) {
      $p = $item['player'] ?? [];
      $s = $item['statistics'][0] ?? [];
      $list[] = [
        'id'      => (int)($p['id'] ?? 0),
        'name'    => canonName($p['name'] ?? ''),
        'photo'   => $p['photo'] ?? '',
        'team'    => $s['team']['name'] ?? '',
        'saves'   => (int)($s['goals']['saves'] ?? 0),
        'conceded'=> (int)($s['goals']['conceded'] ?? 0),
        'games'   => (int)($s['games']['appearences'] ?? 0),
      ];
    }
    if ($list) break;
  }

  // Fuente 2 (fallback total): construir desde archivos fx_*.json ya cacheados,
  // solo si la Fuente 1 no devolvió absolutamente nada.
  if (!$list) {
    $gkMap = [];
    foreach (glob($CACHE . '/fx_*.json') ?: [] as $fxFile) {
      $fx = json_decode((string)@file_get_contents($fxFile), true);
      if (!is_array($fx) || !in_array($fx['status']['short'] ?? '', ['FT','AET','PEN'], true)) continue;
      foreach (['home','away'] as $side) {
        $opp = $side === 'home' ? 'away' : 'home';
        $lu  = $fx['lineups'][$side] ?? [];
        $gk  = null;
        foreach ($lu['startXI'] ?? [] as $pl) {
          if (($pl['pos'] ?? '') === 'G' || ($pl['grid'] ?? '') === '1:1') { $gk = $pl; break; }
        }
        if (!($gk['name'] ?? '')) continue;
        $team = $lu['team'] ?? '';
        $key  = $gk['name'] . '|' . $team;
        if (!isset($gkMap[$key])) $gkMap[$key] = ['name'=>$gk['name'],'photo'=>'','team'=>$team,'saves'=>0,'conceded'=>0,'games'=>0];
        $gkMap[$key]['saves']    += (int)($fx['stats'][$side]['Goalkeeper Saves'] ?? 0);
        $gkMap[$key]['conceded'] += (int)($fx['score'][$opp] ?? 0);
        $gkMap[$key]['games']    += 1;
      }
    }
    $list = array_values($gkMap);
  }

  // Índices para el merge (por ID, por nombre completo, y por apellido+equipo)
  $gkById   = []; // id => idx
  $gkByName = []; // norm(full_name) => idx
  $gkByLast = []; // norm(last_word)|norm(team) => idx
  foreach ($list as $idx => $p) {
    if (!empty($p['id'])) $gkById[(int)$p['id']] = $idx;
    $gkByName[norm($p['name'])] = $idx;
    $gkLast = mb_strtolower(strpos($p['name'],' ')!==false ? trim((string)strrchr($p['name'],' ')) : $p['name']);
    $gkByLast[norm($gkLast).'|'.norm($p['team'])] = $idx;
  }

  // Fuente 3: box score real (/fixtures/players) de todos los partidos FT del Mundial.
  // Se ejecuta SIEMPRE (no solo cuando la Fuente 1 falla), porque la Fuente 1 es un
  // agregado de temporada que suele quedarse atrás — esto era lo que hacía ver el
  // marcador de arqueros "estancado" aunque ya se hubieran jugado varios partidos.
  $gkFpFixtures = fixturesList($KEY, $CACHE);
  $gkFpStats = collectFinishedFixturePlayers($gkFpFixtures, $KEY, $CACHE);
  foreach ($gkFpStats as $key => $st) {
    if ($st['saves'] <= 0) continue; // solo interesa a arqueros con atajadas reales
    $plNorm = norm($st['name']);
    $plLast = mb_strtolower(strpos($st['name'],' ')!==false ? trim((string)strrchr($st['name'],' ')) : $st['name']);
    $lastKey = norm($plLast).'|'.norm($st['team']);
    $idx = (!empty($st['id']) ? ($gkById[$st['id']] ?? null) : null) ?? $gkByName[$plNorm] ?? $gkByLast[$lastKey] ?? null;
    if ($idx !== null) {
      if ($st['saves'] > $list[$idx]['saves']) {
        $list[$idx]['saves']    = $st['saves'];
        $list[$idx]['conceded'] = max($list[$idx]['conceded'], $st['conceded']);
        $list[$idx]['games']    = max($list[$idx]['games'], count($st['games']));
        if (empty($list[$idx]['photo']) && !empty($st['photo'])) $list[$idx]['photo'] = $st['photo'];
      }
    } else {
      $newIdx = count($list);
      $list[] = ['id'=>$st['id'] ?? 0,'name'=>$st['name'],'photo'=>$st['photo'],'team'=>$st['team'],'saves'=>$st['saves'],
                 'conceded'=>$st['conceded'],'games'=>count($st['games'])];
      if (!empty($st['id'])) $gkById[$st['id']] = $newIdx;
      $gkByName[$plNorm] = $newIdx;
      $gkByLast[$lastKey] = $newIdx;
    }
  }

  usort($list, fn($a, $b) => $b['saves'] !== $a['saves'] ? $b['saves'] - $a['saves'] : $a['conceded'] - $b['conceded']);
  $list = array_slice($list, 0, 10);

  // Enriquecer fotos: buscar en squads por equipo (cache 24h, máx 5 llamadas nuevas)
  $needsPhoto = array_filter($list, fn($k) => empty($k['photo']));
  if ($needsPhoto) {
    $allFx   = fixturesList($KEY, $CACHE); // usa caché 6h, sin coste extra
    $teamIds = [];
    foreach ($allFx as $f) {
      foreach (['home', 'away'] as $s) {
        $n = $f['teams'][$s]['name'] ?? '';
        $i = (int)($f['teams'][$s]['id'] ?? 0);
        if ($n && $i) $teamIds[$n] = $i;
      }
    }
    $newCalls = 0;
    foreach ($list as &$k) {
      if (!empty($k['photo'])) continue;
      $tid = $teamIds[$k['team']] ?? 0;
      if (!$tid) continue;
      $sf = $CACHE . '/squad_' . $tid . '.json';
      $squad = cacheGet($sf, 86400);
      if ($squad === null && $newCalls < 12) {
        $sr = apiGet('/players/squads?team=' . $tid, $KEY);
        $squad = $sr['response'][0]['players'] ?? [];
        if ($squad) { cachePut($sf, $squad); }
        $newCalls++;
      }
      $gkName  = $k['name'];
      $gkHasSpace = strpos($gkName, ' ') !== false;
      $gkLast  = mb_strtolower($gkHasSpace ? trim((string)strrchr($gkName, ' ')) : $gkName);
      $gkFirst = mb_strtolower($gkHasSpace ? trim((string)strstr($gkName, ' ', true)) : $gkName);
      foreach ($squad ?: [] as $pl) {
        if (($pl['position'] ?? '') !== 'Goalkeeper') continue;
        $pn  = $pl['name'] ?? '';
        $pho = $pl['photo'] ?? '';
        if (!$pho) continue;
        $pHasSpace = strpos($pn, ' ') !== false;
        $pLast  = mb_strtolower($pHasSpace ? trim((string)strrchr($pn, ' ')) : $pn);
        $pFirst = mb_strtolower($pHasSpace ? trim((string)strstr($pn, ' ', true)) : $pn);
        // Coincidencia: nombre completo norm | apellido norm | mismo nombre de pila (>2 chars, no abreviado)
        if (norm($pn) === norm($gkName)
         || norm($pLast) === norm($gkLast)
         || ($gkFirst && strlen($gkFirst) > 2 && $pFirst === $gkFirst)) {
          $k['photo'] = $pho;
          break;
        }
      }
    }
    unset($k);
  }

  if ($list) { cachePut($cacheFile, $list); out(['ok' => true, 'cached' => false, 'keepers' => $list]); }
  $old = json_decode((string)@file_get_contents($cacheFile), true);
  out(['ok' => (bool)$old, 'cached' => true, 'keepers' => is_array($old) ? $old : []]);
}

// ── Calendario KO (actualización automática) ─────────────────────────
// Limpiar caché ko_schedule (admin)
if (isset($_GET['clear_ko_cache'])) {
  @unlink($CACHE . '/wc_ko_schedule.json');
  @unlink($CACHE . '/wc_fixtures.json');
  out(['ok' => true, 'cleared' => true]);
}

if (isset($_GET['ko_schedule'])) {
  $cFile = $CACHE . '/wc_ko_schedule.json';
  // Servir caché si tiene menos de 30 minutos
  if (is_file($cFile) && (time() - filemtime($cFile)) < 1800) {
    out(['ok' => true, 'cached' => true, 'fixtures' => json_decode(file_get_contents($cFile), true)]);
  }
  // Siempre refrescar el fixture list cuando el ko_schedule expira
  $fxFile = $CACHE . '/wc_fixtures.json';
  @unlink($fxFile);

  // Mapa inglés → español para nombres de equipos
  $teamES = [
    'Mexico'=>'México','South Africa'=>'Sudáfrica','South Korea'=>'Corea del Sur',
    'Korea Republic'=>'Corea del Sur','Czechia'=>'Rep. Checa','Czech Republic'=>'Rep. Checa',
    'Canada'=>'Canadá','Bosnia & Herzegovina'=>'Bosnia','Bosnia and Herzegovina'=>'Bosnia',
    'Qatar'=>'Catar','Switzerland'=>'Suiza','Brazil'=>'Brasil','Morocco'=>'Marruecos',
    'Haiti'=>'Haití','Scotland'=>'Escocia','USA'=>'EE.UU.','United States'=>'EE.UU.',
    'Turkey'=>'Turquía','Türkiye'=>'Turquía','Paraguay'=>'Paraguay','Australia'=>'Australia',
    'Germany'=>'Alemania','Ivory Coast'=>'Costa de Marfil',"Côte d'Ivoire"=>'Costa de Marfil',
    'Ecuador'=>'Ecuador','Curaçao'=>'Curaçao','Netherlands'=>'Países Bajos','Japan'=>'Japón',
    'Sweden'=>'Suecia','Tunisia'=>'Túnez','Spain'=>'España','Saudi Arabia'=>'Arabia Saudita',
    'Uruguay'=>'Uruguay','Cape Verde Islands'=>'Cabo Verde','Cape Verde'=>'Cabo Verde',
    'Belgium'=>'Bélgica','Egypt'=>'Egipto','New Zealand'=>'Nueva Zelanda','Iran'=>'Irán',
    'France'=>'Francia','Senegal'=>'Senegal','Iraq'=>'Irak','Norway'=>'Noruega',
    'Argentina'=>'Argentina','Algeria'=>'Argelia','Jordan'=>'Jordania','Austria'=>'Austria',
    'Portugal'=>'Portugal','Congo DR'=>'RD Congo','DR Congo'=>'RD Congo',
    'Uzbekistan'=>'Uzbekistán','Colombia'=>'Colombia','England'=>'Inglaterra',
    'Croatia'=>'Croacia','Ghana'=>'Ghana','Panama'=>'Panamá',
  ];

  $all = fixturesList($KEY, $CACHE);
  $out = [];
  foreach ($all as $f) {
    $r = $f['league']['round'] ?? '';
    if (preg_match('/^Group\s+Stage/i', $r)) continue; // omitir fase de grupos
    $homeEN = $f['teams']['home']['name'] ?? '';
    $awayEN = $f['teams']['away']['name'] ?? '';
    if (!$homeEN || !$awayEN) continue; // omitir sin equipos
    $out[] = [
      'round' => $r,
      'date'  => $f['fixture']['date'] ?? '',
      'home'  => $teamES[$homeEN] ?? $homeEN,
      'away'  => $teamES[$awayEN] ?? $awayEN,
      'venue' => $f['fixture']['venue']['name'] ?? '',
    ];
  }
  usort($out, fn($a,$b) => strcmp($a['date'], $b['date']));
  file_put_contents($cFile, json_encode(array_values($out)));
  out(['ok' => true, 'cached' => false, 'fixtures' => array_values($out)]);
}

// ── Invalidar caché de estadísticas (llamado cuando termina un partido) ──
if (isset($_GET['refresh_stats'])) {
  @unlink($CACHE . '/wc_scorers.json');
  @unlink($CACHE . '/wc_keepers.json');
  out(['ok' => true]);
}

// ── Tablas de posiciones oficiales por grupo (cache 10 min) ──────────
if (isset($_GET['standings'])) {
  $file = $CACHE . '/wc_standings_v2.json';
  $cached = cacheGet($file, 600);
  if ($cached !== null) out(['ok' => true, 'cached' => true, 'groups' => $cached]);
  $resp = apiGet('/standings?league=' . WC_LEAGUE . '&season=' . WC_SEASON, $KEY);
  $groups = [];
  foreach (($resp['response'][0]['league']['standings'] ?? []) as $grp) {
    foreach ($grp as $row) {
      // La API nombra los grupos "Group A" o "Group Stage - Group A" según la fase:
      // normalizamos a "Group X" y descartamos rankings de terceros.
      $raw = (string)($row['group'] ?? '');
      if (!preg_match('/Group ([A-L])$/', $raw, $mm)) continue;
      $g = 'Group ' . $mm[1];
      $groups[$g][] = [
        'team'   => $row['team']['name'] ?? '',
        'rank'   => (int)($row['rank'] ?? 0),
        'played' => (int)($row['all']['played'] ?? 0),
        'win'    => (int)($row['all']['win'] ?? 0),
        'draw'   => (int)($row['all']['draw'] ?? 0),
        'lose'   => (int)($row['all']['lose'] ?? 0),
        'gd'     => (int)($row['goalsDiff'] ?? 0),
        'pts'    => (int)($row['points'] ?? 0),
      ];
    }
  }
  if ($groups) { cachePut($file, $groups); out(['ok' => true, 'cached' => false, 'groups' => $groups]); }
  $old = json_decode((string)@file_get_contents($file), true);
  out(['ok' => (bool)$old, 'cached' => true, 'groups' => is_array($old) ? $old : new stdClass()]);
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
