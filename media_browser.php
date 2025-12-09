<?php
/**
 * Media Browser - TMDB-powered media discovery and management
 * Browse movies/TV, watch trailers, add to playlist
 */

require_once __DIR__ . '/config.php';

// API handling
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    $tmdbKey = $apiKey ?? '';
    $baseUrl = 'https://api.themoviedb.org/3';
    
    switch ($_GET['api']) {
        case 'trending':
            $type = $_GET['type'] ?? 'movie'; // movie or tv
            $page = intval($_GET['page'] ?? 1);
            $url = "$baseUrl/trending/$type/week?api_key=$tmdbKey&page=$page";
            echo fetchTmdb($url);
            break;
            
        case 'popular':
            $type = $_GET['type'] ?? 'movie';
            $page = intval($_GET['page'] ?? 1);
            $url = "$baseUrl/$type/popular?api_key=$tmdbKey&page=$page";
            echo fetchTmdb($url);
            break;
            
        case 'top_rated':
            $type = $_GET['type'] ?? 'movie';
            $page = intval($_GET['page'] ?? 1);
            $url = "$baseUrl/$type/top_rated?api_key=$tmdbKey&page=$page";
            echo fetchTmdb($url);
            break;
            
        case 'now_playing':
            $page = intval($_GET['page'] ?? 1);
            $url = "$baseUrl/movie/now_playing?api_key=$tmdbKey&page=$page";
            echo fetchTmdb($url);
            break;
            
        case 'upcoming':
            $page = intval($_GET['page'] ?? 1);
            $url = "$baseUrl/movie/upcoming?api_key=$tmdbKey&page=$page";
            echo fetchTmdb($url);
            break;
            
        case 'on_the_air':
            $page = intval($_GET['page'] ?? 1);
            $url = "$baseUrl/tv/on_the_air?api_key=$tmdbKey&page=$page";
            echo fetchTmdb($url);
            break;
            
        case 'search':
            $query = urlencode($_GET['query'] ?? '');
            $type = $_GET['type'] ?? 'multi'; // multi, movie, tv
            $page = intval($_GET['page'] ?? 1);
            $url = "$baseUrl/search/$type?api_key=$tmdbKey&query=$query&page=$page";
            echo fetchTmdb($url);
            break;
            
        case 'details':
            $type = $_GET['type'] ?? 'movie';
            $id = intval($_GET['id'] ?? 0);
            $url = "$baseUrl/$type/$id?api_key=$tmdbKey&append_to_response=videos,credits,external_ids,recommendations,similar";
            echo fetchTmdb($url);
            break;
            
        case 'season':
            $tvId = intval($_GET['tv_id'] ?? 0);
            $seasonNum = intval($_GET['season'] ?? 1);
            $url = "$baseUrl/tv/$tvId/season/$seasonNum?api_key=$tmdbKey";
            echo fetchTmdb($url);
            break;
            
        case 'genres':
            $type = $_GET['type'] ?? 'movie';
            $url = "$baseUrl/genre/$type/list?api_key=$tmdbKey";
            echo fetchTmdb($url);
            break;
            
        case 'discover':
            $type = $_GET['type'] ?? 'movie';
            $page = intval($_GET['page'] ?? 1);
            $params = "api_key=$tmdbKey&page=$page&sort_by=popularity.desc";
            
            if (!empty($_GET['genre'])) {
                $params .= "&with_genres=" . intval($_GET['genre']);
            }
            if (!empty($_GET['year'])) {
                $yearField = $type === 'movie' ? 'primary_release_year' : 'first_air_date_year';
                $params .= "&$yearField=" . intval($_GET['year']);
            }
            if (!empty($_GET['sort'])) {
                $params .= "&sort_by=" . urlencode($_GET['sort']);
            }
            
            $url = "$baseUrl/discover/$type?$params";
            echo fetchTmdb($url);
            break;
            
        case 'add_to_playlist':
            $data = json_decode(file_get_contents('php://input'), true);
            $result = addToPlaylist($data);
            echo json_encode($result);
            break;
            
        case 'check_playlist':
            $type = $_GET['type'] ?? 'movie';
            $id = intval($_GET['id'] ?? 0);
            $result = checkInPlaylist($type, $id);
            echo json_encode($result);
            break;
            
        case 'get_custom_playlist':
            $file = __DIR__ . '/cache/custom_playlist.json';
            if (file_exists($file)) {
                echo file_get_contents($file);
            } else {
                echo json_encode(['movies' => [], 'tv' => []]);
            }
            break;
            
        case 'remove_from_playlist':
            $data = json_decode(file_get_contents('php://input'), true);
            $result = removeFromPlaylist($data);
            echo json_encode($result);
            break;
            
        default:
            echo json_encode(['error' => 'Unknown API endpoint']);
    }
    exit;
}

function fetchTmdb($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ?: json_encode(['error' => 'Failed to fetch data']);
}

function addToPlaylist($data) {
    $file = __DIR__ . '/cache/custom_playlist.json';
    $playlist = [];
    
    if (file_exists($file)) {
        $playlist = json_decode(file_get_contents($file), true) ?? [];
    }
    
    $type = $data['type'] ?? 'movie';
    $key = $type === 'movie' ? 'movies' : 'tv';
    
    if (!isset($playlist[$key])) {
        $playlist[$key] = [];
    }
    
    // Check if already exists
    $id = $data['id'] ?? 0;
    foreach ($playlist[$key] as $item) {
        if ($item['id'] == $id) {
            return ['success' => false, 'message' => 'Already in playlist'];
        }
    }
    
    // Add to playlist
    $playlist[$key][] = [
        'id' => $id,
        'title' => $data['title'] ?? '',
        'poster_path' => $data['poster_path'] ?? '',
        'overview' => $data['overview'] ?? '',
        'vote_average' => $data['vote_average'] ?? 0,
        'release_date' => $data['release_date'] ?? $data['first_air_date'] ?? '',
        'added_at' => date('Y-m-d H:i:s')
    ];
    
    file_put_contents($file, json_encode($playlist, JSON_PRETTY_PRINT));
    
    return ['success' => true, 'message' => 'Added to playlist'];
}

function removeFromPlaylist($data) {
    $file = __DIR__ . '/cache/custom_playlist.json';
    
    if (!file_exists($file)) {
        return ['success' => false, 'message' => 'Playlist not found'];
    }
    
    $playlist = json_decode(file_get_contents($file), true) ?? [];
    $type = $data['type'] ?? 'movie';
    $key = $type === 'movie' ? 'movies' : 'tv';
    $id = $data['id'] ?? 0;
    
    if (!isset($playlist[$key])) {
        return ['success' => false, 'message' => 'Not in playlist'];
    }
    
    $playlist[$key] = array_values(array_filter($playlist[$key], function($item) use ($id) {
        return $item['id'] != $id;
    }));
    
    file_put_contents($file, json_encode($playlist, JSON_PRETTY_PRINT));
    
    return ['success' => true, 'message' => 'Removed from playlist'];
}

function checkInPlaylist($type, $id) {
    // Check actual playlists (not custom playlist)
    if ($type === 'movie') {
        $file = __DIR__ . '/playlist.json';
        if (file_exists($file)) {
            $content = file_get_contents($file);
            // Check if stream_id exists in playlist
            if (preg_match('/"stream_id":' . preg_quote($id, '/') . '[,\}\s]/', $content)) {
                return ['in_playlist' => true];
            }
        }
    } else {
        $file = __DIR__ . '/tv_playlist.json';
        if (file_exists($file)) {
            $content = file_get_contents($file);
            // Check if series_id exists in playlist
            if (preg_match('/"series_id":' . preg_quote($id, '/') . '[,\}\s]/', $content)) {
                return ['in_playlist' => true];
            }
        }
    }
    
    return ['in_playlist' => false];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Media Browser - TMDB</title>
    <style>
        :root {
            --bg-primary: #0d1117;
            --bg-secondary: #161b22;
            --bg-tertiary: #21262d;
            --bg-card: #1c2128;
            --text-primary: #f0f6fc;
            --text-secondary: #8b949e;
            --accent: #58a6ff;
            --accent-hover: #79c0ff;
            --success: #3fb950;
            --warning: #d29922;
            --danger: #f85149;
            --border-color: #30363d;
            --gold: #ffd700;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
        }
        
        /* Header */
        .header {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 2rem;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--accent);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .nav-tabs {
            display: flex;
            gap: 0.5rem;
        }
        
        .nav-tab {
            padding: 0.5rem 1rem;
            background: transparent;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        
        .nav-tab:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }
        
        .nav-tab.active {
            background: var(--accent);
            color: white;
        }
        
        .search-container {
            flex: 1;
            max-width: 500px;
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 1rem;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--accent);
        }
        
        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }
        
        .header-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: var(--accent);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--accent-hover);
        }
        
        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }
        
        .btn-secondary:hover {
            background: var(--bg-card);
        }
        
        /* Main Content */
        .main-content {
            padding: 2rem;
            max-width: 1800px;
            margin: 0 auto;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        /* Media Grid */
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .media-card {
            background: var(--bg-card);
            border-radius: 12px;
            overflow: hidden;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
        }
        
        .media-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        
        .media-poster {
            width: 100%;
            aspect-ratio: 2/3;
            object-fit: cover;
            background: var(--bg-tertiary);
        }
        
        .media-info {
            padding: 0.75rem;
        }
        
        .media-title {
            font-size: 0.95rem;
            font-weight: 500;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .media-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        
        .media-rating {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: var(--gold);
        }
        
        .media-badge {
            position: absolute;
            top: 0.5rem;
            left: 0.5rem;
            background: var(--accent);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .in-playlist-badge {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: var(--success);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
        }
        
        /* Filters */
        .filters-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-select {
            padding: 0.5rem 1rem;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 0.9rem;
            cursor: pointer;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--accent);
        }
        
        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.85);
            display: none;
            justify-content: center;
            align-items: flex-start;
            padding: 2rem;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: var(--bg-secondary);
            border-radius: 16px;
            max-width: 1000px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        
        .modal-backdrop {
            width: 100%;
            height: 400px;
            object-fit: cover;
            border-radius: 16px 16px 0 0;
        }
        
        .modal-backdrop-gradient {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 400px;
            background: linear-gradient(to bottom, transparent 0%, var(--bg-secondary) 100%);
            border-radius: 16px 16px 0 0;
        }
        
        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(0, 0, 0, 0.5);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }
        
        .modal-content {
            padding: 2rem;
            margin-top: -100px;
            position: relative;
            z-index: 5;
        }
        
        .modal-header {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .modal-poster {
            width: 200px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }
        
        .modal-details {
            flex: 1;
        }
        
        .modal-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .modal-tagline {
            color: var(--text-secondary);
            font-style: italic;
            margin-bottom: 1rem;
        }
        
        .modal-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .modal-meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .modal-genres {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        
        .genre-tag {
            background: var(--bg-tertiary);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        
        .modal-overview {
            line-height: 1.7;
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }
        
        .modal-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .btn-play {
            background: var(--success);
            color: white;
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
        }
        
        .btn-play:hover {
            background: #2ea043;
        }
        
        .btn-trailer {
            background: var(--danger);
            color: white;
        }
        
        .btn-add {
            background: var(--accent);
            color: white;
        }
        
        .btn-remove {
            background: var(--danger);
            color: white;
        }
        
        /* Sections in modal */
        .modal-section {
            margin-top: 2rem;
        }
        
        .modal-section-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Cast */
        .cast-grid {
            display: flex;
            gap: 1rem;
            overflow-x: auto;
            padding-bottom: 1rem;
        }
        
        .cast-card {
            flex-shrink: 0;
            width: 100px;
            text-align: center;
        }
        
        .cast-photo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 0.5rem;
            background: var(--bg-tertiary);
        }
        
        .cast-name {
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .cast-character {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }
        
        /* Seasons (for TV) */
        .seasons-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .season-card {
            display: flex;
            gap: 1rem;
            background: var(--bg-tertiary);
            padding: 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .season-card:hover {
            background: var(--bg-card);
        }
        
        .season-poster {
            width: 80px;
            border-radius: 6px;
        }
        
        .season-info h4 {
            margin-bottom: 0.25rem;
        }
        
        .season-info p {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        /* Similar/Recommendations */
        .recommendations-grid {
            display: flex;
            gap: 1rem;
            overflow-x: auto;
            padding-bottom: 1rem;
        }
        
        .rec-card {
            flex-shrink: 0;
            width: 150px;
            cursor: pointer;
        }
        
        .rec-poster {
            width: 100%;
            aspect-ratio: 2/3;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        
        .rec-title {
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Video Modal */
        .video-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.95);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }
        
        .video-modal.active {
            display: flex;
        }
        
        .video-container {
            width: 90%;
            max-width: 1200px;
            aspect-ratio: 16/9;
        }
        
        .video-container iframe {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 8px;
        }
        
        .video-close {
            position: absolute;
            top: 2rem;
            right: 2rem;
            background: transparent;
            border: none;
            color: white;
            font-size: 2rem;
            cursor: pointer;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }
        
        .pagination button {
            padding: 0.5rem 1rem;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 6px;
            cursor: pointer;
        }
        
        .pagination button:hover {
            background: var(--bg-card);
        }
        
        .pagination button.active {
            background: var(--accent);
            border-color: var(--accent);
        }
        
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Loading */
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 4rem;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 3px solid var(--bg-tertiary);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Toast */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            padding: 1rem 1.5rem;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            z-index: 3000;
            display: none;
            animation: slideIn 0.3s ease;
        }
        
        .toast.show {
            display: block;
        }
        
        .toast.success {
            border-color: var(--success);
        }
        
        .toast.error {
            border-color: var(--danger);
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        /* My List Tab */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-secondary);
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header {
                flex-wrap: wrap;
                padding: 1rem;
            }
            
            .search-container {
                order: 3;
                max-width: 100%;
                width: 100%;
            }
            
            .modal-header {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .modal-poster {
                width: 150px;
            }
            
            .media-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <a href="admin.php" class="logo">🎬 Media Browser</a>
        
        <nav class="nav-tabs">
            <button class="nav-tab active" data-tab="movies">Movies</button>
            <button class="nav-tab" data-tab="tv">TV Shows</button>
            <button class="nav-tab" data-tab="mylist">My List</button>
        </nav>
        
        <div class="search-container">
            <span class="search-icon">🔍</span>
            <input type="text" class="search-input" id="searchInput" placeholder="Search movies, TV shows...">
        </div>
        
        <div class="header-actions">
            <a href="admin.php" class="btn btn-secondary">← Back to Admin</a>
        </div>
    </header>
    
    <main class="main-content">
        <!-- Movies Tab -->
        <div id="movies-tab" class="tab-content">
            <div class="filters-bar">
                <select class="filter-select" id="movie-category">
                    <option value="trending">🔥 Trending</option>
                    <option value="popular">⭐ Popular</option>
                    <option value="top_rated">🏆 Top Rated</option>
                    <option value="now_playing">🎬 Now Playing</option>
                    <option value="upcoming">📅 Upcoming</option>
                    <option value="discover">🔍 Discover</option>
                </select>
                <select class="filter-select" id="movie-genre" style="display: none;">
                    <option value="">All Genres</option>
                </select>
                <select class="filter-select" id="movie-year" style="display: none;">
                    <option value="">All Years</option>
                </select>
            </div>
            
            <div id="movies-grid" class="media-grid">
                <div class="loading"><div class="spinner"></div></div>
            </div>
            
            <div class="pagination" id="movies-pagination"></div>
        </div>
        
        <!-- TV Tab -->
        <div id="tv-tab" class="tab-content" style="display: none;">
            <div class="filters-bar">
                <select class="filter-select" id="tv-category">
                    <option value="trending">🔥 Trending</option>
                    <option value="popular">⭐ Popular</option>
                    <option value="top_rated">🏆 Top Rated</option>
                    <option value="on_the_air">📺 On The Air</option>
                    <option value="discover">🔍 Discover</option>
                </select>
                <select class="filter-select" id="tv-genre" style="display: none;">
                    <option value="">All Genres</option>
                </select>
            </div>
            
            <div id="tv-grid" class="media-grid">
                <div class="loading"><div class="spinner"></div></div>
            </div>
            
            <div class="pagination" id="tv-pagination"></div>
        </div>
        
        <!-- My List Tab -->
        <div id="mylist-tab" class="tab-content" style="display: none;">
            <div class="section-header">
                <h2 class="section-title">📋 My Movies</h2>
            </div>
            <div id="mylist-movies" class="media-grid"></div>
            
            <div class="section-header" style="margin-top: 2rem;">
                <h2 class="section-title">📺 My TV Shows</h2>
            </div>
            <div id="mylist-tv" class="media-grid"></div>
        </div>
        
        <!-- Search Results -->
        <div id="search-tab" class="tab-content" style="display: none;">
            <div class="section-header">
                <h2 class="section-title">🔍 Search Results</h2>
            </div>
            <div id="search-grid" class="media-grid"></div>
            <div class="pagination" id="search-pagination"></div>
        </div>
    </main>
    
    <!-- Detail Modal -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal">
            <img class="modal-backdrop" id="modalBackdrop" src="" alt="">
            <div class="modal-backdrop-gradient"></div>
            <button class="modal-close" onclick="closeModal()">×</button>
            
            <div class="modal-content">
                <div class="modal-header">
                    <img class="modal-poster" id="modalPoster" src="" alt="">
                    <div class="modal-details">
                        <h1 class="modal-title" id="modalTitle"></h1>
                        <p class="modal-tagline" id="modalTagline"></p>
                        
                        <div class="modal-meta" id="modalMeta"></div>
                        <div class="modal-genres" id="modalGenres"></div>
                        
                        <p class="modal-overview" id="modalOverview"></p>
                        
                        <div class="modal-actions" id="modalActions"></div>
                    </div>
                </div>
                
                <!-- Cast Section -->
                <div class="modal-section" id="castSection">
                    <h3 class="modal-section-title">👥 Cast</h3>
                    <div class="cast-grid" id="castGrid"></div>
                </div>
                
                <!-- Seasons Section (TV only) -->
                <div class="modal-section" id="seasonsSection" style="display: none;">
                    <h3 class="modal-section-title">📺 Seasons</h3>
                    <div class="seasons-list" id="seasonsList"></div>
                </div>
                
                <!-- Recommendations -->
                <div class="modal-section" id="recommendationsSection">
                    <h3 class="modal-section-title">🎯 Recommendations</h3>
                    <div class="recommendations-grid" id="recommendationsGrid"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Video Modal -->
    <div class="video-modal" id="videoModal">
        <button class="video-close" onclick="closeVideoModal()">×</button>
        <div class="video-container">
            <iframe id="videoFrame" src="" allowfullscreen></iframe>
        </div>
    </div>
    
    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <script>
        const TMDB_IMG_BASE = 'https://image.tmdb.org/t/p/';
        let currentTab = 'movies';
        let currentPage = { movies: 1, tv: 1, search: 1 };
        let totalPages = { movies: 1, tv: 1, search: 1 };
        let searchQuery = '';
        let genres = { movie: [], tv: [] };
        let currentDetailType = 'movie';
        let currentDetailId = 0;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            loadGenres();
            loadMovies();
            setupEventListeners();
            generateYearOptions();
        });
        
        function setupEventListeners() {
            // Tab switching
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.addEventListener('click', () => switchTab(tab.dataset.tab));
            });
            
            // Search
            let searchTimeout;
            document.getElementById('searchInput').addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    searchQuery = e.target.value.trim();
                    if (searchQuery.length >= 2) {
                        currentPage.search = 1;
                        performSearch();
                    } else if (searchQuery.length === 0) {
                        switchTab(currentTab === 'search' ? 'movies' : currentTab);
                    }
                }, 500);
            });
            
            // Category filters
            document.getElementById('movie-category').addEventListener('change', () => {
                const cat = document.getElementById('movie-category').value;
                document.getElementById('movie-genre').style.display = cat === 'discover' ? 'block' : 'none';
                document.getElementById('movie-year').style.display = cat === 'discover' ? 'block' : 'none';
                currentPage.movies = 1;
                loadMovies();
            });
            
            document.getElementById('tv-category').addEventListener('change', () => {
                const cat = document.getElementById('tv-category').value;
                document.getElementById('tv-genre').style.display = cat === 'discover' ? 'block' : 'none';
                currentPage.tv = 1;
                loadTV();
            });
            
            document.getElementById('movie-genre').addEventListener('change', () => {
                currentPage.movies = 1;
                loadMovies();
            });
            
            document.getElementById('tv-genre').addEventListener('change', () => {
                currentPage.tv = 1;
                loadTV();
            });
            
            document.getElementById('movie-year').addEventListener('change', () => {
                currentPage.movies = 1;
                loadMovies();
            });
            
            // Close modals on escape
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closeModal();
                    closeVideoModal();
                }
            });
            
            // Close modal on backdrop click
            document.getElementById('detailModal').addEventListener('click', (e) => {
                if (e.target.classList.contains('modal-overlay')) {
                    closeModal();
                }
            });
        }
        
        function switchTab(tab) {
            currentTab = tab;
            
            document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
            
            document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
            
            if (tab === 'movies') {
                document.getElementById('movies-tab').style.display = 'block';
                if (document.getElementById('movies-grid').children.length <= 1) {
                    loadMovies();
                }
            } else if (tab === 'tv') {
                document.getElementById('tv-tab').style.display = 'block';
                if (document.getElementById('tv-grid').children.length <= 1) {
                    loadTV();
                }
            } else if (tab === 'mylist') {
                document.getElementById('mylist-tab').style.display = 'block';
                loadMyList();
            } else if (tab === 'search') {
                document.getElementById('search-tab').style.display = 'block';
            }
        }
        
        async function loadGenres() {
            try {
                const movieGenres = await fetch('?api=genres&type=movie').then(r => r.json());
                const tvGenres = await fetch('?api=genres&type=tv').then(r => r.json());
                
                genres.movie = movieGenres.genres || [];
                genres.tv = tvGenres.genres || [];
                
                populateGenreSelect('movie-genre', genres.movie);
                populateGenreSelect('tv-genre', genres.tv);
            } catch (e) {
                console.error('Failed to load genres:', e);
            }
        }
        
        function populateGenreSelect(selectId, genreList) {
            const select = document.getElementById(selectId);
            genreList.forEach(g => {
                const option = document.createElement('option');
                option.value = g.id;
                option.textContent = g.name;
                select.appendChild(option);
            });
        }
        
        function generateYearOptions() {
            const select = document.getElementById('movie-year');
            const currentYear = new Date().getFullYear();
            for (let year = currentYear + 1; year >= 1900; year--) {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                select.appendChild(option);
            }
        }
        
        async function loadMovies() {
            const grid = document.getElementById('movies-grid');
            grid.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
            
            const category = document.getElementById('movie-category').value;
            const genre = document.getElementById('movie-genre').value;
            const year = document.getElementById('movie-year').value;
            
            let url = `?api=${category}&type=movie&page=${currentPage.movies}`;
            if (category === 'discover') {
                if (genre) url += `&genre=${genre}`;
                if (year) url += `&year=${year}`;
            }
            
            try {
                const data = await fetch(url).then(r => r.json());
                totalPages.movies = data.total_pages || 1;
                renderMediaGrid(grid, data.results || [], 'movie');
                renderPagination('movies');
            } catch (e) {
                grid.innerHTML = '<p>Failed to load movies</p>';
            }
        }
        
        async function loadTV() {
            const grid = document.getElementById('tv-grid');
            grid.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
            
            const category = document.getElementById('tv-category').value;
            const genre = document.getElementById('tv-genre').value;
            
            let url = `?api=${category}&type=tv&page=${currentPage.tv}`;
            if (category === 'discover' && genre) {
                url += `&genre=${genre}`;
            }
            
            try {
                const data = await fetch(url).then(r => r.json());
                totalPages.tv = data.total_pages || 1;
                renderMediaGrid(grid, data.results || [], 'tv');
                renderPagination('tv');
            } catch (e) {
                grid.innerHTML = '<p>Failed to load TV shows</p>';
            }
        }
        
        async function performSearch() {
            switchTab('search');
            const grid = document.getElementById('search-grid');
            grid.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
            
            try {
                const data = await fetch(`?api=search&type=multi&query=${encodeURIComponent(searchQuery)}&page=${currentPage.search}`).then(r => r.json());
                totalPages.search = data.total_pages || 1;
                
                const results = (data.results || []).filter(r => r.media_type === 'movie' || r.media_type === 'tv');
                renderMediaGrid(grid, results, 'search');
                renderPagination('search');
            } catch (e) {
                grid.innerHTML = '<p>Search failed</p>';
            }
        }
        
        async function loadMyList() {
            try {
                const data = await fetch('?api=get_custom_playlist').then(r => r.json());
                
                const moviesGrid = document.getElementById('mylist-movies');
                const tvGrid = document.getElementById('mylist-tv');
                
                if (data.movies && data.movies.length > 0) {
                    renderMediaGrid(moviesGrid, data.movies.map(m => ({...m, media_type: 'movie'})), 'movie', true);
                } else {
                    moviesGrid.innerHTML = '<div class="empty-state"><div class="empty-state-icon">🎬</div><p>No movies in your list yet</p></div>';
                }
                
                if (data.tv && data.tv.length > 0) {
                    renderMediaGrid(tvGrid, data.tv.map(t => ({...t, media_type: 'tv'})), 'tv', true);
                } else {
                    tvGrid.innerHTML = '<div class="empty-state"><div class="empty-state-icon">📺</div><p>No TV shows in your list yet</p></div>';
                }
            } catch (e) {
                console.error('Failed to load my list:', e);
            }
        }
        
        function renderMediaGrid(container, items, type, isMyList = false) {
            if (items.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">🔍</div><p>No results found</p></div>';
                return;
            }
            
            container.innerHTML = items.map(item => {
                const mediaType = item.media_type || type;
                const title = item.title || item.name || 'Unknown';
                const date = item.release_date || item.first_air_date || '';
                const year = date ? new Date(date).getFullYear() : '';
                const rating = item.vote_average ? item.vote_average.toFixed(1) : 'N/A';
                const poster = item.poster_path 
                    ? `${TMDB_IMG_BASE}w342${item.poster_path}` 
                    : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="342" height="513" fill="%2321262d"><rect width="100%" height="100%"/><text x="50%" y="50%" fill="%238b949e" text-anchor="middle" dy=".3em" font-size="20">No Image</text></svg>';
                
                return `
                    <div class="media-card" onclick="openDetail('${mediaType}', ${item.id})" data-media-id="${item.id}" data-media-type="${mediaType}">
                        ${mediaType === 'tv' ? '<span class="media-badge">TV</span>' : ''}
                        ${isMyList ? '<span class="in-playlist-badge">✓ In List</span>' : '<span class="in-library-check" data-id="${item.id}" data-type="${mediaType}"></span>'}
                        <img class="media-poster" src="${poster}" alt="${title}" loading="lazy">
                        <div class="media-info">
                            <div class="media-title" title="${title}">${title}</div>
                            <div class="media-meta">
                                <span>${year}</span>
                                <span class="media-rating">⭐ ${rating}</span>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
            
            // Check which items are in actual playlists
            if (!isMyList) {
                checkLibraryStatus(container);
            }
        }
        
        async function checkLibraryStatus(container) {
            const checks = container.querySelectorAll('.in-library-check');
            for (const check of checks) {
                const id = check.dataset.id;
                const type = check.dataset.type;
                try {
                    const result = await fetch(`?api=check_playlist&type=${type}&id=${id}`).then(r => r.json());
                    if (result.in_playlist) {
                        check.outerHTML = '<span class="in-playlist-badge">✓ Library</span>';
                    } else {
                        check.remove();
                    }
                } catch (e) {
                    check.remove();
                }
            }
        }
        
        function renderPagination(type) {
            const container = document.getElementById(`${type}-pagination`);
            const current = currentPage[type];
            const total = Math.min(totalPages[type], 500); // TMDB limits to 500 pages
            
            if (total <= 1) {
                container.innerHTML = '';
                return;
            }
            
            let html = '';
            
            html += `<button ${current === 1 ? 'disabled' : ''} onclick="goToPage('${type}', ${current - 1})">← Prev</button>`;
            
            // Page numbers
            const range = 2;
            const start = Math.max(1, current - range);
            const end = Math.min(total, current + range);
            
            if (start > 1) {
                html += `<button onclick="goToPage('${type}', 1)">1</button>`;
                if (start > 2) html += `<button disabled>...</button>`;
            }
            
            for (let i = start; i <= end; i++) {
                html += `<button class="${i === current ? 'active' : ''}" onclick="goToPage('${type}', ${i})">${i}</button>`;
            }
            
            if (end < total) {
                if (end < total - 1) html += `<button disabled>...</button>`;
                html += `<button onclick="goToPage('${type}', ${total})">${total}</button>`;
            }
            
            html += `<button ${current === total ? 'disabled' : ''} onclick="goToPage('${type}', ${current + 1})">Next →</button>`;
            
            container.innerHTML = html;
        }
        
        function goToPage(type, page) {
            currentPage[type] = page;
            if (type === 'movies') loadMovies();
            else if (type === 'tv') loadTV();
            else if (type === 'search') performSearch();
            
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        async function openDetail(type, id) {
            currentDetailType = type;
            currentDetailId = id;
            
            const modal = document.getElementById('detailModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            try {
                const data = await fetch(`?api=details&type=${type}&id=${id}`).then(r => r.json());
                const inPlaylist = await fetch(`?api=check_playlist&type=${type}&id=${id}`).then(r => r.json());
                
                renderDetailModal(data, type, inPlaylist.in_playlist);
            } catch (e) {
                console.error('Failed to load details:', e);
                closeModal();
            }
        }
        
        function renderDetailModal(data, type, inPlaylist) {
            // Backdrop
            const backdrop = data.backdrop_path 
                ? `${TMDB_IMG_BASE}w1280${data.backdrop_path}` 
                : '';
            document.getElementById('modalBackdrop').src = backdrop;
            
            // Poster
            const poster = data.poster_path 
                ? `${TMDB_IMG_BASE}w342${data.poster_path}` 
                : '';
            document.getElementById('modalPoster').src = poster;
            
            // Title
            document.getElementById('modalTitle').textContent = data.title || data.name || '';
            document.getElementById('modalTagline').textContent = data.tagline || '';
            
            // Meta
            const meta = [];
            if (data.release_date || data.first_air_date) {
                const year = new Date(data.release_date || data.first_air_date).getFullYear();
                meta.push(`📅 ${year}`);
            }
            if (data.runtime) {
                meta.push(`⏱️ ${data.runtime} min`);
            }
            if (data.number_of_seasons) {
                meta.push(`📺 ${data.number_of_seasons} Season${data.number_of_seasons > 1 ? 's' : ''}`);
            }
            if (data.vote_average) {
                meta.push(`⭐ ${data.vote_average.toFixed(1)}/10`);
            }
            if (data.status) {
                meta.push(`📊 ${data.status}`);
            }
            document.getElementById('modalMeta').innerHTML = meta.map(m => `<span class="modal-meta-item">${m}</span>`).join('');
            
            // Genres
            document.getElementById('modalGenres').innerHTML = (data.genres || [])
                .map(g => `<span class="genre-tag">${g.name}</span>`)
                .join('');
            
            // Overview
            document.getElementById('modalOverview').textContent = data.overview || 'No overview available.';
            
            // Actions
            const trailer = (data.videos?.results || []).find(v => v.type === 'Trailer' && v.site === 'YouTube');
            const imdbId = data.external_ids?.imdb_id || data.imdb_id || '';
            
            let actionsHtml = '';
            
            if (imdbId) {
                actionsHtml += `<button class="btn btn-play" onclick="playMedia('${type}', ${data.id}, '${imdbId}')">▶️ Play</button>`;
            }
            
            if (trailer) {
                actionsHtml += `<button class="btn btn-trailer" onclick="playTrailer('${trailer.key}')">🎬 Trailer</button>`;
            }
            
            if (inPlaylist) {
                actionsHtml += `<button class="btn btn-remove" onclick="removeFromPlaylist('${type}', ${data.id})">✓ In My List</button>`;
            } else {
                actionsHtml += `<button class="btn btn-add" onclick="addToPlaylist('${type}', ${JSON.stringify({
                    id: data.id,
                    title: data.title || data.name,
                    poster_path: data.poster_path,
                    overview: data.overview,
                    vote_average: data.vote_average,
                    release_date: data.release_date,
                    first_air_date: data.first_air_date
                }).replace(/"/g, '&quot;')})">+ Add to My List</button>`;
            }
            
            if (imdbId) {
                actionsHtml += `<a href="https://www.imdb.com/title/${imdbId}" target="_blank" class="btn btn-secondary">IMDb</a>`;
            }
            
            document.getElementById('modalActions').innerHTML = actionsHtml;
            
            // Cast
            const cast = (data.credits?.cast || []).slice(0, 10);
            if (cast.length > 0) {
                document.getElementById('castSection').style.display = 'block';
                document.getElementById('castGrid').innerHTML = cast.map(c => `
                    <div class="cast-card">
                        <img class="cast-photo" src="${c.profile_path ? TMDB_IMG_BASE + 'w185' + c.profile_path : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" fill="%2321262d"><rect width="100%" height="100%"/></svg>'}" alt="${c.name}">
                        <div class="cast-name">${c.name}</div>
                        <div class="cast-character">${c.character || ''}</div>
                    </div>
                `).join('');
            } else {
                document.getElementById('castSection').style.display = 'none';
            }
            
            // Seasons (TV only)
            if (type === 'tv' && data.seasons && data.seasons.length > 0) {
                document.getElementById('seasonsSection').style.display = 'block';
                document.getElementById('seasonsList').innerHTML = data.seasons
                    .filter(s => s.season_number > 0)
                    .map(s => `
                        <div class="season-card" onclick="openSeason(${data.id}, ${s.season_number})">
                            <img class="season-poster" src="${s.poster_path ? TMDB_IMG_BASE + 'w154' + s.poster_path : poster}" alt="Season ${s.season_number}">
                            <div class="season-info">
                                <h4>${s.name}</h4>
                                <p>${s.episode_count} Episodes • ${s.air_date ? new Date(s.air_date).getFullYear() : 'TBA'}</p>
                            </div>
                        </div>
                    `).join('');
            } else {
                document.getElementById('seasonsSection').style.display = 'none';
            }
            
            // Recommendations
            const recs = data.recommendations?.results || data.similar?.results || [];
            if (recs.length > 0) {
                document.getElementById('recommendationsSection').style.display = 'block';
                document.getElementById('recommendationsGrid').innerHTML = recs.slice(0, 10).map(r => `
                    <div class="rec-card" onclick="openDetail('${type}', ${r.id})">
                        <img class="rec-poster" src="${r.poster_path ? TMDB_IMG_BASE + 'w185' + r.poster_path : ''}" alt="${r.title || r.name}">
                        <div class="rec-title">${r.title || r.name}</div>
                    </div>
                `).join('');
            } else {
                document.getElementById('recommendationsSection').style.display = 'none';
            }
        }
        
        function closeModal() {
            document.getElementById('detailModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        function playTrailer(key) {
            const modal = document.getElementById('videoModal');
            const frame = document.getElementById('videoFrame');
            frame.src = `https://www.youtube.com/embed/${key}?autoplay=1`;
            modal.classList.add('active');
        }
        
        function closeVideoModal() {
            const modal = document.getElementById('videoModal');
            const frame = document.getElementById('videoFrame');
            frame.src = '';
            modal.classList.remove('active');
        }
        
        function playMedia(type, id, imdbId) {
            // Open in new tab with play.php
            const url = type === 'movie' 
                ? `play.php?movieId=${imdbId}`
                : `play.php?movieId=${id}`;
            window.open(url, '_blank');
        }
        
        async function addToPlaylist(type, itemData) {
            const data = typeof itemData === 'string' ? JSON.parse(itemData.replace(/&quot;/g, '"')) : itemData;
            data.type = type;
            
            try {
                const result = await fetch('?api=add_to_playlist', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                }).then(r => r.json());
                
                if (result.success) {
                    showToast('Added to My List!', 'success');
                    // Refresh modal to update button
                    openDetail(type, data.id);
                } else {
                    showToast(result.message || 'Failed to add', 'error');
                }
            } catch (e) {
                showToast('Error adding to list', 'error');
            }
        }
        
        async function removeFromPlaylist(type, id) {
            try {
                const result = await fetch('?api=remove_from_playlist', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ type, id })
                }).then(r => r.json());
                
                if (result.success) {
                    showToast('Removed from My List', 'success');
                    openDetail(type, id);
                    if (currentTab === 'mylist') {
                        loadMyList();
                    }
                }
            } catch (e) {
                showToast('Error removing from list', 'error');
            }
        }
        
        function openSeason(tvId, seasonNum) {
            // Could expand to show episode list
            showToast(`Season ${seasonNum} - Coming soon!`, 'success');
        }
        
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast show ${type}`;
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
    </script>
</body>
</html>
