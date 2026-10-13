<?php
/**
 * Download the BODS regional GTFS timetables listed in config.php and build
 * data/gtfs.sqlite. Safe to run from cron while the site is live: the new
 * database is built alongside and swapped in atomically when complete.
 *
 * Usage: php bin/import-gtfs.php [--regions=a,b] [--zip-dir=DIR] [--keep-zips]
 *   --regions   override the regions in config.php
 *   --zip-dir   use already-downloaded {region}.zip files from DIR
 *   --keep-zips keep downloaded zips in data/zips/ instead of deleting them
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/naptan.php';

ini_set('memory_limit', '4G');

$opts = getopt('', ['regions:', 'zip-dir:', 'keep-zips']);
$config = mbm_config();
$regions = isset($opts['regions']) ? explode(',', $opts['regions']) : $config['gtfs_regions'];
$regions = array_values(array_filter(array_map('trim', $regions)));
foreach ($regions as $r) {
    if (!isset(MBM_REGIONS[$r])) {
        fwrite(STDERR, "Unknown region '$r'. Valid: " . implode(', ', array_keys(MBM_REGIONS)) . "\n");
        exit(1);
    }
}
if (!$regions) {
    fwrite(STDERR, "No regions configured (gtfs_regions in config.php)\n");
    exit(1);
}

$dataDir = mbm_data_dir();
$zipDir = $opts['zip-dir'] ?? $dataDir . '/zips';
$downloading = !isset($opts['zip-dir']);
if (!is_dir($zipDir) && !mkdir($zipDir, 0775, true)) {
    fwrite(STDERR, "Cannot create $zipDir\n");
    exit(1);
}

function say(string $msg): void
{
    static $t0 = null;
    $t0 ??= microtime(true);
    printf("[%6.1fs] %s\n", microtime(true) - $t0, $msg);
}

function download(string $region, string $dest): void
{
    $url = "https://data.bus-data.dft.gov.uk/timetable/download/gtfs-file/$region/";
    $tmp = "$dest.part";
    $fh = fopen($tmp, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 1800,
        CURLOPT_USERAGENT      => 'mybusmap-importer',
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if (!$ok) {
        @unlink($tmp);
        throw new RuntimeException("Download of $region failed: $err");
    }
    rename($tmp, $dest);
}

/** Iterate a CSV inside the zip as associative rows. */
function csv_rows(ZipArchive $zip, string $name): Generator
{
    $fh = $zip->getStream($name);
    if (!$fh) {
        return;
    }
    $head = fgetcsv($fh, 0, ',', '"', '');
    if (!$head) {
        return;
    }
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
    $cols = array_flip($head);
    while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($row === [null]) {
            continue;
        }
        yield [$cols, $row];
    }
    fclose($fh);
}

$newPath = "$dataDir/gtfs-new.sqlite";
@unlink($newPath);
$db = new PDO('sqlite:' . $newPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA journal_mode = OFF; PRAGMA synchronous = OFF; PRAGMA cache_size = -500000; PRAGMA temp_store = MEMORY');
$db->exec(<<<SQL
CREATE TABLE regions (
    code TEXT PRIMARY KEY, name TEXT, feed_version TEXT, imported_at INTEGER,
    min_lat REAL, min_lon REAL, max_lat REAL, max_lon REAL
);
CREATE TABLE agencies (id INTEGER PRIMARY KEY, noc TEXT UNIQUE, name TEXT);
CREATE TABLE routes (
    id INTEGER PRIMARY KEY, region TEXT, agency_id INTEGER,
    short_name TEXT, long_name TEXT, type INTEGER
);
CREATE TABLE stops (
    id INTEGER PRIMARY KEY, atco TEXT UNIQUE, platform TEXT, name TEXT, lat REAL, lon REAL,
    street TEXT, locality TEXT, search TEXT
);
CREATE VIRTUAL TABLE stops_rtree USING rtree(id, min_lat, max_lat, min_lon, max_lon);
CREATE TABLE services (id INTEGER PRIMARY KEY, days INTEGER, start_date INTEGER, end_date INTEGER);
CREATE TABLE service_dates (service_id INTEGER, date INTEGER, type INTEGER, PRIMARY KEY (service_id, date)) WITHOUT ROWID;
CREATE TABLE trips (
    id INTEGER PRIMARY KEY, route_id INTEGER, service_id INTEGER, headsign TEXT,
    direction INTEGER, first_stop INTEGER, first_dep INTEGER, last_arr INTEGER
);
CREATE TABLE stop_times (
    trip_id INTEGER, seq INTEGER, stop_id INTEGER, arr INTEGER, dep INTEGER, pickup INTEGER, dropoff INTEGER,
    PRIMARY KEY (trip_id, seq)
) WITHOUT ROWID;
CREATE TABLE route_shapes (route_id INTEGER, direction INTEGER, trips INTEGER, polyline TEXT);
CREATE TABLE route_stops (route_id INTEGER, stop_id INTEGER, PRIMARY KEY (route_id, stop_id)) WITHOUT ROWID;
SQL);

$agencyIds = [];   // noc => id
$stopIds = [];     // atco => id
$tripIds = [];     // gtfs trip_id => id (across all regions, to skip duplicates)
$nextRoute = $nextService = $nextTrip = 1;
$dayCols = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

try {
    foreach ($regions as $region) {
        $zipPath = "$zipDir/$region.zip";
        if ($downloading) {
            say("Downloading $region");
            download($region, $zipPath);
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Cannot open $zipPath");
        }
        say(sprintf('Importing %s (%.0f MB)', $region, filesize($zipPath) / 1e6));
        $db->beginTransaction();

        $feedVersion = '';
        foreach (csv_rows($zip, 'feed_info.txt') as [$c, $r]) {
            $feedVersion = $r[$c['feed_version']] ?? '';
        }

        // Agencies: GTFS agency_id is per-feed, the NOC is national.
        $agencyMap = [];
        $ins = $db->prepare('INSERT INTO agencies (noc, name) VALUES (?, ?)');
        foreach (csv_rows($zip, 'agency.txt') as [$c, $r]) {
            $noc = trim($r[$c['agency_noc']] ?? '') ?: $r[$c['agency_id']];
            if (!isset($agencyIds[$noc])) {
                $ins->execute([$noc, $r[$c['agency_name']]]);
                $agencyIds[$noc] = (int) $db->lastInsertId();
            }
            $agencyMap[$r[$c['agency_id']]] = $agencyIds[$noc];
        }

        $routeMap = [];
        $ins = $db->prepare('INSERT INTO routes (id, region, agency_id, short_name, long_name, type) VALUES (?, ?, ?, ?, ?, ?)');
        foreach (csv_rows($zip, 'routes.txt') as [$c, $r]) {
            $id = $nextRoute++;
            $routeMap[$r[$c['route_id']]] = $id;
            $ins->execute([$id, $region, $agencyMap[$r[$c['agency_id']]] ?? null,
                $r[$c['route_short_name']], $r[$c['route_long_name']] ?? '', (int) $r[$c['route_type']]]);
        }

        $ins = $db->prepare('INSERT INTO stops (atco, platform, name, lat, lon) VALUES (?, ?, ?, ?, ?)');
        $insTree = $db->prepare('INSERT INTO stops_rtree VALUES (?, ?, ?, ?, ?)');
        $stopCoords = [];
        foreach (csv_rows($zip, 'stops.txt') as [$c, $r]) {
            $atco = $r[$c['stop_id']];
            $lat = (float) $r[$c['stop_lat']];
            $lon = (float) $r[$c['stop_lon']];
            if (!isset($stopIds[$atco])) {
                if ($lat == 0.0 && $lon == 0.0) {
                    continue;
                }
                $ins->execute([$atco, $r[$c['platform_code']] ?? '', $r[$c['stop_name']], $lat, $lon]);
                $id = (int) $db->lastInsertId();
                $stopIds[$atco] = $id;
                $insTree->execute([$id, $lat, $lat, $lon, $lon]);
            }
            $stopCoords[$stopIds[$atco]] = [$lat, $lon];
        }

        $serviceMap = [];
        $ins = $db->prepare('INSERT INTO services VALUES (?, ?, ?, ?)');
        foreach (csv_rows($zip, 'calendar.txt') as [$c, $r]) {
            $days = 0;
            foreach ($dayCols as $bit => $col) {
                if ($r[$c[$col]] === '1') {
                    $days |= 1 << $bit;
                }
            }
            $id = $nextService++;
            $serviceMap[$r[$c['service_id']]] = $id;
            $ins->execute([$id, $days, (int) $r[$c['start_date']], (int) $r[$c['end_date']]]);
        }
        $ins = $db->prepare('INSERT OR REPLACE INTO service_dates VALUES (?, ?, ?)');
        foreach (csv_rows($zip, 'calendar_dates.txt') as [$c, $r]) {
            $sid = $r[$c['service_id']];
            if (!isset($serviceMap[$sid])) {   // service only defined by dates
                $serviceMap[$sid] = $nextService++;
                $db->prepare('INSERT INTO services VALUES (?, 0, 0, 0)')->execute([$serviceMap[$sid]]);
            }
            $ins->execute([$serviceMap[$sid], (int) $r[$c['date']], (int) $r[$c['exception_type']]]);
        }

        // Trips. Journeys crossing a region boundary appear in both feeds;
        // the first region imported wins.
        $regionTrips = [];     // trip id => true (trips owned by this region)
        $shapeUse = [];        // gtfs shape_id => [route|dir => count]
        $ins = $db->prepare('INSERT INTO trips (id, route_id, service_id, headsign, direction) VALUES (?, ?, ?, ?, ?)');
        $dupes = 0;
        foreach (csv_rows($zip, 'trips.txt') as [$c, $r]) {
            $gid = $r[$c['trip_id']];
            if (isset($tripIds[$gid])) {
                $dupes++;
                continue;
            }
            $route = $routeMap[$r[$c['route_id']]] ?? null;
            $service = $serviceMap[$r[$c['service_id']]] ?? null;
            if ($route === null || $service === null) {
                continue;
            }
            $id = $nextTrip++;
            $tripIds[$gid] = $id;
            $regionTrips[$id] = true;
            $dir = (int) ($r[$c['direction_id']] ?? 0);
            $ins->execute([$id, $route, $service, $r[$c['trip_headsign']] ?? '', $dir]);
            $shape = $r[$c['shape_id']] ?? '';
            if ($shape !== '') {
                $key = "$route|$dir";
                $shapeUse[$shape][$key] = ($shapeUse[$shape][$key] ?? 0) + 1;
            }
        }
        say(sprintf('  %d trips (%d duplicates from earlier regions skipped)', count($regionTrips), $dupes));

        $ins = $db->prepare('INSERT OR IGNORE INTO stop_times VALUES (?, ?, ?, ?, ?, ?, ?)');
        $first = [];   // trip id => [seq, stop, dep]
        $last = [];    // trip id => [seq, arr]
        $usedStops = [];
        $n = 0;
        foreach (csv_rows($zip, 'stop_times.txt') as [$c, $r]) {
            $trip = $tripIds[$r[$c['trip_id']]] ?? null;
            if ($trip === null || !isset($regionTrips[$trip])) {
                continue;
            }
            $stop = $stopIds[$r[$c['stop_id']]] ?? null;
            if ($stop === null) {
                continue;
            }
            $seq = (int) $r[$c['stop_sequence']];
            $arr = mbm_parse_time($r[$c['arrival_time']]);
            $dep = mbm_parse_time($r[$c['departure_time']]);
            $arr ??= $dep;
            $dep ??= $arr;
            $ins->execute([$trip, $seq, $stop, $arr, $dep,
                (int) ($r[$c['pickup_type']] ?? 0), (int) ($r[$c['drop_off_type']] ?? 0)]);
            $usedStops[$stop] = true;
            if (!isset($first[$trip]) || $seq < $first[$trip][0]) {
                $first[$trip] = [$seq, $stop, $dep];
            }
            if (!isset($last[$trip]) || $seq > $last[$trip][0]) {
                $last[$trip] = [$seq, $arr];
            }
            if (++$n % 1000000 === 0) {
                say(sprintf('  %dM stop times', $n / 1000000));
            }
        }
        $upd = $db->prepare('UPDATE trips SET first_stop = ?, first_dep = ?, last_arr = ? WHERE id = ?');
        foreach ($first as $trip => [, $stop, $dep]) {
            $upd->execute([$stop, $dep, $last[$trip][1], $trip]);
        }
        say("  $n stop times");

        // Keep one representative shape per route+direction: the most used.
        $best = [];   // route|dir => [shape, count]
        foreach ($shapeUse as $shape => $uses) {
            foreach ($uses as $key => $count) {
                if (!isset($best[$key]) || $count > $best[$key][1]) {
                    $best[$key] = [$shape, $count];
                }
            }
        }
        $wanted = [];   // gtfs shape_id => [route|dir => count]
        foreach ($best as $key => [$shape, $count]) {
            $wanted[$shape][$key] = $count;
        }
        unset($shapeUse);
        $ins = $db->prepare('INSERT INTO route_shapes VALUES (?, ?, ?, ?)');
        $store = function (string $shape, array $pts) use ($wanted, $ins): void {
            ksort($pts);
            $line = mbm_polyline_encode(mbm_simplify(array_values($pts), 0.00004));
            foreach ($wanted[$shape] as $key => $count) {
                [$route, $dir] = explode('|', $key);
                $ins->execute([(int) $route, (int) $dir, $count, $line]);
            }
        };
        $cur = null;
        $pts = [];
        foreach (csv_rows($zip, 'shapes.txt') as [$c, $r]) {
            $shape = $r[$c['shape_id']];
            if (!isset($wanted[$shape])) {
                continue;
            }
            if ($shape !== $cur) {
                if ($cur !== null) {
                    $store($cur, $pts);
                }
                $cur = $shape;
                $pts = [];
            }
            $pts[(int) $r[$c['shape_pt_sequence']]] = [(float) $r[$c['shape_pt_lat']], (float) $r[$c['shape_pt_lon']]];
        }
        if ($cur !== null) {
            $store($cur, $pts);
        }

        // Region extent, trimming outlying stops (long-distance coaches etc).
        $lats = $lons = [];
        foreach ($usedStops as $id => $_) {
            if (isset($stopCoords[$id])) {
                $lats[] = $stopCoords[$id][0];
                $lons[] = $stopCoords[$id][1];
            }
        }
        sort($lats);
        sort($lons);
        $pct = fn(array $a, float $p) => $a ? $a[(int) floor((count($a) - 1) * $p)] : 0;
        $db->prepare('INSERT INTO regions VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $region, MBM_REGIONS[$region], $feedVersion, time(),
            $pct($lats, 0.01), $pct($lons, 0.01), $pct($lats, 0.99), $pct($lons, 0.99),
        ]);

        $db->commit();
        $zip->close();
        if ($downloading && !isset($opts['keep-zips'])) {
            unlink($zipPath);
        }
        unset($first, $last, $usedStops, $stopCoords, $regionTrips);
    }

    // Routes rarely have a long name in BODS data; describe them by the
    // most common destinations in each direction instead.
    say('Naming routes');
    $heads = [];
    foreach ($db->query("SELECT route_id, direction, headsign, COUNT(*) AS n FROM trips WHERE headsign != ''
                         GROUP BY route_id, direction, headsign ORDER BY n DESC") as $r) {
        $heads[$r['route_id']][$r['direction']] ??= $r['headsign'];
    }
    $upd = $db->prepare("UPDATE routes SET long_name = ? WHERE id = ? AND long_name = ''");
    $db->beginTransaction();
    foreach ($heads as $route => $dirs) {
        ksort($dirs);
        $upd->execute([implode(' – ', array_unique($dirs)), $route]);
    }
    $db->commit();

    say('Adding stop details from NaPTAN');
    try {
        mbm_naptan_enrich($db, 'say');
    } catch (Throwable $e) {
        // Not worth failing the import over: stops stay searchable by name.
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        say('  Skipped: ' . $e->getMessage());
        $db->exec('UPDATE stops SET search = lower(name) WHERE search IS NULL');
    }

    say('Building indexes');
    $db->exec(<<<SQL
CREATE INDEX stop_times_stop ON stop_times (stop_id, dep);
CREATE INDEX trips_first ON trips (first_stop, first_dep);
CREATE INDEX trips_route ON trips (route_id);
CREATE INDEX routes_name ON routes (agency_id, short_name);
CREATE INDEX route_shapes_route ON route_shapes (route_id);
CREATE INDEX stops_name ON stops (name COLLATE NOCASE);
INSERT INTO route_stops SELECT DISTINCT t.route_id, st.stop_id FROM stop_times st JOIN trips t ON t.id = st.trip_id;
CREATE INDEX route_stops_stop ON route_stops (stop_id);
ANALYZE;
SQL);

    // Some operators publish no shapes; draw those routes stop to stop,
    // using the longest journey from the most common starting point.
    say('Filling in missing route shapes');
    $missing = $db->query(
        'SELECT DISTINCT t.route_id, t.direction FROM trips t
         WHERE NOT EXISTS (SELECT 1 FROM route_shapes s WHERE s.route_id = t.route_id AND s.direction = t.direction)'
    )->fetchAll(PDO::FETCH_NUM);
    $pick = $db->prepare(
        'SELECT t.id, (SELECT COUNT(*) FROM stop_times st WHERE st.trip_id = t.id) AS n,
                (SELECT COUNT(*) FROM trips u WHERE u.route_id = t.route_id AND u.direction = t.direction AND u.first_stop = t.first_stop) AS starts
         FROM trips t WHERE t.route_id = ? AND t.direction = ? ORDER BY starts DESC, n DESC LIMIT 1'
    );
    $path = $db->prepare('SELECT s.lat, s.lon FROM stop_times st JOIN stops s ON s.id = st.stop_id WHERE st.trip_id = ? ORDER BY st.seq');
    $count = $db->prepare('SELECT COUNT(*) FROM trips WHERE route_id = ? AND direction = ?');
    $ins = $db->prepare('INSERT INTO route_shapes VALUES (?, ?, ?, ?)');
    $db->beginTransaction();
    foreach ($missing as [$route, $dir]) {
        $pick->execute([$route, $dir]);
        $trip = $pick->fetchColumn();
        $path->execute([$trip]);
        $pts = array_map(fn($p) => [(float) $p[0], (float) $p[1]], $path->fetchAll(PDO::FETCH_NUM));
        if (count($pts) >= 2) {
            $count->execute([$route, $dir]);
            $ins->execute([$route, $dir, (int) $count->fetchColumn(), mbm_polyline_encode($pts)]);
        }
    }
    $db->commit();
    say(sprintf('  %d routes drawn from stops', count($missing)));

    $db->exec('PRAGMA journal_mode = DELETE');
    $db = null;
    chmod($newPath, 0664);
    rename($newPath, mbm_db_path());
    say('Done: ' . mbm_db_path() . sprintf(' (%.0f MB)', filesize(mbm_db_path()) / 1e6));
} catch (Throwable $e) {
    $db = null;
    @unlink($newPath);
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
