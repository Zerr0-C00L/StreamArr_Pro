<?php
/**
 * Sync episode cache by calling get_series_info for all series in playlist
 * This pre-populates the episode lookup cache so playback works immediately
 * 
 * Run with: php sync_github_cache.php [start_index]
 * Or runs automatically after playlist generation
 */

set_time_limit(0);

require_once __DIR__ . '/../config.php';

$startIndex = isset($argv[1]) ? (int)$argv[1] : 0;

echo "=== Syncing Episode Cache ===\n";

$localPlaylist = __DIR__ . "/../tv_playlist.json";

// Use local playlist (populated by MDBList)
if (file_exists($localPlaylist)) {
    echo "Using local playlist: $localPlaylist\n";
    $tvData = file_get_contents($localPlaylist);
} else {
    die("ERROR: Local playlist not found. Sync MDBList first.\n");
}

if (!$tvData) {
    die("ERROR: Could not load TV playlist\n");
}

$tvSeries = json_decode($tvData, true);
if (!$tvSeries) {
    die("ERROR: Invalid JSON in TV playlist\n");
}

$total = count($tvSeries);
echo "Found $total series\n";

// Load existing cache to skip already-cached series
$cacheFile = __DIR__ . "/../cache/episode_lookup.json";
$existingCache = [];
if (file_exists($cacheFile)) {
    $existingCache = json_decode(file_get_contents($cacheFile), true) ?? [];
}
echo "Existing cache has " . count($existingCache) . " episodes\n";

// Build set of series IDs that are already cached
$cachedSeriesIds = [];
foreach ($existingCache as $epData) {
    $cachedSeriesIds[$epData['series_id']] = true;
}
echo "Already cached series: " . count($cachedSeriesIds) . "\n";

echo "Starting from index: $startIndex\n\n";

// Server URL
$serverUrl = "http://127.0.0.1:8080";

$processed = 0;
$skipped = 0;
$errors = 0;

for ($i = $startIndex; $i < $total; $i++) {
    $series = $tvSeries[$i];
    $seriesId = $series['series_id'] ?? null;
    $seriesName = $series['name'] ?? 'Unknown';
    
    if (!$seriesId) continue;
    
    // Skip if already cached
    if (isset($cachedSeriesIds[(string)$seriesId])) {
        $skipped++;
        continue;
    }
    
    // Call our own get_series_info API (this populates the cache)
    $url = "{$serverUrl}/player_api.php?action=get_series_info&series_id={$seriesId}&username=test&password=test";
    
    $ctx = stream_context_create(['http' => ['timeout' => 30]]);
    $result = @file_get_contents($url, false, $ctx);
    
    $processed++;
    
    if ($result === false) {
        $errors++;
        echo "[$i/$total] ERROR: $seriesName (ID: $seriesId)\n";
    } else {
        if ($processed % 100 == 0) {
            // Check cache size
            $cacheData = json_decode(file_get_contents($cacheFile), true) ?? [];
            $cacheSize = count($cacheData);
            echo "[$i/$total] Processed $processed, Skipped $skipped, Cache: $cacheSize episodes\n";
        }
    }
    
    // Small delay to avoid overwhelming the server
    usleep(100000); // 100ms = ~10 requests/second
}

// Final stats
$cacheData = json_decode(file_get_contents($cacheFile), true) ?? [];
$finalCacheSize = count($cacheData);

echo "\n=== SYNC COMPLETE ===\n";
echo "Series processed: $processed\n";
echo "Series skipped (already cached): $skipped\n";
echo "Errors: $errors\n";
echo "Episodes in cache: $finalCacheSize\n";

