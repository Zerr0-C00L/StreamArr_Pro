<?php
/**
 * Background Sync Daemon
 * 
 * Merges GitHub's large playlists (~45K movies, ~17K series) with your local playlist
 * Runs on startup, after restarts, and periodically
 * 
 * Usage:
 *   php background_sync_daemon.php          # Run once
 *   php background_sync_daemon.php --daemon # Run continuously in background
 */

require_once __DIR__ . '/config.php';

// Configuration - Using your forked public-files repo
$GITHUB_MOVIE_PLAYLIST = 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/playlist.json';
$GITHUB_TV_PLAYLIST = 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/tv_playlist.json';
$GITHUB_COLLECTIONS = 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/collections_playlist.json';
$GITHUB_LIVE_TV = 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/Pluto-TV/us.m3u8';
$GITHUB_LIVE_EPG = 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/Pluto-TV/us.xml';

// TMDB Movie Lists URLs
$GITHUB_MOVIE_LISTS = [
    'now_playing' => 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/movie_lists/now_playing_movies.json',
    'popular' => 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/movie_lists/popular_movies.json',
    'top_rated' => 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/movie_lists/top_rated_movies.json',
    'upcoming' => 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/movie_lists/upcoming_movies.json',
    'latest_releases' => 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/movie_lists/latest_releases_movies.json'
];

// TMDB Series Lists URLs
$GITHUB_SERIES_LISTS = [
    'airing_today' => 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/series_lists/airing_today_series.json',
    'on_the_air' => 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/series_lists/on_the_air_series.json',
    'popular' => 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/series_lists/popular_series.json',
    'top_rated' => 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/series_lists/top_rated_series.json',
    'latest_releases' => 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/series_lists/latest_releases_series.json'
];

$SYNC_INTERVAL_HOURS = 6; // How often to sync with GitHub
$LOCK_FILE = __DIR__ . '/cache/sync_daemon.lock';
$STATUS_FILE = __DIR__ . '/cache/sync_status.json';

// Logging
function logMsg($msg) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $msg\n";
    @file_put_contents(__DIR__ . '/logs/sync_daemon.log', "[$timestamp] $msg\n", FILE_APPEND);
}

// Check if already running
function isRunning() {
    global $LOCK_FILE;
    if (file_exists($LOCK_FILE)) {
        $pid = file_get_contents($LOCK_FILE);
        // Check if process is still running (Linux/Mac)
        if (file_exists("/proc/$pid") || posix_kill(intval($pid), 0)) {
            return true;
        }
        // Stale lock file
        unlink($LOCK_FILE);
    }
    return false;
}

// Create lock file
function createLock() {
    global $LOCK_FILE;
    file_put_contents($LOCK_FILE, getmypid());
}

// Remove lock file
function removeLock() {
    global $LOCK_FILE;
    @unlink($LOCK_FILE);
}

// Update status
function updateStatus($status, $progress = null, $details = null) {
    global $STATUS_FILE;
    $data = [
        'status' => $status,
        'progress' => $progress,
        'details' => $details,
        'updated' => date('Y-m-d H:i:s'),
        'timestamp' => time()
    ];
    file_put_contents($STATUS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

// Fetch JSON from URL
function fetchJson($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    return json_decode($response, true);
}

// Check if content is released (not future dated)
function isReleased($item) {
    $today = date('Y-m-d');
    $currentYear = (int)date('Y');
    $currentMonth = (int)date('m');
    
    // Check explicit release date
    $releaseDate = $item['release_date'] ?? $item['first_air_date'] ?? null;
    if ($releaseDate && $releaseDate > $today) {
        return false;
    }
    
    // Check the 'added' timestamp - if it's in the future, skip
    if (isset($item['added']) && $item['added'] > time()) {
        return false;
    }
    
    // Check year in title - e.g., "Zootopia 2 (2025)" 
    $name = $item['name'] ?? '';
    if (preg_match('/\((\d{4})\)/', $name, $matches)) {
        $titleYear = (int)$matches[1];
        // If title year is more than current year, it's unreleased
        if ($titleYear > $currentYear) {
            return false;
        }
        // If title year is current year and we're in first half, 
        // be more conservative (some 2025 movies may not be out yet)
        // But we'll allow current year content since most will be released
    }
    
    return true;
}

// Merge playlists (local + GitHub, removing duplicates by stream_id)
// Also filters out unreleased content
function mergePlaylists($local, $github, $filterUnreleased = true) {
    $merged = [];
    $seenIds = [];
    $skippedUnreleased = 0;
    
    // Add local first (priority)
    foreach ($local as $item) {
        $id = $item['stream_id'] ?? $item['series_id'] ?? null;
        if ($id && !isset($seenIds[$id])) {
            // Check if released
            if ($filterUnreleased && !isReleased($item)) {
                $skippedUnreleased++;
                continue;
            }
            $merged[] = $item;
            $seenIds[$id] = true;
        }
    }
    
    // Add GitHub items that aren't duplicates
    foreach ($github as $item) {
        $id = $item['stream_id'] ?? $item['series_id'] ?? null;
        if ($id && !isset($seenIds[$id])) {
            // Check if released
            if ($filterUnreleased && !isReleased($item)) {
                $skippedUnreleased++;
                continue;
            }
            $merged[] = $item;
            $seenIds[$id] = true;
        }
    }
    
    if ($skippedUnreleased > 0) {
        logMsg("Filtered out $skippedUnreleased unreleased items");
    }
    
    return $merged;
}

// Main sync function
function syncWithGithub() {
    global $GITHUB_MOVIE_PLAYLIST, $GITHUB_TV_PLAYLIST, $GITHUB_COLLECTIONS;
    global $GITHUB_MOVIE_LISTS, $GITHUB_SERIES_LISTS;
    global $useGithubForCache; // Regular variable from config.php
    
    logMsg("========== STARTING GITHUB SYNC ==========");
    updateStatus('syncing', 0, 'Starting sync with GitHub...');
    
    // Check if we're using the full GitHub playlists or just curated lists
    // Note: $useGithubForCache is a regular variable, not a GLOBAL
    $useGithubCache = $useGithubForCache ?? ($GLOBALS['useGithubForCache'] ?? true);
    logMsg("Use GitHub Cache (Full Library): " . ($useGithubCache ? 'YES' : 'NO'));
    
    $mergedMovies = [];
    $mergedTV = [];
    
    // ========== FULL GITHUB PLAYLISTS (if enabled) ==========
    if ($useGithubCache) {
        // ========== MOVIES ==========
        logMsg("Fetching GitHub movie playlist...");
        updateStatus('syncing', 10, 'Fetching GitHub movies...');
        
        $githubMovies = fetchJson($GITHUB_MOVIE_PLAYLIST);
        if (!$githubMovies) {
            logMsg("ERROR: Failed to fetch GitHub movie playlist");
            updateStatus('error', null, 'Failed to fetch GitHub movies');
            return false;
        }
        logMsg("GitHub movies: " . count($githubMovies) . " items");
        
        // Load local playlist
        $localMoviesFile = __DIR__ . '/playlist.json';
        $localMovies = [];
        if (file_exists($localMoviesFile)) {
            $localMovies = json_decode(file_get_contents($localMoviesFile), true) ?? [];
        }
        logMsg("Local movies: " . count($localMovies) . " items");
        
        // Merge
        updateStatus('syncing', 30, 'Merging movie playlists...');
        $mergedMovies = mergePlaylists($localMovies, $githubMovies);
        logMsg("Merged movies: " . count($mergedMovies) . " items");
        
        // ========== TV SERIES ==========
        logMsg("Fetching GitHub TV playlist...");
        updateStatus('syncing', 50, 'Fetching GitHub TV series...');
        
        $githubTV = fetchJson($GITHUB_TV_PLAYLIST);
        if (!$githubTV) {
            logMsg("ERROR: Failed to fetch GitHub TV playlist");
            updateStatus('error', null, 'Failed to fetch GitHub TV series');
            return false;
        }
        logMsg("GitHub TV series: " . count($githubTV) . " items");
        
        // Load local TV playlist
        $localTVFile = __DIR__ . '/tv_playlist.json';
        $localTV = [];
        if (file_exists($localTVFile)) {
            $localTV = json_decode(file_get_contents($localTVFile), true) ?? [];
        }
        logMsg("Local TV series: " . count($localTV) . " items");
        
        // Merge
        updateStatus('syncing', 70, 'Merging TV playlists...');
        $mergedTV = mergePlaylists($localTV, $githubTV);
        logMsg("Merged TV series: " . count($mergedTV) . " items");
    } else {
        logMsg("GitHub full playlists disabled - using curated lists only");
    }
    
    // ========== TMDB MOVIE LISTS (if any enabled) ==========
    $movieListSettings = [
        'now_playing' => $GLOBALS['INCLUDE_NOW_PLAYING'] ?? false,
        'popular' => $GLOBALS['INCLUDE_POPULAR_MOVIES'] ?? false,
        'top_rated' => $GLOBALS['INCLUDE_TOP_RATED_MOVIES'] ?? false,
        'upcoming' => $GLOBALS['INCLUDE_UPCOMING'] ?? false,
        'latest_releases' => $GLOBALS['INCLUDE_LATEST_RELEASES_MOVIES'] ?? false
    ];
    
    $anyMovieListEnabled = array_filter($movieListSettings);
    if (!empty($anyMovieListEnabled)) {
        logMsg("Fetching TMDB movie lists...");
        updateStatus('syncing', 72, 'Fetching TMDB movie lists...');
        
        $existingIds = [];
        foreach ($mergedMovies as $movie) {
            if (isset($movie['stream_id'])) {
                $existingIds[$movie['stream_id']] = true;
            }
        }
        
        foreach ($movieListSettings as $listName => $enabled) {
            if (!$enabled) continue;
            
            $url = $GITHUB_MOVIE_LISTS[$listName] ?? null;
            if (!$url) continue;
            
            $listData = fetchJson($url);
            if ($listData && isset($listData['movies'])) {
                $addedCount = 0;
                foreach ($listData['movies'] as $movie) {
                    $streamId = $movie['id'] ?? null;
                    if ($streamId && !isset($existingIds[$streamId])) {
                        // Convert TMDB format to our playlist format
                        $mergedMovies[] = [
                            'stream_id' => $streamId,
                            'name' => ($movie['title'] ?? 'Unknown') . ' (' . substr($movie['release_date'] ?? '', 0, 4) . ')',
                            'stream_icon' => $movie['poster_path'] ? 'https://image.tmdb.org/t/p/w500' . $movie['poster_path'] : '',
                            'category_id' => 'TMDB ' . ucwords(str_replace('_', ' ', $listName)),
                            'tmdb_id' => $streamId,
                            'release_date' => $movie['release_date'] ?? '',
                            'vote_average' => $movie['vote_average'] ?? 0
                        ];
                        $existingIds[$streamId] = true;
                        $addedCount++;
                    }
                }
                logMsg("Added $addedCount movies from '$listName' list");
            } else {
                logMsg("Could not fetch '$listName' movie list (run GitHub workflow first)");
            }
        }
    }
    
    // ========== TMDB SERIES LISTS (if any enabled) ==========
    $seriesListSettings = [
        'airing_today' => $GLOBALS['INCLUDE_AIRING_TODAY'] ?? false,
        'on_the_air' => $GLOBALS['INCLUDE_ON_THE_AIR'] ?? false,
        'popular' => $GLOBALS['INCLUDE_POPULAR_SERIES'] ?? false,
        'top_rated' => $GLOBALS['INCLUDE_TOP_RATED_SERIES'] ?? false,
        'latest_releases' => $GLOBALS['INCLUDE_LATEST_RELEASES_SERIES'] ?? false
    ];
    
    $anySeriesListEnabled = array_filter($seriesListSettings);
    if (!empty($anySeriesListEnabled)) {
        logMsg("Fetching TMDB series lists...");
        updateStatus('syncing', 74, 'Fetching TMDB series lists...');
        
        $existingIds = [];
        foreach ($mergedTV as $series) {
            if (isset($series['series_id'])) {
                $existingIds[$series['series_id']] = true;
            }
        }
        
        foreach ($seriesListSettings as $listName => $enabled) {
            if (!$enabled) continue;
            
            $url = $GITHUB_SERIES_LISTS[$listName] ?? null;
            if (!$url) continue;
            
            $listData = fetchJson($url);
            if ($listData && isset($listData['series'])) {
                $addedCount = 0;
                foreach ($listData['series'] as $series) {
                    $seriesId = $series['id'] ?? null;
                    if ($seriesId && !isset($existingIds[$seriesId])) {
                        // Convert TMDB format to our playlist format
                        $mergedTV[] = [
                            'series_id' => $seriesId,
                            'name' => ($series['name'] ?? 'Unknown') . ' (' . substr($series['first_air_date'] ?? '', 0, 4) . ')',
                            'cover' => $series['poster_path'] ? 'https://image.tmdb.org/t/p/w500' . $series['poster_path'] : '',
                            'category_id' => 'TMDB ' . ucwords(str_replace('_', ' ', $listName)),
                            'tmdb_id' => $seriesId,
                            'first_air_date' => $series['first_air_date'] ?? '',
                            'vote_average' => $series['vote_average'] ?? 0
                        ];
                        $existingIds[$seriesId] = true;
                        $addedCount++;
                    }
                }
                logMsg("Added $addedCount series from '$listName' list");
            } else {
                logMsg("Could not fetch '$listName' series list (run GitHub workflow first)");
            }
        }
    }
    
    // ========== COLLECTIONS ==========
    $localMoviesFile = __DIR__ . '/playlist.json';
    $localTVFile = __DIR__ . '/tv_playlist.json';
    
    $collectionMovies = [];
    if ($GLOBALS['INCLUDE_COLLECTIONS'] ?? true) {
        logMsg("Fetching GitHub collections playlist...");
        updateStatus('syncing', 75, 'Fetching TMDB collections...');
        
        $collections = fetchJson($GITHUB_COLLECTIONS);
        if ($collections && is_array($collections)) {
            // Add collection movies to the movie list (avoiding duplicates)
            $existingIds = [];
            foreach ($mergedMovies as $movie) {
                if (isset($movie['stream_id'])) {
                    $existingIds[$movie['stream_id']] = true;
                }
            }
            
            $addedCount = 0;
            foreach ($collections as $movie) {
                if (isset($movie['stream_id']) && !isset($existingIds[$movie['stream_id']])) {
                    $mergedMovies[] = $movie;
                    $existingIds[$movie['stream_id']] = true;
                    $addedCount++;
                }
            }
            
            if ($addedCount > 0) {
                logMsg("Added $addedCount unique collection movies");
            }
            $collectionMovies = $collections;
            logMsg("Collections: " . count($collections) . " movies from TMDB collections");
        } else {
            logMsg("Collections playlist not available yet (run GitHub workflow first)");
        }
    }
    
    // ========== SAVE PLAYLISTS ==========
    logMsg("Saving playlists...");
    file_put_contents($localMoviesFile, json_encode($mergedMovies, JSON_PRETTY_PRINT));
    logMsg("Saved movie playlist: " . count($mergedMovies) . " movies");
    
    file_put_contents($localTVFile, json_encode($mergedTV, JSON_PRETTY_PRINT));
    logMsg("Saved TV playlist: " . count($mergedTV) . " series");
    
    // ========== REGENERATE M3U8 ==========
    updateStatus('syncing', 85, 'Regenerating M3U8 playlist...');
    logMsg("Regenerating M3U8 playlist...");
    
    // Generate M3U8 from merged JSON
    regenerateM3U8($mergedMovies, $mergedTV);
    
    // Calculate next sync time
    global $SYNC_INTERVAL_HOURS;
    $nextSync = date('Y-m-d H:i:s', time() + ($SYNC_INTERVAL_HOURS * 3600));
    
    // ========== DONE ==========
    updateStatus('complete', 100, [
        'movies' => count($mergedMovies),
        'series' => count($mergedTV),
        'last_sync' => date('Y-m-d H:i:s'),
        'next_sync' => $nextSync
    ]);
    
    logMsg("========== SYNC COMPLETE ==========");
    logMsg("Total: " . count($mergedMovies) . " movies, " . count($mergedTV) . " series");
    logMsg("Next sync: " . $nextSync);
    
    return true;
}

// Regenerate M3U8 playlist from JSON
function regenerateM3U8($movies, $tvSeries) {
    $m3u8File = __DIR__ . '/playlist.m3u8';
    $baseUrl = getBaseUrl();
    
    // Check if there's a limit set
    $limit = $GLOBALS['M3U8_LIMIT'] ?? 0;
    
    if ($limit > 0) {
        // Split limit: 70% movies, 30% TV
        $movieLimit = (int)($limit * 0.7);
        $tvLimit = $limit - $movieLimit;
        
        // Slice arrays to limit
        $movies = array_slice($movies, 0, $movieLimit);
        $tvSeries = array_slice($tvSeries, 0, $tvLimit);
        
        logMsg("M3U8 limited to $limit items ($movieLimit movies, $tvLimit TV)");
    }
    
    $m3u = "#EXTM3U\n";
    
    // Add Live TV first (from Pluto TV) if enabled
    $liveTVCount = 0;
    if ($GLOBALS['INCLUDE_LIVE_TV'] ?? true) {
        $liveTV = fetchLiveTV();
        if (!empty($liveTV)) {
            $m3u .= $liveTV;
            $liveTVCount = substr_count($liveTV, '#EXTINF');
            logMsg("Added $liveTVCount live TV channels");
        }
    }
    
    // Add movies
    foreach ($movies as $movie) {
        $name = $movie['name'] ?? 'Unknown';
        $streamId = $movie['stream_id'] ?? '';
        $logo = $movie['stream_icon'] ?? '';
        $category = $movie['category_id'] ?? 'Movies';
        
        $m3u .= "#EXTINF:-1 tvg-id=\"$streamId\" tvg-logo=\"$logo\" group-title=\"$category\",$name\n";
        $m3u .= "{$baseUrl}/movie/user/pass/{$streamId}.mp4\n";
    }
    
    // Add TV series (just the series, episodes loaded on demand)
    foreach ($tvSeries as $series) {
        $name = $series['name'] ?? 'Unknown';
        $seriesId = $series['series_id'] ?? '';
        $logo = $series['cover'] ?? '';
        $category = $series['category_id'] ?? 'TV Shows';
        
        $m3u .= "#EXTINF:-1 tvg-id=\"$seriesId\" tvg-logo=\"$logo\" group-title=\"$category\",$name\n";
        $m3u .= "{$baseUrl}/series/user/pass/{$seriesId}.mp4\n";
    }
    
    file_put_contents($m3u8File, $m3u);
    $totalEntries = (count($movies) + count($tvSeries) + ($liveTVCount ?? 0));
    logMsg("Generated M3U8 with $totalEntries entries");
}

// Fetch Live TV from GitHub
function fetchLiveTV() {
    global $GITHUB_LIVE_TV;
    
    $ch = curl_init($GITHUB_LIVE_TV);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        logMsg("Failed to fetch Live TV playlist");
        return '';
    }
    
    // Remove the #EXTM3U header (we already have one)
    $lines = explode("\n", $response);
    $output = '';
    foreach ($lines as $line) {
        if (strpos($line, '#EXTM3U') === 0) continue;
        $output .= $line . "\n";
    }
    
    return $output;
}

// Get base URL
function getBaseUrl() {
    // Try to detect from config or use server IP
    $host = $GLOBALS['userSetHost'] ?? '';
    if (empty($host)) {
        $host = trim(shell_exec('hostname -I 2>/dev/null | awk \'{print $1}\'') ?? '');
        if (empty($host)) {
            $host = trim(shell_exec('curl -s ifconfig.me 2>/dev/null') ?? 'localhost');
        }
    }
    return "http://{$host}";
}

// Check if sync is needed
function needsSync() {
    global $SYNC_INTERVAL_HOURS, $STATUS_FILE;
    
    if (!file_exists($STATUS_FILE)) {
        return true; // Never synced
    }
    
    $status = json_decode(file_get_contents($STATUS_FILE), true);
    $lastSync = $status['timestamp'] ?? 0;
    $hoursSinceSync = (time() - $lastSync) / 3600;
    
    return $hoursSinceSync >= $SYNC_INTERVAL_HOURS;
}

// ========== MAIN ==========

// Handle signals for graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() {
        logMsg("Received SIGTERM, shutting down...");
        removeLock();
        exit(0);
    });
    pcntl_signal(SIGINT, function() {
        logMsg("Received SIGINT, shutting down...");
        removeLock();
        exit(0);
    });
}

// Parse arguments
$daemon = in_array('--daemon', $argv ?? []);
$force = in_array('--force', $argv ?? []);

if (isRunning() && !$force) {
    echo "Sync daemon is already running. Use --force to override.\n";
    exit(1);
}

createLock();
register_shutdown_function('removeLock');

logMsg("Background Sync Daemon started");
logMsg("Mode: " . ($daemon ? "Continuous daemon" : "One-time sync"));

if ($daemon) {
    // Continuous daemon mode
    logMsg("Running in daemon mode (sync every {$SYNC_INTERVAL_HOURS} hours)");
    
    // Initial sync
    syncWithGithub();
    
    // Loop forever
    while (true) {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
        // Sleep for 1 hour, then check if sync needed
        sleep(3600);
        
        if (needsSync()) {
            logMsg("Scheduled sync starting...");
            syncWithGithub();
        }
    }
} else {
    // One-time sync
    if (needsSync() || $force) {
        syncWithGithub();
    } else {
        logMsg("Sync not needed yet (last sync was recent)");
        $status = json_decode(file_get_contents($STATUS_FILE), true);
        logMsg("Last sync: " . ($status['details']['last_sync'] ?? 'unknown'));
        logMsg("Use --force to sync anyway");
    }
}

removeLock();
logMsg("Daemon finished");
