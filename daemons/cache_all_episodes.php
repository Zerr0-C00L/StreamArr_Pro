<?php
/**
 * Pre-cache all episodes from the TV playlist
 * Memory-efficient version - streams to file instead of keeping all in memory
 */

ini_set('memory_limit', '512M');
set_time_limit(0);

require_once __DIR__ . '/../config.php';

$localPlaylist = __DIR__ . '/../tv_playlist.json';
$episodeLookupFile = __DIR__ . '/../cache/episode_lookup.json';
$progressFile = __DIR__ . '/../cache/cache_progress.json';

// Load playlist
echo "Loading playlist...\n";
$tvData = file_exists($localPlaylist) ? file_get_contents($localPlaylist) : null;

if (!$tvData) die("Error: Could not load TV playlist. Sync MDBList first.\n");

$tvPlaylist = json_decode($tvData, true);
unset($tvData);
$totalShows = count($tvPlaylist);
echo "Found $totalShows shows\n";

// Get cached series IDs efficiently (regex scan instead of full JSON parse)
$cachedSeriesIds = [];
if (file_exists($episodeLookupFile) && filesize($episodeLookupFile) > 0) {
    $content = file_get_contents($episodeLookupFile);
    preg_match_all('/"series_id":"(\d+)"/', $content, $matches);
    $cachedSeriesIds = array_flip($matches[1]);
    $epCount = substr_count($content, '"series_id"');
    echo "Existing cache: ~$epCount episodes from " . count($cachedSeriesIds) . " series\n";
    unset($content, $matches);
}

// Resume support
$startIndex = 0;
if (file_exists($progressFile)) {
    $progress = json_decode(file_get_contents($progressFile), true);
    $startIndex = ($progress['last_index'] ?? -1) + 1;
    if ($startIndex > 0) echo "Resuming from index $startIndex\n";
}

// Filter shows to cache
$showsToCache = [];
foreach ($tvPlaylist as $idx => $show) {
    if ($idx < $startIndex) continue;
    if (!isset($cachedSeriesIds[(string)$show['series_id']])) {
        $showsToCache[$idx] = $show;
    }
}
unset($tvPlaylist, $cachedSeriesIds);

$remaining = count($showsToCache);
echo "Shows to cache: $remaining\n\n";

if ($remaining == 0) {
    echo "All shows cached!\n";
    @unlink($progressFile);
    exit(0);
}

$processed = 0;
$newEpisodes = 0;
$batch = [];

foreach ($showsToCache as $origIdx => $show) {
    $seriesId = $show['series_id'];
    $name = $show['name'];
    
    echo "[" . ($processed + 1) . "/$remaining] $name (ID: $seriesId)... ";
    
    // Get series info
    $url = "https://api.themoviedb.org/3/tv/{$seriesId}?api_key={$apiKey}&append_to_response=external_ids";
    $info = @json_decode(@file_get_contents($url), true);
    
    if (!$info) {
        echo "FAILED\n";
        $processed++;
        continue;
    }
    
    $imdbId = $info['external_ids']['imdb_id'] ?? '';
    $seasons = [];
    foreach ($info['seasons'] ?? [] as $s) {
        if (($s['season_number'] ?? 0) > 0) $seasons[] = $s['season_number'];
    }
    unset($info);
    
    $epCount = 0;
    
    // Fetch seasons (batches of 20)
    foreach (array_chunk($seasons, 20) as $chunk) {
        $append = implode(',', array_map(fn($n) => "season/$n", $chunk));
        $url = "https://api.themoviedb.org/3/tv/{$seriesId}?api_key={$apiKey}&append_to_response=$append";
        $data = @json_decode(@file_get_contents($url), true);
        
        if (!$data) continue;
        
        foreach ($chunk as $sNum) {
            foreach ($data["season/$sNum"]['episodes'] ?? [] as $ep) {
                if (empty($ep['air_date'])) continue;
                try {
                    if (new DateTime($ep['air_date']) > new DateTime()) continue;
                } catch (Exception $e) { continue; }
                
                $batch[(string)$ep['id']] = [
                    'series_id' => (string)$seriesId,
                    'season' => $sNum,
                    'episode' => $ep['episode_number'],
                    'imdb_id' => $imdbId
                ];
                $epCount++;
                $newEpisodes++;
            }
        }
        unset($data);
        usleep(100000);
    }
    
    echo "$epCount eps\n";
    $processed++;
    
    // Save every 5 shows
    if ($processed % 5 == 0) {
        saveBatch($episodeLookupFile, $batch);
        saveToShards($batch);
        file_put_contents($progressFile, json_encode(['last_index' => $origIdx]));
        $batch = [];
        gc_collect_cycles(); // Force garbage collection
    }
    
    usleep(250000);
}

// Final save
if (!empty($batch)) {
    saveBatch($episodeLookupFile, $batch);
    saveToShards($batch);
}
@unlink($progressFile);

echo "\n=== DONE ===\n";
echo "Processed: $processed | New episodes: $newEpisodes\n";

/**
 * Save episodes to sharded cache (fast lookups)
 */
function saveToShards($episodes) {
    $shardDir = __DIR__ . '/../cache/episode_shards';
    if (!is_dir($shardDir)) mkdir($shardDir, 0755, true);
    
    $shards = [];
    
    // Group episodes by shard key
    foreach ($episodes as $epId => $data) {
        $shardKey = substr(str_pad($epId, 7, '0', STR_PAD_LEFT), 0, 2);
        if (!isset($shards[$shardKey])) {
            $shards[$shardKey] = [];
        }
        $shards[$shardKey][$epId] = $data;
    }
    
    // Merge with existing shards
    foreach ($shards as $shardKey => $newEpisodes) {
        $shardFile = $shardDir . "/shard_{$shardKey}.json";
        $existing = [];
        if (file_exists($shardFile)) {
            $existing = json_decode(file_get_contents($shardFile), true) ?? [];
        }
        $merged = array_merge($existing, $newEpisodes);
        file_put_contents($shardFile, json_encode($merged));
    }
}

function saveBatch($file, $episodes) {
    if (empty($episodes)) return;
    
    $fp = fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) return;
    
    $size = filesize($file);
    
    if ($size > 2) {
        // Append to existing JSON
        fseek($fp, -1, SEEK_END);
        $json = '';
        foreach ($episodes as $id => $data) {
            $json .= ',"' . $id . '":' . json_encode($data);
        }
        fwrite($fp, $json . '}');
        fseek($fp, -1 - strlen($json), SEEK_END); // Back up to overwrite the old }
        fwrite($fp, $json . '}');
    } else {
        // New file
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($episodes));
    }
    
    flock($fp, LOCK_UN);
    fclose($fp);
}
