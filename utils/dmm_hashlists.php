<?php
/**
 * DMM Hashlists Decoder
 * Uses the debridmediamanager/hashlists GitHub repository
 * to find cached torrents without using RD instant availability API
 */

class DMMHashlists {
    private $cacheDir = "/var/www/html/cache/dmm/";
    private $baseUrl = "https://raw.githubusercontent.com/debridmediamanager/hashlists/main/";
    
    public function __construct() {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Download and decode a single hashlist file
     */
    public function decodeHashlist($filename) {
        // Check cache first
        $cacheFile = $this->cacheDir . pathinfo($filename, PATHINFO_FILENAME) . ".json";
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            $data = json_decode(file_get_contents($cacheFile), true);
            // Normalize format - handle {"title": "...", "torrents": [...]} format
            if (is_array($data) && isset($data["torrents"])) {
                return $data["torrents"];
            }
            return $data;
        }
        
        // Download the HTML file
        $url = $this->baseUrl . $filename;
        $html = @file_get_contents($url);
        if (!$html) return null;
        
        // Extract compressed data from iframe src
        if (!preg_match('/src="[^"]+#([^"]+)"/', $html, $match)) {
            return null;
        }
        
        $compressed = urldecode($match[1]);
        $decoded = $this->decompress($compressed);
        
        if ($decoded) {
            $data = json_decode($decoded, true);
            @file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT));
            
            // Normalize format - handle {"title": "...", "torrents": [...]} format
            if (is_array($data) && isset($data["torrents"])) {
                return $data["torrents"];
            }
            return $data;
        }
        
        return null;
    }
    
    /**
     * LZ-String decompression using Python helper
     */
    private function decompress($input) {
        if (empty($input)) return "";
        
        // Write to temp file to avoid shell escaping issues
        $tempFile = "/tmp/lz_input_" . md5($input) . ".txt";
        file_put_contents($tempFile, $input);
        
        $cmd = "python3 << 'PYEOF'
import lzstring
x = lzstring.LZString()
with open('$tempFile', 'r') as f:
    data = f.read()
result = x.decompressFromEncodedURIComponent(data)
if result:
    print(result)
PYEOF";
        
        $output = shell_exec($cmd);
        @unlink($tempFile);
        return $output ? trim($output) : null;
    }
    
    /**
     * Get list of all hashlist files from GitHub
     */
    public function getHashlistFiles() {
        $cacheFile = $this->cacheDir . "file_list.json";
        
        // Cache for 1 hour
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            return json_decode(file_get_contents($cacheFile), true);
        }
        
        // Fetch from GitHub API (only returns first 1000)
        $url = "https://api.github.com/repos/debridmediamanager/hashlists/contents";
        $opts = [
            "http" => [
                "header" => "User-Agent: PHP\r\n"
            ]
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        
        if (!$json) return [];
        
        $data = json_decode($json, true);
        $files = [];
        foreach ($data as $item) {
            if (isset($item["name"]) && str_ends_with($item["name"], ".html")) {
                $files[] = $item["name"];
            }
        }
        
        @file_put_contents($cacheFile, json_encode($files));
        return $files;
    }
    
    /**
     * Search all hashlists for a title
     */
    public function searchHashlists($query, $maxResults = 20) {
        $results = [];
        $files = $this->getHashlistFiles();
        $query = strtolower($query);
        
        foreach ($files as $file) {
            $data = $this->decodeHashlist($file);
            if (!$data) continue;
            
            foreach ($data as $torrent) {
                if (isset($torrent["filename"]) && 
                    stripos($torrent["filename"], $query) !== false) {
                    $results[] = [
                        "filename" => $torrent["filename"],
                        "hash" => $torrent["hash"],
                        "size_gb" => round($torrent["bytes"] / 1024 / 1024 / 1024, 2),
                        "source_file" => $file
                    ];
                    
                    if (count($results) >= $maxResults) {
                        return $results;
                    }
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Build a local hash database from all hashlists
     */
    public function buildHashDatabase($progressCallback = null) {
        $dbFile = $this->cacheDir . "hash_database.json";
        $files = $this->getHashlistFiles();
        $allHashes = [];
        $count = 0;
        
        foreach ($files as $file) {
            $count++;
            if ($progressCallback) {
                $progressCallback($count, count($files), $file);
            }
            
            $data = $this->decodeHashlist($file);
            if (!$data) continue;
            
            foreach ($data as $torrent) {
                if (isset($torrent["hash"])) {
                    $hash = strtolower($torrent["hash"]);
                    $allHashes[$hash] = [
                        "filename" => $torrent["filename"] ?? "Unknown",
                        "bytes" => $torrent["bytes"] ?? 0
                    ];
                }
            }
        }
        
        file_put_contents($dbFile, json_encode($allHashes, JSON_PRETTY_PRINT));
        return count($allHashes);
    }
    
    /**
     * Check if a hash is in our database (cached on RD according to DMM)
     */
    public function isHashCached($hash) {
        $dbFile = $this->cacheDir . "hash_database.json";
        if (!file_exists($dbFile)) return null;
        
        $db = json_decode(file_get_contents($dbFile), true);
        $hash = strtolower($hash);
        
        return isset($db[$hash]) ? $db[$hash] : null;
    }
    
    /**
     * Filter an array of hashes, returning only cached ones
     */
    public function filterCachedHashes($hashes) {
        $dbFile = $this->cacheDir . "hash_database.json";
        if (!file_exists($dbFile)) return [];
        
        $db = json_decode(file_get_contents($dbFile), true);
        $cached = [];
        
        foreach ($hashes as $hash) {
            $hash = strtolower($hash);
            if (isset($db[$hash])) {
                $cached[$hash] = $db[$hash];
            }
        }
        
        return $cached;
    }
}

// CLI test
if (php_sapi_name() === "cli" && isset($argv[1])) {
    $dmm = new DMMHashlists();
    
    if ($argv[1] === "test") {
        echo "Testing DMM hashlist decoding...\n";
        $files = $dmm->getHashlistFiles();
        echo "Found " . count($files) . " hashlist files\n";
        
        if (count($files) > 0) {
            echo "\nDecoding first hashlist: " . $files[0] . "\n";
            $data = $dmm->decodeHashlist($files[0]);
            if ($data) {
                echo "Success! Found " . count($data) . " torrents\n";
                echo "First torrent:\n";
                print_r($data[0]);
            } else {
                echo "Failed to decode\n";
            }
        }
    } elseif ($argv[1] === "search" && isset($argv[2])) {
        echo "Searching for: " . $argv[2] . "\n";
        $results = $dmm->searchHashlists($argv[2]);
        foreach ($results as $r) {
            echo "- " . $r["filename"] . "\n";
            echo "  Hash: " . $r["hash"] . "\n";
            echo "  Size: " . $r["size_gb"] . " GB\n\n";
        }
        echo "Found " . count($results) . " results\n";
    } elseif ($argv[1] === "build") {
        echo "Building hash database...\n";
        $count = $dmm->buildHashDatabase(function($current, $total, $file) {
            echo "\rProcessing $current / $total: $file                    ";
        });
        echo "\nBuilt database with $count unique hashes\n";
    }
}
