<?php
/**
 * Refresh stop streets, localities and indicators from NaPTAN in the existing
 * timetable database, without re-importing timetables. import-gtfs.php does
 * this itself; this is for updating a database built before it did.
 *
 * Usage: php bin/import-naptan.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/naptan.php';

try {
    $db = new PDO('sqlite:' . mbm_db_path(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout = 10000');
    mbm_naptan_enrich($db, fn(string $m) => print($m . "\n"));
} catch (Throwable $e) {
    fwrite(STDERR, 'NaPTAN update failed: ' . $e->getMessage() . "\n");
    exit(1);
}
