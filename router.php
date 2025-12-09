<?php
// Router for PHP built-in server to handle Xtream Codes URL format
// Converts /movie/username/password/movieId.ext to play.php?movieId=movieId

// Auto-check for new episodes periodically (non-blocking)
require_once __DIR__ . '/config.php';
$autoCacheIntervalHours = $autoCacheIntervalHours ?? 6;
if ($autoCacheIntervalHours > 0) {
    $lastCheckFile = __DIR__ . '/cache/last_cache_check.json';
    $shouldCheck = false;
    
    if (!file_exists($lastCheckFile)) {
        $shouldCheck = true;
    } else {
        $lastCheck = json_decode(file_get_contents($lastCheckFile), true);
        $hoursSinceCheck = (time() - ($lastCheck['last_check'] ?? 0)) / 3600;
        if ($hoursSinceCheck >= $autoCacheIntervalHours) {
            $shouldCheck = true;
        }
    }
    
    if ($shouldCheck) {
        // Run cache check in background (non-blocking)
        $script = __DIR__ . '/daemons/auto_cache_daemon.php';
        if (file_exists($script)) {
            exec("php \"$script\" >> logs/auto_cache.log 2>&1 &");
        }
    }
}

// Log all incoming requests for debugging
$logFile = __DIR__ . '/logs/requests.log';
@file_put_contents($logFile, date('Y-m-d H:i:s') . " ROUTER: " . $_SERVER['REQUEST_URI'] . " | UA: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'none') . "\n", FILE_APPEND);

$uri = $_SERVER['REQUEST_URI'];

// Parse the URL
$path = parse_url($uri, PHP_URL_PATH);
$query = parse_url($uri, PHP_URL_QUERY);

// Handle stream_id direct play: /movie/username/password/stream_123.ext
// This plays a specific cached stream by its database ID
if (preg_match('#^/movie/[^/]+/[^/]+/stream_(\d+)\.#', $path, $matches)) {
    // Look up the stream info from SQLite to get the movie ID
    $streamDbId = $matches[1];
    $dbFile = __DIR__ . "/cache/episodes.db";
    if (file_exists($dbFile)) {
        try {
            $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
            $stmt = $db->prepare('SELECT content_key FROM streams WHERE id = :id');
            $stmt->bindValue(':id', (int)$streamDbId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                // content_key format: "tt1234567:movie" or "tt1234567:series:1:2"
                $parts = explode(':', $row['content_key']);
                $imdbId = $parts[0];
                
                // Get movie by IMDB ID
                $movieStmt = $db->prepare('SELECT tmdb_id FROM movies WHERE imdb_id = :imdb');
                $movieStmt->bindValue(':imdb', $imdbId, SQLITE3_TEXT);
                $movieResult = $movieStmt->execute();
                
                if ($movieRow = $movieResult->fetchArray(SQLITE3_ASSOC)) {
                    $_GET['movieId'] = $movieRow['tmdb_id'];
                    $_GET['type'] = 'movies';
                    $_GET['stream_id'] = $streamDbId;
                }
            }
            $db->close();
        } catch (Exception $e) {}
    }
    
    if (isset($_GET['movieId'])) {
        require 'play.php';
        exit;
    }
    // Fallback to select_stream if we couldn't map the stream
    $_GET['stream_id'] = $streamDbId;
    require 'select_stream.php';
    exit;
}

// Handle movie requests with quality: /movie/username/password/movieId_quality.ext
// e.g., /movie/user/pass/550_1080p.mp4 or /movie/user/pass/550_4k.mp4
if (preg_match('#^/movie/[^/]+/[^/]+/(\d+)_(4k|2160p|1080p|720p|480p)\.#i', $path, $matches)) {
    $_GET['movieId'] = $matches[1];
    $_GET['type'] = 'movies';
    $_GET['quality'] = strtolower($matches[2]); // Normalize to lowercase
    require 'play.php';
    exit;
}

// Handle movie requests: /movie/username/password/movieId.ext
if (preg_match('#^/movie/[^/]+/[^/]+/(\d+)\.#', $path, $matches)) {
    $_GET['movieId'] = $matches[1];
    $_GET['type'] = 'movies';
    require 'play.php';
    exit;
}

// Handle series stream_id: /series/username/password/stream_123.ext
if (preg_match('#^/series/[^/]+/[^/]+/stream_(\d+)\.#', $path, $matches)) {
    $streamDbId = $matches[1];
    $dbFile = __DIR__ . "/cache/episodes.db";
    if (file_exists($dbFile)) {
        try {
            $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
            $stmt = $db->prepare('SELECT content_key FROM streams WHERE id = :id');
            $stmt->bindValue(':id', (int)$streamDbId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                // content_key format: "tt1234567:series:1:2" for series
                $parts = explode(':', $row['content_key']);
                if (count($parts) >= 4 && $parts[1] === 'series') {
                    $imdbId = $parts[0];
                    $season = (int)$parts[2];
                    $episode = (int)$parts[3];
                    
                    // Find series by IMDB ID
                    $seriesStmt = $db->prepare('SELECT series_id FROM episodes WHERE imdb_id = :imdb AND season = :s AND episode = :e LIMIT 1');
                    $seriesStmt->bindValue(':imdb', $imdbId, SQLITE3_TEXT);
                    $seriesStmt->bindValue(':s', $season, SQLITE3_INTEGER);
                    $seriesStmt->bindValue(':e', $episode, SQLITE3_INTEGER);
                    $seriesResult = $seriesStmt->execute();
                    
                    if ($seriesRow = $seriesResult->fetchArray(SQLITE3_ASSOC)) {
                        $data = base64_encode($imdbId . ':' . $seriesRow['series_id'] . '/season/' . $season . '/episode/' . $episode);
                        $_GET['movieId'] = $seriesRow['series_id'];
                        $_GET['type'] = 'series';
                        $_GET['data'] = $data;
                        $_GET['stream_id'] = $streamDbId;
                    }
                }
            }
            $db->close();
        } catch (Exception $e) {}
    }
    
    if (isset($_GET['movieId'])) {
        require 'play.php';
        exit;
    }
    // Fallback
    $_GET['stream_id'] = $streamDbId;
    require 'select_stream.php';
    exit;
}

// Handle series with quality: /series/username/password/episodeId_quality.ext
// e.g., /series/user/pass/12345_1080p.mp4 or /series/user/pass/12345_4k.mp4
if (preg_match('#^/series/[^/]+/[^/]+/(\d+)_(4k|2160p|1080p|720p|480p)\.#i', $path, $matches)) {
    $episodeId = $matches[1];
    $_GET['quality'] = strtolower($matches[2]); // Normalize to lowercase
    
    // Look up episode info from SQLite
    $dbFile = __DIR__ . "/cache/episodes.db";
    if (file_exists($dbFile)) {
        try {
            $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
            $stmt = $db->prepare('SELECT series_id, season, episode, imdb_id FROM episodes WHERE episode_id = :id');
            $stmt->bindValue(':id', (int)$episodeId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $data = base64_encode($row['imdb_id'] . ':' . $row['series_id'] . '/season/' . $row['season'] . '/episode/' . $row['episode']);
                $_GET['movieId'] = $row['series_id'];
                $_GET['type'] = 'series';
                $_GET['data'] = $data;
            }
            $db->close();
        } catch (Exception $e) {}
    }
    
    if (isset($_GET['movieId'])) {
        require 'play.php';
        exit;
    }
}

// Handle series requests with encoded data: /series/username/password/movieId.data.ext
if (preg_match('#^/series/[^/]+/[^/]+/(\d+)\.(.+)\.[^.]+$#', $path, $matches)) {
    $_GET['movieId'] = $matches[1];
    $_GET['type'] = 'series';
    $_GET['data'] = $matches[2];
    require 'play.php';
    exit;
}

// Handle simple series requests (episode ID only): /series/username/password/episodeId.ext
// This is the format many IPTV apps use - lookup episode info from SQLite cache
if (preg_match('#^/series/[^/]+/[^/]+/(\d+)\.[^.]+$#', $path, $matches)) {
    $episodeId = $matches[1];
    
    // Log this specific case
    @file_put_contents($logFile, date('H:i:s') . " SIMPLE SERIES: episodeId=$episodeId\n", FILE_APPEND);
    
    // Try SQLite database lookup (fast, no memory issues)
    $dbFile = __DIR__ . "/cache/episodes.db";
    $epInfo = null;
    
    if (file_exists($dbFile)) {
        try {
            $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
            $stmt = $db->prepare('SELECT series_id, season, episode, imdb_id FROM episodes WHERE episode_id = :id');
            $stmt->bindValue(':id', (int)$episodeId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $epInfo = [
                    'series_id' => (string)$row['series_id'],
                    'season' => (int)$row['season'],
                    'episode' => (int)$row['episode'],
                    'imdb_id' => $row['imdb_id'] ?? ''
                ];
            }
            $db->close();
        } catch (Exception $e) {
            @file_put_contents($logFile, date('H:i:s') . " DB ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }
    
    if ($epInfo) {
        // Build the data string: imdbId:seriesId/season/X/episode/Y
        $data = base64_encode($epInfo['imdb_id'] . ':' . $epInfo['series_id'] . '/season/' . $epInfo['season'] . '/episode/' . $epInfo['episode']);
        
        @file_put_contents($logFile, date('H:i:s') . " LOOKUP HIT: imdb=" . $epInfo['imdb_id'] . ", series=" . $epInfo['series_id'] . ", S" . $epInfo['season'] . "E" . $epInfo['episode'] . "\n", FILE_APPEND);
        
        $_GET['movieId'] = $epInfo['series_id'];
        $_GET['type'] = 'series';
        $_GET['data'] = $data;
        require 'play.php';
        exit;
    }
    
    // Cache miss - TMDB doesn't support reverse lookup (episode ID → series ID)
    // User needs to view the show info first, which caches all episodes
    @file_put_contents($logFile, date('H:i:s') . " CACHE MISS: Episode $episodeId not found - need to view series info first\n", FILE_APPEND);
    
    // Return a helpful error - user needs to view show info in app to cache episodes
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "Episode not cached. Please tap/click on the show first to view its info, then try playing again.";
    exit;
}

// Handle live requests: /live/username/password/streamId.ext
if (preg_match('#^/live/[^/]+/[^/]+/(\d+)\.#', $path, $matches)) {
    $_GET['streamId'] = $matches[1];
    require 'live_play.php';
    exit;
}

// If no match, serve the requested file normally
if (file_exists(__DIR__ . $path) && is_file(__DIR__ . $path)) {
    return false; // Let PHP serve the file
}

// If it's a directory or doesn't exist, try to route to index
if (file_exists(__DIR__ . $path . '/index.php')) {
    require __DIR__ . $path . '/index.php';
    exit;
}

// Default to 404
http_response_code(404);
echo "404 - File not found";
