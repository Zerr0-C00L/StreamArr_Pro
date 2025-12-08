<?php
/**
 * Fetch New DMM Hashlists
 * Downloads and decodes new hashlists from GitHub incrementally
 * Run via cron: php /var/www/html/fetch_new_hashlists.php
 */

$cacheDir = "/var/www/html/cache/dmm/";
$shardDir = "/var/www/html/cache/personal_hashlists/";
$logFile = "/var/www/html/logs/hashlist_fetch.log";

// Create directories
@mkdir($cacheDir, 0755, true);
@mkdir(dirname($logFile), 0755, true);

function logMsg($msg) {
    global $logFile;
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
    echo "[$timestamp] $msg\n";
}

function getGitHubFiles($page = 1, $perPage = 100) {
    $url = "https://api.github.com/repos/debridmediamanager/hashlists/contents?page=$page&per_page=$perPage";
    $opts = [
        "http" => [
            "header" => "User-Agent: PHP HashlistFetcher\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $json = @file_get_contents($url, false, $context);
    return $json ? json_decode($json, true) : [];
}

function decodeHashlist($filename) {
    $url = "https://raw.githubusercontent.com/debridmediamanager/hashlists/main/$filename";
    $html = @file_get_contents($url);
    if (!$html) return null;
    
    // Extract LZ-String data from iframe src hash fragment
    if (!preg_match('/<iframe[^>]+src="[^"]+#([^"]+)"/', $html, $match)) {
        return null;
    }
    
    $lzData = urldecode($match[1]);
    
    // Use Python to decompress (PHP doesn't have good LZ-String support)
    $tempFile = "/tmp/lz_" . md5($filename) . ".txt";
    file_put_contents($tempFile, $lzData);
    
    $output = shell_exec("python3 << 'PYEOF'
import lzstring
import json

with open('$tempFile', 'r') as f:
    data = f.read()

x = lzstring.LZString()
result = x.decompressFromEncodedURIComponent(data)
if result:
    print(result)
PYEOF");
    
    @unlink($tempFile);
    
    if (!$output) return null;
    
    $data = json_decode(trim($output), true);
    
    // Handle {"title": "...", "torrents": [...]} format
    if (is_array($data) && isset($data["torrents"])) {
        return $data["torrents"];
    }
    
    return $data;
}

function addToShard($hash, $info) {
    global $shardDir;
    
    $hash = strtolower($hash);
    $prefix = substr($hash, 0, 2);
    $shardFile = $shardDir . "shard_{$prefix}.json";
    
    $shard = [];
    if (file_exists($shardFile)) {
        $shard = json_decode(file_get_contents($shardFile), true) ?? [];
    }
    
    if (!isset($shard[$hash])) {
        $shard[$hash] = $info;
        file_put_contents($shardFile, json_encode($shard));
        return true; // New hash added
    }
    
    return false; // Already exists
}

// Main execution
logMsg("Starting hashlist fetch...");

// Get list of files from GitHub (first page only to limit API calls)
$files = getGitHubFiles(1, 100);
logMsg("Found " . count($files) . " files on GitHub (first page)");

$newHashes = 0;
$processedFiles = 0;
$maxFiles = 10; // Process max 10 new files per run to avoid timeout

foreach ($files as $file) {
    if ($processedFiles >= $maxFiles) break;
    
    $name = $file["name"] ?? "";
    if (!str_ends_with($name, ".html")) continue;
    
    // Check if already cached
    $cacheFile = $cacheDir . str_replace(".html", ".json", $name);
    if (file_exists($cacheFile)) continue;
    
    logMsg("Processing new file: $name");
    $processedFiles++;
    
    $torrents = decodeHashlist($name);
    if (!$torrents || !is_array($torrents)) {
        logMsg("  Failed to decode $name");
        continue;
    }
    
    logMsg("  Found " . count($torrents) . " torrents");
    
    // Cache the decoded file
    file_put_contents($cacheFile, json_encode($torrents));
    
    // Add to shards
    $added = 0;
    foreach ($torrents as $t) {
        $hash = $t["hash"] ?? "";
        if (strlen($hash) !== 40) continue;
        
        // Detect quality
        $filename = $t["filename"] ?? "";
        $quality = "unknown";
        if (preg_match("/2160p|4K|UHD/i", $filename)) $quality = "2160p";
        elseif (preg_match("/1080p/i", $filename)) $quality = "1080p";
        elseif (preg_match("/720p/i", $filename)) $quality = "720p";
        elseif (preg_match("/480p/i", $filename)) $quality = "480p";
        
        // Detect type
        $type = "movie";
        if (preg_match("/S\d{1,2}E\d{1,2}/i", $filename)) $type = "episode";
        elseif (preg_match("/Season|Complete|S\d{1,2}/i", $filename)) $type = "series";
        
        $info = [
            "hash" => $hash,
            "filename" => $filename,
            "bytes" => $t["bytes"] ?? 0,
            "imdb_id" => null,
            "type" => $type,
            "quality" => $quality
        ];
        
        if (addToShard($hash, $info)) {
            $added++;
        }
    }
    
    $newHashes += $added;
    logMsg("  Added $added new hashes to shards");
}

logMsg("Fetch complete: Processed $processedFiles files, added $newHashes new hashes");
