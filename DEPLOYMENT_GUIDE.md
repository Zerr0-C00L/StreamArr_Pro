# Quick Setup Guide - M3U Playlist Fix (Direct Binary Deployment)

## What Changed
The M3U playlist `/get.php` endpoint now respects your "Only Include Media with Cached Streams" setting:

- **Setting ON** → Playlist shows only cached streams from Stream Cache Monitor
- **Setting OFF** → Playlist shows ALL monitored movies and series

## To Deploy These Changes (Direct Binary)

### Step 1: Build Locally (macOS)
```bash
cd /Users/zeroq/StreamArr\ Pro
GOOS=linux GOARCH=arm64 go build -o bin/server-linux ./cmd/server
```
*(Adjust GOARCH based on remote server: amd64 for x86, arm64 for ARM)*

### Step 2: Upload to Remote Server
```bash
scp bin/server-linux root@77.42.16.119:/path/to/StreamArr/bin/server
```

### Step 3: Stop Current Server, Deploy, and Restart
```bash
ssh root@77.42.16.119 << 'EOF'
# Stop the running server
pkill -f "bin/server" || true

# Wait for graceful shutdown
sleep 2

# Backup old binary (optional)
cp /path/to/StreamArr/bin/server /path/to/StreamArr/bin/server.backup

# Copy new binary
cp /path/to/StreamArr/bin/server-linux /path/to/StreamArr/bin/server

# Make executable
chmod +x /path/to/StreamArr/bin/server

# Restart server
cd /path/to/StreamArr && ./bin/server &

# Verify it's running
sleep 3
curl http://localhost:8080/api/v1/health || echo "Server failed to start"
EOF
```

### Step 4: Test
```bash
curl "http://77.42.16.119/get.php?username=streamarr&password=streamarr" | head -20
```

You should see M3U entries with movies and Live TV.

## Quick One-Liner Deployment
```bash
cd /Users/zeroq/StreamArr\ Pro && \
GOOS=linux GOARCH=arm64 go build -o bin/server-linux ./cmd/server && \
scp bin/server-linux root@77.42.16.119:/path/to/StreamArr/bin/server && \
ssh root@77.42.16.119 "pkill -f 'bin/server' || true; sleep 2; cd /path/to/StreamArr && ./bin/server &" && \
echo "✓ Deployed successfully"
```

## Usage

### To show ONLY cached streams in playlist:
1. Go to Settings > Services > Stream Search
2. Enable "Only Include Media with Cached Streams" ✓
3. Refresh playlist in IPTV app

### To show ALL library in playlist:
1. Go to Settings > Services > Stream Search  
2. Disable "Only Include Media with Cached Streams" ✗
3. Refresh playlist in IPTV app

## Verify Deployment
```bash
# Check if server is running
ssh root@77.42.16.119 "ps aux | grep bin/server"

# Check logs for playlist activity
ssh root@77.42.16.119 "tail -f /path/to/StreamArr/logs/server.log | grep handleGetPlaylist"

# Test playlist endpoint
curl "http://77.42.16.119/get.php?username=streamarr&password=streamarr" | head -20
```

## Files Changed
- `internal/xtream/handler.go` - handleGetPlaylist() function
  - Now queries `media_streams` when cached-only mode enabled
  - Queries full `library_*` tables when disabled
  - Always includes Live TV
