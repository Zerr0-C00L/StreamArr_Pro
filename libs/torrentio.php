<?php
/**
 * Torrentio-style Stream Resolver
 * 
 * Combines multiple torrent sources to find streams for movies/series,
 * then resolves through Real-Debrid for instant cached playback.
 * 
 * Flow:
 * 1. Search torrent sites (EZTV, YTS) for content
 * 2. Extract info hashes from torrents
 * 3. Add magnet to RD and get streaming URL
 */

class Torrentio {
    private $rdApiKey;
    private $cacheDir;
    private $cacheTtl = 1800; // 30 min cache
    private $rdBaseUrl = 'https://api.real-debrid.com/rest/1.0';
    
    public function __construct($rdApiKey) {
        $this->rdApiKey = $rdApiKey;
        $this->cacheDir = __DIR__ . '/../cache/torrentio';
        
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Get streams for a movie
     */
    public function getMovieStreams($imdbId, $title = null, $year = null) {
        $cacheKey = md5("movie_{$imdbId}");
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) return $cached;
        
        $streams = [];
        
        // Try YTS for movies
        $ytsStreams = $this->searchYTS($imdbId);
        $streams = array_merge($streams, $ytsStreams);
        
        // Sort by seeders
        usort($streams, fn($a, $b) => ($b['seeders'] ?? 0) - ($a['seeders'] ?? 0));
        
        $this->setCache($cacheKey, $streams);
        return $streams;
    }
    
    /**
     * Get streams for a TV episode
     */
    public function getEpisodeStreams($imdbId, $season, $episode, $title = null) {
        $cacheKey = md5("series_{$imdbId}_{$season}_{$episode}");
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) return $cached;
        
        $streams = [];
        
        // Try EZTV for series
        $eztvStreams = $this->searchEZTV($imdbId, $season, $episode);
        $streams = array_merge($streams, $eztvStreams);
        
        // Sort by seeders
        usort($streams, fn($a, $b) => ($b['seeders'] ?? 0) - ($a['seeders'] ?? 0));
        
        $this->setCache($cacheKey, $streams);
        return $streams;
    }
    
    /**
     * Get the best stream (highest quality, most seeders)
     */
    public function getBestStream($imdbId, $season = null, $episode = null, $title = null, $year = null) {
        if ($season !== null && $episode !== null) {
            $streams = $this->getEpisodeStreams($imdbId, $season, $episode, $title);
        } else {
            $streams = $this->getMovieStreams($imdbId, $title, $year);
        }
        
        if (empty($streams)) return null;
        
        // Prefer 1080p/720p with most seeders
        $qualityOrder = ['2160p' => 1, '1080p' => 2, '720p' => 3, '480p' => 4];
        
        usort($streams, function($a, $b) use ($qualityOrder) {
            $qa = $qualityOrder[$a['quality'] ?? 'unknown'] ?? 99;
            $qb = $qualityOrder[$b['quality'] ?? 'unknown'] ?? 99;
            if ($qa !== $qb) return $qa - $qb;
            return ($b['seeders'] ?? 0) - ($a['seeders'] ?? 0);
        });
        
        return $streams[0] ?? null;
    }
    
    /**
     * Resolve a torrent hash to a RD streaming URL
     * This is the main method - give it a hash and get back a streaming URL
     */
    public function resolveHash($infoHash, $fileIndex = null) {
        $infoHash = strtolower($infoHash);
        
        // Build magnet link
        $magnet = "magnet:?xt=urn:btih:{$infoHash}";
        
        // Add torrent to RD
        $addResult = $this->rdRequest('POST', '/torrents/addMagnet', [
            'magnet' => $magnet
        ]);
        
        if (!isset($addResult['id'])) {
            return ['error' => 'Failed to add magnet to RD'];
        }
        
        $torrentId = $addResult['id'];
        
        // Get torrent info
        $torrentInfo = $this->rdRequest('GET', "/torrents/info/{$torrentId}");
        
        if (!$torrentInfo) {
            return ['error' => 'Failed to get torrent info'];
        }
        
        // If status is waiting_files_selection, select all files
        if ($torrentInfo['status'] === 'waiting_files_selection') {
            $files = $torrentInfo['files'] ?? [];
            $fileIds = [];
            
            // Find video files
            foreach ($files as $file) {
                if ($this->isVideoFile($file['path'])) {
                    $fileIds[] = $file['id'];
                }
            }
            
            if (empty($fileIds)) {
                // Select all if no video files found
                $fileIds = array_column($files, 'id');
            }
            
            // Select specific file if index provided
            if ($fileIndex !== null && isset($files[$fileIndex])) {
                $fileIds = [$files[$fileIndex]['id']];
            }
            
            $this->rdRequest('POST', "/torrents/selectFiles/{$torrentId}", [
                'files' => implode(',', $fileIds)
            ]);
            
            // Re-fetch torrent info
            sleep(1);
            $torrentInfo = $this->rdRequest('GET', "/torrents/info/{$torrentId}");
        }
        
        // Check if already downloaded (cached)
        if ($torrentInfo['status'] === 'downloaded') {
            $links = $torrentInfo['links'] ?? [];
            
            if (!empty($links)) {
                // Unrestrict the first link
                $link = $links[0];
                if ($fileIndex !== null && isset($links[$fileIndex])) {
                    $link = $links[$fileIndex];
                }
                
                $unrestricted = $this->rdRequest('POST', '/unrestrict/link', [
                    'link' => $link
                ]);
                
                if (isset($unrestricted['download'])) {
                    return [
                        'url' => $unrestricted['download'],
                        'filename' => $unrestricted['filename'] ?? '',
                        'filesize' => $unrestricted['filesize'] ?? 0,
                        'cached' => true
                    ];
                }
            }
        }
        
        // Not cached - delete the torrent to avoid cluttering
        $this->rdRequest('DELETE', "/torrents/delete/{$torrentId}");
        
        return ['error' => 'Torrent not cached on RD', 'cached' => false];
    }
    
    /**
     * Get direct streaming URL for content
     * Combines search + resolve in one call
     */
    public function getStreamUrl($imdbId, $season = null, $episode = null, $title = null, $year = null) {
        $stream = $this->getBestStream($imdbId, $season, $episode, $title, $year);
        
        if (!$stream || empty($stream['infoHash'])) {
            return null;
        }
        
        $result = $this->resolveHash($stream['infoHash'], $stream['fileIndex'] ?? null);
        
        return $result['url'] ?? null;
    }
    
    /**
     * Search EZTV for TV episodes
     */
    private function searchEZTV($imdbId, $season, $episode) {
        $streams = [];
        $imdbNum = preg_replace('/^tt/', '', $imdbId);
        
        // EZTV API endpoint
        $url = "https://eztv.re/api/get-torrents?imdb_id={$imdbNum}&limit=100";
        
        $response = $this->httpGet($url);
        if (!$response) return $streams;
        
        $data = json_decode($response, true);
        if (!isset($data['torrents'])) return $streams;
        
        $searchPattern = sprintf('S%02dE%02d', $season, $episode);
        
        foreach ($data['torrents'] as $torrent) {
            $title = $torrent['title'] ?? '';
            
            // Check if this torrent matches our episode
            if (stripos($title, $searchPattern) !== false) {
                $streams[] = [
                    'source' => 'EZTV',
                    'title' => $title,
                    'infoHash' => strtolower($torrent['hash'] ?? ''),
                    'quality' => $this->extractQuality($title),
                    'seeders' => (int)($torrent['seeds'] ?? 0),
                    'size' => $this->formatSize($torrent['size_bytes'] ?? 0),
                    'sizeBytes' => (int)($torrent['size_bytes'] ?? 0),
                ];
            }
        }
        
        return $streams;
    }
    
    /**
     * Search YTS for movies
     */
    private function searchYTS($imdbId) {
        $streams = [];
        
        $url = "https://yts.mx/api/v2/list_movies.json?query_term={$imdbId}";
        
        $response = $this->httpGet($url);
        if (!$response) return $streams;
        
        $data = json_decode($response, true);
        if (!isset($data['data']['movies'])) return $streams;
        
        foreach ($data['data']['movies'] as $movie) {
            if (($movie['imdb_code'] ?? '') !== $imdbId) continue;
            
            foreach ($movie['torrents'] ?? [] as $torrent) {
                $streams[] = [
                    'source' => 'YTS',
                    'title' => $movie['title'] . ' (' . $movie['year'] . ') ' . ($torrent['quality'] ?? '') . ' ' . ($torrent['type'] ?? ''),
                    'infoHash' => strtolower($torrent['hash'] ?? ''),
                    'quality' => $torrent['quality'] ?? 'unknown',
                    'seeders' => (int)($torrent['seeds'] ?? 0),
                    'size' => $torrent['size'] ?? '',
                    'sizeBytes' => (int)(($torrent['size_bytes'] ?? 0)),
                ];
            }
        }
        
        return $streams;
    }
    
    /**
     * Make RD API request
     */
    private function rdRequest($method, $endpoint, $data = []) {
        $url = $this->rdBaseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->rdApiKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            error_log("RD API Error: {$httpCode} - {$response}");
            return null;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * HTTP GET request
     */
    private function httpGet($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($httpCode === 200) ? $response : null;
    }
    
    /**
     * Extract quality from title
     */
    private function extractQuality($title) {
        if (preg_match('/\b(2160p|4K)\b/i', $title)) return '2160p';
        if (preg_match('/\b1080p\b/i', $title)) return '1080p';
        if (preg_match('/\b720p\b/i', $title)) return '720p';
        if (preg_match('/\b480p\b/i', $title)) return '480p';
        return 'unknown';
    }
    
    /**
     * Format file size
     */
    private function formatSize($bytes) {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        return round($bytes / 1024, 2) . ' KB';
    }
    
    /**
     * Check if file is video
     */
    private function isVideoFile($path) {
        $videoExtensions = ['mkv', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, $videoExtensions);
    }
    
    /**
     * Get from cache
     */
    private function getCache($key) {
        $file = "{$this->cacheDir}/{$key}.json";
        if (file_exists($file) && (time() - filemtime($file)) < $this->cacheTtl) {
            return json_decode(file_get_contents($file), true);
        }
        return null;
    }
    
    /**
     * Set cache
     */
    private function setCache($key, $data) {
        $file = "{$this->cacheDir}/{$key}.json";
        file_put_contents($file, json_encode($data));
    }
    
    /**
     * Parse stream info for display
     */
    public function parseStreamInfo($stream) {
        return [
            'quality' => $stream['quality'] ?? 'unknown',
            'title' => $stream['title'] ?? '',
            'source' => $stream['source'] ?? '',
            'seeders' => $stream['seeders'] ?? 0,
            'size' => $stream['size'] ?? '',
            'infoHash' => $stream['infoHash'] ?? '',
        ];
    }
}
