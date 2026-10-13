<?php
/**
 * JSON API for the map page. All BODS requests are made from here so the API
 * key stays on the server.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/timetable.php';
require dirname(__DIR__) . '/lib/live.php';
require dirname(__DIR__) . '/lib/planner.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

final class ApiError extends Exception
{
}

function out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function param(string $name): string
{
    $v = $_GET[$name] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** "lat,lon" within Great Britain. */
function point(string $name): array
{
    $p = explode(',', param($name));
    if (count($p) !== 2 || !is_numeric($p[0]) || !is_numeric($p[1])) {
        throw new ApiError("Invalid $name");
    }
    [$lat, $lon] = [(float) $p[0], (float) $p[1]];
    if ($lat < 49 || $lat > 61 || $lon < -9 || $lon > 3) {
        throw new ApiError("$name is outside the UK");
    }
    return [$lat, $lon];
}

/** "minLon,minLat,maxLon,maxLat" within Great Britain, no larger than $maxArea square degrees. */
function bbox(float $maxArea): array
{
    $b = explode(',', param('bbox'));
    if (count($b) !== 4 || array_filter($b, fn($v) => !is_numeric($v))) {
        throw new ApiError('Invalid bbox');
    }
    $b = array_map('floatval', $b);
    $b = [max(-9.0, $b[0]), max(49.0, $b[1]), min(3.0, $b[2]), min(61.0, $b[3])];
    if ($b[0] >= $b[2] || $b[1] >= $b[3]) {
        throw new ApiError('Area is outside the UK');
    }
    if (($b[2] - $b[0]) * ($b[3] - $b[1]) > $maxArea) {
        throw new ApiError('Area too large - zoom in');
    }
    return $b;
}

function valid_noc(string $noc): bool
{
    return (bool) preg_match('/^[A-Z0-9]{1,8}$/', $noc);
}

function valid_line(string $line): bool
{
    return (bool) preg_match('#^[A-Za-z0-9 ._/-]{1,16}$#', $line);
}

function bbox_param(array $b): string
{
    return implode(',', array_map(fn($v) => sprintf('%.5f', $v), $b));
}

const ACCESS_COOKIE = 'mbm_access';

/** The cookie value for a name: the name plus a signature only this server can make. */
function access_token(string $name, string $secret): string
{
    return $name . '.' . hash_hmac('sha256', $name, $secret);
}

/** The signed-in name, or null. Removing a name from access_names revokes its cookie. */
function access_name(array $config): ?string
{
    $cookie = $_COOKIE[ACCESS_COOKIE] ?? '';
    if (!is_string($cookie) || !str_contains($cookie, '.')) {
        return null;
    }
    $name = substr($cookie, 0, strrpos($cookie, '.'));
    if (!in_array($name, access_names($config), true)) {
        return null;
    }
    return hash_equals(access_token($name, $config['access_secret']), $cookie) ? $name : null;
}

function access_names(array $config): array
{
    return array_map(fn($n) => mb_strtolower(trim((string) $n)), (array) $config['access_names']);
}

try {
    $config = mbm_config();
    $gated = access_names($config) !== [];
    if ($gated && strlen((string) $config['access_secret']) < 32) {
        throw new RuntimeException('access_secret in config.php must be at least 32 characters');
    }

    if (param('action') === 'login') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new ApiError('Use POST', 405);
        }
        $name = mb_strtolower(trim((string) ($_POST['name'] ?? '')));
        if (!$gated) {
            out(['ok' => true]);
        }
        if ($name === '' || !in_array($name, access_names($config), true)) {
            sleep(1);   // slows down guessing
            throw new ApiError("Sorry, that name isn't on the list", 403);
        }
        setcookie(ACCESS_COOKIE, access_token($name, $config['access_secret']), [
            'expires'  => time() + 365 * 86400,
            'path'     => '/',
            'secure'   => ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        out(['ok' => true]);
    }
    if ($gated && access_name($config) === null) {
        out(['error' => 'Please enter your name', 'login' => true], 401);
    }

    switch (param('action')) {
        case 'config':
            $regions = [];
            try {
                foreach (mbm_db()->query('SELECT code, name, imported_at, min_lat, min_lon, max_lat, max_lon FROM regions') as $r) {
                    $regions[] = [
                        'code'     => $r['code'],
                        'name'     => $r['name'],
                        'imported' => $r['imported_at'],
                        'bounds'   => [[(float) $r['min_lat'], (float) $r['min_lon']], [(float) $r['max_lat'], (float) $r['max_lon']]],
                    ];
                }
            } catch (RuntimeException) {
                // No timetables yet: live tracking of the default services still works.
            }
            $d = $config['defaults'];
            out([
                'center'    => $d['center'] ?? [52.4975, -2.1686],
                'zoom'      => $d['zoom'] ?? 13,
                'services'  => $d['services'] ?? [],
                'regions'   => $regions,
                'timetable' => (bool) $regions,
                'live'      => $config['bods_api_key'] !== '',
                'liveBbox'  => (float) $config['max_live_bbox'],
            ]);

        case 'vehicles':
            $queries = [];
            if (param('lines') !== '') {
                foreach (array_slice(array_unique(explode(',', param('lines'))), 0, 20) as $item) {
                    [$noc, $line] = array_pad(explode(':', $item, 2), 2, '');
                    if (!valid_noc($noc) || !valid_line($line)) {
                        throw new ApiError('Invalid service');
                    }
                    $queries[] = ['operatorRef' => $noc, 'lineRef' => $line];
                }
            }
            if (param('bbox') !== '') {
                $queries[] = ['boundingBox' => bbox_param(bbox((float) $config['max_live_bbox']))];
            }
            if (!$queries) {
                out(['vehicles' => [], 'time' => time()]);
            }
            $vehicles = mbm_fetch_vehicles($queries);
            if (param('lines') !== '' && param('bbox') === '') {
                // lineRef matching in BODS is loose; keep only what was asked for.
                $want = array_flip(array_map('strtoupper', explode(',', param('lines'))));
                $vehicles = array_values(array_filter($vehicles, fn($v) => isset($want[strtoupper($v['noc'] . ':' . $v['line'])])));
            }
            $vehicles = array_map('mbm_public_vehicle', mbm_enrich_vehicles($vehicles));
            out(['vehicles' => $vehicles, 'time' => time()]);

        case 'services':
            out(['services' => mbm_services_in_bbox(bbox(1.0))]);

        case 'stops':
            out(['stops' => array_map(fn($s) => [
                'id' => $s['id'], 'name' => $s['name'], 'platform' => $s['platform'],
                'lat' => (float) $s['lat'], 'lon' => (float) $s['lon'],
            ], mbm_stops_in_bbox(bbox(0.01)))]);

        case 'stop':
            $atco = param('id');
            if (!preg_match('/^[A-Za-z0-9]{4,16}$/', $atco) || !($stop = mbm_stop_by_atco($atco))) {
                throw new ApiError('Unknown stop', 404);
            }
            $now = time();
            $deps = mbm_departures((int) $stop['id'], $now - 1800, $now + 5400, 60);

            // Live positions around the stop, to show expected times.
            $live = [];
            $liveError = null;
            if ($config['bods_api_key'] !== '' && $deps) {
                try {
                    $lat = (float) $stop['lat'];
                    $lon = (float) $stop['lon'];
                    $vehicles = mbm_enrich_vehicles(mbm_fetch_vehicles([
                        ['boundingBox' => bbox_param([$lon - 0.1, $lat - 0.06, $lon + 0.1, $lat + 0.06])],
                    ]));
                    foreach ($vehicles as $v) {
                        if (isset($v['trip'], $v['delay'])) {
                            $live[$v['trip']] = $v;
                        }
                    }
                } catch (RuntimeException $e) {
                    $liveError = $e->getMessage();
                }
            }

            $out = [];
            foreach ($deps as $d) {
                $row = [
                    'line' => $d['line'], 'noc' => $d['noc'], 'operator' => $d['operator'],
                    'headsign' => $d['headsign'], 'scheduled' => $d['time'],
                    'trip' => $d['trip'], 'seq' => $d['seq'],
                ];
                if (isset($live[$d['trip']])) {
                    $v = $live[$d['trip']];
                    $stops = mbm_trip_stops($d['trip']);
                    $idx = array_search($d['seq'], array_map('intval', array_column($stops, 'seq')), true);
                    if ($idx !== false && $v['pos'] > $idx + 0.05) {
                        continue;   // already gone
                    }
                    $row['expected'] = $d['time'] + max(0, $v['delay']);
                    $row['delay'] = $v['delay'];
                    $row['vehicle'] = $v['id'];
                } elseif ($d['time'] < $now - 120) {
                    continue;       // scheduled in the past and no sign of it
                }
                $out[] = $row;
                if (count($out) >= 30) {
                    break;
                }
            }
            out([
                'stop' => [
                    'id' => $stop['atco'], 'name' => $stop['name'], 'platform' => $stop['platform'],
                    'lat' => (float) $stop['lat'], 'lon' => (float) $stop['lon'],
                ],
                'services'   => mbm_services_at_stop((int) $stop['id']),
                'departures' => $out,
                'liveError'  => $liveError,
                'time'       => $now,
            ]);

        case 'search':
            $q = param('q');
            if (mb_strlen($q) < 2 || mb_strlen($q) > 60) {
                throw new ApiError('Type at least two characters');
            }
            $near = param('near') !== '' ? point('near') : [null, null];
            out(['stops' => mbm_search_stops($q, ...$near)]);

        case 'route':
            $noc = param('noc');
            $line = param('line');
            if (!valid_noc($noc) || !valid_line($line)) {
                throw new ApiError('Invalid service');
            }
            $route = mbm_route($noc, $line, mb_substr(param('name'), 0, 200));
            if (!$route) {
                throw new ApiError('Route not found', 404);
            }
            out($route);

        case 'trip':
            // Where one bus goes from a stop: the rest of its stops with times.
            $id = filter_var(param('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $seq = filter_var(param('seq'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $at = filter_var(param('at'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($id === false || $seq === false || $at === false) {
                throw new ApiError('Journey not found', 404);
            }
            // step=next or prev: the same service's bus after or before this one.
            $step = ['next' => 1, 'prev' => -1][param('step')] ?? 0;
            if ($step) {
                if (!($adj = mbm_adjacent_trip($id, $seq, $at, $step))) {
                    throw new ApiError($step > 0 ? 'No later bus in the next three days' : 'No earlier bus found', 404);
                }
                ['trip' => $id, 'seq' => $seq, 'at' => $at] = $adj;
            }
            if (!($trip = mbm_trip($id, $seq, $at))) {
                throw new ApiError('Journey not found', 404);
            }
            out($trip);

        case 'plan':
            [$fLat, $fLon] = point('from');
            [$tLat, $tLon] = point('to');
            if (mbm_distance($fLat, $fLon, $tLat, $tLon) > 60000) {
                throw new ApiError('The planner is for local journeys of up to 60 km');
            }
            $ts = time();
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', param('time'), $m)) {
                $ts = (new DateTimeImmutable('today'))->setTime((int) $m[1], (int) $m[2])->getTimestamp();
                if ($ts < time() - 3600) {
                    $ts += 86400;   // a time earlier today means tomorrow
                }
            }
            out(mbm_plan($fLat, $fLon, $tLat, $tLon, $ts) + ['time' => $ts]);

        default:
            throw new ApiError('Unknown action', 404);
    }
} catch (ApiError $e) {
    out(['error' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    // Details go to the server log only; the message never includes the key.
    error_log('mybusmap: ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    $msg = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Something went wrong';
    out(['error' => $msg], 503);
}
