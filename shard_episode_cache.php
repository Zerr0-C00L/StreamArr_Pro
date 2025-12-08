<?php
/**
 * Convert the large episode_lookup.json into sharded files
 * Shards are based on the first 2 digits of the episode ID
 * This keeps each shard small enough to load quickly
 */

ini_set('memory_limit', '1G');
error_reporting(E_ALL);

$cacheDir = __DIR__ . '/cache';
$shardDir = $cacheDir . '/episode_shards';
$sourceFile = $cacheDir . '/episode_lookup.json';

if (!file_exists($sourceFile)) {
    die("Error: episode_lookup.json not found\n");
}

// Create shard directory
if (!is_dir($shardDir)) {
    mkdir($shardDir, 0755, true);
}

echo "Loading episode cache...\n";
$jsonContent = file_get_contents($sourceFile);
$fileSize = strlen($jsonContent);
echo "File size: " . round($fileSize / 1024 / 1024, 2) . " MB\n";

// Parse JSON in streaming fashion to avoid memory issues
// Strategy: read key-value pairs one at a time

$shards = [];
$count = 0;
$totalShards = 0;

// Use regex to extract episode entries
// Format: "episodeId":{"series_id":"...","season":X,"episode":Y,"imdb_id":"..."}
preg_match_all('/"(\d+)":\{"series_id":"(\d+)","season":(\d+),"episode":(\d+),"imdb_id":"([^"]*)"\}/', $jsonContent, $matches, PREG_SET_ORDER);

echo "Found " . count($matches) . " episodes\n";

foreach ($matches as $match) {
    $episodeId = $match[1];
    $seriesId = $match[2];
    $season = (int)$match[3];
    $episode = (int)$match[4];
    $imdbId = $match[5];
    
    // Shard key = first 2 characters of episode ID (padded)
    $shardKey = substr(str_pad($episodeId, 7, '0', STR_PAD_LEFT), 0, 2);
    
    if (!isset($shards[$shardKey])) {
        $shards[$shardKey] = [];
    }
    
    $shards[$shardKey][$episodeId] = [
        'series_id' => $seriesId,
        'season' => $season,
        'episode' => $episode,
        'imdb_id' => $imdbId
    ];
    
    $count++;
    
    if ($count % 50000 === 0) {
        echo "Processed $count episodes...\n";
    }
}

// Free memory
unset($jsonContent);
unset($matches);

echo "\nWriting shards...\n";

// Write each shard to a separate file
foreach ($shards as $shardKey => $data) {
    $shardFile = $shardDir . "/shard_{$shardKey}.json";
    $json = json_encode($data);
    file_put_contents($shardFile, $json);
    
    $shardSize = strlen($json);
    $entryCount = count($data);
    echo "  Shard {$shardKey}: {$entryCount} entries, " . round($shardSize / 1024, 1) . " KB\n";
    $totalShards++;
}

echo "\n========================================\n";
echo "Sharding complete!\n";
echo "Total episodes: $count\n";
echo "Total shards: $totalShards\n";
echo "Shard directory: $shardDir\n";
echo "========================================\n";

// Create an index file with shard metadata
$indexData = [
    'total_episodes' => $count,
    'total_shards' => $totalShards,
    'created' => date('Y-m-d H:i:s'),
    'shards' => array_keys($shards)
];
file_put_contents($shardDir . '/index.json', json_encode($indexData, JSON_PRETTY_PRINT));
echo "\nIndex written to: $shardDir/index.json\n";
