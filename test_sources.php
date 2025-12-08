<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'libs/cached_sources.php';

$rdKey = $GLOBALS['PRIVATE_TOKEN'] ?? '';
echo "RD Key: " . (empty($rdKey) ? 'EMPTY' : 'SET (' . substr($rdKey, 0, 10) . '...)') . PHP_EOL;

// Test with Stargate SG-1
$imdbId = 'tt0118480';
echo PHP_EOL . "Testing IMDB ID: $imdbId (Stargate SG-1)" . PHP_EOL;

// Check the index directly
$indexFile = __DIR__ . '/imdb_index.json';
echo "Index file: $indexFile" . PHP_EOL;
echo "Index exists: " . (file_exists($indexFile) ? 'yes' : 'no') . PHP_EOL;

$index = json_decode(file_get_contents($indexFile), true);
if (isset($index[$imdbId])) {
    echo "Index entries for $imdbId: " . count($index[$imdbId]) . PHP_EOL;
    echo "First entry: " . print_r($index[$imdbId][0], true);
    
    // Check RD availability for first hash
    $hash = $index[$imdbId][0]['h'];
    echo PHP_EOL . "Checking RD availability for hash: $hash" . PHP_EOL;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.real-debrid.com/rest/1.0/torrents/instantAvailability/{$hash}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$rdKey}"
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: $httpCode" . PHP_EOL;
    echo "Response: " . substr($response, 0, 500) . PHP_EOL;
} else {
    echo "IMDB ID not found in index" . PHP_EOL;
}

echo PHP_EOL . "Now testing with CachedStreamSources class..." . PHP_EOL;
$finder = new CachedStreamSources($rdKey);
$sources = $finder->getCachedSources($imdbId, 'series', 5, 10);
echo "Cached sources found: " . count($sources) . PHP_EOL;

if (!empty($sources)) {
    echo PHP_EOL . "Top 5 sources:" . PHP_EOL;
    foreach (array_slice($sources, 0, 5) as $i => $source) {
        echo ($i+1) . ". [{$source['quality']}] {$source['title']}" . PHP_EOL;
        echo "   Source: {$source['source']}, Size: " . CachedStreamSources::formatBytes($source['bytes']) . PHP_EOL;
        echo "   Hash: {$source['hash']}" . PHP_EOL . PHP_EOL;
    }
}
