<?php
/**
 * Auto Episode Cache Daemon
 * Runs continuously and checks for new series/episodes periodically
 * 
 * Usage:
 *   php auto_cache_daemon.php          - Run once (for cron)
 *   php auto_cache_daemon.php --daemon - Run continuously in background
 * 
 * For cron (check every 6 hours):
 *   0 0,6,12,18 * * * cd /path/to/tmdb-to-vod-playlist && php auto_cache_daemon.php >> logs/auto_cache.log 2>&1
 */

require_once __DIR__ . '/../config.php';

$isDaemon = in_array('--daemon', $argv);
$checkInterval = 6 * 60 * 60; // 6 hours between full checks
$localPlaylist = __DIR__ . '/../tv_playlist.json';
$episodeLookupFile = __DIR__ . '/../cache/episode_lookup.json';
$lastCheckFile = __DIR__ . '/../cache/last_cache_check.json';

function logMsg($msg) {
    echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
}

function loadPlaylist() {
    global $localPlaylist;
    
    if (file_exists($localPlaylist)) {
        return json_decode(file_get_contents($localPlaylist), true);
    }
    
    return null;
}

function fetchSeriesEpisodes($seriesId, $apiKey) {
    // Get series details with external IDs
    $infoUrl = "https://api.themoviedb.org/3/tv/{$seriesId}?api_key={$apiKey}&append_to_response=external_ids";
    $details = @json_decode(@file_get_contents($infoUrl), true);
    
    if (!$details) return [];
    
    $imdbId = $details['external_ids']['imdb_id'] ?? '';
    $episodes = [];
    
    // Get season numbers
    $seasonNumbers = [];
    if (isset($details['seasons'])) {
        foreach ($details['seasons'] as $season) {
            if (isset($season['season_number']) && $season['season_number'] > 0) {
                $seasonNumbers[] = $season['season_number'];
            }
        }
    }
    
    // Fetch seasons in batches of 20
    $chunks = array_chunk($seasonNumbers, 20);
    foreach ($chunks as $chunk) {
        $seasonsParam = implode(',', array_map(fn($s) => "season/$s", $chunk));
        $url = "https://api.themoviedb.org/3/tv/{$seriesId}?api_key={$apiKey}&append_to_response={$seasonsParam}";
        $data = @json_decode(@file_get_contents($url), true);
        
        if (!$data) continue;
        
        foreach ($chunk as $seasonNum) {
            $seasonData = $data["season/{$seasonNum}"] ?? null;
            if (!$seasonData || !isset($seasonData['episodes'])) continue;
            
            foreach ($seasonData['episodes'] as $ep) {
                // Skip future episodes
                if (empty($ep['air_date'])) continue;
                try {
                    if (new DateTime($ep['air_date']) > new DateTime()) continue;
                } catch (Exception $e) { continue; }
                
                $episodes[(string)$ep['id']] = [
                    'series_id' => (string)$seriesId,
                    'season' => $seasonNum,
                    'episode' => $ep['episode_number'],
                    'imdb_id' => $imdbId
                ];
            }
        }
        usleep(250000); // 250ms delay between TMDB requests
    }
    
    return $episodes;
}

function runCacheCheck() {
    global $apiKey, $episodeLookupFile, $lastCheckFile;
    
    logMsg("Starting episode cache check...");
    
    // Load playlist
    $playlist = loadPlaylist();
    if (!$playlist) {
        logMsg("ERROR: Could not load playlist");
        return false;
    }
    
    logMsg("Playlist has " . count($playlist) . " series");
    
    // Load existing cache
    $cache = [];
    if (file_exists($episodeLookupFile)) {
        $cache = json_decode(file_get_contents($episodeLookupFile), true) ?? [];
    }
    logMsg("Existing cache has " . count($cache) . " episodes");
    
    // Get cached series IDs
    $cachedSeriesIds = [];
    foreach ($cache as $ep) {
        $cachedSeriesIds[$ep['series_id']] = true;
    }
    
    // Find uncached series
    $uncached = [];
    foreach ($playlist as $show) {
        $id = (string)$show['series_id'];
        if (!isset($cachedSeriesIds[$id])) {
            $uncached[] = $show;
        }
    }
    
    $newCount = count($uncached);
    logMsg("Found $newCount new series to cache");
    
    if ($newCount == 0) {
        logMsg("All series already cached!");
        return true;
    }
    
    // Cache new series
    $processed = 0;
    $totalEpisodes = 0;
    
    foreach ($uncached as $show) {
        $seriesId = $show['series_id'];
        $name = $show['name'] ?? 'Unknown';
        
        $episodes = fetchSeriesEpisodes($seriesId, $apiKey);
        $epCount = count($episodes);
        $totalEpisodes += $epCount;
        
        if ($epCount > 0) {
            $cache = $cache + $episodes; // Merge without overwriting
        }
        
        $processed++;
        
        // Save every 10 series
        if ($processed % 10 == 0) {
            file_put_contents($episodeLookupFile, json_encode($cache), LOCK_EX);
            logMsg("[$processed/$newCount] Cached $name ($epCount episodes) - Total: " . count($cache));
        }
        
        usleep(250000); // 250ms rate limit
    }
    
    // Final save
    file_put_contents($episodeLookupFile, json_encode($cache), LOCK_EX);
    logMsg("COMPLETE: Added $totalEpisodes episodes from $newCount series");
    logMsg("Total cache: " . count($cache) . " episodes");
    
    // Update last check time
    file_put_contents($lastCheckFile, json_encode([
        'last_check' => time(),
        'series_added' => $newCount,
        'episodes_added' => $totalEpisodes
    ]));
    
    return true;
}

// Main execution
if ($isDaemon) {
    logMsg("Starting in daemon mode (check every " . ($checkInterval/3600) . " hours)");
    
    while (true) {
        runCacheCheck();
        logMsg("Sleeping for " . ($checkInterval/3600) . " hours...");
        sleep($checkInterval);
    }
} else {
    // Single run mode (for cron)
    runCacheCheck();
}
