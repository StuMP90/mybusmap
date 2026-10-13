<?php
/**
 * Shared setup for the API and CLI tools. Nothing in lib/ is web-accessible.
 */
declare(strict_types=1);

date_default_timezone_set('Europe/London');

const MBM_REGIONS = [
    'east_anglia'   => 'East Anglia',
    'east_midlands' => 'East Midlands',
    'london'        => 'London',
    'north_east'    => 'North East',
    'north_west'    => 'North West',
    'scotland'      => 'Scotland',
    'south_east'    => 'South East',
    'south_west'    => 'South West',
    'wales'         => 'Wales',
    'west_midlands' => 'West Midlands',
    'yorkshire'     => 'Yorkshire',
];

function mbm_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = dirname(__DIR__) . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('config.php not found - copy config.php.example to config.php');
        }
        $config = require $file;
        $config += [
            'bods_api_key'   => '',
            'gtfs_regions'   => [],
            'data_dir'       => dirname(__DIR__) . '/data',
            'live_cache_ttl' => 10,
            'max_live_bbox'  => 0.05,
            'defaults'       => [],
            'access_names'   => [],
            'access_secret'  => '',
        ];
    }
    return $config;
}

function mbm_data_dir(): string
{
    return rtrim(mbm_config()['data_dir'], '/');
}

function mbm_db_path(): string
{
    return mbm_data_dir() . '/gtfs.sqlite';
}

/** Read-only connection to the imported timetable database. */
function mbm_db(): PDO
{
    static $db = null;
    if ($db === null) {
        $path = mbm_db_path();
        if (!is_file($path)) {
            throw new RuntimeException('Timetable database missing - run bin/import-gtfs.php');
        }
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLITE_ATTR_OPEN_FLAGS  => PDO::SQLITE_OPEN_READONLY,
        ]);
        $db->exec('PRAGMA temp_store = MEMORY');
    }
    return $db;
}

/** Great-circle distance in metres. */
function mbm_distance(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/** Degrees of latitude/longitude covering $metres around a latitude. */
function mbm_degrees(float $metres, float $lat): array
{
    return [$metres / 111320.0, $metres / (111320.0 * cos(deg2rad($lat)))];
}

/** Encode [[lat, lon], ...] as a Google encoded polyline (precision 5). */
function mbm_polyline_encode(array $points): string
{
    $out = '';
    $pLat = 0;
    $pLon = 0;
    foreach ($points as [$lat, $lon]) {
        $iLat = (int) round($lat * 1e5);
        $iLon = (int) round($lon * 1e5);
        foreach ([$iLat - $pLat, $iLon - $pLon] as $v) {
            $v = $v < 0 ? ~($v << 1) : ($v << 1);
            while ($v >= 0x20) {
                $out .= chr((0x20 | ($v & 0x1f)) + 63);
                $v >>= 5;
            }
            $out .= chr($v + 63);
        }
        $pLat = $iLat;
        $pLon = $iLon;
    }
    return $out;
}

/** Douglas-Peucker line simplification on [[lat, lon], ...]; $tolerance in degrees. */
function mbm_simplify(array $points, float $tolerance): array
{
    $n = count($points);
    if ($n < 3) {
        return $points;
    }
    $keep = array_fill(0, $n, false);
    $keep[0] = $keep[$n - 1] = true;
    $stack = [[0, $n - 1]];
    $tol2 = $tolerance * $tolerance;
    while ($stack) {
        [$first, $last] = array_pop($stack);
        [$ax, $ay] = $points[$first];
        [$bx, $by] = $points[$last];
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len2 = $dx * $dx + $dy * $dy;
        $maxD = 0.0;
        $idx = 0;
        for ($i = $first + 1; $i < $last; $i++) {
            [$px, $py] = $points[$i];
            if ($len2 == 0.0) {
                $d = ($px - $ax) ** 2 + ($py - $ay) ** 2;
            } else {
                $t = max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2));
                $d = ($px - $ax - $t * $dx) ** 2 + ($py - $ay - $t * $dy) ** 2;
            }
            if ($d > $maxD) {
                $maxD = $d;
                $idx = $i;
            }
        }
        if ($maxD > $tol2) {
            $keep[$idx] = true;
            $stack[] = [$first, $idx];
            $stack[] = [$idx, $last];
        }
    }
    $out = [];
    foreach ($points as $i => $p) {
        if ($keep[$i]) {
            $out[] = $p;
        }
    }
    return $out;
}

/** "HH:MM:SS" (may exceed 24h) to seconds; null if blank. */
function mbm_parse_time(string $t): ?int
{
    if ($t === '') {
        return null;
    }
    $p = explode(':', $t);
    return ((int) $p[0]) * 3600 + ((int) ($p[1] ?? 0)) * 60 + (int) ($p[2] ?? 0);
}

/**
 * Lower-case words for stop search, with common street abbreviations spelt
 * out, so "Balmoral Rd" and "balmoral road" match the same stops.
 */
function mbm_search_norm(string $text): string
{
    static $abbr = [
        'rd' => 'road', 'st' => 'street', 'ave' => 'avenue', 'av' => 'avenue', 'ln' => 'lane',
        'dr' => 'drive', 'cl' => 'close', 'cres' => 'crescent', 'gdns' => 'gardens', 'gr' => 'grove',
        'pk' => 'park', 'pl' => 'place', 'sq' => 'square', 'ter' => 'terrace', 'terr' => 'terrace',
        'hosp' => 'hospital', 'stn' => 'station', 'opp' => 'opposite', 'nr' => 'near', 'ctr' => 'centre',
        'center' => 'centre', 'int' => 'interchange',
    ];
    $text = mb_strtolower(str_replace('&', ' and ', $text));
    $text = preg_replace("/['\x{2019}.]/u", '', $text);
    $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return implode(' ', array_map(fn($w) => $abbr[$w] ?? $w, $words));
}
