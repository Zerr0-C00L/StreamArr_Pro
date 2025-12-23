# Stream Cache Monitor Setup & Usage

## Overview

The Stream Cache Monitor is now fully functional on your remote Linux server (77.42.16.119). The M3U playlist generation respects the "Only Include Media with Cached Streams" setting, allowing you to toggle between two modes:

1. **FULL LIBRARY MODE** - Shows all monitored movies and series
2. **CACHED-ONLY MODE** - Shows only streams that have been cached by the Stream Search service

## Current Status

✅ **Database Connection**: Working  
✅ **M3U Playlist Generation**: Working  
✅ **Dynamic Toggle**: Working (no restart needed)  
✅ **Cached Streams**: 281 movies cached from Stream Search

### Library Content

- **Monitored Movies**: 12,487
- **Monitored Series**: 875
- **Cached Streams**: 281 (movies only, currently)

## Xtream Credentials

The built-in Xtream API uses these credentials:

```
Username: streamarr
Password: streamarr
```

These can be changed in the web UI Settings page under "IPTV Setup".

## M3U Playlist URLs

### Full Library (All Monitored Content)
```
http://77.42.16.119/get.php?username=streamarr&password=streamarr&type=m3u
```
**Returns**: 13,362 items (12,487 movies + 875 series)

### Cached-Only (Cached Streams Only)
```
http://77.42.16.119/get.php?username=streamarr&password=streamarr&type=m3u
```
**Returns**: 281 items (cached movies only)

*Note: The URL is the same for both modes. The mode is determined by the database setting `only_cached_streams`.*

## How to Toggle Between Modes

### Option 1: Via SSH/Database

```bash
# Set to FULL LIBRARY mode (show all monitored content)
ssh root@77.42.16.119 "PGPASSWORD='streamarr_password' psql -U streamarr -d streamarr -h localhost -c \"UPDATE settings SET value = 'false' WHERE key = 'only_cached_streams';\""

# Set to CACHED-ONLY mode (show only cached streams)
ssh root@77.42.16.119 "PGPASSWORD='streamarr_password' psql -U streamarr -d streamarr -h localhost -c \"UPDATE settings SET value = 'true' WHERE key = 'only_cached_streams';\""
```

### Option 2: Via Web UI (Once implemented)

The web UI Settings page should have a toggle for "Only Include Media with Cached Streams". Toggling it will dynamically update the M3U playlist without requiring a server restart.

## Stream Cache Monitor Operation

The Stream Search service (when enabled from the web UI) will:

1. Scan all monitored movies and series
2. Search for available streams using configured providers (Torrentio, Real-Debrid, etc.)
3. Cache found streams in the `media_streams` database table
4. Update quality scores for existing cached streams

### Current Issues

- **No Series Cached Yet**: The cache scanner currently only has 281 cached movies. Series caching may require additional configuration.
- **Live TV**: Live TV streams are always included in the playlist regardless of the cache setting (as intended).

## Database Schema

### Key Tables

```sql
-- Settings (controls behavior)
settings (key='only_cached_streams', value='true'/'false')

-- Content Libraries
library_movies (monitored=true)
library_series (monitored=true)

-- Cached Streams
media_streams (movie_id, series_id, quality_score, etc.)
```

## Technical Details

### How the Toggle Works

1. **Setting Storage**: The `only_cached_streams` setting is stored in the `settings` table with key='only_cached_streams'
2. **Dynamic Reload**: The settings manager reloads this value on every request (no caching)
3. **Playlist Logic**: 
   - If `only_cached_streams = false`: Returns all movies and series from `library_movies` and `library_series`
   - If `only_cached_streams = true`: Returns only movies and series that have cached streams in `media_streams`

### M3U Format

The playlist is returned in M3U format with extended attributes:

```
#EXTM3U
#EXTINF:-1 tvg-id="movie_1179558" tvg-name="15 Cameras (2023)" tvg-logo="..." group-title="Movies",15 Cameras (2023)
http://77.42.16.119/movie/streamarr/streamarr/1179558.mp4
```

Group titles:
- `Movies` - VOD movies
- `Series` - TV series
- `Live TV` - Live television channels (always included)

## Troubleshooting

### M3U Not Updating After Toggle

The setting reloads dynamically. If the playlist isn't changing:

1. Check the setting value:
   ```bash
   PGPASSWORD='streamarr_password' psql -U streamarr -d streamarr -h localhost -c "SELECT value FROM settings WHERE key = 'only_cached_streams';"
   ```

2. Check server logs:
   ```bash
   ssh root@77.42.16.119 "tail -50 /root/streamarr-pro/server.log | grep -i 'only_cached'"
   ```

3. Restart the server if needed:
   ```bash
   ssh root@77.42.16.119 "pkill -9 server && sleep 2 && cd /root/streamarr-pro && nohup ./bin/server > server.log 2>&1 &"
   ```

### No Cached Streams Found

If `media_streams` is empty even though Stream Search is "Running":

1. Check if the service actually ran:
   ```bash
   PGPASSWORD='streamarr_password' psql -U streamarr -d streamarr -h localhost -c "SELECT COUNT(*) FROM media_streams;"
   ```

2. Check logs for errors:
   ```bash
   tail -100 /root/streamarr-pro/server.log | grep -i "cache\|search\|error"
   ```

3. Verify provider configuration (TMDB API key, Real-Debrid key, etc.)

## Next Steps

1. **Configure Providers**: Set up Real-Debrid, Stremio addons, or other providers in Settings
2. **Run Stream Search**: Trigger the Stream Search service to cache more content
3. **Set Web UI Toggle**: Once available, use the UI to toggle between modes instead of SSH

## File Locations (Remote Server)

- **Binary**: `/root/streamarr-pro/bin/server`
- **Database**: PostgreSQL on localhost:5432
- **Logs**: `/root/streamarr-pro/server.log`
- **.env**: `/root/streamarr-pro/.env`

## Database Credentials

```
Database URL: postgres://streamarr:streamarr_password@localhost:5432/streamarr
```

---

**Last Updated**: 2025-12-23  
**Environment**: Ubuntu 22.04 (x86_64) without Docker  
**Server Address**: 77.42.16.119  
**Web UI Port**: 80 (proxied via Nginx to localhost:8080)
