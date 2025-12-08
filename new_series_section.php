if (isset($_GET['action']) && $_GET['action'] == 'get_series') {
    // Cache settings - 6 hours
    $cacheDir = __DIR__ . '/cache/';
    $cacheTimeFile = $cacheDir . 'tv_playlist_time.txt';
    $cacheMaxAge = 6 * 60 * 60;
    
    // Ensure cache directory exists
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    // Check if we need to update (cache expired or doesn't exist)
    $needsUpdate = true;
    if (!$GLOBALS['userCreatePlaylist'] && file_exists($cacheTimeFile) && file_exists('tv_playlist.json')) {
        $cacheTime = (int)@file_get_contents($cacheTimeFile);
        if ((time() - $cacheTime) < $cacheMaxAge) {
            $needsUpdate = false;
        }
    }
    
    // If using local playlist (userCreatePlaylist=true), skip caching entirely
    if ($GLOBALS['userCreatePlaylist']) {
        $needsUpdate = false;
    }
    
    // Fetch fresh data if cache is stale
    if ($needsUpdate && !$GLOBALS['userCreatePlaylist']) {
        $jsonUrl = "https://raw.githubusercontent.com/gogetta69/public-files/main/tv_playlist.json";
        $jsonContent = @file_get_contents($jsonUrl);
        
        if ($jsonContent !== false) {
            $BasePath = rtrim($BasePath, '/');
            $jsonContent = str_replace('[[SERVER_URL]]', $BasePath, $jsonContent);
            
            // Save to tv_playlist.json
            @file_put_contents('tv_playlist.json', $jsonContent);
            
            // Update cache timestamp
            @file_put_contents($cacheTimeFile, time());
        } else {
            echo "Failed to load the JSON file.";
            exit;
        }
    }
    
    // Redirect to the cached file
    header('HTTP/1.1 302 Moved Temporarily');
    header('Location: tv_playlist.json');
    exit();
}

