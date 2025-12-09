<?php
/**
 * Radarr/Sonarr-style Dashboard for TMDB VOD
 * Browse movies and series with Real-Debrid availability indicators
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/libs/episode_cache_db.php';

$cache = new EpisodeCacheDB();
$stats = $cache->getStats();

// Get page and section
$section = $_GET['section'] ?? 'movies';
$page = max(1, (int)($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$perPage = 24;

// Build quick lookup of items in playlists (for "in library" indicator)
$playlistIds = [];
if ($section === 'movies' && file_exists(__DIR__ . '/playlist.json')) {
    $content = file_get_contents(__DIR__ . '/playlist.json');
    preg_match_all('/"stream_id":(\d+)/', $content, $matches);
    $playlistIds = array_flip($matches[1]);
} elseif ($section === 'series' && file_exists(__DIR__ . '/tv_playlist.json')) {
    $content = file_get_contents(__DIR__ . '/tv_playlist.json');
    preg_match_all('/"series_id":(\d+)/', $content, $matches);
    $playlistIds = array_flip($matches[1]);
}

// Load data based on section
$items = [];
$totalItems = 0;

if ($section === 'movies') {
    $playlistFile = __DIR__ . '/playlist.json';
    if (file_exists($playlistFile)) {
        // Stream read the JSON to get items for current page
        $handle = fopen($playlistFile, 'r');
        $buffer = '';
        $allItems = [];
        $chunkSize = 1024 * 512;
        
        while (!feof($handle)) {
            $buffer .= fread($handle, $chunkSize);
            
            // Extract movie objects
            while (preg_match('/\{"num":(\d+),"name":"([^"]+)"[^}]*"stream_id":(\d+)[^}]*"stream_icon":"([^"]*)"[^}]*"rating":([0-9.]+)[^}]*"group":"([^"]*)"/s', $buffer, $match, PREG_OFFSET_CAPTURE)) {
                $item = [
                    'num' => (int)$match[1][0],
                    'name' => stripcslashes($match[2][0]),
                    'id' => (int)$match[3][0],
                    'poster' => stripcslashes($match[4][0]),
                    'rating' => (float)$match[5][0],
                    'group' => $match[6][0]
                ];
                
                // Apply search filter
                if (empty($search) || stripos($item['name'], $search) !== false) {
                    $allItems[] = $item;
                }
                
                $buffer = substr($buffer, $match[0][1] + strlen($match[0][0]));
            }
            
            if (strlen($buffer) > 2000) {
                $buffer = substr($buffer, -2000);
            }
        }
        fclose($handle);
        
        $totalItems = count($allItems);
        $items = array_slice($allItems, ($page - 1) * $perPage, $perPage);
    }
} else {
    // Series
    $playlistFile = __DIR__ . '/tv_playlist.json';
    if (file_exists($playlistFile)) {
        $handle = fopen($playlistFile, 'r');
        $buffer = '';
        $allItems = [];
        $chunkSize = 1024 * 512;
        
        while (!feof($handle)) {
            $buffer .= fread($handle, $chunkSize);
            
            while (preg_match('/\{"num":(\d+),"name":"([^"]+)"[^}]*"series_id":(\d+)[^}]*"cover":"([^"]*)"/s', $buffer, $match, PREG_OFFSET_CAPTURE)) {
                $item = [
                    'num' => (int)$match[1][0],
                    'name' => stripcslashes($match[2][0]),
                    'id' => (int)$match[3][0],
                    'poster' => stripcslashes($match[4][0]),
                    'rating' => 0,
                    'group' => ''
                ];
                
                if (empty($search) || stripos($item['name'], $search) !== false) {
                    $allItems[] = $item;
                }
                
                $buffer = substr($buffer, $match[0][1] + strlen($match[0][0]));
            }
            
            if (strlen($buffer) > 2000) {
                $buffer = substr($buffer, -2000);
            }
        }
        fclose($handle);
        
        $totalItems = count($allItems);
        $items = array_slice($allItems, ($page - 1) * $perPage, $perPage);
    }
}

$totalPages = ceil($totalItems / $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TMDB VOD Dashboard</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #1a1a2e;
            color: #eee;
            min-height: 100vh;
        }
        
        .header {
            background: #16213e;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #0f3460;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: #e94560;
        }
        
        .logo span {
            color: #0f3460;
        }
        
        .stats {
            display: flex;
            gap: 2rem;
            font-size: 0.9rem;
            color: #888;
        }
        
        .stats strong {
            color: #e94560;
        }
        
        .nav {
            background: #16213e;
            padding: 0.5rem 2rem;
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .nav a {
            color: #888;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            transition: all 0.2s;
        }
        
        .nav a:hover {
            color: #fff;
            background: #0f3460;
        }
        
        .nav a.active {
            color: #fff;
            background: #e94560;
        }
        
        .search-box {
            margin-left: auto;
            display: flex;
            gap: 0.5rem;
        }
        
        .search-box input {
            padding: 0.5rem 1rem;
            border: 1px solid #0f3460;
            border-radius: 4px;
            background: #1a1a2e;
            color: #fff;
            width: 250px;
        }
        
        .search-box button {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            background: #e94560;
            color: #fff;
            cursor: pointer;
        }
        
        .main {
            padding: 2rem;
        }
        
        .section-title {
            font-size: 1.2rem;
            margin-bottom: 1rem;
            color: #888;
        }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 1.5rem;
        }
        
        .card {
            background: #16213e;
            border-radius: 8px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            position: relative;
        }
        
        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        }
        
        .card-poster {
            width: 100%;
            aspect-ratio: 2/3;
            object-fit: cover;
            background: #0f3460;
        }
        
        .card-info {
            padding: 0.75rem;
        }
        
        .card-title {
            font-size: 0.85rem;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 0.25rem;
        }
        
        .card-meta {
            font-size: 0.75rem;
            color: #888;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .rating {
            color: #ffd700;
        }
        
        .availability {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #888;
            border: 2px solid #1a1a2e;
        }
        
        .availability.available {
            background: #4ade80;
            box-shadow: 0 0 8px #4ade80;
        }
        
        .availability.partial {
            background: #fbbf24;
            box-shadow: 0 0 8px #fbbf24;
        }
        
        .availability.unavailable {
            background: #ef4444;
        }
        
        .availability.checking {
            background: #888;
            animation: pulse 1s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .in-library-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            z-index: 2;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }
        
        .pagination a {
            padding: 0.5rem 1rem;
            background: #16213e;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
        }
        
        .pagination a:hover {
            background: #0f3460;
        }
        
        .pagination a.active {
            background: #e94560;
        }
        
        .pagination span {
            padding: 0.5rem 1rem;
            color: #888;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: #16213e;
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow: auto;
        }
        
        .modal-header {
            padding: 1rem;
            border-bottom: 1px solid #0f3460;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: #888;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .modal-body {
            padding: 1rem;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 1rem;
        }
        
        .detail-poster {
            width: 100%;
            border-radius: 8px;
        }
        
        .detail-info h2 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }
        
        .detail-meta {
            color: #888;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        
        .availability-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem;
            background: #1a1a2e;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .availability-detail.available {
            border-left: 3px solid #4ade80;
        }
        
        .availability-detail.unavailable {
            border-left: 3px solid #ef4444;
        }
        
        .quality-badges {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.5rem;
        }
        
        .quality-badge {
            padding: 0.25rem 0.5rem;
            background: #0f3460;
            border-radius: 4px;
            font-size: 0.75rem;
        }
        
        .play-btn {
            display: inline-block;
            padding: 0.75rem 2rem;
            background: #e94560;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            margin-top: 1rem;
        }
        
        .play-btn:hover {
            background: #d63850;
        }
        
        .play-btn.disabled {
            background: #444;
            cursor: not-allowed;
        }
        
        .play-btn.secondary {
            background: #0f3460;
            margin-left: 0.5rem;
        }
        
        .play-btn.secondary:hover {
            background: #1a4a8a;
        }
        
        .stream-list {
            margin-top: 1rem;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .stream-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            background: #1a1a2e;
            border-radius: 6px;
            margin-bottom: 0.5rem;
        }
        
        .stream-item:hover {
            background: #0f3460;
        }
        
        .stream-quality {
            font-weight: bold;
            color: #4ade80;
            min-width: 60px;
        }
        
        .stream-size {
            color: #888;
            font-size: 0.85rem;
            min-width: 80px;
        }
        
        .stream-title {
            flex: 1;
            font-size: 0.8rem;
            color: #aaa;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin: 0 1rem;
        }
        
        .stream-play {
            background: #e94560;
            color: #fff;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
        }
        
        .stream-play:hover {
            background: #d63850;
        }
        
        .loading {
            text-align: center;
            padding: 2rem;
            color: #888;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">TMDB<span>VOD</span></div>
        <div class="stats">
            <span><strong><?= number_format($stats['movies']) ?></strong> Movies</span>
            <span><strong><?= number_format($stats['series']) ?></strong> Series</span>
            <span><strong><?= number_format($stats['episodes']) ?></strong> Episodes</span>
        </div>
    </div>
    
    <div class="nav">
        <a href="?section=movies" class="<?= $section === 'movies' ? 'active' : '' ?>">🎬 Movies</a>
        <a href="?section=series" class="<?= $section === 'series' ? 'active' : '' ?>">📺 Series</a>
        
        <form class="search-box" method="get">
            <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
            <input type="text" name="search" placeholder="Search <?= $section ?>..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit">Search</button>
        </form>
    </div>
    
    <div class="main">
        <div class="section-title">
            <?php if ($search): ?>
                Search results for "<?= htmlspecialchars($search) ?>" (<?= number_format($totalItems) ?> found)
            <?php else: ?>
                <?= $section === 'movies' ? 'Movies' : 'Series' ?> (<?= number_format($totalItems) ?> total)
            <?php endif; ?>
        </div>
        
        <div class="grid">
            <?php foreach ($items as $item): ?>
            <div class="card" onclick="showDetail(<?= $item['id'] ?>, '<?= $section ?>')">
                <div class="availability checking" data-id="<?= $item['id'] ?>" data-type="<?= $section ?>"></div>
                <?php if (isset($playlistIds[$item['id']])): ?>
                <div class="in-library-badge" title="In Your Playlist">✓ Library</div>
                <?php endif; ?>
                <img class="card-poster" 
                     src="<?= htmlspecialchars($item['poster']) ?>" 
                     alt="<?= htmlspecialchars($item['name']) ?>"
                     onerror="this.src='https://via.placeholder.com/160x240/0f3460/888?text=No+Image'">
                <div class="card-info">
                    <div class="card-title" title="<?= htmlspecialchars($item['name']) ?>">
                        <?= htmlspecialchars($item['name']) ?>
                    </div>
                    <div class="card-meta">
                        <?php if ($item['rating'] > 0): ?>
                        <span class="rating">★ <?= number_format($item['rating'], 1) ?></span>
                        <?php endif; ?>
                        <span><?= htmlspecialchars($item['group']) ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?section=<?= $section ?>&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">← Prev</a>
            <?php endif; ?>
            
            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            
            if ($start > 1): ?>
            <a href="?section=<?= $section ?>&page=1&search=<?= urlencode($search) ?>">1</a>
            <?php if ($start > 2): ?><span>...</span><?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $start; $i <= $end; $i++): ?>
            <a href="?section=<?= $section ?>&page=<?= $i ?>&search=<?= urlencode($search) ?>" 
               class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($end < $totalPages): ?>
            <?php if ($end < $totalPages - 1): ?><span>...</span><?php endif; ?>
            <a href="?section=<?= $section ?>&page=<?= $totalPages ?>&search=<?= urlencode($search) ?>"><?= $totalPages ?></a>
            <?php endif; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?section=<?= $section ?>&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Detail Modal -->
    <div class="modal" id="detailModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Loading...</h3>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="loading">Loading details...</div>
            </div>
        </div>
    </div>
    
    <script>
        // Check availability for visible items
        const checkAvailability = async (id, type) => {
            const indicator = document.querySelector(`.availability[data-id="${id}"][data-type="${type}"]`);
            if (!indicator) return;
            
            try {
                const endpoint = type === 'movies' 
                    ? `/check_availability.php?type=movie&tmdb=${id}`
                    : `/check_availability.php?type=series&tmdb=${id}&season=1&episode=1`;
                
                const response = await fetch(endpoint);
                const data = await response.json();
                
                indicator.classList.remove('checking');
                if (data.available && data.cached_streams >= 10) {
                    indicator.classList.add('available');
                    indicator.title = `${data.cached_streams} streams available`;
                } else if (data.available) {
                    indicator.classList.add('partial');
                    indicator.title = `${data.cached_streams} streams (limited)`;
                } else {
                    indicator.classList.add('unavailable');
                    indicator.title = 'Not cached on Real-Debrid';
                }
            } catch (e) {
                indicator.classList.remove('checking');
                indicator.classList.add('unavailable');
            }
        };
        
        // Check availability for all visible items (with rate limiting)
        const items = document.querySelectorAll('.availability');
        let delay = 0;
        items.forEach(item => {
            setTimeout(() => {
                checkAvailability(item.dataset.id, item.dataset.type);
            }, delay);
            delay += 200; // 200ms between requests to avoid overwhelming Torrentio
        });
        
        // Modal functions
        const showDetail = async (id, type) => {
            const modal = document.getElementById('detailModal');
            const title = document.getElementById('modalTitle');
            const body = document.getElementById('modalBody');
            
            modal.classList.add('show');
            title.textContent = 'Loading...';
            body.innerHTML = '<div class="loading">Loading details...</div>';
            
            try {
                // Get availability
                const availEndpoint = type === 'movies' 
                    ? `/check_availability.php?type=movie&tmdb=${id}`
                    : `/check_availability.php?type=series&tmdb=${id}&season=1&episode=1`;
                
                const availResponse = await fetch(availEndpoint);
                const availData = await availResponse.json();
                
                // Get TMDB details
                const tmdbType = type === 'movies' ? 'movie' : 'tv';
                const tmdbResponse = await fetch(`https://api.themoviedb.org/3/${tmdbType}/${id}?api_key=<?= $apiKey ?>`);
                const tmdbData = await tmdbResponse.json();
                
                title.textContent = tmdbData.title || tmdbData.name || 'Unknown';
                
                const availClass = availData.available ? 'available' : 'unavailable';
                const availText = availData.available 
                    ? `✓ Available (${availData.cached_streams} cached streams)`
                    : '✗ Not cached on Real-Debrid';
                
                const qualityBadges = availData.qualities 
                    ? Object.entries(availData.qualities).map(([q, c]) => 
                        `<span class="quality-badge">${q} (${c})</span>`).join('')
                    : '';
                
                const playUrl = type === 'movies'
                    ? `/play.php?movieId=${id}&type=movies`
                    : `/play.php?movieId=${id}&type=series`;
                
                // Build stream list HTML
                let streamListHtml = '';
                if (availData.streams && availData.streams.length > 0) {
                    streamListHtml = '<div class="stream-list">';
                    availData.streams.slice(0, 15).forEach(stream => {
                        const cleanTitle = (stream.title || '').split('\n')[0].substring(0, 50);
                        streamListHtml += `
                            <div class="stream-item">
                                <span class="stream-quality">${stream.quality || '?'}</span>
                                <span class="stream-size">${stream.size || '-'}</span>
                                <span class="stream-title" title="${stream.title}">${cleanTitle}</span>
                                <button class="stream-play" onclick="window.open('/select_stream.php?stream_id=${stream.id}', '_blank')">▶ Play</button>
                            </div>
                        `;
                    });
                    streamListHtml += '</div>';
                }
                
                body.innerHTML = `
                    <div class="detail-grid">
                        <img class="detail-poster" 
                             src="https://image.tmdb.org/t/p/w300${tmdbData.poster_path}" 
                             alt="${title.textContent}">
                        <div class="detail-info">
                            <h2>${title.textContent}</h2>
                            <div class="detail-meta">
                                ${tmdbData.release_date || tmdbData.first_air_date || ''} • 
                                ${tmdbData.vote_average ? '★ ' + tmdbData.vote_average.toFixed(1) : ''}
                                ${tmdbData.number_of_seasons ? ' • ' + tmdbData.number_of_seasons + ' seasons' : ''}
                            </div>
                            
                            <div class="availability-detail ${availClass}">
                                <div>
                                    <strong>${availText}</strong>
                                    ${qualityBadges ? '<div class="quality-badges">' + qualityBadges + '</div>' : ''}
                                </div>
                            </div>
                            
                            <p>${tmdbData.overview || 'No description available.'}</p>
                            
                            ${availData.available 
                                ? `<a href="${playUrl}" class="play-btn" target="_blank">▶ Play Best</a>
                                   <a href="/select_stream.php?tmdb=${id}&type=${type === 'movies' ? 'movie' : 'series'}&format=html" class="play-btn secondary" target="_blank">🎬 Select Quality</a>`
                                : `<span class="play-btn disabled">Not Available</span>`
                            }
                        </div>
                    </div>
                    ${streamListHtml ? '<h4 style="margin-top:1rem;color:#888;">Available Streams:</h4>' + streamListHtml : ''}
                `;
            } catch (e) {
                body.innerHTML = `<div class="loading">Error loading details: ${e.message}</div>`;
            }
        };
        
        const closeModal = () => {
            document.getElementById('detailModal').classList.remove('show');
        };
        
        // Close modal on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });
        
        // Close modal on backdrop click
        document.getElementById('detailModal').addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) closeModal();
        });
    </script>
</body>
</html>
