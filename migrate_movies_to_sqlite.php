<?php
/**
 * Migrate movies from playlist.json to SQLite database
 * Uses streaming parsing to avoid memory issues with large JSON files
 */

require_once __DIR__ . '/libs/episode_cache_db.php';

echo "=== Movie Migration to SQLite ===\n\n";

$playlistFile = __DIR__ . '/playlist.json';

if (!file_exists($playlistFile)) {
    die("Error: playlist.json not found\n");
}

echo "Reading playlist.json...\n";
$fileSize = filesize($playlistFile);
echo "File size: " . round($fileSize / 1024 / 1024, 2) . " MB\n";

// Use streaming approach with regex to extract movies
$handle = fopen($playlistFile, 'r');
if (!$handle) {
    die("Error: Could not open playlist.json\n");
}

// Read in chunks and extract movies using regex
$buffer = '';
$movies = [];
$chunkSize = 1024 * 1024; // 1MB chunks

echo "Extracting movies using streaming parser...\n";

while (!feof($handle)) {
    $buffer .= fread($handle, $chunkSize);
    
    // Find complete movie objects: {"num":...,"stream_id":XXX,"name":"..."}
    // Match stream_id and name from each movie object
    while (preg_match('/\{"num":\d+,"name":"([^"]+)","stream_type":"movie","stream_id":(\d+)/', $buffer, $match, PREG_OFFSET_CAPTURE)) {
        $name = $match[1][0];
        $streamId = (int)$match[2][0];
        
        // Extract year from name like "Movie Title (2025)"
        $year = 0;
        if (preg_match('/\((\d{4})\)/', $name, $yearMatch)) {
            $year = (int)$yearMatch[1];
        }
        
        $movies[$streamId] = [
            'title' => stripcslashes($name),
            'imdb_id' => '',
            'year' => $year
        ];
        
        // Remove processed part from buffer
        $buffer = substr($buffer, $match[0][1] + strlen($match[0][0]));
    }
    
    // Keep last 1000 chars for partial matches at chunk boundaries
    if (strlen($buffer) > 1000) {
        $buffer = substr($buffer, -1000);
    }
}

fclose($handle);

echo "Found " . count($movies) . " movies\n";

if (count($movies) === 0) {
    die("Error: No movies found in playlist.json\n");
}

// Connect to database
$cache = new EpisodeCacheDB();

// Bulk import movies
echo "Importing to SQLite...\n";
$count = $cache->bulkInsertMovies($movies);

echo "Imported $count movies to SQLite\n";

// Show stats
$stats = $cache->getStats();
echo "\n=== Database Stats ===\n";
echo "Episodes: {$stats['episodes']}\n";
echo "Series: {$stats['series']}\n";
echo "Movies: {$stats['movies']}\n";

// Show database size
$dbFile = __DIR__ . '/cache/episodes.db';
if (file_exists($dbFile)) {
    echo "\nDatabase size: " . round(filesize($dbFile) / 1024 / 1024, 2) . " MB\n";
}

echo "\nMigration complete!\n";
