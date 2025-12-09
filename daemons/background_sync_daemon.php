<?php
/**
 * Background Sync Daemon
 * 
 * Syncs Live TV channels from GitHub and regenerates M3U8 playlist from local files
 * Movies and TV series are managed via MDBList integration
 * 
 * Usage:
 *   php background_sync_daemon.php          # Run once
 *   php background_sync_daemon.php --daemon # Run continuously in background
 */

require_once __DIR__ . '/../config.php';

// Configuration - Live TV from public-files repo
$GITHUB_LIVE_TV = 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/Pluto-TV/us.m3u8';
$GITHUB_LIVE_EPG = 'https://raw.githubusercontent.com/Zerr0-C00L/public-files/main/Pluto-TV/us.xml';

$SYNC_INTERVAL_HOURS = 6; // How often to sync Live TV
$LOCK_FILE = __DIR__ . '/../cache/sync_daemon.lock';
$STATUS_FILE = __DIR__ . '/../cache/sync_status.json';

// Logging
function logMsg($msg) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $msg\n";
    @file_put_contents(__DIR__ . '/../logs/sync_daemon.log', "[$timestamp] $msg\n", FILE_APPEND);
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

// Main sync function - Now only syncs Live TV from GitHub
// Movies and TV series are managed via MDBList integration
function syncWithGithub() {
    logMsg("========== STARTING SYNC ==========");
    updateStatus('syncing', 0, 'Starting sync...');
    
    // Load local playlists (populated by MDBList integration)
    $localMoviesFile = __DIR__ . '/../playlist.json';
    $localTVFile = __DIR__ . '/../tv_playlist.json';
    
    $movies = [];
    $tvSeries = [];
    
    // Load local movies
    if (file_exists($localMoviesFile)) {
        $movies = json_decode(file_get_contents($localMoviesFile), true) ?? [];
    }
    logMsg("Local movies: " . count($movies) . " items");
    
    // Load local TV series
    if (file_exists($localTVFile)) {
        $tvSeries = json_decode(file_get_contents($localTVFile), true) ?? [];
    }
    logMsg("Local TV series: " . count($tvSeries) . " items");
    
    // ========== REGENERATE M3U8 ==========
    updateStatus('syncing', 50, 'Regenerating M3U8 playlist...');
    logMsg("Regenerating M3U8 playlist...");
    
    // Generate M3U8 from local JSON playlists
    regenerateM3U8($movies, $tvSeries);
    
    // Calculate next sync time
    global $SYNC_INTERVAL_HOURS;
    $nextSync = date('Y-m-d H:i:s', time() + ($SYNC_INTERVAL_HOURS * 3600));
    
    // ========== DONE ==========
    updateStatus('complete', 100, [
        'movies' => count($movies),
        'series' => count($tvSeries),
        'last_sync' => date('Y-m-d H:i:s'),
        'next_sync' => $nextSync
    ]);
    
    logMsg("========== SYNC COMPLETE ==========");
    logMsg("Total: " . count($movies) . " movies, " . count($tvSeries) . " series");
    logMsg("Next sync: " . $nextSync);
    
    return true;
}

// Regenerate M3U8 playlist from JSON
function regenerateM3U8($movies, $tvSeries) {
    $m3u8File = __DIR__ . '/../playlist.m3u8';
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

// Fetch Live TV from GitHub and save as JSON for XC API
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
    
    // Parse M3U8 and create JSON for XC API
    $lines = explode("\n", $response);
    $liveChannels = [];
    $categories = [];
    $categoryMap = [];
    $streamId = 1;
    $catId = 1;
    
    $currentInfo = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#EXTINF') === 0) {
            // Parse channel info
            preg_match('/tvg-id="([^"]*)"/', $line, $tvgId);
            preg_match('/tvg-logo="([^"]*)"/', $line, $logo);
            preg_match('/group-title="([^"]*)"/', $line, $group);
            preg_match('/,(.*)$/', $line, $name);
            
            $categoryName = $group[1] ?? 'Live TV';
            if (!isset($categoryMap[$categoryName])) {
                $categoryMap[$categoryName] = $catId;
                $categories[] = [
                    'category_id' => (string)$catId,
                    'category_name' => $categoryName,
                    'parent_id' => 0
                ];
                $catId++;
            }
            
            $currentInfo = [
                'num' => $streamId,
                'name' => $name[1] ?? 'Unknown',
                'stream_type' => 'live',
                'stream_id' => $streamId,
                'stream_icon' => $logo[1] ?? '',
                'epg_channel_id' => $tvgId[1] ?? '',
                'added' => time(),
                'category_id' => (string)$categoryMap[$categoryName],
                'custom_sid' => '',
                'tv_archive' => 0,
                'direct_source' => '',
                'tv_archive_duration' => 0
            ];
            $streamId++;
        } elseif ($currentInfo && strpos($line, 'http') === 0) {
            $currentInfo['_stream_url'] = $line;
            $liveChannels[] = $currentInfo;
            $currentInfo = null;
        }
    }
    
    // Save JSON files for XC API
    $channelsDir = __DIR__ . '/../channels';
    if (!is_dir($channelsDir)) {
        mkdir($channelsDir, 0755, true);
    }
    
    file_put_contents($channelsDir . '/live_playlist.json', json_encode($liveChannels, JSON_PRETTY_PRINT));
    file_put_contents($channelsDir . '/get_live_categories.json', json_encode($categories, JSON_PRETTY_PRINT));
    logMsg("Saved " . count($liveChannels) . " live channels and " . count($categories) . " categories for XC API");
    
    // Also fetch EPG for live channels
    fetchLiveEPG();
    
    // Return M3U8 format (without header)
    $output = '';
    foreach ($lines as $line) {
        if (strpos($line, '#EXTM3U') === 0) continue;
        $output .= $line . "\n";
    }
    
    return $output;
}

// Fetch Live TV EPG from GitHub
function fetchLiveEPG() {
    global $GITHUB_LIVE_EPG;
    
    $channelsDir = __DIR__ . '/../channels';
    if (!is_dir($channelsDir)) {
        mkdir($channelsDir, 0755, true);
    }
    
    logMsg("Fetching Live TV EPG from GitHub...");
    
    $ch = curl_init($GITHUB_LIVE_EPG);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        logMsg("Failed to fetch Live TV EPG (HTTP $httpCode)");
        return false;
    }
    
    // Validate it's XML
    if (strpos($response, '<?xml') === false && strpos($response, '<tv') === false) {
        logMsg("EPG response is not valid XML");
        return false;
    }
    
    // Save as plain XML
    $xmlFile = $channelsDir . '/epg.xml';
    file_put_contents($xmlFile, $response);
    
    // Also create gzipped version
    $gzFile = $channelsDir . '/epg.xml.gz';
    $gz = gzopen($gzFile, 'w9');
    gzwrite($gz, $response);
    gzclose($gz);
    
    // Update last updated timestamp
    file_put_contents($channelsDir . '/last_updated_epg.txt', time());
    
    $size = strlen($response);
    logMsg("Saved Live TV EPG: " . number_format($size) . " bytes");
    
    return true;
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
