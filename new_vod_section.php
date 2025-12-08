if (isset($_GET['action']) && $_GET['action'] == 'get_vod_streams') {
    // Cache settings - 6 hours
    $cacheDir = __DIR__ . '/cache/';
    $cacheTimeFile = $cacheDir . 'vod_playlist_time.txt';
    $cacheMaxAge = 6 * 60 * 60;
    
    // Ensure cache directory exists
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    // Check if we need to update (cache expired or doesn't exist)
    $needsUpdate = true;
    if (!$GLOBALS['userCreatePlaylist'] && file_exists($cacheTimeFile) && file_exists('playlist.json')) {
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
        // Handle adult VOD
        if ($GLOBALS['INCLUDE_ADULT_VOD']) {
            $jsonUrl = "https://raw.githubusercontent.com/gogetta69/public-files/main/adult-movies.json";
            $jsonContent = @file_get_contents($jsonUrl);
            
            if ($jsonContent !== false) {
                $BasePath = rtrim($BasePath, '/');
                $jsonContent = str_replace('[[SERVER_URL]]', $BasePath, $jsonContent);
                @file_put_contents('adult-movies.json', $jsonContent);
            }
        }
        
        // Fetch main playlist from GitHub
        $jsonUrl = "https://raw.githubusercontent.com/gogetta69/public-files/main/playlist.json";
        $jsonContent = @file_get_contents($jsonUrl);
        
        if ($jsonContent !== false) {
            $BasePath = rtrim($BasePath, '/');
            $jsonContent = str_replace('[[SERVER_URL]]', $BasePath, $jsonContent);
            
            // Save to playlist.json
            @file_put_contents('playlist.json', $jsonContent);
            
            // Update cache timestamp
            @file_put_contents($cacheTimeFile, time());
        }
        
        // Also fetch m3u8
        $m3u8Url = "https://raw.githubusercontent.com/gogetta69/public-files/main/playlist.m3u8";
        $m3u8Content = @file_get_contents($m3u8Url);
        if ($m3u8Content !== false) {
            $m3u8Content = str_replace('[[SERVER_URL]]', $BasePath, $m3u8Content);
            @file_put_contents('playlist.m3u8', $m3u8Content);
        }
    }
    
    // Now serve the response - use existing playlist.json if it exists
    if ($GLOBALS['INCLUDE_ADULT_VOD']) {
        $playlistJson = @file_get_contents('playlist.json');
        $playlist = json_decode($playlistJson, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(["error" => "JSON decoding error in playlist.json: " . json_last_error_msg()]);
            exit;
        }
        
        $adultJsonContent = @file_get_contents('adult-movies.json');
        if ($adultJsonContent !== false) {
            $adultMovies = json_decode($adultJsonContent, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $playlist = array_merge($playlist, $adultMovies);
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode($playlist);
        exit();
    }
    
    if (isset($_GET['type']) && ($_GET['type'] == 'm3u8' || $_GET['type'] == 'm3u')) {
        header('HTTP/1.1 302 Moved Temporarily');
        header('Location: playlist.m3u8');
    } else {
        header('HTTP/1.1 302 Moved Temporarily');
        header('Location: playlist.json');
    }
    
    exit();
}

