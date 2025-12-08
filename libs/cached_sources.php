<?php
/**
 * Torrentio Stream Sources
 * Gets available cached sources from Torrentio with Real-Debrid
 */

class CachedStreamSources {
    private $rdApiKey;
    private $torrentioConfig;
    
    public function __construct($rdApiKey) {
        $this->rdApiKey = $rdApiKey;
        // Torrentio configuration with RD key - shows only cached torrents [RD+]
        // debridoptions=nodownloadlinks,nocatalog excludes your personal library
        $this->torrentioConfig = implode('|', [
            'providers=yts,eztv,rarbg,1337x,thepiratebay,kickasstorrents,torrentgalaxy,magnetdl,horriblesubs,nyaasi,tokyotosho,anidex',
            'sort=qualitysize',
            'debridoptions=nodownloadlinks,nocatalog',
            'realdebrid=' . $rdApiKey
        ]);
    }
    
    /**
     * Get all cached sources for a movie/series from Torrentio
     * Only returns [RD+] cached torrents (instant play)
     * Torrentio searches by IMDB ID so results should be accurate
     */
    public function getCachedSources($imdbId, $type = 'movie', $season = null, $episode = null) {
        $sources = [];
        
        // Build Torrentio URL with config
        $baseUrl = "https://torrentio.strem.fun/{$this->torrentioConfig}";
        
        if ($type === 'series' && $season !== null && $episode !== null) {
            $url = "{$baseUrl}/stream/series/{$imdbId}:{$season}:{$episode}.json";
        } else {
            $url = "{$baseUrl}/stream/movie/{$imdbId}.json";
        }
        
        // Fetch from Torrentio
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if (!$response || $httpCode !== 200) {
            return $sources;
        }
        
        $data = json_decode($response, true);
        if (!isset($data['streams'])) {
            return $sources;
        }
        
        foreach ($data['streams'] as $stream) {
            // Extract hash from URL (Torrentio with RD config uses resolve URLs)
            $hash = null;
            $fileIdx = 0;
            $fileName = 'video.mkv';
            
            if (isset($stream['url'])) {
                // Parse URL: https://torrentio.strem.fun/resolve/realdebrid/KEY/HASH/null/FILEIDX/FILENAME
                if (preg_match('#/resolve/realdebrid/[^/]+/([a-f0-9]{40})/[^/]*/(\d+)/(.+)$#i', $stream['url'], $matches)) {
                    $hash = strtolower($matches[1]);
                    $fileIdx = intval($matches[2]);
                    $fileName = urldecode($matches[3]);
                }
            } elseif (isset($stream['infoHash'])) {
                $hash = strtolower($stream['infoHash']);
                $fileIdx = $stream['fileIdx'] ?? 0;
            }
            
            if (!$hash) continue;
            
            // Parse name/title
            $name = $stream['name'] ?? '';
            $title = $stream['title'] ?? '';
            
            // Check if cached on RD (marked with [RD+])
            $isCached = strpos($name, '[RD+]') !== false || strpos($name, 'RD+') !== false;
            
            // Extract quality from name
            $quality = $this->extractQuality($name . ' ' . $title . ' ' . $fileName);
            $size = $this->extractSize($title);
            
            $sources[] = [
                'hash' => $hash,
                'title' => trim(str_replace(['[RD+]', '[RD download]'], '', $name)),
                'description' => $title,
                'fileName' => $fileName,
                'fileIdx' => $fileIdx,
                'bytes' => $size,
                'quality' => $quality,
                'type' => $type,
                'source' => 'Torrentio',
                'cached' => $isCached,
                'resolveUrl' => $stream['url'] ?? null
            ];
        }
        
        // Filter out unwanted quality types (REMUX, HDR, DV, 3D, CAM, SCR)
        $sources = array_values(array_filter($sources, function($s) {
            $text = strtoupper($s['description'] . ' ' . $s['fileName']);
            // Exclude REMUX
            if (strpos($text, 'REMUX') !== false) return false;
            // Exclude HDR variants
            if (preg_match('/\bHDR\b|\bHDR10\b|\bDOLBY.?VISION\b|\bDV\b|\bDOVI\b/', $text)) return false;
            // Exclude 3D
            if (preg_match('/\b3D\b/', $text)) return false;
            // Exclude CAM/Screener
            if (preg_match('/\bCAM\b|\bTS\b|\bTELESYNC\b|\bTELECINE\b|\bSCR\b|\bSCREENER\b/', $text)) return false;
            return true;
        }));
        
        // Sort by quality, then by size
        usort($sources, function($a, $b) {
            // Then by quality
            $qualityOrder = ['2160p' => 1, '4k' => 1, '1080p' => 2, '720p' => 3, '480p' => 4, 'unknown' => 5];
            $qa = $qualityOrder[strtolower($a['quality'] ?? 'unknown')] ?? 5;
            $qb = $qualityOrder[strtolower($b['quality'] ?? 'unknown')] ?? 5;
            
            if ($qa !== $qb) return $qa - $qb;
            
            // Then by size (larger = better)
            return ($b['bytes'] ?? 0) - ($a['bytes'] ?? 0);
        });
        
        return $sources;
    }
    
    /**
     * Get direct stream URL using Torrentio's resolve endpoint
     */
    public function getStreamUrl($hash, $fileIdx = 0, $fileName = 'video.mkv') {
        if (empty($this->rdApiKey)) return null;
        
        // Use Torrentio's resolve endpoint - it handles all RD API calls
        $resolveUrl = "https://torrentio.strem.fun/resolve/realdebrid/" 
            . urlencode($this->rdApiKey) 
            . "/" . urlencode($hash) 
            . "/null/" . intval($fileIdx) 
            . "/" . urlencode($fileName);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $resolveUrl);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Extract Location header from 302 redirect
        if ($httpCode === 302 && preg_match('/Location:\s*(.+)/i', $response, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }
    
    /**
     * Extract quality from title string
     */
    private function extractQuality($title) {
        $title = strtolower($title);
        if (preg_match('/2160p|4k|uhd/i', $title)) return '2160p';
        if (preg_match('/1080p|fullhd|fhd/i', $title)) return '1080p';
        if (preg_match('/720p|hd/i', $title)) return '720p';
        if (preg_match('/480p|sd/i', $title)) return '480p';
        return 'unknown';
    }
    
    /**
     * Extract size in bytes from title string
     */
    private function extractSize($title) {
        if (preg_match('/💾\s*([\d.]+)\s*(TB|GB|MB)/i', $title, $matches) ||
            preg_match('/([\d.]+)\s*(TB|GB|MB)/i', $title, $matches)) {
            $size = floatval($matches[1]);
            $unit = strtoupper($matches[2]);
            
            switch ($unit) {
                case 'TB': return (int)($size * 1024 * 1024 * 1024 * 1024);
                case 'GB': return (int)($size * 1024 * 1024 * 1024);
                case 'MB': return (int)($size * 1024 * 1024);
            }
        }
        return 0;
    }
    
    /**
     * Format bytes to human readable
     */
    public static function formatBytes($bytes) {
        if ($bytes >= 1099511627776) {
            return round($bytes / 1099511627776, 2) . ' TB';
        } elseif ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        return $bytes . ' B';
    }
}
