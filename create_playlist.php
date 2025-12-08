<?php
// Created By gogetta.teams@gmail.com
// Please leave this in this script.
// https://github.com/Zerr0-C00L/tmdb-to-vod-playlist/

require_once 'config.php';
set_time_limit(0); // Remove PHP's time restriction

if ($GLOBALS['DEBUG'] !== true) {
    error_reporting(0);	
} else {
	accessLog();
}	

$domain = 'http://localhost';

if (php_sapi_name() != 'cli') {
    
    if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
        $domain = 'http://' . $_SERVER['HTTP_HOST'];
    }
} else {
    
    if (isset($userSetHost) && !empty($userSetHost)) {
        $domain = 'http://' . $userSetHost;
    }
}

$basePath = '/'; 

// If the script is not running in CLI mode, set the base path
if (php_sapi_name() != 'cli') {
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
}

$playVodUrl = $domain . $basePath . "play.php";

//Set globals
$minYear = 1970; // Skip older titles
$minRuntime = 30; // In Minutes. Works with /discover only.
$num = 0;
$outputData = []; // Initialize json content
$outputContent = "#EXTM3U\n"; // Initialize M3U8 content
$addedMovieIds = []; // Initialize to prevent duplicates


fetchMovies($playVodUrl, $language, $apiKey, $totalPages);

function fetchMovies($playVodUrl, $language, $apiKey, $totalPages)
{
    global $listType, $outputData, $outputContent, $num;

	//Limit some categories to less items. (This allows the other categories to be populated)
	$limitTotalPages = ($totalPages > 15) ? 15 : $totalPages;
	
    // Call the function for now playing
    measureExecutionTime('fetchNowPlayingMovies', $playVodUrl, $language, $apiKey, $limitTotalPages);

    // Call the function for popular
    measureExecutionTime('fetchPopularMovies', $playVodUrl, $language, $apiKey, $limitTotalPages);

    // Call the function for genres
    measureExecutionTime('fetchGenres', $playVodUrl, $language, $apiKey, $totalPages);

    //Save the Json and M3U8 Data
    file_put_contents('playlist.m3u8', $outputContent);

    file_put_contents('playlist.json', json_encode($outputData));

    return;
}


// Function to fetch and handle errors for a URL
function fetchAndHandleErrors($url, $errorMessage)
{
    try {
        $response = file_get_contents($url);

        if ($response !== false) {
            $data = json_decode($response, true);
            if ($data !== null) {
                return $data;
            } else {
                error_log($errorMessage . ' Invalid JSON format');
            }
        } else {
            error_log($errorMessage . ' Request failed');
        }
    }
    catch (exception $error) {
        error_log($errorMessage . ' ' . $error->getMessage());
    }
    return null;
}

/**
 * Add movie entries to output arrays (supports multi-quality variants)
 * Returns true if movie was added (not duplicate)
 */
function addMovieEntry($movie, $playVodUrl, $group, &$num, &$outputData, &$outputContent, &$addedMovieIds) {
    $id = $movie['id'];
    
    // Check if the movie ID has already been added
    if (isset($addedMovieIds[$id])) {
        return false;
    }
    
    // Parse date/year
    if (isset($movie['release_date'])) {
        $dateParts = explode("-", $movie['release_date']);
        $year = $dateParts[0];
        $date = $movie['release_date'];
        $timestamp = strtotime($date);
    } else { 
        $date = '1970-01-01';
        $year = '1970';
        $timestamp = 24034884;
    }
    
    $title = $movie['title'];
    $poster = 'https://image.tmdb.org/t/p/original' . $movie['poster_path'];
    $backdrop = 'https://image.tmdb.org/t/p/original' . $movie['backdrop_path'];
    $rating = isset($movie['vote_average']) ? $movie['vote_average'] : 0;
    $plot = $movie['overview'] ?? '';
    
    // Check if multi-quality variants are enabled
    $enableVariants = $GLOBALS['ENABLE_QUALITY_VARIANTS'] ?? false;
    $qualityVariants = $GLOBALS['QUALITY_VARIANTS'] ?? ['4k', '1080p', '720p'];
    $showFullStreamName = $GLOBALS['SHOW_FULL_STREAM_NAME'] ?? false;
    
    // Try to get cached streams from SQLite for full stream names
    $cachedStreams = [];
    if ($enableVariants && $showFullStreamName) {
        try {
            // Get IMDB ID first
            $imdbId = null;
            static $tmdbCache = [];
            static $dbInstance = null;
            
            // Try SQLite cache first
            if ($dbInstance === null) {
                require_once __DIR__ . '/libs/episode_cache_db.php';
                $dbInstance = new EpisodeCacheDB();
            }
            
            $movieData = $dbInstance->getMovie($id);
            if ($movieData && !empty($movieData['imdb_id'])) {
                $imdbId = $movieData['imdb_id'];
            }
            
            // Fetch streams if we have IMDB ID
            if ($imdbId) {
                $cachedStreams = $dbInstance->getStreams($imdbId, 'movie');
            }
        } catch (Exception $e) {
            // Silent fail - continue without stream names
        }
    }
    
    if ($enableVariants && !empty($qualityVariants)) {
        // Generate multiple entries for each quality
        foreach ($qualityVariants as $quality) {
            $qualityLabel = strtoupper($quality);
            $qualitySuffix = strtolower($quality);
            
            // Create unique stream_id for this quality variant
            // Format: movieId * 10 + quality_index to avoid collisions
            $qualityIndex = array_search($quality, ['4k', '1080p', '720p', '480p']);
            $streamId = $id * 10 + ($qualityIndex !== false ? $qualityIndex : 0);
            
            // Try to find matching stream for full name
            $displayName = "{$title} ({$year}) [{$qualityLabel}]";
            if ($showFullStreamName && !empty($cachedStreams)) {
                // Find best matching stream for this quality
                $qualityPatterns = [
                    '4k' => '/\b(4K|2160p|UHD)\b/i',
                    '2160p' => '/\b(4K|2160p|UHD)\b/i',
                    '1080p' => '/\b1080p\b/i',
                    '720p' => '/\b720p\b/i',
                    '480p' => '/\b480p\b/i'
                ];
                $pattern = $qualityPatterns[$qualitySuffix] ?? null;
                
                if ($pattern) {
                    foreach ($cachedStreams as $stream) {
                        $streamTitle = $stream['title'] ?? '';
                        if (preg_match($pattern, $streamTitle)) {
                            // Extract just the release name (first line, clean it up)
                            $streamName = trim(explode("\n", $streamTitle)[0]);
                            // Remove emoji and extra info
                            $streamName = preg_replace('/^👤.*$|^💾.*$|^⚙️.*$/m', '', $streamName);
                            $streamName = trim($streamName);
                            if (!empty($streamName)) {
                                $displayName = $streamName;
                            }
                            break;
                        }
                    }
                }
            }
            
            $movieData = [
                "num" => ++$num,
                "name" => $displayName,
                "stream_type" => "movie",
                "stream_id" => $streamId,
                "stream_icon" => $poster,
                "rating" => $rating,
                "rating_5based" => $rating / 2,
                "added" => $timestamp,
                "category_id" => 999992,
                "container_extension" => "mp4",
                "custom_sid" => null,
                "direct_source" => "{$playVodUrl}?movieId={$id}&type=movies&quality={$qualitySuffix}",
                "plot" => $plot,
                "backdrop_path" => $backdrop,
                "group" => $group,
                "quality" => $qualitySuffix
            ];
            
            $outputData[] = $movieData;
            $outputContent .= "#EXTINF:-1 group-title=\"{$group}\" tvg-id=\"{$title}\" tvg-logo=\"{$poster}\",{$displayName}\n{$playVodUrl}?movieId={$id}&type=movies&quality={$qualitySuffix}\n\n";
        }
    } else {
        // Single entry (original behavior, optionally with default quality)
        $defaultQuality = $GLOBALS['DEFAULT_QUALITY'] ?? null;
        $qualityParam = $defaultQuality ? "&quality={$defaultQuality}" : "";
        $nameQualitySuffix = $defaultQuality ? " [" . strtoupper($defaultQuality) . "]" : "";
        
        $movieData = [
            "num" => ++$num,
            "name" => "{$title} ({$year}){$nameQualitySuffix}",
            "stream_type" => "movie",
            "stream_id" => $id,
            "stream_icon" => $poster,
            "rating" => $rating,
            "rating_5based" => $rating / 2,
            "added" => $timestamp,
            "category_id" => 999992,
            "container_extension" => "mp4",
            "custom_sid" => null,
            "direct_source" => "{$playVodUrl}?movieId={$id}{$qualityParam}",
            "plot" => $plot,
            "backdrop_path" => $backdrop,
            "group" => $group
        ];
        
        $outputData[] = $movieData;
        $outputContent .= "#EXTINF:-1 group-title=\"{$group}\" tvg-id=\"{$title}\" tvg-logo=\"{$poster}\",{$title} ({$year}){$nameQualitySuffix}\n{$playVodUrl}?movieId={$id}{$qualityParam}\n\n";
    }
    
    // Mark the movie ID as added
    $addedMovieIds[$id] = true;
    return true;
}

// Fetch now playing movies
function fetchNowPlayingMovies($playVodUrl, $language, $apiKey, $totalPages)
{
    global $outputData, $outputContent, $addedMovieIds, $movies_with_origin_country, $listType, $num;
    $baseUrl = 'https://api.themoviedb.org/3/movie/now_playing';
	
     $capturedTotalPages = null; 
    //$pagesForCategory = ceil(0.20 * $totalPages); // Calculate 20% of $totalPages for this category
    for ($page = 1; $page <= $totalPages; $page++) {
        // with_release_type=4|5|6 filters to Digital/Streaming (4), Physical/BluRay (5), or TV (6) releases only
        $url = $baseUrl . "?api_key=$apiKey&include_adult=false&with_origin_country=$movies_with_origin_country&with_release_type=4|5|6&language=$language&page=$page";
        $data = fetchAndHandleErrors($url, 'Request for now playing movies failed.');
		
        // Set the total pages after the first request
        if ($page == 1 && isset($data['total_pages'])) {
            $capturedTotalPages = $data['total_pages'];
        }

        if ($data !== null) {
            $movies = $data['results'];

            foreach ($movies as $movie) {
                // Skip invalid movies
                if (!isValidMovie($movie)) {
                    continue;
                }
                // Use helper function for multi-quality support
                addMovieEntry($movie, $playVodUrl, 'Now Playing', $num, $outputData, $outputContent, $addedMovieIds);
            }
        }
		
		if ($capturedTotalPages !== null && $page >= $capturedTotalPages) {
            break; // break out of the loop
        }
    }

    return;
}

// Fetch popular movies
function fetchPopularMovies($playVodUrl, $language, $apiKey, $totalPages)
{
    global $outputData, $outputContent, $addedMovieIds, $movies_with_origin_country, $listType, $num;
    $baseUrl = 'https://api.themoviedb.org/3/movie/popular';
	
	 $capturedTotalPages = null;

    for ($page = 1; $page <= $totalPages; $page++) {
        // with_release_type=4|5|6 filters to Digital/Streaming (4), Physical/BluRay (5), or TV (6) releases only
        $url = $baseUrl . "?api_key=$apiKey&include_adult=false&with_origin_country=$movies_with_origin_country&with_release_type=4|5|6&language=$language&page=$page";
        $data = fetchAndHandleErrors($url, 'Request for popular movies failed.');
		
        // Set the total pages after the first request
        if ($page == 1 && isset($data['total_pages'])) {
            $capturedTotalPages = $data['total_pages'];
        }

        if ($data !== null) {
            $movies = $data['results'];


            foreach ($movies as $movie) {
                // Skip invalid movies
                if (!isValidMovie($movie)) {
                    continue;
                }
                // Use helper function for multi-quality support
                addMovieEntry($movie, $playVodUrl, 'Popular', $num, $outputData, $outputContent, $addedMovieIds);
            }
        }
		if ($capturedTotalPages !== null && $page >= $capturedTotalPages) {
            break; // break out of the loop
        }
    }

    return;

}

// Fetch genres and movies for each genre
function fetchMoviesByGenre($genreId, $genreName, $playVodUrl, $language, $apiKey,
    $totalPages)
{
    global $outputData, $outputContent, $addedMovieIds, $movies_with_origin_country, $listType, $num;
    $baseUrl = 'https://api.themoviedb.org/3/discover/movie';
	
	$capturedTotalPages = null;

    for ($page = 1; $page <= $totalPages; $page++) {
        // with_release_type=4|5|6 filters to Digital/Streaming (4), Physical/BluRay (5), or TV (6) releases only
        $url = $baseUrl . "?api_key=$apiKey&include_adult=false&with_runtime.gte=$minRuntime&with_origin_country=$movies_with_origin_country&with_release_type=4|5|6&language=$language&with_genres=$genreId&page=$page";
        $data = fetchAndHandleErrors($url, "Request for $genreName movies failed.");
		
        // Set the total pages after the first request
        if ($page == 1 && isset($data['total_pages'])) {
            $capturedTotalPages = $data['total_pages'];
        }

        if ($data !== null) {
            $movies = $data['results'];

            foreach ($movies as $movie) {
                // Skip invalid movies
                if (!isValidMovie($movie)) {
                    continue;
                }
                // Use helper function for multi-quality support
                addMovieEntry($movie, $playVodUrl, $genreName, $num, $outputData, $outputContent, $addedMovieIds);
            }
        }
		if ($capturedTotalPages !== null && $page >= $capturedTotalPages) {
            break; // break out of the loop
        }
    }

    return;
}

// Fetch genres dynamically
function fetchGenres($playVodUrl, $language, $apiKey, $totalPages)
{
    global $outputData, $outputContent, $listType, $num;

    $genresUrl = "https://api.themoviedb.org/3/genre/movie/list?api_key=$apiKey&include_adult=false&language=$language";
    $genreData = fetchAndHandleErrors($genresUrl, 'Request for genres failed.');
    if ($genreData !== null) {
        $genres = $genreData['genres'];

        foreach ($genres as $genre) {
            if ($listType == 'json') {
                fetchMoviesByGenre($genre['id'], $genre['name'], $playVodUrl, $language, $apiKey,
                    $totalPages);
            } else {
                fetchMoviesByGenre($genre['id'], $genre['name'], $playVodUrl, $language, $apiKey,
                    $totalPages);
            }
        }
    }

    return;
}

function measureExecutionTime($func, ...$params) {
    $start = microtime(true);

    call_user_func($func, ...$params);

    $end = microtime(true);
    $elapsedTime = $end - $start;

    $minutes = (int) ($elapsedTime / 60);
    $seconds = $elapsedTime % 60;
    $milliseconds = ($seconds - floor($seconds)) * 1000;

    echo "Total Execution Time for $func: " . $minutes . " minute(s) and " . floor($seconds) . "." . sprintf('%03d', $milliseconds) . " second(s)</br>";
}

function isValidMovie($movie) {
		global $minYear;
    // Check if movie has a poster image
    if (empty($movie['poster_path'])) {
        return false;
    }
    
    // Check release year
    $releaseDate = $movie['release_date'] ?? '1970-01-01';
    $year = (int)substr($releaseDate, 0, 4);
    
    // Skip movies older than minYear
    if ($year < $minYear) {
        return false;
    }
    
    // Exclude movies with future release dates
    $currentDate = time();
    $movieReleaseTimestamp = strtotime($releaseDate);
    
    if ($movieReleaseTimestamp > $currentDate) {
        return false;
    }
    
    return true;
}
?>
