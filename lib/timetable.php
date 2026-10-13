<?php
/**
 * Timetable queries against the imported GTFS database.
 *
 * GTFS times are seconds after midnight of the "service day" and can exceed
 * 24h for journeys running past midnight, so most queries are run against
 * both today's and yesterday's services (see mbm_service_days()).
 */
declare(strict_types=1);

/**
 * Service days relevant to $ts: [['day' => Ymd, 'midnight' => ts], ...] for
 * today and yesterday. Also fills the temp table `active` (service_id, day)
 * used in joins; it accumulates dates, so earlier results stay valid.
 */
function mbm_service_days(int $ts): array
{
    static $filled = [];
    $today = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/London'))->setTime(0, 0);
    $db = mbm_db();
    if (!$filled) {
        $db->exec('CREATE TEMP TABLE IF NOT EXISTS active (service_id INTEGER, day INTEGER, PRIMARY KEY (service_id, day)) WITHOUT ROWID');
    }
    $days = [];
    foreach ([$today, $today->modify('-1 day')] as $date) {
        $ymd = (int) $date->format('Ymd');
        if (!isset($filled[$ymd])) {
            $bit = 1 << ((int) $date->format('N') - 1);
            $ids = $db->query("SELECT id FROM services WHERE start_date <= $ymd AND end_date >= $ymd AND (days & $bit) != 0")
                ->fetchAll(PDO::FETCH_COLUMN);
            $set = array_flip($ids);
            foreach ($db->query("SELECT service_id, type FROM service_dates WHERE date = $ymd") as $row) {
                if ((int) $row['type'] === 1) {
                    $set[$row['service_id']] = true;
                } else {
                    unset($set[$row['service_id']]);
                }
            }
            $ins = $db->prepare('INSERT OR IGNORE INTO active VALUES (?, ?)');
            $db->beginTransaction();
            foreach ($set as $id => $_) {
                $ins->execute([$id, $ymd]);
            }
            $db->commit();
            $filled[$ymd] = true;
        }
        $days[] = ['day' => $ymd, 'midnight' => $date->getTimestamp()];
    }
    return $days;
}

function mbm_stop_by_atco(string $atco): ?array
{
    $q = mbm_db()->prepare('SELECT id, atco, name, platform, lat, lon FROM stops WHERE atco = ?');
    $q->execute([$atco]);
    return $q->fetch() ?: null;
}

function mbm_stops_in_bbox(array $b, int $limit = 600): array
{
    $q = mbm_db()->prepare(
        'SELECT s.atco AS id, s.name, s.platform, s.lat, s.lon FROM stops_rtree r JOIN stops s ON s.id = r.id
         WHERE r.min_lat >= ? AND r.max_lat <= ? AND r.min_lon >= ? AND r.max_lon <= ?
         AND EXISTS (SELECT 1 FROM route_stops rs WHERE rs.stop_id = s.id) LIMIT ' . $limit
    );
    $q->execute([$b[1], $b[3], $b[0], $b[2]]);
    return $q->fetchAll();
}

/** Stops within $metres of a point, nearest first, with 'dist' in metres. */
function mbm_stops_near(float $lat, float $lon, float $metres, int $limit = 20): array
{
    [$dLat, $dLon] = mbm_degrees($metres, $lat);
    $q = mbm_db()->prepare(
        'SELECT s.id, s.atco, s.name, s.platform, s.lat, s.lon FROM stops_rtree r JOIN stops s ON s.id = r.id
         WHERE r.min_lat >= ? AND r.max_lat <= ? AND r.min_lon >= ? AND r.max_lon <= ?
         AND EXISTS (SELECT 1 FROM route_stops rs WHERE rs.stop_id = s.id)'
    );
    $q->execute([$lat - $dLat, $lat + $dLat, $lon - $dLon, $lon + $dLon]);
    $out = [];
    foreach ($q as $s) {
        $s['dist'] = mbm_distance($lat, $lon, (float) $s['lat'], (float) $s['lon']);
        if ($s['dist'] <= $metres) {
            $out[] = $s;
        }
    }
    usort($out, fn($a, $b) => $a['dist'] <=> $b['dist']);
    return array_slice($out, 0, $limit);
}

function mbm_search_stops(string $text, ?float $lat, ?float $lon): array
{
    // Every word typed must start a word of the stop's name, street, landmark
    // or locality. Words are tried as typed and with abbreviations expanded,
    // so a half-typed "st" still finds "Station" as well as "Street".
    $where = [];
    $args = [];
    $wordForms = [];
    $esc = fn(string $w) => '% ' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w) . '%';
    foreach (preg_split('/[^\p{L}\p{N}\'\x{2019}.]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
        $plain = preg_replace("/['\x{2019}.]/u", '', $word);
        $forms = array_unique(array_filter([$plain, mbm_search_norm($word)]));
        if (!$forms) {
            continue;
        }
        $wordForms[] = $forms;
        $where[] = '(' . implode(' OR ', array_fill(0, count($forms), "(' ' || COALESCE(search, lower(name))) LIKE ? ESCAPE '\\'")) . ')';
        array_push($args, ...array_map($esc, $forms));
        if (count($where) >= 6) {
            break;
        }
    }
    if (!$where) {
        return [];
    }
    $q = mbm_db()->prepare(
        'SELECT atco AS id, name, platform, street, locality, lat, lon FROM stops s WHERE ' . implode(' AND ', $where) . '
         AND EXISTS (SELECT 1 FROM route_stops rs WHERE rs.stop_id = s.id) LIMIT 2000'
    );
    $q->execute($args);
    $rows = $q->fetchAll();
    $needle = mbm_search_norm($text);
    foreach ($rows as &$r) {
        $name = ' ' . mbm_search_norm($r['name']);
        $inName = true;
        foreach ($wordForms as $forms) {
            $inName = $inName && array_filter($forms, fn($f) => str_contains($name, " $f"));
        }
        $r['dist'] = $lat !== null ? mbm_distance($lat, $lon, (float) $r['lat'], (float) $r['lon']) : 0;
        // Matches on the stop's own name rank as if 3 km nearer than
        // matches on its street or locality.
        $r['rank'] = $r['dist'] + ($inName ? 0 : 3000);
        $r['starts'] = str_starts_with(ltrim($name), $needle) ? 0 : ($inName ? 1 : 2);
    }
    unset($r);
    usort($rows, fn($a, $b) => $lat !== null
        ? $a['rank'] <=> $b['rank']
        : [$a['starts'], $a['name']] <=> [$b['starts'], $b['name']]);
    return array_map(fn($r) => [
        'id' => $r['id'], 'name' => $r['name'], 'platform' => $r['platform'],
        'street' => (string) $r['street'], 'locality' => (string) $r['locality'],
        'lat' => (float) $r['lat'], 'lon' => (float) $r['lon'],
        'dist' => $lat !== null ? (int) round($r['dist']) : null,
    ], array_slice($rows, 0, 25));
}

/** Services (operator + line) calling at stops inside a bounding box. */
function mbm_services_in_bbox(array $b): array
{
    $q = mbm_db()->prepare(
        'SELECT a.noc, a.name AS operator, r.short_name AS line, r.long_name, r.type, COUNT(*) AS stops
         FROM stops_rtree x
         JOIN route_stops rs ON rs.stop_id = x.id
         JOIN routes r ON r.id = rs.route_id
         JOIN agencies a ON a.id = r.agency_id
         WHERE x.min_lat >= ? AND x.max_lat <= ? AND x.min_lon >= ? AND x.max_lon <= ?
         GROUP BY a.noc, r.short_name, r.long_name'
    );
    $q->execute([$b[1], $b[3], $b[0], $b[2]]);
    return mbm_format_services($q->fetchAll());
}

function mbm_services_at_stop(int $stopId): array
{
    $q = mbm_db()->prepare(
        'SELECT a.noc, a.name AS operator, r.short_name AS line, r.long_name, r.type
         FROM route_stops rs JOIN routes r ON r.id = rs.route_id JOIN agencies a ON a.id = r.agency_id
         WHERE rs.stop_id = ? GROUP BY a.noc, r.short_name, r.long_name'
    );
    $q->execute([$stopId]);
    return mbm_format_services($q->fetchAll());
}

function mbm_format_services(array $rows): array
{
    $out = array_map(fn($r) => [
        'noc'      => $r['noc'],
        'operator' => $r['operator'],
        'line'     => $r['line'],
        'name'     => $r['long_name'],
        'mode'     => match ((int) $r['type']) { 0 => 'tram', 200 => 'coach', default => 'bus' },
    ], $rows);
    usort($out, fn($a, $b) => strnatcasecmp($a['line'], $b['line']) ?: strcmp($a['noc'], $b['noc']) ?: strcmp((string) $a['name'], (string) $b['name']));
    return $out;
}

/**
 * Map shapes and stops for one service (all its routes across regions).
 * Operators reuse numbers in different towns, e.g. National Express 16 in
 * both Birmingham and Wolverhampton, so $name (the route's long name) picks one.
 */
function mbm_route(string $noc, string $line, string $name = ''): ?array
{
    $db = mbm_db();
    $q = $db->prepare(
        'SELECT r.id, a.name AS operator, r.long_name FROM routes r JOIN agencies a ON a.id = r.agency_id
         WHERE a.noc = ? AND r.short_name = ?'
    );
    $q->execute([$noc, $line]);
    $routes = $q->fetchAll();
    $named = array_values(array_filter($routes, fn($r) => $r['long_name'] === $name));
    if ($named) {
        $routes = $named;
    }
    if (!$routes) {
        return null;
    }
    $ids = implode(',', array_map(fn($r) => (int) $r['id'], $routes));
    $shapes = $db->query("SELECT direction, trips, polyline FROM route_shapes WHERE route_id IN ($ids) ORDER BY trips DESC")
        ->fetchAll();
    $stops = $db->query(
        "SELECT DISTINCT s.atco AS id, s.name, s.platform, s.lat, s.lon FROM route_stops rs JOIN stops s ON s.id = rs.stop_id
         WHERE rs.route_id IN ($ids)"
    )->fetchAll();
    return [
        'noc'      => $noc,
        'line'     => $line,
        'operator' => $routes[0]['operator'],
        'name'     => $routes[0]['long_name'],
        'shapes'   => array_map(fn($s) => ['dir' => (int) $s['direction'], 'polyline' => $s['polyline']], $shapes),
        'stops'    => array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'platform' => $s['platform'],
            'lat' => (float) $s['lat'], 'lon' => (float) $s['lon']], $stops),
    ];
}

/**
 * One trip from stop call $seq onwards, timed so that call is at $at, with the
 * shapes drawn for its route and direction (the browser trims them to fit).
 */
function mbm_trip(int $tripId, int $seq, int $at): ?array
{
    $db = mbm_db();
    $q = $db->prepare(
        'SELECT t.route_id, t.direction, t.headsign, r.short_name AS line, r.long_name, a.noc, a.name AS operator
         FROM trips t JOIN routes r ON r.id = t.route_id JOIN agencies a ON a.id = r.agency_id WHERE t.id = ?'
    );
    $q->execute([$tripId]);
    $t = $q->fetch();
    $calls = array_values(array_filter(mbm_trip_stops($tripId), fn($c) => (int) $c['seq'] >= $seq));
    if (!$t || count($calls) < 2 || (int) $calls[0]['seq'] !== $seq) {
        return null;
    }
    $base = $at - (int) $calls[0]['dep'];
    $q = $db->prepare('SELECT polyline FROM route_shapes WHERE route_id = ? AND direction = ? ORDER BY trips DESC');
    $q->execute([$t['route_id'], $t['direction']]);
    return [
        'trip'     => $tripId,
        'seq'      => $seq,
        'at'       => $at,
        'noc'      => $t['noc'],
        'line'     => $t['line'],
        'name'     => $t['long_name'],
        'operator' => $t['operator'],
        'headsign' => $t['headsign'],
        'shapes'   => $q->fetchAll(PDO::FETCH_COLUMN),
        'stops'    => array_map(fn($c) => [
            'id' => $c['atco'], 'name' => $c['name'], 'platform' => $c['platform'],
            'lat' => (float) $c['lat'], 'lon' => (float) $c['lon'], 'time' => $base + (int) $c['arr'],
        ], $calls),
    ];
}

/**
 * The bus before ($step -1) or after ($step 1) a trip's call at a stop: the
 * same route and direction from the same stop, up to three days either way so
 * a gap over a weekend is crossed. Returns [trip, seq, at] or null.
 */
function mbm_adjacent_trip(int $tripId, int $seq, int $at, int $step): ?array
{
    $db = mbm_db();
    $q = $db->prepare(
        'SELECT st.stop_id, t.route_id, t.direction FROM stop_times st JOIN trips t ON t.id = st.trip_id
         WHERE st.trip_id = ? AND st.seq = ?'
    );
    $q->execute([$tripId, $seq]);
    if (!($from = $q->fetch())) {
        return null;
    }
    $span = 3 * 86400;
    $days = [];
    foreach ($step > 0 ? [0, 1, 2, 3] : [0, -1, -2] as $k) {
        foreach (mbm_service_days($at + $k * 86400) as $d) {
            $days[$d['day']] = $d['midnight'];
        }
    }
    $q = $db->prepare(
        'SELECT st.trip_id, st.seq, st.dep
         FROM stop_times st
         JOIN trips t ON t.id = st.trip_id
         JOIN active ac ON ac.service_id = t.service_id AND ac.day = :day
         WHERE st.stop_id = :stop AND t.route_id = :route AND t.direction IS :dir
           AND st.dep > :t0 AND st.dep < :t1 AND st.pickup != 1
           AND EXISTS (SELECT 1 FROM stop_times n WHERE n.trip_id = st.trip_id AND n.seq > st.seq)
         ORDER BY st.dep ' . ($step > 0 ? 'ASC' : 'DESC') . ' LIMIT 1'
    );
    $best = null;
    foreach ($days as $day => $midnight) {
        [$t0, $t1] = $step > 0 ? [$at, $at + $span] : [$at - $span, $at];
        $q->execute([
            'day' => $day, 'stop' => $from['stop_id'], 'route' => $from['route_id'], 'dir' => $from['direction'],
            't0' => $t0 - $midnight, 't1' => $t1 - $midnight,
        ]);
        if ($r = $q->fetch()) {
            $time = $midnight + (int) $r['dep'];
            if (!$best || ($step > 0 ? $time < $best['at'] : $time > $best['at'])) {
                $best = ['trip' => (int) $r['trip_id'], 'seq' => (int) $r['seq'], 'at' => $time];
            }
        }
        $q->closeCursor();
    }
    return $best;
}

/** Stop calls of a trip in order, with coordinates. */
function mbm_trip_stops(int $tripId): array
{
    static $cache = [];
    if (!isset($cache[$tripId])) {
        static $q = null;
        $q ??= mbm_db()->prepare(
            'SELECT st.seq, st.arr, st.dep, s.id AS stop_id, s.atco, s.name, s.platform, s.lat, s.lon
             FROM stop_times st JOIN stops s ON s.id = st.stop_id WHERE st.trip_id = ? ORDER BY st.seq'
        );
        $q->execute([$tripId]);
        $cache[$tripId] = $q->fetchAll();
    }
    return $cache[$tripId];
}

/**
 * Scheduled departures from a stop between $from and $to (unix timestamps),
 * including journeys that started yesterday and run past midnight.
 */
function mbm_departures(int $stopId, int $from, int $to, int $limit = 40): array
{
    $days = mbm_service_days($from);
    $db = mbm_db();
    $q = $db->prepare(
        'SELECT st.trip_id, st.seq, st.dep, t.headsign, t.first_dep, r.short_name AS line, a.noc, a.name AS operator
         FROM stop_times st
         JOIN trips t ON t.id = st.trip_id
         JOIN active ac ON ac.service_id = t.service_id AND ac.day = :day
         JOIN routes r ON r.id = t.route_id
         JOIN agencies a ON a.id = r.agency_id
         WHERE st.stop_id = :stop AND st.dep BETWEEN :t0 AND :t1 AND st.pickup != 1
           AND EXISTS (SELECT 1 FROM stop_times n WHERE n.trip_id = st.trip_id AND n.seq > st.seq)
         ORDER BY st.dep LIMIT ' . $limit
    );
    $out = [];
    foreach ($days as $d) {
        $q->execute(['day' => $d['day'], 'stop' => $stopId, 't0' => $from - $d['midnight'], 't1' => $to - $d['midnight']]);
        foreach ($q as $r) {
            $out[] = [
                'trip'     => (int) $r['trip_id'],
                'seq'      => (int) $r['seq'],
                'time'     => $d['midnight'] + (int) $r['dep'],
                'line'     => $r['line'],
                'noc'      => $r['noc'],
                'operator' => $r['operator'],
                'headsign' => $r['headsign'],
                'midnight' => $d['midnight'],
            ];
        }
    }
    usort($out, fn($a, $b) => $a['time'] <=> $b['time']);
    return array_slice($out, 0, $limit);
}
