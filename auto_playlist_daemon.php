<?php
/**
 * Auto Playlist & Stream Cache Daemon
 * 
 * This script:
 * 1. Regenerates movie/TV playlists with multi-quality entries
 * 2. Pre-caches available streams from Torrentio/RD to SQLite
 * 3. Refreshes stale stream cache (24h TTL)
 * 
 * Usage:
 *   php auto_playlist_daemon.php                    - Run once (for cron)
 *   php auto_playlist_daemon.php --daemon           - Run continuously
 *   php auto_playlist_daemon.php --playlist-only    - Only regenerate playlists
 *   php auto_playlist_daemon.php --streams-only     - Only refresh stream cache
 * 
 * For cron (daily at 3am):
 *   0 3 * * * cd /path/to/tmdb-to-vod-playlist && php auto_playlist_daemon.php >> logs/auto_playlist.log 2>&1
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/libs/episode_cache_db.php';

$isDaemon = in_array('--daemon', $argv);
$playlistOnly = in_array('--playlist-only', $argv);
$streamsOnly = in_array('--streams-only', $argv);

$checkInterval = 24 * 60 * 60; // 24 hours between full runs
$streamCacheTTL = 24; // Hours before stream cache is considered stale
$maxStreamsPerRun = 100; // Limit API calls per run

function logMsg($msg) {
    echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
    @file_put_contents(__DIR__ . '/logs/auto_playlist.log', "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

/**
 * Regenerate movie and TV playlists
 */
function regeneratePlaylists() {
    logMsg("=== REGENERATING PLAYLISTS ===");
    
    $baseDir = __DIR__;
    
    // Regenerate movie playlist
    logMsg("Generating movie playlist...");
    $output = [];
    $returnCode = 0;
    exec("cd " . escapeshellarg($baseDir) . " && php create_playlist.php 2>&1", $output, $returnCode);
    if ($returnCode === 0) {
        logMsg("Movie playlist done");
    } else {
        logMsg("Movie playlist error: " . implode("\n", $output));
    }
    
    // Regenerate TV playlist  
    logMsg("Generating TV playlist...");
    $output = [];
    exec("cd " . escapeshellarg($baseDir) . " && php create_tv_playlist.php 2>&1", $output, $returnCode);
    if ($returnCode === 0) {
        logMsg("TV playlist done");
    } else {
        logMsg("TV playlist error: " . implode("\n", $output));
    }
    
    return true;
}

/**
 * Fetch and cache streams from Torrentio for a movie/series
 */
function cacheStreamsForContent($imdbId, $type, $season = null, $episode = null) {
    global $PRIVATE_TOKEN;
    
    $rdKey = $PRIVATE_TOKEN;
    $config = "providers=yts,eztv,rarbg,1337x,thepiratebay,kickasstorrents,torrentgalaxy,magnetdl|sort=qualitysize|debridoptions=nodownloadlinks,nocatalog|realdebrid={$rdKey}";
    
    if ($type === 'series' && $season !== null && $episode !== null) {
        $url = "https://torrentio.strem.fun/{$config}/stream/series/{$imdbId}:{$season}:{$episode}.json";
    } else {
        $url = "https://torrentio.strem.fun/{$config}/stream/movie/{$imdbId}.json";
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        return 0;
    }
    
    $data = json_decode($response, true);
    $streams = $data['streams'] ?? [];
    
    if (empty($streams)) {
        return 0;
    }
    
    // Process streams - only cache RD+ (already cached on Real-Debrid)
    $db = new EpisodeCacheDB();
    $dbType = ($type === 'series') ? 'series' : 'movie';
    
    $streamsToCache = [];
    foreach ($streams as $s) {
        $sName = $s['name'] ?? '';
        if (strpos($sName, '[RD+]') !== false) {
            $sTitle = $s['title'] ?? '';
            $sQuality = 'unknown';
            if (preg_match('/\b(4K|2160p|UHD)\b/i', $sTitle)) $sQuality = '2160P';
            elseif (preg_match('/\b1080p\b/i', $sTitle)) $sQuality = '1080P';
            elseif (preg_match('/\b720p\b/i', $sTitle)) $sQuality = '720P';
            elseif (preg_match('/\b480p\b/i', $sTitle)) $sQuality = '480P';
            
            $hash = $s['infoHash'] ?? '';
            if (empty($hash) && !empty($s['url'])) {
                if (preg_match('/\/([a-f0-9]{40})\//i', $s['url'], $hMatch)) {
                    $hash = $hMatch[1];
                }
            }
            
            $streamsToCache[] = [
                'quality' => $sQuality,
                'title' => $sTitle,
                'hash' => $hash,
                'file_idx' => $s['fileIdx'] ?? 0,
                'resolve_url' => $s['url'] ?? ''
            ];
        }
    }
    
    if (!empty($streamsToCache)) {
        $db->saveStreams($imdbId, $dbType, $streamsToCache, $season, $episode);
        return count($streamsToCache);
    }
    
    return 0;
}

/**
 * Refresh stale stream caches
 */
function refreshStreamCache() {
    global $streamCacheTTL, $maxStreamsPerRun, $apiKey;
    
    logMsg("=== REFRESHING STREAM CACHE ===");
    
    $db = new EpisodeCacheDB();
    
    // Get movies from playlist that need stream cache refresh
    $playlistFile = __DIR__ . '/playlist.json';
    if (!file_exists($playlistFile)) {
        logMsg("No movie playlist found");
        return;
    }
    
    $movies = json_decode(file_get_contents($playlistFile), true) ?? [];
    logMsg("Found " . count($movies) . " movies in playlist");
    
    // Get unique movie IDs (when multi-quality, same movie appears multiple times)
    $uniqueMovies = [];
    foreach ($movies as $m) {
        $id = $m['stream_id'];
        // Handle multi-quality IDs (stream_id = movieId * 10 + quality_index)
        if ($id > 10000000) {
            $id = intdiv($id, 10);
        }
        if (!isset($uniqueMovies[$id])) {
            $uniqueMovies[$id] = $m;
        }
    }
    logMsg("Unique movies: " . count($uniqueMovies));
    
    $refreshed = 0;
    $skipped = 0;
    
    foreach ($uniqueMovies as $movieId => $movie) {
        if ($refreshed >= $maxStreamsPerRun) {
            logMsg("Reached max streams per run ($maxStreamsPerRun)");
            break;
        }
        
        // Get IMDB ID
        $imdbId = null;
        $movieData = $db->getMovie($movieId);
        if ($movieData && !empty($movieData['imdb_id'])) {
            $imdbId = $movieData['imdb_id'];
        } else {
            // Fetch from TMDB API
            $tmdbUrl = "https://api.themoviedb.org/3/movie/{$movieId}/external_ids?api_key={$apiKey}";
            $response = @file_get_contents($tmdbUrl);
            if ($response) {
                $data = json_decode($response, true);
                $imdbId = $data['imdb_id'] ?? null;
                if ($imdbId) {
                    $db->setMovie($movieId, $movie['name'] ?? '', $imdbId, 0);
                }
            }
            usleep(100000); // 100ms rate limit for TMDB
        }
        
        if (!$imdbId) {
            $skipped++;
            continue;
        }
        
        // Check if cache is stale
        if ($db->hasValidStreams($imdbId, 'movie', null, null, $streamCacheTTL)) {
            $skipped++;
            continue;
        }
        
        // Refresh stream cache
        $count = cacheStreamsForContent($imdbId, 'movie');
        if ($count > 0) {
            logMsg("Cached $count streams for {$movie['name']} ($imdbId)");
            $refreshed++;
        }
        
        usleep(500000); // 500ms between Torrentio requests
    }
    
    logMsg("Stream cache refresh: $refreshed refreshed, $skipped skipped");
}

/**
 * Main run function
 */
function runDaemon() {
    global $playlistOnly, $streamsOnly;
    
    logMsg("========================================");
    logMsg("AUTO PLAYLIST DAEMON STARTING");
    logMsg("========================================");
    
    if (!$streamsOnly) {
        regeneratePlaylists();
    }
    
    if (!$playlistOnly) {
        refreshStreamCache();
    }
    
    logMsg("========================================");
    logMsg("DAEMON RUN COMPLETE");
    logMsg("========================================");
}

// Main execution
if ($isDaemon) {
    logMsg("Starting in daemon mode (run every " . ($checkInterval/3600) . " hours)");
    
    while (true) {
        runDaemon();
        logMsg("Sleeping for " . ($checkInterval/3600) . " hours...");
        sleep($checkInterval);
    }
} else {
    // Single run mode (for cron)
    runDaemon();
}
