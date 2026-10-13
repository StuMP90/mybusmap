<?php
/**
 * Adds NaPTAN details to the stops table. GTFS gives each stop a single name
 * (often the nearest side road), but NaPTAN also records the street it is
 * on, its locality and the indicator painted on the flag ("opp", "Stand C").
 * These make stops findable by the road they are on and tell apart the two
 * stops either side of a road.
 */
declare(strict_types=1);

const MBM_NAPTAN_URL = 'https://naptan.api.dft.gov.uk/v1/access-nodes?dataFormat=csv&atcoAreaCodes=';

/** Adds the street/locality/search columns if this database predates them. */
function mbm_naptan_columns(PDO $db): void
{
    $have = array_column($db->query('PRAGMA table_info(stops)')->fetchAll(), 'name');
    foreach (['street', 'locality', 'search'] as $col) {
        if (!in_array($col, $have, true)) {
            $db->exec("ALTER TABLE stops ADD COLUMN $col TEXT");
        }
    }
}

/**
 * Fill in NaPTAN details for every stop. Stops NaPTAN does not know about
 * still become searchable by name. $say receives progress messages.
 */
function mbm_naptan_enrich(PDO $db, callable $say): void
{
    mbm_naptan_columns($db);
    $areas = $db->query('SELECT DISTINCT substr(atco, 1, 3) FROM stops')->fetchAll(PDO::FETCH_COLUMN);
    $areas = array_values(array_filter($areas, fn($a) => preg_match('/^\d{3}$/', $a)));

    $upd = $db->prepare(
        "UPDATE stops SET street = ?, locality = ?, search = ?,
                platform = CASE WHEN platform = '' OR platform IS NULL THEN ? ELSE platform END
         WHERE atco = ?"
    );
    $found = 0;
    $chunks = array_chunk($areas, 10);
    while ($chunk = array_shift($chunks)) {
        try {
            $fh = mbm_naptan_fetch($chunk);
        } catch (RuntimeException $e) {
            // One unknown area code fails the whole request: retry singly.
            if (count($chunk) > 1) {
                array_unshift($chunks, ...array_map(fn($a) => [$a], $chunk));
            } else {
                $say("  NaPTAN area {$chunk[0]} skipped: " . $e->getMessage());
            }
            continue;
        }
        $say('  NaPTAN areas ' . implode(',', $chunk));
        $head = fgetcsv($fh, 0, ',', '"', '');
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $head[0]);
        $c = array_flip($head);
        foreach (['ATCOCode', 'CommonName', 'Street', 'Indicator', 'Landmark', 'LocalityName'] as $col) {
            if (!isset($c[$col])) {
                throw new RuntimeException("NaPTAN CSV has no $col column");
            }
        }
        $db->beginTransaction();
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (count($r) < count($head)) {
                continue;
            }
            $street = mbm_naptan_clean($r[$c['Street']]);
            $locality = mbm_naptan_clean($r[$c['LocalityName']]);
            $landmark = mbm_naptan_clean($r[$c['Landmark']]);
            $indicator = mbm_naptan_clean($r[$c['Indicator']]);
            $search = mbm_search_norm(implode(' ', [$r[$c['CommonName']], $street, $landmark, $locality]));
            $upd->execute([$street, $locality, $search, mb_substr($indicator, 0, 16), $r[$c['ATCOCode']]]);
            $found += $upd->rowCount();
        }
        $db->commit();
        fclose($fh);
    }
    // Anything NaPTAN did not cover is still searchable by its GTFS name.
    $rest = $db->query('SELECT id, name FROM stops WHERE search IS NULL')->fetchAll(PDO::FETCH_NUM);
    $set = $db->prepare('UPDATE stops SET search = ? WHERE id = ?');
    $db->beginTransaction();
    foreach ($rest as [$id, $name]) {
        $set->execute([mbm_search_norm((string) $name), $id]);
    }
    $db->commit();
    $say(sprintf('  %d stops matched in NaPTAN, %d without', $found, count($rest)));
}

/** NaPTAN uses "-" and similar placeholders for "none". */
function mbm_naptan_clean(string $v): string
{
    $v = trim($v);
    return in_array(strtolower($v), ['', '-', '--', 'n/a', 'na', 'unknown', '*'], true) ? '' : $v;
}

/** @return resource a stream of the CSV, downloaded to a temporary file */
function mbm_naptan_fetch(array $areas)
{
    $fh = fopen('php://temp/maxmemory:' . (8 << 20), 'w+b');
    $ch = curl_init(MBM_NAPTAN_URL . implode(',', $areas));
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_USERAGENT      => 'mybusmap',
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$ok) {
        throw new RuntimeException("NaPTAN download failed: $err");
    }
    rewind($fh);
    return $fh;
}
