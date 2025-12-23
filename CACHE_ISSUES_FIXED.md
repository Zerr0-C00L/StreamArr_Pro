# StreamArr Cache Monitor & M3U Playlist - Fixed

## Changes Made

### 1. **M3U Playlist Now Respects "Only Include Media with Cached Streams" Setting** ✅
The `/get.php` endpoint now has two modes:

**Mode 1: CACHED_ONLY (When "Only Include Media with Cached Streams" = ON)**
- Only shows movies and series that have entries in the `media_streams` table (Stream Cache Monitor)
- Ensures instant playback for all items in the playlist
- Great for reliability - only verified working streams

**Mode 2: FULL_LIBRARY (When "Only Include Media with Cached Streams" = OFF)**
- Shows ALL monitored movies and series from your library
- Streams are fetched on-demand when you press play
- Good for full library access, but playback may require waiting for stream discovery

### 2. **Live TV Always Included** ✅
Live TV channels are always added to the playlist (if available)

### 3. **Better Logging** ✅
The playlist generation now logs:
- Which mode is active (CACHED_ONLY or FULL_LIBRARY)
- How many items were added
- Any errors during generation

## How It Works Now

### Setting: "Only Include Media with Cached Streams"
Located in **Settings > Services > Stream Search**

**When ENABLED ✓**:
```
M3U Playlist = Only Cached Streams (from media_streams table)
```
- Only shows movies/series with cached streams
- Instant playback
- Smaller playlist file
- Better for unreliable internet

**When DISABLED ✗**:
```
M3U Playlist = FULL Library (all monitored content)
```
- Shows all library movies/series
- On-demand stream fetching
- Larger playlist file
- Better for complete library access

## Database Structure

### media_streams table (Stream Cache Monitor)
- Contains: `movie_id`, `series_id`, `quality_score`, `resolution`, etc.
- Updated by: Cache Scanner service
- Used by: Playlist generation (when enabled)

### library_movies / library_series tables
- Contains: All your monitored movies/series
- Used by: IPTV app for browsing
- Used by: Playlist generation (always)

## Testing the Fix

### Test Cached-Only Playlist:
```bash
# Ensure setting is ON
curl "http://77.42.16.119/get.php?username=streamarr&password=streamarr"
# Should only show movies with cached streams
```

### Test Full Library Playlist:
```bash
# Ensure setting is OFF  
curl "http://77.42.16.119/get.php?username=streamarr&password=streamarr"
# Should show ALL monitored movies/series
```

### Check Server Logs:
```bash
docker logs streamarr 2>&1 | grep "handleGetPlaylist"
```

You should see:
- `Mode=CACHED_ONLY` or `Mode=FULL_LIBRARY`
- Count of items added
- Any errors

## Deployment Steps

1. **Rebuild**:
   ```bash
   cd /Users/zeroq/StreamArr\ Pro
   go build -o bin/server ./cmd/server
   ```

2. **Rebuild Docker image**:
   ```bash
   docker build -t streamarrpro-streamarr:latest .
   ```

3. **Push to server** (if local):
   ```bash
   docker tag streamarrpro-streamarr:latest your-registry/streamarrpro-streamarr:latest
   docker push your-registry/streamarrpro-streamarr:latest
   ```

4. **Restart on remote**:
   ```bash
   ssh root@77.42.16.119 "cd /path/to/streamarr && docker-compose down && docker-compose up -d"
   ```

5. **Verify**:
   ```bash
   curl http://77.42.16.119/get.php?username=streamarr&password=streamarr | head -20
   ```

## Files Modified

- [internal/xtream/handler.go](internal/xtream/handler.go#L2134) - `handleGetPlaylist()` function
