<?php
/**
 * A simple local journey planner: walk to a stop, take one bus (or two with
 * a change), walk to the destination. Not a full multi-modal router, but
 * good for "how do I get across town" questions.
 */
declare(strict_types=1);

const MBM_WALK_SPEED = 1.2;        // m/s, allowing for road layout
const MBM_WALK_DETOUR = 1.3;       // straight-line to street distance
const MBM_ACCESS_RADIUS = 600;     // metres to look for stops at each end
const MBM_TRANSFER_RADIUS = 250;   // metres to walk between buses
const MBM_MIN_TRANSFER = 60;       // seconds allowed to change buses

function mbm_walk_secs(float $metres): int
{
    return (int) ceil($metres * MBM_WALK_DETOUR / MBM_WALK_SPEED);
}

function mbm_plan(float $fLat, float $fLon, float $tLat, float $tLon, int $ts): array
{
    $db = mbm_db();
    $origins = [];
    foreach (mbm_stops_near($fLat, $fLon, MBM_ACCESS_RADIUS, 15) as $s) {
        $origins[(int) $s['id']] = mbm_walk_secs($s['dist']);
    }
    $dests = [];
    foreach (mbm_stops_near($tLat, $tLon, MBM_ACCESS_RADIUS, 15) as $s) {
        $dests[(int) $s['id']] = mbm_walk_secs($s['dist']);
    }
    $direct = mbm_distance($fLat, $fLon, $tLat, $tLon);
    $results = [];
    if ($direct <= 2000) {
        $walk = mbm_walk_secs($direct);
        $results[] = ['depart' => $ts, 'arrive' => $ts + $walk, 'changes' => 0, 'walkOnly' => true,
            'legs' => [['type' => 'walk', 'secs' => $walk]]];
    }
    if (!$origins || !$dests) {
        return ['itineraries' => $results, 'message' => !$origins ? 'No bus stops near the start' : 'No bus stops near the destination'];
    }
    // A stop near both ends means there is no point taking a bus.
    if (array_intersect_key($origins, $dests)) {
        return ['itineraries' => $results, 'message' => 'Start and destination are within walking distance'];
    }

    $oIn = implode(',', array_keys($origins));
    $dIn = implode(',', array_keys($dests));
    $cands = [];

    foreach (mbm_service_days($ts) as $d) {
        $day = $d['day'];
        $mid = $d['midnight'];
        $t0 = $ts - $mid;

        // Direct journeys.
        $q = $db->prepare(
            "SELECT a.trip_id, t.route_id, a.stop_id AS s1, a.dep, a.seq AS q1, b.stop_id AS s2, b.arr, b.seq AS q2
             FROM stop_times a
             JOIN trips t ON t.id = a.trip_id
             JOIN active ac ON ac.service_id = t.service_id AND ac.day = $day
             JOIN stop_times b ON b.trip_id = a.trip_id AND b.seq > a.seq
             WHERE a.stop_id IN ($oIn) AND a.dep BETWEEN ? AND ? AND a.pickup != 1
               AND b.stop_id IN ($dIn) AND b.dropoff != 1"
        );
        $q->execute([$t0, $t0 + 3 * 3600]);
        foreach ($q as $r) {
            if ($r['dep'] < $t0 + $origins[$r['s1']]) {
                continue;   // can't walk to the stop in time
            }
            $leg = mbm_leg($r['trip_id'], $r['route_id'], $r['s1'], $r['q1'], $r['dep'], $r['s2'], $r['q2'], $r['arr'], $mid);
            $cands[] = mbm_itinerary([$leg], $origins[$r['s1']], $dests[$r['s2']]);
        }

        // One change: everywhere reachable from the origin stops...
        $q = $db->prepare(
            "SELECT a.trip_id, t.route_id, a.stop_id AS s1, a.dep, a.seq AS q1, b.stop_id AS x, b.arr, b.seq AS qx
             FROM stop_times a
             JOIN trips t ON t.id = a.trip_id
             JOIN active ac ON ac.service_id = t.service_id AND ac.day = $day
             JOIN stop_times b ON b.trip_id = a.trip_id AND b.seq > a.seq
             WHERE a.stop_id IN ($oIn) AND a.dep BETWEEN ? AND ? AND a.pickup != 1 AND b.dropoff != 1"
        );
        $q->execute([$t0, $t0 + 90 * 60]);
        $reach = [];   // stop => [[arr, dep, row], ...]
        foreach ($q as $r) {
            if ($r['dep'] >= $t0 + $origins[$r['s1']] && !isset($dests[$r['x']])) {
                $reach[(int) $r['x']][] = $r;
            }
        }
        if (!$reach) {
            continue;
        }
        // ...and everywhere with a bus to the destination stops.
        $q = $db->prepare(
            "SELECT b.trip_id, t.route_id, a.stop_id AS y, a.dep, a.seq AS qy, b.stop_id AS s2, b.arr, b.seq AS q2
             FROM stop_times b
             JOIN trips t ON t.id = b.trip_id
             JOIN active ac ON ac.service_id = t.service_id AND ac.day = $day
             JOIN stop_times a ON a.trip_id = b.trip_id AND a.seq < b.seq
             WHERE b.stop_id IN ($dIn) AND b.arr BETWEEN ? AND ? AND b.dropoff != 1 AND a.pickup != 1"
        );
        $q->execute([$t0, $t0 + 4 * 3600]);
        $board = [];
        foreach ($q as $r) {
            if (!isset($origins[$r['y']])) {
                $board[(int) $r['y']][] = $r;
            }
        }
        if (!$board) {
            continue;
        }

        // Link them through stops within transfer walking distance.
        $coords = [];
        $ids = implode(',', array_unique(array_merge(array_keys($reach), array_keys($board))));
        foreach ($db->query("SELECT id, lat, lon FROM stops WHERE id IN ($ids)") as $s) {
            $coords[(int) $s['id']] = [(float) $s['lat'], (float) $s['lon']];
        }
        $cell = fn(float $lat, float $lon) => floor($lat / 0.0025) . ':' . floor($lon / 0.004);
        $grid = [];
        foreach (array_keys($reach) as $x) {
            if (isset($coords[$x])) {
                $grid[$cell(...$coords[$x])][] = $x;
            }
        }
        $best = [];   // trip pair => itinerary
        foreach ($board as $y => $rows2) {
            if (!isset($coords[$y])) {
                continue;
            }
            [$lat, $lon] = $coords[$y];
            $ci = floor($lat / 0.0025);
            $cj = floor($lon / 0.004);
            for ($di = -1; $di <= 1; $di++) {
                for ($dj = -1; $dj <= 1; $dj++) {
                    foreach ($grid[($ci + $di) . ':' . ($cj + $dj)] ?? [] as $x) {
                        $dist = $x === $y ? 0 : mbm_distance($lat, $lon, ...$coords[$x]);
                        if ($dist > MBM_TRANSFER_RADIUS) {
                            continue;
                        }
                        $change = MBM_MIN_TRANSFER + mbm_walk_secs($dist);
                        foreach ($rows2 as $r2) {
                            foreach ($reach[$x] as $r1) {
                                if ($r1['route_id'] === $r2['route_id'] || $r1['arr'] + $change > $r2['dep']) {
                                    continue;
                                }
                                $arrive = $r2['arr'] + $dests[$r2['s2']];
                                $depart = $r1['dep'] - $origins[$r1['s1']];
                                $k = $r1['trip_id'] . '-' . $r2['trip_id'];
                                if (isset($best[$k]) && [$best[$k][0], -$best[$k][1]] <= [$arrive, -$depart]) {
                                    continue;
                                }
                                $best[$k] = [$arrive, $depart, $r1, $r2];
                            }
                        }
                    }
                }
            }
        }
        // Keep the most promising before building full leg details.
        usort($best, fn($a, $b) => [$a[0], -$a[1]] <=> [$b[0], -$b[1]]);
        foreach (array_slice(array_values($best), 0, 30) as [, , $r1, $r2]) {
            $cands[] = mbm_itinerary([
                mbm_leg($r1['trip_id'], $r1['route_id'], $r1['s1'], $r1['q1'], $r1['dep'], $r1['x'], $r1['qx'], $r1['arr'], $mid),
                mbm_leg($r2['trip_id'], $r2['route_id'], $r2['y'], $r2['qy'], $r2['dep'], $r2['s2'], $r2['q2'], $r2['arr'], $mid),
            ], $origins[$r1['s1']], $dests[$r2['s2']]);
        }
    }

    // Drop journeys beaten on every count (leave no earlier, arrive no
    // sooner, change no less) by another, then show the soonest arrivals.
    usort($cands, fn($a, $b) => [$a['arrive'], -$a['depart'], $a['changes']] <=> [$b['arrive'], -$b['depart'], $b['changes']]);
    $kept = [];
    foreach ($cands as $c) {
        foreach ($kept as $k) {
            if ($k['arrive'] <= $c['arrive'] && $k['depart'] >= $c['depart'] && $k['changes'] <= $c['changes']) {
                continue 2;
            }
        }
        $kept[] = $c;
        if (count($kept) >= 6) {
            break;
        }
    }
    $results = array_merge($results, $kept);
    usort($results, fn($a, $b) => $a['arrive'] <=> $b['arrive']);
    return ['itineraries' => $results, 'message' => $kept ? null : 'No bus journeys found in the next few hours'];
}

/** A bus leg with its path between boarding and alighting stops. */
function mbm_leg($trip, $route, $s1, $q1, $dep, $s2, $q2, $arr, int $midnight): array
{
    static $routeQ = null;
    $routeQ ??= mbm_db()->prepare(
        'SELECT r.short_name, r.long_name, r.type, a.noc, a.name, t.headsign FROM trips t JOIN routes r ON r.id = t.route_id
         JOIN agencies a ON a.id = r.agency_id WHERE t.id = ?'
    );
    $routeQ->execute([$trip]);
    $info = $routeQ->fetch();
    $path = [];
    $from = $to = null;
    foreach (mbm_trip_stops((int) $trip) as $s) {
        if ($s['seq'] < $q1 || $s['seq'] > $q2) {
            continue;
        }
        $path[] = [(float) $s['lat'], (float) $s['lon']];
        $stop = ['id' => $s['atco'], 'name' => $s['name'], 'platform' => $s['platform'],
            'lat' => (float) $s['lat'], 'lon' => (float) $s['lon']];
        if ($s['seq'] == $q1) {
            $from = $stop;
        }
        if ($s['seq'] == $q2) {
            $to = $stop;
        }
    }
    return [
        'type'     => 'bus',
        'line'     => $info['short_name'],
        'noc'      => $info['noc'],
        'operator' => $info['name'],
        'name'     => $info['long_name'],
        'mode'     => match ((int) $info['type']) { 0 => 'tram', 200 => 'coach', default => 'bus' },
        'headsign' => $info['headsign'],
        'trip'     => (int) $trip,
        'from'     => $from,
        'to'       => $to,
        'dep'      => $midnight + (int) $dep,
        'arr'      => $midnight + (int) $arr,
        'stops'    => count($path) - 1,
        'path'     => $path,
    ];
}

function mbm_itinerary(array $busLegs, int $walkStart, int $walkEnd): array
{
    $legs = [['type' => 'walk', 'secs' => $walkStart]];
    foreach ($busLegs as $i => $leg) {
        if ($i > 0) {
            $prev = $busLegs[$i - 1]['to'];
            $d = mbm_distance($prev['lat'], $prev['lon'], $leg['from']['lat'], $leg['from']['lon']);
            if ($d > 0) {
                $legs[] = ['type' => 'walk', 'secs' => mbm_walk_secs($d)];
            }
        }
        $legs[] = $leg;
    }
    $legs[] = ['type' => 'walk', 'secs' => $walkEnd];
    return [
        'depart'  => $busLegs[0]['dep'] - $walkStart,
        'arrive'  => end($busLegs)['arr'] + $walkEnd,
        'changes' => count($busLegs) - 1,
        'legs'    => $legs,
    ];
}
