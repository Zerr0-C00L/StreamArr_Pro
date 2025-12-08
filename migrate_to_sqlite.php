<?php
/**
 * Migrate episode cache from JSON to SQLite
 * Uses streaming to avoid memory issues
 */

ini_set('memory_limit', '256M');

$jsonFile = __DIR__ . '/cache/episode_lookup.json';
$dbFile = __DIR__ . '/cache/episodes.db';

echo "=== Episode Cache Migration: JSON → SQLite ===\n\n";

// Remove old DB if exists
if (file_exists($dbFile)) {
    echo "Removing old database...\n";
    unlink($dbFile);
}

// Create database directly
$db = new SQLite3($dbFile);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA synchronous = OFF'); // Faster for bulk import

// Create tables
$db->exec('
    CREATE TABLE IF NOT EXISTS episodes (
        episode_id INTEGER PRIMARY KEY,
        series_id INTEGER NOT NULL,
        season INTEGER NOT NULL,
        episode INTEGER NOT NULL,
        imdb_id TEXT
    )
');
$db->exec('CREATE INDEX IF NOT EXISTS idx_series ON episodes(series_id)');

$db->exec('
    CREATE TABLE IF NOT EXISTS series (
        series_id INTEGER PRIMARY KEY,
        name TEXT,
        imdb_id TEXT,
        episode_count INTEGER DEFAULT 0,
        cached_at INTEGER
    )
');

echo "Reading JSON file in chunks...\n";
$fileSize = filesize($jsonFile);
echo "File size: " . round($fileSize / 1024 / 1024, 2) . " MB\n";

$handle = fopen($jsonFile, 'r');
if (!$handle) {
    die("Error: Cannot open $jsonFile\n");
}

// Read file in chunks and extract episodes using regex
$chunkSize = 4 * 1024 * 1024; // 4MB chunks
$buffer = '';
$count = 0;
$seriesIds = [];

// Prepare statement
$stmt = $db->prepare('
    INSERT OR REPLACE INTO episodes (episode_id, series_id, season, episode, imdb_id)
    VALUES (:ep_id, :series_id, :season, :episode, :imdb_id)
');

$db->exec('BEGIN TRANSACTION');

$bytesRead = 0;
while (!feof($handle)) {
    $chunk = fread($handle, $chunkSize);
    $bytesRead += strlen($chunk);
    $buffer .= $chunk;
    
    // Find complete episodes in buffer
    // Pattern: "episodeId":{"series_id":"X","season":Y,"episode":Z,"imdb_id":"..."}
    while (preg_match('/"(\d+)":\{"series_id":"(\d+)","season":(\d+),"episode":(\d+),"imdb_id":"([^"]*)"\}/', $buffer, $match, PREG_OFFSET_CAPTURE)) {
        $epId = $match[1][0];
        $seriesId = $match[2][0];
        $season = $match[3][0];
        $episode = $match[4][0];
        $imdbId = $match[5][0];
        
        $stmt->bindValue(':ep_id', (int)$epId, SQLITE3_INTEGER);
        $stmt->bindValue(':series_id', (int)$seriesId, SQLITE3_INTEGER);
        $stmt->bindValue(':season', (int)$season, SQLITE3_INTEGER);
        $stmt->bindValue(':episode', (int)$episode, SQLITE3_INTEGER);
        $stmt->bindValue(':imdb_id', $imdbId, SQLITE3_TEXT);
        $stmt->execute();
        $stmt->reset();
        
        $seriesIds[$seriesId] = true;
        $count++;
        
        // Remove processed part from buffer
        $endPos = $match[0][1] + strlen($match[0][0]);
        $buffer = substr($buffer, $endPos);
    }
    
    // Keep only last part of buffer (might have incomplete match)
    if (strlen($buffer) > 500) {
        $buffer = substr($buffer, -500);
    }
    
    // Progress
    $pct = round($bytesRead / $fileSize * 100);
    echo "\r  Progress: $pct% | Episodes: " . number_format($count) . " | Series: " . count($seriesIds);
}

fclose($handle);
echo "\n";

// Mark all series as cached
echo "Marking series as cached...\n";
$seriesStmt = $db->prepare('INSERT OR IGNORE INTO series (series_id, cached_at) VALUES (:id, :time)');
foreach (array_keys($seriesIds) as $sid) {
    $seriesStmt->bindValue(':id', (int)$sid, SQLITE3_INTEGER);
    $seriesStmt->bindValue(':time', time(), SQLITE3_INTEGER);
    $seriesStmt->execute();
    $seriesStmt->reset();
}

$db->exec('COMMIT');

// Set back to normal sync mode
$db->exec('PRAGMA synchronous = NORMAL');

echo "\n=== Results ===\n";
echo "Episodes imported: " . number_format($count) . "\n";
echo "Series tracked: " . number_format(count($seriesIds)) . "\n";
echo "Database file: $dbFile\n";
echo "Database size: " . round(filesize($dbFile) / 1024 / 1024, 2) . " MB\n";

// Test lookups
echo "\n=== Testing Lookups ===\n";

$testStmt = $db->prepare('SELECT series_id, season, episode, imdb_id FROM episodes WHERE episode_id = :id');

// Test Simpsons episode
$testStmt->bindValue(':id', 62228, SQLITE3_INTEGER);
$result = $testStmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
$testStmt->reset();

if ($row) {
    echo "✅ Simpsons S1E1 (62228): series={$row['series_id']}, S{$row['season']}E{$row['episode']}, imdb={$row['imdb_id']}\n";
} else {
    echo "❌ Simpsons episode not found\n";
}

// Test Stargate SG-1 episode
$testStmt->bindValue(':id', 336043, SQLITE3_INTEGER);
$result = $testStmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);

if ($row) {
    echo "✅ Stargate SG-1 S1E1 (336043): series={$row['series_id']}, S{$row['season']}E{$row['episode']}, imdb={$row['imdb_id']}\n";
} else {
    echo "❌ Stargate episode not found\n";
}

$db->close();

echo "\n✅ Migration complete! The episode cache now uses SQLite.\n";
