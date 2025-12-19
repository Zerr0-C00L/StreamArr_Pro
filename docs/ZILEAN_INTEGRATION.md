# Zilean DMM Integration for StreamArr Pro

## What is Zilean?

Zilean is a service that indexes [DebridMediaManager (DMM)](https://github.com/debridmediamanager/debrid-media-manager) sourced content shared by users. It provides instant access to cached torrents from debrid services, significantly improving streaming performance.

## Features

- **Instant Cached Content**: Access to millions of pre-cached DMM torrents
- **Fast Search**: Search by IMDB ID or text query
- **Automatic Quality Detection**: Extracts quality info from filenames
- **Season/Episode Filtering**: Automatically filters series content
- **Priority Provider**: Zilean searches first for fastest results
- **Real-Debrid Integration**: Works seamlessly with your existing RD setup

## Installation

### 1. Deploy Zilean

The easiest way to deploy Zilean is using Docker:

```bash
# Generate Postgres password
echo "POSTGRES_PASSWORD=$(openssl rand -base64 42 | tr -dc A-Za-z0-9 | cut -c -32 | tr -d '\n')" > .env

# Create docker-compose.yml
cat > docker-compose.yml << 'EOF'
volumes:
  zilean_data:
  zilean_tmp:
  postgres_data:

services:
  zilean:
    image: ipromknight/zilean:latest
    restart: unless-stopped
    container_name: zilean
    tty: true
    environment:
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
    ports:
      - "8181:8181"
    volumes:
      - zilean_data:/app/data
      - zilean_tmp:/tmp
    healthcheck:
      test: curl --connect-timeout 10 --silent --show-error --fail http://localhost:8181/healthchecks/ping
      timeout: 60s
      interval: 30s
      retries: 10
    depends_on:
      postgres:
        condition: service_healthy

  postgres:
    image: postgres:17.2-alpine
    container_name: postgres
    restart: unless-stopped
    shm_size: 2G
    environment:
      PGDATA: /var/lib/postgresql/data/pgdata
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
      POSTGRES_DB: zilean
    volumes:
      - postgres_data:/var/lib/postgresql/data/pgdata
    healthcheck:
      test: [ "CMD-SHELL", "pg_isready -U postgres" ]
      interval: 10s
      timeout: 5s
      retries: 5
EOF

# Start Zilean
docker compose up -d
```

### 2. Configure StreamArr Pro

1. **Open Settings** in StreamArr Pro web interface
2. **Scroll to "Zilean DMM Integration" section**
3. **Enable Zilean Provider** checkbox
4. **Enter Zilean URL**: `http://localhost:8181` (or your server IP)
5. **API Key** (optional): Found in `/app/data/settings.json` inside the container
6. **Save Settings**

## Configuration Options

### Zilean URL
- **Local**: `http://localhost:8181`
- **Remote**: `http://your-server-ip:8181`
- **Domain**: `https://zilean.yourdomain.com` (if using reverse proxy)

### API Key
- Optional - only needed if authentication is enabled
- Found in Zilean's `settings.json` file
- Generated automatically on first run

## How It Works

1. **Search Request**: When you request a stream, StreamArr Pro queries Zilean first
2. **IMDB Lookup**: Zilean searches its database by IMDB ID
3. **Cached Results**: Returns only cached torrents from debrid services
4. **Quality Extraction**: Automatically detects quality from filenames
5. **Season/Episode Filter**: For series, filters by S01E01 patterns
6. **Stream Generation**: Creates playable streams with magnet links

## API Endpoints Used

```
GET /imdb/search?query=tt1234567    # Search by IMDB ID
GET /dmm/search?query=movie name    # Search by text
GET /healthchecks/ping              # Health check
```

## Advantages Over Other Providers

- ✅ **Faster**: Pre-indexed database, no live scraping
- ✅ **Cached Only**: Only returns debrid-cached content
- ✅ **Higher Quality**: DMM focuses on quality releases
- ✅ **No Timeouts**: Instant results from database
- ✅ **Community Driven**: Content shared by real users

## Troubleshooting

### Zilean Not Responding
```bash
# Check if Zilean is running
docker ps | grep zilean

# View logs
docker logs zilean

# Restart if needed
docker restart zilean
```

### No Results
- Ensure Zilean has indexed content (takes time on first run)
- Check API key if authentication is enabled
- Verify IMDB ID is correct
- Try text search instead of IMDB search

### Connection Refused
- Check Zilean URL is correct
- Ensure port 8181 is accessible
- If remote, check firewall rules
- Verify Docker container is healthy

## Advanced Configuration

### Using with Reverse Proxy

If you want HTTPS and a custom domain:

```nginx
server {
    listen 443 ssl http2;
    server_name zilean.yourdomain.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://localhost:8181;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Remote Zilean Instance

If Zilean is on a different server:
1. Update Zilean URL to remote IP: `http://192.168.1.100:8181`
2. Ensure port 8181 is accessible from StreamArr Pro server
3. Consider using VPN or firewall rules for security

## Resources

- **Zilean Documentation**: https://ipromknight.github.io/zilean/
- **Zilean GitHub**: https://github.com/iPromKnight/zilean
- **DMM Project**: https://github.com/debridmediamanager/debrid-media-manager
- **Support**: StreamArr Pro GitHub Issues

## Performance Tips

1. **Local Deployment**: Deploy Zilean on same server as StreamArr Pro
2. **Database Maintenance**: Regularly backup Postgres data
3. **Index Size**: Larger index = more content but slower searches
4. **Caching**: Zilean results are cached for 30 minutes in StreamArr Pro
5. **Multiple Providers**: Use Zilean alongside other providers for best coverage

## Example Workflow

```
User Request → StreamArr Pro → Zilean (Priority #1)
                            ↓
                         Found? → Return cached stream
                            ↓
                        Not Found? → Try other providers
                                   (Torrentio, Comet, etc.)
```

With Zilean, most requests complete in <500ms with high-quality cached results!
