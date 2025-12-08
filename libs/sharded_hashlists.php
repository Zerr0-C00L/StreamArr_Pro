<?php
/**
 * Sharded Hashlist Lookup
 * Uses sharded JSON files for memory-efficient hash lookups
 * Optimized for low-memory servers
 */

class ShardedHashlists {
    private $dataDir = "/var/www/html/cache/personal_hashlists/";
    private $shardCache = [];
    private $maxCachedShards = 5; // Keep only 5 shards in memory
    
    public function __construct() {
        // Nothing to initialize
    }
    
    /**
     * Look up a hash in the sharded files
     */
    public function lookup($hash) {
        $hash = strtolower(trim($hash));
        if (strlen($hash) < 2) return null;
        
        $prefix = substr($hash, 0, 2);
        $shard = $this->loadShard($prefix);
        
        if ($shard && isset($shard[$hash])) {
            return $shard[$hash];
        }
        
        return null;
    }
    
    /**
     * Check if a hash exists
     */
    public function exists($hash) {
        return $this->lookup($hash) !== null;
    }
    
    /**
     * Check multiple hashes at once (efficient for batch lookups)
     */
    public function lookupMultiple($hashes) {
        $results = [];
        $byPrefix = [];
        
        // Group hashes by prefix
        foreach ($hashes as $hash) {
            $hash = strtolower(trim($hash));
            if (strlen($hash) >= 2) {
                $prefix = substr($hash, 0, 2);
                $byPrefix[$prefix][] = $hash;
            }
        }
        
        // Load each needed shard and lookup
        foreach ($byPrefix as $prefix => $hashList) {
            $shard = $this->loadShard($prefix);
            if ($shard) {
                foreach ($hashList as $hash) {
                    if (isset($shard[$hash])) {
                        $results[$hash] = $shard[$hash];
                    }
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Load a shard file (with caching)
     */
    private function loadShard($prefix) {
        // Check cache first
        if (isset($this->shardCache[$prefix])) {
            return $this->shardCache[$prefix];
        }
        
        $shardFile = $this->dataDir . "shard_{$prefix}.json";
        if (!file_exists($shardFile)) {
            return null;
        }
        
        $data = json_decode(file_get_contents($shardFile), true);
        
        // Manage cache size
        if (count($this->shardCache) >= $this->maxCachedShards) {
            // Remove oldest shard from cache (FIFO)
            array_shift($this->shardCache);
        }
        
        $this->shardCache[$prefix] = $data;
        return $data;
    }
    
    /**
     * Get total hash count from metadata
     */
    public function getTotalCount() {
        $metaFile = $this->dataDir . "shards_meta.json";
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            return $meta["total_hashes"] ?? 0;
        }
        return 0;
    }
    
    /**
     * Search for hashes by filename pattern (searches all shards)
     * Warning: This is slow for large datasets, use sparingly
     */
    public function searchByFilename($pattern, $limit = 50) {
        $results = [];
        $pattern = strtolower($pattern);
        
        // Get list of shard files
        $shardFiles = glob($this->dataDir . "shard_*.json");
        
        foreach ($shardFiles as $shardFile) {
            if (count($results) >= $limit) break;
            
            $data = json_decode(file_get_contents($shardFile), true);
            if (!$data) continue;
            
            foreach ($data as $hash => $info) {
                if (count($results) >= $limit) break;
                
                $filename = strtolower($info["filename"] ?? "");
                if (strpos($filename, $pattern) !== false) {
                    $results[$hash] = $info;
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Get stats about the sharded collection
     */
    public function getStats() {
        $metaFile = $this->dataDir . "shards_meta.json";
        if (file_exists($metaFile)) {
            return json_decode(file_get_contents($metaFile), true);
        }
        return null;
    }
    
    /**
     * Clear the in-memory cache
     */
    public function clearCache() {
        $this->shardCache = [];
    }
}
