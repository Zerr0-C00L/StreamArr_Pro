<?php
/**
 * Personal Hashlist Manager
 * Builds your own DMM-style hashlist from successful RD streams
 * Can be optionally synced to your GitHub repo
 */

class PersonalHashlists {
    private $dataDir = "/var/www/html/cache/personal_hashlists/";
    private $hashFile;
    private $imdbIndex;
    private $githubRepo;
    private $githubToken;
    private $useShards = true; // Use sharded storage
    
    public function __construct($githubRepo = null, $githubToken = null) {
        $this->hashFile = $this->dataDir . "hashes.json";
        $this->imdbIndex = $this->dataDir . "imdb_index.json";
        $this->githubRepo = $githubRepo; // e.g., "Zerr0-C00L/my-hashlists"
        $this->githubToken = $githubToken;
        
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0755, true);
        }
        
        // Check if we have sharded storage
        $this->useShards = file_exists($this->dataDir . "shards_meta.json");
        
        // Initialize files if needed (for non-sharded mode)
        if (!$this->useShards) {
            if (!file_exists($this->hashFile)) {
                file_put_contents($this->hashFile, json_encode([]));
            }
        }
        if (!file_exists($this->imdbIndex)) {
            file_put_contents($this->imdbIndex, json_encode([]));
        }
    }
    
    /**
     * Add a successful hash to your personal hashlist
     */
    public function addHash($hash, $metadata = []) {
        $hash = strtolower(trim($hash));
        if (strlen($hash) !== 40) return false;
        
        $entry = [
            "hash" => $hash,
            "filename" => $metadata["filename"] ?? "Unknown",
            "bytes" => $metadata["bytes"] ?? 0,
            "imdb_id" => $metadata["imdb_id"] ?? null,
            "type" => $metadata["type"] ?? "movie",
            "season" => $metadata["season"] ?? null,
            "episode" => $metadata["episode"] ?? null,
            "quality" => $this->detectQuality($metadata["filename"] ?? ""),
            "added" => time(),
            "last_used" => time(),
            "use_count" => 1
        ];
        
        if ($this->useShards) {
            // Add to shard file
            $prefix = substr($hash, 0, 2);
            $shardFile = $this->dataDir . "shard_{$prefix}.json";
            
            $shard = [];
            if (file_exists($shardFile)) {
                $shard = json_decode(file_get_contents($shardFile), true) ?? [];
            }
            
            if (isset($shard[$hash])) {
                // Update existing
                $shard[$hash]["last_used"] = time();
                $shard[$hash]["use_count"] = ($shard[$hash]["use_count"] ?? 0) + 1;
                // Update IMDB if we have it now
                if (!empty($metadata["imdb_id"]) && empty($shard[$hash]["imdb_id"])) {
                    $shard[$hash]["imdb_id"] = $metadata["imdb_id"];
                }
            } else {
                // Add new
                $shard[$hash] = $entry;
            }
            
            file_put_contents($shardFile, json_encode($shard));
        } else {
            // Legacy: single file mode
            $hashes = $this->loadHashes();
            
            if (isset($hashes[$hash])) {
                $hashes[$hash]["last_used"] = time();
                $hashes[$hash]["use_count"] = ($hashes[$hash]["use_count"] ?? 0) + 1;
            } else {
                $hashes[$hash] = $entry;
            }
            
            $this->saveHashes($hashes);
        }
        
        // Update IMDB index
        if (!empty($metadata["imdb_id"])) {
            $this->updateImdbIndex($hash, $metadata["imdb_id"], $metadata);
        }
        
        return true;
    }
    
    /**
     * Find hashes by IMDB ID
     */
    public function findByImdb($imdbId, $season = null, $episode = null) {
        $index = $this->loadImdbIndex();
        $imdbId = $this->normalizeImdbId($imdbId);
        
        if (!isset($index[$imdbId])) {
            return [];
        }
        
        $hashes = $this->loadHashes();
        $results = [];
        
        foreach ($index[$imdbId] as $hashRef) {
            $hash = $hashRef["hash"];
            if (!isset($hashes[$hash])) continue;
            
            $entry = $hashes[$hash];
            
            // Filter by season/episode if specified
            if ($season !== null && isset($entry["season"]) && $entry["season"] != $season) {
                continue;
            }
            if ($episode !== null && isset($entry["episode"]) && $entry["episode"] != $episode) {
                continue;
            }
            
            $results[] = [
                "hash" => $hash,
                "filename" => $entry["filename"],
                "bytes" => $entry["bytes"],
                "quality" => $entry["quality"],
                "use_count" => $entry["use_count"],
                "season" => $entry["season"],
                "episode" => $entry["episode"]
            ];
        }
        
        // Sort by quality and use count
        usort($results, function($a, $b) {
            $qualityOrder = ["2160p" => 4, "1080p" => 3, "720p" => 2, "480p" => 1, "unknown" => 0];
            $qa = $qualityOrder[$a["quality"]] ?? 0;
            $qb = $qualityOrder[$b["quality"]] ?? 0;
            if ($qa != $qb) return $qb - $qa;
            return $b["use_count"] - $a["use_count"];
        });
        
        return $results;
    }
    
    /**
     * Get all hashes (for export/sync)
     */
    public function getAllHashes() {
        return $this->loadHashes();
    }
    
    /**
     * Get stats
     */
    public function getStats() {
        $hashes = $this->loadHashes();
        $index = $this->loadImdbIndex();
        
        $totalSize = 0;
        $byType = ["movie" => 0, "series" => 0];
        $byQuality = [];
        
        foreach ($hashes as $hash => $data) {
            $totalSize += $data["bytes"] ?? 0;
            $type = $data["type"] ?? "movie";
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $quality = $data["quality"] ?? "unknown";
            $byQuality[$quality] = ($byQuality[$quality] ?? 0) + 1;
        }
        
        return [
            "total_hashes" => count($hashes),
            "unique_titles" => count($index),
            "total_size_gb" => round($totalSize / 1024 / 1024 / 1024, 2),
            "by_type" => $byType,
            "by_quality" => $byQuality
        ];
    }
    
    /**
     * Export to DMM-compatible format (LZ-String compressed)
     */
    public function exportToDMM() {
        $hashes = $this->loadHashes();
        $export = [];
        
        foreach ($hashes as $hash => $data) {
            $export[] = [
                "hash" => $hash,
                "filename" => $data["filename"],
                "bytes" => $data["bytes"]
            ];
        }
        
        $json = json_encode($export);
        return $this->lzCompress($json);
    }
    
    /**
     * Import from DMM format
     */
    public function importFromDMM($compressed) {
        $json = $this->lzDecompress($compressed);
        if (!$json) return 0;
        
        $data = json_decode($json, true);
        if (!is_array($data)) return 0;
        
        $count = 0;
        foreach ($data as $entry) {
            if (isset($entry["hash"])) {
                $this->addHash($entry["hash"], [
                    "filename" => $entry["filename"] ?? "Imported",
                    "bytes" => $entry["bytes"] ?? 0
                ]);
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Sync to GitHub (optional)
     */
    public function syncToGithub() {
        if (!$this->githubRepo || !$this->githubToken) {
            return ["error" => "GitHub not configured"];
        }
        
        $compressed = $this->exportToDMM();
        $content = '<!doctype html>
<html>
<head>
<meta charset="UTF-8">
<title>Personal Hashlist</title>
</head>
<body>
<script>location.href="https://debridmediamanager.com/hashlist#' . $compressed . '";</script>
</body>
</html>';
        
        // Create/update file via GitHub API
        $filename = "personal_hashlist.html";
        $url = "https://api.github.com/repos/{$this->githubRepo}/contents/{$filename}";
        
        // Get existing file SHA if it exists
        $sha = null;
        $opts = [
            "http" => [
                "method" => "GET",
                "header" => [
                    "User-Agent: PHP",
                    "Authorization: token {$this->githubToken}"
                ]
            ]
        ];
        $existing = @file_get_contents($url, false, stream_context_create($opts));
        if ($existing) {
            $existingData = json_decode($existing, true);
            $sha = $existingData["sha"] ?? null;
        }
        
        // Update file
        $postData = [
            "message" => "Update hashlist - " . date("Y-m-d H:i:s"),
            "content" => base64_encode($content)
        ];
        if ($sha) {
            $postData["sha"] = $sha;
        }
        
        $opts = [
            "http" => [
                "method" => "PUT",
                "header" => [
                    "User-Agent: PHP",
                    "Authorization: token {$this->githubToken}",
                    "Content-Type: application/json"
                ],
                "content" => json_encode($postData)
            ]
        ];
        
        $result = @file_get_contents($url, false, stream_context_create($opts));
        return $result ? json_decode($result, true) : ["error" => "Failed to sync"];
    }
    
    // Private helpers
    
    private function loadHashes() {
        return json_decode(file_get_contents($this->hashFile), true) ?: [];
    }
    
    private function saveHashes($hashes) {
        file_put_contents($this->hashFile, json_encode($hashes, JSON_PRETTY_PRINT));
    }
    
    private function loadImdbIndex() {
        return json_decode(file_get_contents($this->imdbIndex), true) ?: [];
    }
    
    private function updateImdbIndex($hash, $imdbId, $metadata) {
        $index = $this->loadImdbIndex();
        $imdbId = $this->normalizeImdbId($imdbId);
        
        if (!isset($index[$imdbId])) {
            $index[$imdbId] = [];
        }
        
        // Check if hash already in index for this IMDB
        foreach ($index[$imdbId] as $entry) {
            if ($entry["hash"] === $hash) {
                return; // Already indexed
            }
        }
        
        $index[$imdbId][] = [
            "hash" => $hash,
            "season" => $metadata["season"] ?? null,
            "episode" => $metadata["episode"] ?? null
        ];
        
        file_put_contents($this->imdbIndex, json_encode($index, JSON_PRETTY_PRINT));
    }
    
    private function normalizeImdbId($imdbId) {
        if (is_numeric($imdbId)) {
            return "tt" . str_pad($imdbId, 7, "0", STR_PAD_LEFT);
        }
        return $imdbId;
    }
    
    private function detectQuality($filename) {
        $filename = strtolower($filename);
        if (strpos($filename, "2160p") !== false || strpos($filename, "4k") !== false) return "2160p";
        if (strpos($filename, "1080p") !== false) return "1080p";
        if (strpos($filename, "720p") !== false) return "720p";
        if (strpos($filename, "480p") !== false) return "480p";
        return "unknown";
    }
    
    private function lzCompress($input) {
        $tempFile = "/tmp/lz_compress_" . md5($input) . ".txt";
        file_put_contents($tempFile, $input);
        
        $cmd = "python3 << 'PYEOF'
import lzstring
x = lzstring.LZString()
with open('$tempFile', 'r') as f:
    data = f.read()
result = x.compressToEncodedURIComponent(data)
if result:
    print(result)
PYEOF";
        
        $output = shell_exec($cmd);
        @unlink($tempFile);
        return $output ? trim($output) : null;
    }
    
    private function lzDecompress($input) {
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
}

// CLI interface
if (php_sapi_name() === "cli" && isset($argv[1])) {
    $ph = new PersonalHashlists();
    
    switch ($argv[1]) {
        case "stats":
            print_r($ph->getStats());
            break;
            
        case "add":
            if (isset($argv[2])) {
                $metadata = [
                    "filename" => $argv[3] ?? "Manual add",
                    "imdb_id" => $argv[4] ?? null
                ];
                if ($ph->addHash($argv[2], $metadata)) {
                    echo "Added hash: " . $argv[2] . "\n";
                }
            }
            break;
            
        case "find":
            if (isset($argv[2])) {
                $results = $ph->findByImdb($argv[2], $argv[3] ?? null, $argv[4] ?? null);
                print_r($results);
            }
            break;
            
        case "export":
            echo $ph->exportToDMM();
            break;
            
        default:
            echo "Usage:\n";
            echo "  php personal_hashlists.php stats\n";
            echo "  php personal_hashlists.php add <hash> [filename] [imdb_id]\n";
            echo "  php personal_hashlists.php find <imdb_id> [season] [episode]\n";
            echo "  php personal_hashlists.php export\n";
    }
}
