<?php
/**
 * Stream Selection Endpoint
 * Returns available streams for selection or plays a specific stream
 * 
 * Usage:
 *   GET /select_stream.php?imdb=tt0137523&type=movie              - List available streams (JSON)
 *   GET /select_stream.php?imdb=tt0096697&type=series&s=1&e=1     - List series streams
 *   GET /select_stream.php?stream_id=123                           - Play specific stream
 *   GET /select_stream.php?imdb=tt0137523&type=movie&format=m3u   - M3U playlist of streams
 *   GET /select_stream.php?imdb=tt0137523&type=movie&format=html  - HTML selection page
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/libs/episode_cache_db.php';

$cache = new EpisodeCacheDB();

// If stream_id is provided, play that specific stream
if (isset($_GET['stream_id'])) {
    $streamId = (int)$_GET['stream_id'];
    
    // Get stream from database
    $db = new SQLite3(__DIR__ . '/cache/episodes.db', SQLITE3_OPEN_READONLY);
    $stmt = $db->prepare('SELECT * FROM streams WHERE id = :id');
    $stmt->bindValue(':id', $streamId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $stream = $result->fetchArray(SQLITE3_ASSOC);
    $db->close();
    
    if (!$stream) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain');
        echo "Stream not found";
        exit;
    }
    
    $resolveUrl = $stream['resolve_url'];
    
    if (empty($resolveUrl)) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain');
        echo "No resolve URL for this stream";
        exit;
    }
    
    // Log the request
    $logFile = __DIR__ . '/logs/requests.log';
    @file_put_contents($logFile, date('H:i:s') . " SELECT_STREAM: Playing stream #{$streamId} - {$stream['quality']} {$stream['title']}\n", FILE_APPEND);
    
    // Resolve to get RD download URL
    $ch = curl_init($resolveUrl);
    curl_setopt_array($ch, [
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    $resolveResponse = curl_exec($ch);
    curl_close($ch);
    
    if (preg_match('/Location:\s*(.+)/i', $resolveResponse, $matches)) {
        $rdUrl = trim($matches[1]);
        
        // Skip failed streams
        if (strpos($rdUrl, 'failed') !== false) {
            header('HTTP/1.1 503 Service Unavailable');
            header('Content-Type: text/plain');
            echo "Stream temporarily unavailable on Real-Debrid";
            exit;
        }
        
        @file_put_contents($logFile, date('H:i:s') . " SELECT_STREAM: Redirecting to RD: " . substr($rdUrl, 0, 80) . "...\n", FILE_APPEND);
        
        // Redirect to RD URL
        header("Location: " . $rdUrl);
        exit;
    }
    
    header('HTTP/1.1 503 Service Unavailable');
    header('Content-Type: text/plain');
    echo "Could not resolve stream from Torrentio";
    exit;
}

// List available streams
$imdbId = $_GET['imdb'] ?? null;
$tmdbId = $_GET['tmdb'] ?? null;
$type = $_GET['type'] ?? 'movie';
$season = isset($_GET['s']) ? (int)$_GET['s'] : (isset($_GET['season']) ? (int)$_GET['season'] : null);
$episode = isset($_GET['e']) ? (int)$_GET['e'] : (isset($_GET['episode']) ? (int)$_GET['episode'] : null);
$format = $_GET['format'] ?? 'json';

// Get IMDB ID from TMDB ID if needed
if (!$imdbId && $tmdbId) {
    if ($type === 'movie' || $type === 'movies') {
        $movieData = $cache->getMovie($tmdbId);
        if ($movieData && !empty($movieData['imdb_id'])) {
            $imdbId = $movieData['imdb_id'];
        }
    }
    
    if (!$imdbId) {
        $tmdbType = ($type === 'movie' || $type === 'movies') ? 'movie' : 'tv';
        $tmdbUrl = "https://api.themoviedb.org/3/{$tmdbType}/{$tmdbId}/external_ids?api_key={$apiKey}";
        $response = @file_get_contents($tmdbUrl);
        if ($response) {
            $data = json_decode($response, true);
            $imdbId = $data['imdb_id'] ?? null;
        }
    }
}

if (!$imdbId) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'imdb or tmdb parameter required']);
    exit;
}

$dbType = ($type === 'movie' || $type === 'movies') ? 'movie' : 'series';

// Check if we need to fetch fresh streams
if (!$cache->hasValidStreams($imdbId, $dbType, $season, $episode, 24)) {
    // Fetch from Torrentio and cache
    $rdKey = $PRIVATE_TOKEN;
    $config = "providers=yts,eztv,rarbg,1337x,thepiratebay,kickasstorrents,torrentgalaxy,magnetdl|sort=qualitysize|debridoptions=nodownloadlinks,nocatalog|realdebrid={$rdKey}";
    
    if ($dbType === 'series' && $season !== null && $episode !== null) {
        $url = "https://torrentio.strem.fun/{$config}/stream/series/{$imdbId}:{$season}:{$episode}.json";
    } else {
        $url = "https://torrentio.strem.fun/{$config}/stream/movie/{$imdbId}.json";
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
        $data = json_decode($response, true);
        $torrStreams = $data['streams'] ?? [];
        
        $cachedStreams = [];
        $excludePatterns = '/\bREMUX\b|\bHDR\b|\bDV\b|\bDolby.?Vision\b|\b3D\b|\bCAM\b|\bTS\b|\bSCR\b/i';
        
        foreach ($torrStreams as $stream) {
            $name = $stream['name'] ?? '';
            $title = $stream['title'] ?? '';
            
            if (strpos($name, '[RD+]') === false) continue;
            if (preg_match($excludePatterns, $title) || preg_match($excludePatterns, $name)) continue;
            
            $quality = '';
            if (preg_match('/\b(4K|2160p)\b/i', $title)) $quality = '2160P';
            elseif (preg_match('/\b1080p\b/i', $title)) $quality = '1080P';
            elseif (preg_match('/\b720p\b/i', $title)) $quality = '720P';
            elseif (preg_match('/\b480p\b/i', $title)) $quality = '480P';
            
            $size = '';
            if (preg_match('/(\d+\.?\d*\s*[GMKT]B)/i', $title, $sizeMatch)) {
                $size = $sizeMatch[1];
            }
            
            $hash = '';
            $fileIdx = 0;
            $resolveUrl = $stream['url'] ?? '';
            if (preg_match('/\/([a-f0-9]{40})\//', $resolveUrl, $hashMatch)) {
                $hash = $hashMatch[1];
            }
            if (preg_match('/\/(\d+)\/[^\/]+$/', $resolveUrl, $idxMatch)) {
                $fileIdx = (int)$idxMatch[1];
            }
            
            $cachedStreams[] = [
                'quality' => $quality,
                'size' => $size,
                'title' => substr($title, 0, 200),
                'hash' => $hash,
                'file_idx' => $fileIdx,
                'resolve_url' => $resolveUrl
            ];
        }
        
        if (!empty($cachedStreams)) {
            $cache->saveStreams($imdbId, $dbType, $cachedStreams, $season, $episode);
        }
    }
}

// Get streams from cache
$streams = $cache->getStreams($imdbId, $dbType, $season, $episode);

// Output based on format
if ($format === 'm3u') {
    // Return M3U playlist format for IPTV apps
    header('Content-Type: audio/x-mpegurl');
    header('Content-Disposition: inline; filename="streams.m3u"');
    
    echo "#EXTM3U\n";
    
    foreach ($streams as $stream) {
        $title = $stream['quality'];
        if ($stream['size']) {
            $title .= " ({$stream['size']})";
        }
        $title .= " - " . substr($stream['title'], 0, 60);
        
        echo "#EXTINF:-1,{$title}\n";
        echo "http://{$_SERVER['HTTP_HOST']}/select_stream.php?stream_id={$stream['id']}\n";
    }
    exit;
}

if ($format === 'html') {
    // HTML selection page
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Select Stream - <?= htmlspecialchars($imdbId) ?></title>
        <style>
            body { font-family: -apple-system, sans-serif; background: #1a1a2e; color: #fff; padding: 20px; }
            h1 { color: #e94560; font-size: 1.2rem; }
            .stream { background: #16213e; padding: 15px; margin: 10px 0; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; }
            .stream:hover { background: #0f3460; }
            .quality { font-weight: bold; color: #4ade80; min-width: 60px; }
            .size { color: #888; margin: 0 15px; min-width: 80px; }
            .title { flex: 1; font-size: 0.9rem; color: #aaa; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .play-btn { background: #e94560; color: #fff; padding: 8px 20px; border-radius: 5px; text-decoration: none; }
            .play-btn:hover { background: #d63850; }
            .no-streams { text-align: center; padding: 40px; color: #888; }
        </style>
    </head>
    <body>
        <h1>Select Stream Quality - <?= htmlspecialchars($imdbId) ?><?= $season ? " S{$season}E{$episode}" : '' ?></h1>
        
        <?php if (empty($streams)): ?>
        <div class="no-streams">No cached streams available for this content</div>
        <?php else: ?>
        <?php foreach ($streams as $stream): ?>
        <div class="stream">
            <span class="quality"><?= htmlspecialchars($stream['quality'] ?: 'Unknown') ?></span>
            <span class="size"><?= htmlspecialchars($stream['size'] ?: '-') ?></span>
            <span class="title"><?= htmlspecialchars($stream['title']) ?></span>
            <a href="/select_stream.php?stream_id=<?= $stream['id'] ?>" class="play-btn">▶ Play</a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

// Return JSON (default)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'imdb_id' => $imdbId,
    'type' => $type,
    'season' => $season,
    'episode' => $episode,
    'stream_count' => count($streams),
    'streams' => array_map(function($s) {
        return [
            'id' => $s['id'],
            'quality' => $s['quality'],
            'size' => $s['size'],
            'title' => $s['title'],
            'play_url' => "http://{$_SERVER['HTTP_HOST']}/select_stream.php?stream_id={$s['id']}"
        ];
    }, $streams)
]);
