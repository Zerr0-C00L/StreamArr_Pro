<?php
// Optimized Real-Debrid functions - to be inserted into play.php

function selectHashByPreferences($torrents, $maxResolution, $tSite, $service)
{
    global $PRIVATE_TOKEN, $usePremiumize, $useRealDebrid, $seasonNoPad;

    // === Filter out DTS ===
    $filteredOutCount = 0;
    $torrents = array_filter($torrents, function ($torrent) use (&$filteredOutCount) {
        if (stripos($torrent['extracted_title'], 'DTS') !== false) {
            $filteredOutCount++;
            return false;
        }
        return true;
    });

    if ($GLOBALS['DEBUG']) {
        echo "Total torrents filtered out due to DTS audio: " . $filteredOutCount . "</br></br>";
    }
    if (empty($torrents)) {
        if ($GLOBALS['DEBUG']) {
            echo "No torrents available after filtering out DTS audio.</br></br>";
        }
        return false;
    }

    // === Remove duplicate hashes ===
    $uniqueTorrents = [];
    foreach ($torrents as $torrent) {
        $key = strtolower($torrent['hash']);
        if (!array_key_exists($key, $uniqueTorrents)) {
            $uniqueTorrents[$key] = $torrent;
        }
    }
    $torrents = array_values($uniqueTorrents);

    $initialCount = count($torrents);
    $availableCountRealDebrid = $initialCount;
    $availableCountPremiumize = $initialCount;

    // === Sorting + resolution filtering before any service checks ===
    sortTorrentsByQuality($torrents);
    $sortedTorrents = filterTorrentsByResolution($torrents, $maxResolution);

    // === RealDebrid OPTIMIZED: Batch check instant availability FIRST ===
    if ($useRealDebrid === true && $service === 'RealDebrid') {
        if ($GLOBALS['DEBUG']) {
            echo "⚡ OPTIMIZED RD: Batch checking " . count($sortedTorrents) . " hashes for instant availability...</br></br>";
        }
        
        // Collect all hashes
        $allHashes = [];
        $hashToTorrent = [];
        foreach ($sortedTorrents as $torrent) {
            $hash = strtolower($torrent['hash']);
            $allHashes[] = $hash;
            $hashToTorrent[$hash] = $torrent;
        }
        
        // Batch check instant availability (ONE API call for ALL hashes!)
        $cachedHashes = batchCheckInstantAvailability_RD($allHashes);
        
        if ($GLOBALS['DEBUG']) {
            echo "⚡ Found " . count($cachedHashes) . " cached torrents out of " . count($allHashes) . "</br></br>";
        }
        
        // Process only cached torrents (already sorted by quality)
        foreach ($sortedTorrents as $torrent) {
            $hash = strtolower($torrent['hash']);
            if (!isset($cachedHashes[$hash])) {
                continue; // Skip non-cached torrents
            }
            
            if ($GLOBALS['DEBUG']) {
                echo "⚡ Processing CACHED torrent: " . ($torrent['extracted_title'] ?? $hash) . "</br>";
            }
            
            // For cached torrents, use fast path
            $streamUrl = processInstantTorrent_RD($torrent, $cachedHashes[$hash]);
            if ($streamUrl) {
                return [
                    $streamUrl,
                    $availableCountRealDebrid,
                ];
            }
        }
        
        // Fallback: try non-cached torrents (old behavior, limited attempts)
        if ($GLOBALS['DEBUG']) {
            echo "⚠️ No cached torrents worked, trying non-cached (up to 3)...</br></br>";
        }
        $attemptCount = 0;
        foreach ($sortedTorrents as $torrent) {
            $hash = strtolower($torrent['hash']);
            if (isset($cachedHashes[$hash])) {
                continue; // Already tried cached ones
            }
            
            $streamUrl = instantAvailability_RD([$torrent], $tSite);
            if ($streamUrl) {
                return [
                    $streamUrl,
                    $availableCountRealDebrid,
                ];
            }
            
            $attemptCount++;
            if ($attemptCount >= 3) {
                break;
            }
        }
        
        return false;
    }

    // === Premiumize branch (unchanged) ===
    $attemptCount = 0;
    foreach ($sortedTorrents as $torrent) {
        $selectedHash = $torrent['hash'];

        if ($usePremiumize === true && $service === 'Premiumize') {
            $getStreamingLinkReturn = getStreamingLink_PM([$torrent], 'magnet:?xt=urn:btih:' . $selectedHash, $tSite);
            if ($getStreamingLinkReturn) {
                return [
                    $getStreamingLinkReturn,
                    $availableCountPremiumize,
                ];
            }
        }

        $attemptCount++;
        if ($attemptCount >= 5) {
            return false; // don't loop endlessly
        }
    }

    if ($GLOBALS['DEBUG']) {
        echo "No hash was found to be suitable.</br></br>";
    }
    return false;
}

/**
 * Batch check instant availability for multiple hashes in ONE API call
 * This is the key optimization - instead of 4-5 calls per torrent, we do 1 call for ALL torrents
 * 
 * @param array $hashes Array of torrent hashes to check
 * @return array Associative array of cached hashes with their file info [hash => fileInfo]
 */
function batchCheckInstantAvailability_RD($hashes) {
    global $PRIVATE_TOKEN;
    
    if (empty($hashes) || empty($PRIVATE_TOKEN)) {
        return [];
    }
    
    // Real-Debrid batch API: /torrents/instantAvailability/{hash1}/{hash2}/{hash3}...
    $hashString = implode('/', $hashes);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.real-debrid.com/rest/1.0/torrents/instantAvailability/" . $hashString);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $PRIVATE_TOKEN"
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($GLOBALS['DEBUG']) {
        echo "📡 Batch instantAvailability API response code: $httpCode</br>";
    }
    
    if ($httpCode !== 200 || !$response) {
        if ($GLOBALS['DEBUG']) {
            echo "❌ Batch check failed</br></br>";
        }
        return [];
    }
    
    $availability = json_decode($response, true);
    $cachedHashes = [];
    
    // Parse the response - format is: { "hash": { "rd": [ { fileId: {filename, filesize} } ] } }
    foreach ($hashes as $hash) {
        $hashLower = strtolower($hash);
        if (isset($availability[$hashLower]) && !empty($availability[$hashLower]['rd'])) {
            // Has cached data - extract first available variant
            $rdData = $availability[$hashLower]['rd'];
            if (is_array($rdData) && !empty($rdData)) {
                $firstVariant = reset($rdData);
                $cachedHashes[$hashLower] = [
                    'rd_data' => $firstVariant,
                    'all_variants' => $rdData
                ];
            }
        }
    }
    
    return $cachedHashes;
}

/**
 * Process a torrent that we KNOW is instantly available (cached)
 * This is fast: addMagnet → selectFiles → unrestrict (3 calls instead of 5+)
 * 
 * @param array $torrent The torrent data
 * @param array $cachedInfo Info from batch availability check
 * @return string|false Download URL or false on failure
 */
function processInstantTorrent_RD($torrent, $cachedInfo) {
    global $PRIVATE_TOKEN;
    
    // Build magnet from hash
    $hash = $torrent['hash'] ?? $torrent['infoHash'] ?? '';
    if (empty($hash)) {
        return false;
    }
    
    $magnet = "magnet:?xt=urn:btih:" . $hash;
    
    if ($GLOBALS['DEBUG']) {
        echo "⚡ Fast processing cached torrent: $hash</br>";
    }
    
    // Step 1: Add magnet (instant for cached torrents)
    $added = Stremio_rd_api('torrents/addMagnet', ['magnet' => $magnet], 'POST');
    
    if (!isset($added['id'])) {
        if ($GLOBALS['DEBUG']) {
            echo "❌ Failed to add cached magnet</br>";
        }
        return false;
    }
    
    $torrentId = $added['id'];
    
    // Step 2: Get info and find best video file
    $info = Stremio_rd_api("torrents/info/$torrentId", [], 'GET');
    
    if (empty($info['files'])) {
        Stremio_rd_api("torrents/delete/$torrentId", [], 'DELETE');
        return false;
    }
    
    $video = findVideoIndex_RD($info['files']);
    
    if (!$video) {
        Stremio_rd_api("torrents/delete/$torrentId", [], 'DELETE');
        return false;
    }
    
    // Step 3: Select the video file
    Stremio_rd_api("torrents/selectFiles/$torrentId", ['files' => $video['id']], 'POST');
    
    // Step 4: Get updated info with links
    $info = Stremio_rd_api("torrents/info/$torrentId", [], 'GET');
    
    if (empty($info['links'])) {
        if ($GLOBALS['DEBUG']) {
            echo "❌ No links available after selection</br>";
        }
        Stremio_rd_api("torrents/delete/$torrentId", [], 'DELETE');
        return false;
    }
    
    // Step 5: Unrestrict the link
    $rdUrl = $info['links'][0];
    $unrestricted = Stremio_rd_api("unrestrict/link", ['link' => $rdUrl], 'POST');
    
    // Cleanup
    Stremio_rd_api("torrents/delete/$torrentId", [], 'DELETE');
    
    if (!empty($unrestricted['download'])) {
        if ($GLOBALS['DEBUG']) {
            echo "🎉 SUCCESS! Instant torrent link: " . $unrestricted['download'] . "</br></br>";
        }
        return $unrestricted['download'];
    }
    
    return false;
}
