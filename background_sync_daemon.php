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

// Merge playlists (local + GitHub, removing duplicates by stream_id)
function mergePlaylists($local, $github) {
    $merged = [];
    $seenIds = [];
    
    // Add local first (priority)
    foreach ($local as $item) {
        $id = $item['stream_id'] ?? $item['series_id'] ?? null;
        if ($id && !isset($seenIds[$id])) {
            $merged[] = $item;
            $seenIds[$id] = true;
        }
    }
    
    // Add GitHub items that aren't duplicates
    foreach ($github as $item) {
        $id = $item['stream_id'] ?? $item['series_id'] ?? null;
        if ($id && !isset($seenIds[$id])) {
            $merged[] = $item;
            $seenIds[$id] = true;
        }
    }
    
    return $merged;
}

// Main sync function
function syncWithGithub() {
    global $GITHUB_MOVIE_PLAYLIST, $GITHUB_TV_PLAYLIST;
    
    logMsg("========== STARTING GITHUB SYNC ==========");
    updateStatus('syncing', 0, 'Starting sync with GitHub...');
    
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
    
    // Save merged playlist
    file_put_contents($localMoviesFile, json_encode($mergedMovies, JSON_PRETTY_PRINT));
    logMsg("Saved merged movie playlist");
    
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
    
    // Save merged playlist
    file_put_contents($localTVFile, json_encode($mergedTV, JSON_PRETTY_PRINT));
    logMsg("Saved merged TV playlist");
    
    // ========== REGENERATE M3U8 ==========
    updateStatus('syncing', 85, 'Regenerating M3U8 playlist...');
    logMsg("Regenerating M3U8 playlist...");
    
    // Generate M3U8 from merged JSON
    regenerateM3U8($mergedMovies, $mergedTV);
    
    // ========== DONE ==========
    updateStatus('complete', 100, [
        'movies' => count($mergedMovies),
        'series' => count($mergedTV),
        'last_sync' => date('Y-m-d H:i:s')
    ]);
    
    logMsg("========== SYNC COMPLETE ==========");
    logMsg("Total: " . count($mergedMovies) . " movies, " . count($mergedTV) . " series");
    
    return true;
}

// Regenerate M3U8 playlist from JSON
function regenerateM3U8($movies, $tvSeries) {
    $m3u8File = __DIR__ . '/playlist.m3u8';
    $baseUrl = getBaseUrl();
    
    $m3u = "#EXTM3U\n";
    
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
    logMsg("Generated M3U8 with " . (count($movies) + count($tvSeries)) . " entries");
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
