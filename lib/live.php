<?php
/**
 * Live vehicle positions from the BODS SIRI-VM API, with a short file cache,
 * and matching of vehicles to timetabled trips to estimate lateness.
 *
 * The API key is only ever added to the outgoing request here; it is never
 * logged, cached or returned to the browser.
 */
declare(strict_types=1);

const MBM_BODS_URL = 'https://data.bus-data.dft.gov.uk/api/v1/datafeed/';
const MBM_STALE_AFTER = 900;   // ignore positions older than 15 minutes

function mbm_cache_dir(): string
{
    $dir = mbm_data_dir() . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 02775, true);
    }
    return $dir;
}

function mbm_cache_get(string $key, int $ttl): mixed
{
    $file = mbm_cache_dir() . '/' . sha1($key) . '.json';
    if (is_file($file) && filemtime($file) > time() - $ttl) {
        $data = json_decode((string) @file_get_contents($file), true);
        if (is_array($data)) {
            return $data;
        }
    }
    return null;
}

function mbm_cache_put(string $key, array $data): void
{
    $dir = mbm_cache_dir();
    $file = $dir . '/' . sha1($key) . '.json';
    $tmp = $file . '.' . getmypid();
    if (@file_put_contents($tmp, json_encode($data)) !== false) {
        @rename($tmp, $file);
    }
    if (mt_rand(1, 200) === 1) {   // occasional tidy-up
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - 600) {
                @unlink($f);
            }
        }
    }
}

/**
 * Fetch vehicles for several SIRI-VM queries in parallel.
 * Each query is an array of API parameters, e.g. ['operatorRef' => 'DIAM', 'lineRef' => '226']
 * or ['boundingBox' => 'minLon,minLat,maxLon,maxLat']. Returns a de-duplicated vehicle list.
 */
function mbm_fetch_vehicles(array $queries): array
{
    $config = mbm_config();
    $ttl = (int) $config['live_cache_ttl'];
    if ($config['bods_api_key'] === '') {
        throw new RuntimeException('No BODS API key configured');
    }
    $results = [];
    $pending = [];
    foreach ($queries as $params) {
        ksort($params);
        $key = 'siri:' . http_build_query($params);
        $cached = mbm_cache_get($key, $ttl);
        if ($cached !== null) {
            $results[] = $cached;
        } else {
            $pending[$key] = $params;
        }
    }

    if ($pending) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($pending as $key => $params) {
            $ch = curl_init(MBM_BODS_URL . '?' . http_build_query($params + ['api_key' => $config['bods_api_key']]));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => 'mybusmap',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $failed = 0;
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $vehicles = ($code === 200 && $body) ? mbm_parse_siri($body) : null;
            if ($vehicles === null) {
                // Deliberately does not log the URL, which contains the key.
                error_log("mybusmap: BODS request failed (HTTP $code) for " . substr($key, 5));
                $failed++;
                continue;
            }
            mbm_cache_put($key, $vehicles);
            $results[] = $vehicles;
        }
        curl_multi_close($mh);
        if ($failed && !$results) {
            throw new RuntimeException('Live bus data is unavailable at the moment');
        }
    }

    // Merge, keeping the newest report for each vehicle.
    $out = [];
    $cutoff = time() - MBM_STALE_AFTER;
    foreach ($results as $list) {
        foreach ($list as $v) {
            if ($v['recorded'] < $cutoff) {
                continue;
            }
            if (!isset($out[$v['id']]) || $out[$v['id']]['recorded'] < $v['recorded']) {
                $out[$v['id']] = $v;
            }
        }
    }
    return array_values($out);
}

/** Parse a SIRI-VM document into a compact vehicle list; null if unparseable. */
function mbm_parse_siri(string $xml): ?array
{
    $xml = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml, 5);
    $doc = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    if ($doc === false || !isset($doc->ServiceDelivery)) {
        return null;
    }
    $clean = fn($s) => trim(preg_replace('/[\s_]+/', ' ', (string) $s));
    $out = [];
    foreach ($doc->ServiceDelivery->VehicleMonitoringDelivery->VehicleActivity ?? [] as $va) {
        $j = $va->MonitoredVehicleJourney;
        $lat = (float) $j->VehicleLocation->Latitude;
        $lon = (float) $j->VehicleLocation->Longitude;
        if ($lat == 0.0 || $lon == 0.0) {
            continue;
        }
        $noc = (string) $j->OperatorRef;
        $ref = (string) $j->VehicleRef;
        $out[] = [
            'id'          => $noc . ':' . ($ref !== '' ? $ref : (string) $va->ItemIdentifier),
            'noc'         => $noc,
            'line'        => (string) ($j->PublishedLineName ?: $j->LineRef),
            'lat'         => round($lat, 6),
            'lon'         => round($lon, 6),
            'bearing'     => isset($j->Bearing) && (string) $j->Bearing !== '' ? (int) round((float) $j->Bearing) : null,
            'origin'      => $clean($j->OriginName),
            'dest'        => $clean($j->DestinationName),
            'originRef'   => (string) $j->OriginRef,
            'originTime'  => strtotime((string) $j->OriginAimedDepartureTime) ?: null,
            'recorded'    => strtotime((string) $va->RecordedAtTime) ?: time(),
        ];
    }
    return $out;
}

/**
 * Find the timetabled trip a live vehicle is running, via its origin stop and
 * scheduled departure time. Returns [tripId, serviceMidnight] or null.
 */
function mbm_match_trip(array $v): ?array
{
    static $stopQ = null, $tripQ = null, $memo = [];
    if ($v['originRef'] === '' || !$v['originTime']) {
        return null;
    }
    $key = $v['noc'] . '|' . $v['originRef'] . '|' . $v['originTime'] . '|' . $v['line'];
    if (array_key_exists($key, $memo)) {
        return $memo[$key];
    }
    $days = mbm_service_days($v['originTime']);   // creates the `active` table
    $db = mbm_db();
    $stopQ ??= $db->prepare('SELECT id FROM stops WHERE atco = ?');
    $tripQ ??= $db->prepare(
        'SELECT t.id, a.noc, r.short_name FROM trips t
         JOIN active ac ON ac.service_id = t.service_id AND ac.day = ?
         JOIN routes r ON r.id = t.route_id JOIN agencies a ON a.id = r.agency_id
         WHERE t.first_stop = ? AND t.first_dep = ?'
    );
    $stopQ->execute([$v['originRef']]);
    $stopId = $stopQ->fetchColumn();
    $match = null;
    if ($stopId !== false) {
        foreach ($days as $d) {
            $tripQ->execute([$d['day'], $stopId, $v['originTime'] - $d['midnight']]);
            $best = -1;
            foreach ($tripQ as $t) {
                $score = ($t['noc'] === $v['noc'] ? 2 : 0) + (strcasecmp($t['short_name'], $v['line']) === 0 ? 1 : 0);
                if ($score > $best) {
                    $best = $score;
                    $match = [(int) $t['id'], $d['midnight']];
                }
            }
            if ($match) {
                break;
            }
        }
    }
    return $memo[$key] = $match;
}

/**
 * Where a vehicle is along its trip and how late it is.
 * Returns ['pos' => fractional index into the trip's stops, 'delay' => seconds late] or null.
 */
function mbm_trip_progress(array $v, int $tripId, int $midnight): ?array
{
    $stops = mbm_trip_stops($tripId);
    $n = count($stops);
    if ($n < 2) {
        return null;
    }
    // Project onto each stop-to-stop segment in a local flat approximation.
    $kx = cos(deg2rad($v['lat'])) * 111320.0;
    $ky = 111320.0;
    $px = $v['lon'] * $kx;
    $py = $v['lat'] * $ky;
    $now = $v['recorded'] - $midnight;
    $cands = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $ax = $stops[$i]['lon'] * $kx;
        $ay = $stops[$i]['lat'] * $ky;
        $dx = $stops[$i + 1]['lon'] * $kx - $ax;
        $dy = $stops[$i + 1]['lat'] * $ky - $ay;
        $len2 = $dx * $dx + $dy * $dy;
        $t = $len2 > 0 ? max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2)) : 0.0;
        $d = sqrt(($px - $ax - $t * $dx) ** 2 + ($py - $ay - $t * $dy) ** 2);
        $sched = $stops[$i]['dep'] + $t * ($stops[$i + 1]['arr'] - $stops[$i]['dep']);
        $cands[] = [$d, $i, $t, $sched];
    }
    $minD = min(array_column($cands, 0));
    if ($minD > 1500) {
        return null;   // off route (diversion, wrong match, out of service)
    }
    // Routes that double back pass the same road twice, and straight lines
    // between stops cut corners: of the segments nearly as close as the best,
    // take the one whose timetable is nearest the clock.
    $best = null;
    foreach ($cands as $c) {
        if ($c[0] <= $minD + 150 && ($best === null || abs($now - $c[3]) < abs($now - $best[3]))) {
            $best = $c;
        }
    }
    [, $bestI, $bestT, $sched] = $best;
    $delay = (int) round($now - $sched);
    if ($bestI === 0 && $bestT < 0.05 && $delay < 0) {
        $delay = 0;    // waiting at the start of the route
    }
    return ['pos' => $bestI + $bestT, 'delay' => $delay];
}

/** Add timetable details (trip, delay, next stop) to live vehicles. */
function mbm_enrich_vehicles(array $vehicles): array
{
    try {
        mbm_db();
    } catch (RuntimeException) {
        return $vehicles;   // no timetable imported yet; positions still work
    }
    foreach ($vehicles as &$v) {
        $m = mbm_match_trip($v);
        if (!$m) {
            continue;
        }
        [$tripId, $midnight] = $m;
        $v['trip'] = $tripId;
        $v['route'] = mbm_trip_route_name($tripId);
        $p = mbm_trip_progress($v, $tripId, $midnight);
        if ($p) {
            $stops = mbm_trip_stops($tripId);
            $next = $stops[min(count($stops) - 1, (int) floor($p['pos']) + 1)];
            $v['delay'] = $p['delay'];
            $v['pos'] = round($p['pos'], 2);
            $v['next'] = [
                'name' => $next['name'],
                'time' => $midnight + (int) $next['arr'] + max(0, $p['delay']),
            ];
        }
    }
    unset($v);
    return $vehicles;
}

/** The long name of a trip's route, which tells apart services sharing a number. */
function mbm_trip_route_name(int|string $tripId): ?string
{
    static $q;
    $q ??= mbm_db()->prepare('SELECT r.long_name FROM trips t JOIN routes r ON r.id = t.route_id WHERE t.id = ?');
    $q->execute([$tripId]);
    $name = $q->fetchColumn();
    return $name === false ? null : $name;
}

/** Remove internal fields before sending vehicles to the browser. */
function mbm_public_vehicle(array $v): array
{
    unset($v['originRef'], $v['originTime'], $v['pos']);
    return $v;
}
