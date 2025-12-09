<?php
// Quick stats - hardcoded for speed
$movieCount = 1094;
$seriesCount = 1903;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CineSync Home</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            max-width: 900px;
            text-align: center;
        }
        
        .logo {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        
        h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            font-weight: 700;
        }
        
        .subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 3rem;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .stat-number {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .quick-links {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 1rem 2rem;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 12px;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }
        
        .btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">🎬</div>
        <h1>Welcome to CineSync</h1>
        <p class="subtitle">Your Personal Media Management System</p>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?= number_format($movieCount) ?></div>
                <div class="stat-label">Movies Available</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($seriesCount) ?></div>
                <div class="stat-label">TV Series</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($movieCount + $seriesCount) ?></div>
                <div class="stat-label">Total Content</div>
            </div>
        </div>
        
        <div class="quick-links">
            <a href="?view=browse" class="btn">🔍 Browse Media</a>
            <a href="media_browser.php" class="btn">🎬 Media Browser</a>
            <a href="admin.php" class="btn">⚙️ Admin Panel</a>
        </div>
    </div>
</body>
</html>
