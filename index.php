<?php
/**
 * CineSync - Unified Media Management Interface
 * Single-page application with sidebar navigation
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/libs/episode_cache_db.php';

// Get current view
$view = $_GET['view'] ?? 'browse';

// Quick stats
$cache = new EpisodeCacheDB();
$movieCount = 0;
$seriesCount = 0;

if (file_exists(__DIR__ . '/playlist.json')) {
    $content = file_get_contents(__DIR__ . '/playlist.json');
    preg_match_all('/"stream_id":\d+/', $content, $matches);
    $movieCount = count($matches[0]);
}

if (file_exists(__DIR__ . '/tv_playlist.json')) {
    $content = file_get_contents(__DIR__ . '/tv_playlist.json');
    preg_match_all('/"series_id":\d+/', $content, $matches);
    $seriesCount = count($matches[0]);
}

// Request stats
$requestsFile = __DIR__ . '/cache/requests.json';
$pendingRequests = 0;
if (file_exists($requestsFile)) {
    $requests = json_decode(file_get_contents($requestsFile), true) ?: [];
    $pendingRequests = count(array_filter($requests, fn($r) => $r['status'] === 'pending'));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CineSync - Media Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #0f0f0f;
            color: #e5e5e5;
            overflow: hidden;
        }
        
        .app-container {
            display: flex;
            height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 260px;
            background: #141414;
            border-right: 1px solid #2a2a2a;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s;
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #2a2a2a;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .nav-menu {
            flex: 1;
            padding: 20px 0;
            overflow-y: auto;
        }
        
        .nav-section {
            margin-bottom: 30px;
        }
        
        .nav-section-title {
            padding: 0 20px 10px;
            font-size: 0.75em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.5;
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: #b3b3b3;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
            position: relative;
        }
        
        .nav-item:hover {
            background: #1f1f1f;
            color: #fff;
        }
        
        .nav-item.active {
            background: #1f1f1f;
            color: #fff;
        }
        
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .nav-icon {
            font-size: 1.2em;
            width: 24px;
            text-align: center;
        }
        
        .nav-badge {
            margin-left: auto;
            background: #e50914;
            color: #fff;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75em;
            font-weight: 600;
        }
        
        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid #2a2a2a;
        }
        
        .stats-mini {
            display: flex;
            gap: 15px;
            font-size: 0.85em;
        }
        
        .stat-mini {
            text-align: center;
        }
        
        .stat-mini-value {
            font-weight: 700;
            font-size: 1.2em;
            color: #667eea;
        }
        
        .stat-mini-label {
            opacity: 0.6;
            font-size: 0.8em;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .topbar {
            height: 60px;
            background: #141414;
            border-bottom: 1px solid #2a2a2a;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }
        
        .topbar-title {
            font-size: 1.3em;
            font-weight: 600;
        }
        
        .topbar-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .search-box {
            display: flex;
            align-items: center;
            background: #1f1f1f;
            border-radius: 20px;
            padding: 8px 16px;
            gap: 8px;
            min-width: 250px;
        }
        
        .search-box input {
            background: none;
            border: none;
            color: #fff;
            outline: none;
            width: 100%;
        }
        
        .search-box input::placeholder {
            color: #666;
        }
        
        .btn {
            padding: 8px 16px;
            background: #1f1f1f;
            border: 1px solid #2a2a2a;
            color: #fff;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.9em;
        }
        
        .btn:hover {
            background: #2a2a2a;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .btn-primary:hover {
            opacity: 0.9;
        }
        
        .content-area {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }
        
        .content-area::-webkit-scrollbar {
            width: 8px;
        }
        
        .content-area::-webkit-scrollbar-track {
            background: #141414;
        }
        
        .content-area::-webkit-scrollbar-thumb {
            background: #2a2a2a;
            border-radius: 4px;
        }
        
        .content-area::-webkit-scrollbar-thumb:hover {
            background: #3a3a3a;
        }
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5em;
            cursor: pointer;
            padding: 10px;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                bottom: 0;
                z-index: 1000;
                transform: translateX(-100%);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .mobile-menu-toggle {
                display: block;
            }
            
            .search-box {
                display: none;
            }
        }
        
        /* Loading Animation */
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
        }
        
        .spinner {
            border: 3px solid #2a2a2a;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <span>🎬</span>
                    <span>CineSync</span>
                </div>
            </div>
            
            <nav class="nav-menu">
                <div class="nav-section">
                    <div class="nav-section-title">Library</div>
                    <a href="?view=browse" class="nav-item <?php echo $view === 'browse' ? 'active' : ''; ?>">
                        <span class="nav-icon">🔍</span>
                        <span>Browse Media</span>
                    </a>
                    <a href="?view=movies" class="nav-item <?php echo $view === 'movies' ? 'active' : ''; ?>">
                        <span class="nav-icon">🎬</span>
                        <span>Movies</span>
                        <span class="nav-badge"><?php echo number_format($movieCount); ?></span>
                    </a>
                    <a href="?view=series" class="nav-item <?php echo $view === 'series' ? 'active' : ''; ?>">
                        <span class="nav-icon">📺</span>
                        <span>TV Series</span>
                        <span class="nav-badge"><?php echo number_format($seriesCount); ?></span>
                    </a>
                    <a href="?view=requests" class="nav-item <?php echo $view === 'requests' ? 'active' : ''; ?>">
                        <span class="nav-icon">⬇️</span>
                        <span>Requests</span>
                        <?php if ($pendingRequests > 0): ?>
                            <span class="nav-badge"><?php echo $pendingRequests; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <a href="?view=activity" class="nav-item <?php echo $view === 'activity' ? 'active' : ''; ?>">
                        <span class="nav-icon">📈</span>
                        <span>Activity</span>
                    </a>
                    <a href="?view=files" class="nav-item <?php echo $view === 'files' ? 'active' : ''; ?>">
                        <span class="nav-icon">📁</span>
                        <span>Files</span>
                    </a>
                    <a href="admin.php" class="nav-item" target="_blank">
                        <span class="nav-icon">⚙️</span>
                        <span>Settings</span>
                    </a>
                </div>
            </nav>
            
            <div class="sidebar-footer">
                <div class="stats-mini">
                    <div class="stat-mini">
                        <div class="stat-mini-value"><?php echo number_format($movieCount); ?></div>
                        <div class="stat-mini-label">Movies</div>
                    </div>
                    <div class="stat-mini">
                        <div class="stat-mini-value"><?php echo number_format($seriesCount); ?></div>
                        <div class="stat-mini-label">Series</div>
                    </div>
                </div>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="topbar">
                <button class="mobile-menu-toggle" onclick="toggleSidebar()">☰</button>
                <div class="topbar-title" id="pageTitle">Dashboard</div>
                <div class="topbar-actions">
                    <div class="search-box">
                        <span>🔍</span>
                        <input type="text" placeholder="Search media..." id="globalSearch">
                    </div>
                    <button class="btn" onclick="location.reload()">🔄</button>
                </div>
            </div>
            
            <div class="content-area" id="contentArea">
                <div class="loading">
                    <div class="spinner"></div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        const views = {
            'browse': { title: 'Browse Media', url: 'media_browser.php' },
            'movies': { title: 'Movies', url: 'create_playlist.php' },
            'series': { title: 'TV Series', url: 'create_tv_playlist.php' },
            'requests': { title: 'Requests', url: 'admin.php' },
            'activity': { title: 'Activity', url: 'admin.php' },
            'files': { title: 'Files', url: 'admin.php' }
        };nst views = {
            'home': { title: 'Home', url: 'home.php' },
            'browse': { title: 'Browse Media', url: 'media_browser.php' },
            'requests': { title: 'Requests', url: 'admin.php' },
            'activity': { title: 'Activity', url: 'info.php' },
            'files': { title: 'Files', url: 'info.php' }
        };  if (!view) return;
            
            document.getElementById('pageTitle').textContent = view.title;
            
            // Load content via iframe for simplicity
            const iframe = document.createElement('iframe');
            iframe.style.width = '100%';
            iframe.style.height = '100%';
            iframe.style.border = 'none';
            iframe.src = view.url;
            
            const contentArea = document.getElementById('contentArea');
            contentArea.innerHTML = '';
            contentArea.appendChild(iframe);
        }
        
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        
        // Global search
        document.getElementById('globalSearch')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                const query = e.target.value;
                window.location.href = `?view=search&q=${encodeURIComponent(query)}`;
            }
        });
        
        // Load initial view
        loadView();
    </script>
</body>
</html>
