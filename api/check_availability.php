<?php
/**
 * Check if content is available on Real-Debrid via Torrentio
 * Returns JSON with availability status and caches streams to SQLite
 * 
 * Usage:
 *   /check_availability.php?type=movie&tmdb=550
 *   /check_availability.php?type=series&imdb=tt0096697&season=1&episode=1
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../libs/episode_cache_db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$type = $_GET['type'] ?? 'movie';
$tmdbId = $_GET['tmdb'] ?? null;
$imdbId = $_GET['imdb'] ?? null;
$season = isset($_GET['season']) ? (int)$_GET['season'] : null;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : null;
$forceRefresh = isset($_GET['refresh']);

$cache = new EpisodeCacheDB();

// Get IMDB ID if only TMDB ID provided
if (!$imdbId && $tmdbId) {
    // Try SQLite cache first for movies
    if ($type === 'movie' || $type === 'movies') {
        $movieData = $cache->getMovie($tmdbId);
        if ($movieData && !empty($movieData['imdb_id'])) {
            $imdbId = $movieData['imdb_id'];
        }
    }
    
    // Fallback to TMDB API
    if (!$imdbId) {
        $tmdbType = ($type === 'movie' || $type === 'movies') ? 'movie' : 'tv';
        $tmdbUrl = "https://api.themoviedb.org/3/{$tmdbType}/{$tmdbId}/external_ids?api_key={$apiKey}";
        
        $response = @file_get_contents($tmdbUrl);
        if ($response) {
            $data = json_decode($response, true);
            $imdbId = $data['imdb_id'] ?? null;
            
            // Cache the IMDB ID
            if ($imdbId && ($type === 'movie' || $type === 'movies')) {
                $cache->setMovie($tmdbId, '', $imdbId, 0);
            }
        }
    }
}

if (!$imdbId) {
    echo json_encode([
        'available' => false,
        'error' => 'Could not find IMDB ID',
        'cached_streams' => 0
    ]);
    exit;
}

$dbType = ($type === 'movie' || $type === 'movies') ? 'movie' : 'series';

// Check if we have fresh cached streams (24 hour TTL)
if (!$forceRefresh && $cache->hasValidStreams($imdbId, $dbType, $season, $episode, 24)) {
    $cachedStreams = $cache->getStreams($imdbId, $dbType, $season, $episode);
    
    $qualities = [];
    foreach ($cachedStreams as $s) {
        if (!empty($s['quality'])) {
            $qualities[$s['quality']] = ($qualities[$s['quality']] ?? 0) + 1;
        }
    }
    
    echo json_encode([
        'available' => count($cachedStreams) > 0,
        'cached_streams' => count($cachedStreams),
        'qualities' => $qualities,
        'imdb_id' => $imdbId,
        'type' => $type,
        'from_cache' => true,
        'streams' => array_map(function($s) {
            return [
                'id' => $s['id'],
                'quality' => $s['quality'],
                'size' => $s['size'],
                'title' => $s['title']
            ];
        }, $cachedStreams)
    ]);
    exit;
}

// Fetch from Torrentio
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
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo json_encode([
        'available' => false,
        'error' => 'Torrentio request failed',
        'cached_streams' => 0,
        'imdb_id' => $imdbId
    ]);
    exit;
}

$data = json_decode($response, true);
$streams = $data['streams'] ?? [];

// Process and filter RD-cached streams
$cachedStreams = [];
$qualities = [];

// Quality filters - exclude REMUX, HDR, DV, 3D, CAM
$excludePatterns = '/\bREMUX\b|\bHDR\b|\bDV\b|\bDolby.?Vision\b|\b3D\b|\bCAM\b|\bTS\b|\bSCR\b/i';

foreach ($streams as $stream) {
    $name = $stream['name'] ?? '';
    $title = $stream['title'] ?? '';
    
    // Only use cached RD streams
    if (strpos($name, '[RD+]') === false) {
        continue;
    }
    
    // Apply quality filter
    if (preg_match($excludePatterns, $title) || preg_match($excludePatterns, $name)) {
        continue;
    }
    
    // Extract quality
    $quality = '';
    if (preg_match('/\b(4K|2160p)\b/i', $title)) {
        $quality = '2160P';
    } elseif (preg_match('/\b1080p\b/i', $title)) {
        $quality = '1080P';
    } elseif (preg_match('/\b720p\b/i', $title)) {
        $quality = '720P';
    } elseif (preg_match('/\b480p\b/i', $title)) {
        $quality = '480P';
    }
    
    // Extract size
    $size = '';
    if (preg_match('/(\d+\.?\d*\s*[GMKT]B)/i', $title, $sizeMatch)) {
        $size = $sizeMatch[1];
    }
    
    // Extract hash from URL
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
    
    if ($quality) {
        $qualities[$quality] = ($qualities[$quality] ?? 0) + 1;
    }
}

// Save to SQLite
if (!empty($cachedStreams)) {
    $cache->saveStreams($imdbId, $dbType, $cachedStreams, $season, $episode);
}

// Get saved streams with IDs
$savedStreams = $cache->getStreams($imdbId, $dbType, $season, $episode);

echo json_encode([
    'available' => count($cachedStreams) > 0,
    'cached_streams' => count($cachedStreams),
    'total_streams' => count($streams),
    'qualities' => $qualities,
    'imdb_id' => $imdbId,
    'type' => $type,
    'from_cache' => false,
    'streams' => array_map(function($s) {
        return [
            'id' => $s['id'],
            'quality' => $s['quality'],
            'size' => $s['size'],
            'title' => $s['title']
        ];
    }, $savedStreams)
]);
