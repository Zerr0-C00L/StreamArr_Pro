# Updating StreamArr Pro

This guide covers how to update StreamArr Pro to the latest version while preserving your data.

## For Docker Users (Recommended)

### Option 1: Using Tagged Releases (Recommended)

1. **Check for new releases**: Visit [GitHub Releases](https://github.com/Zerr0-C00L/StreamArr/releases)

2. **Pull the new version**:
   ```bash
   docker pull ghcr.io/zerr0-c00l/streamarr-pro:v0.1.0  # Replace with desired version
   # Or for latest stable:
   docker pull ghcr.io/zerr0-c00l/streamarr-pro:latest
   ```

3. **Update your docker-compose.yml**:
   ```yaml
   services:
     streamarr:
       image: ghcr.io/zerr0-c00l/streamarr-pro:v0.1.0  # Pin to specific version
   ```

4. **Recreate containers** (keeps volumes/data):
   ```bash
   docker-compose down
   docker-compose up -d
   ```

5. **Verify the update**:
   ```bash
   docker logs streamarr_streamarr_1 --tail 50
   ```

### Option 2: Building from Source

1. **Download latest code**:
   ```bash
   wget https://github.com/Zerr0-C00L/StreamArr/archive/refs/heads/main.zip
   unzip main.zip
   cd StreamArr-main
   ```

2. **Rebuild the image**:
   ```bash
   docker build -t streamarr_pro:latest .
   ```

3. **Update your stack** (in Portainer or docker-compose):
   ```bash
   docker-compose down
   docker-compose up -d
   ```

**Important**: Your data is preserved in Docker volumes. The database, settings, and library persist across updates.

## For Non-Docker Users (Bare Metal / VPS)

### Automatic Update (Recommended)

Use the built-in update script:

```bash
cd /root/streamarr-pro  # Or your installation directory
./scripts/update-local.sh
```

This will:
- Pull latest code from GitHub
- Build Go binaries
- Build UI
- Restart services

### Manual Update

1. **Backup your database**:
   ```bash
   pg_dump -U streamarr streamarr > backup-$(date +%Y%m%d).sql
   ```

2. **Stop services**:
   ```bash
   systemctl stop streamarr streamarr-worker
   ```

3. **Pull latest code**:
   ```bash
   cd /root/streamarr-pro
   git pull origin main
   ```

4. **Build Go binaries**:
   ```bash
   go build -o bin/server ./cmd/server
   go build -o bin/worker ./cmd/worker
   go build -o bin/migrate ./cmd/migrate
   ```

5. **Build UI**:
   ```bash
   cd streamarr-pro-ui
   npm install
   npm run build
   cd ..
   ```

6. **Run database migrations**:
   ```bash
   ./bin/migrate up
   ```

7. **Start services**:
   ```bash
   systemctl start streamarr
   # Worker starts automatically with main service
   ```

8. **Verify**:
   ```bash
   systemctl status streamarr streamarr-worker
   journalctl -u streamarr -f
   ```

## Version Pinning (Docker)

To avoid unexpected changes, pin to specific versions:

```yaml
# docker-compose.yml
services:
  streamarr:
    image: ghcr.io/zerr0-c00l/streamarr-pro:v0.1.0  # Specific version
    # OR
    image: ghcr.io/zerr0-c00l/streamarr-pro:latest  # Latest stable
```

## Release Channels

- **`latest`** - Latest stable release
- **`v0.x.x`** - Specific version tags (recommended for production)
- **`main`** - Latest development code (may be unstable)

## Database Migrations

Database migrations run automatically on startup. Your schema version is tracked in the `schema_migrations` table.

**Important**: 
- Always backup before updating
- Migrations are forward-only (no automatic rollback)
- Do not manually delete migration entries

## Troubleshooting Updates

### UI Changes Not Appearing (Docker)

```bash
# Clear browser cache (Ctrl+Shift+R or Cmd+Shift+R)
# Or force rebuild UI in container:
docker exec streamarr_streamarr_1 rm -rf /app/streamarr-pro-ui/dist
docker restart streamarr_streamarr_1
```

### UI Changes Not Appearing (Non-Docker)

```bash
# Rebuild UI
cd streamarr-pro-ui
rm -rf dist node_modules
npm install
npm run build
cd ..

# If using nginx, copy to web root:
cp -r streamarr-pro-ui/dist/* /root/streamarr-pro/streamarr-pro-ui/dist/
systemctl reload nginx
```

### Database Migration Failures

```bash
# Check migration status
./bin/migrate version

# Force to specific version (dangerous!)
./bin/migrate force <version>

# Restore from backup
psql -U streamarr streamarr < backup-20251220.sql
```

### Binary Permission Issues

```bash
chmod +x bin/server bin/worker bin/migrate
```

## What Gets Preserved During Updates

✅ **Preserved**:
- Database (all library content, settings, users)
- Docker volumes
- Configuration files (if not in Git)
- Logs

❌ **Replaced**:
- Binaries (server, worker, migrate)
- UI files
- Code files
- Dependencies

## Synology NAS / Portainer Specific

1. **Via Portainer UI**:
   - Go to Stacks → Your StreamArr stack
   - Click "Editor"
   - Update image tag in docker-compose.yml
   - Click "Update the stack"
   - Select "Re-pull image and redeploy"

2. **Via SSH**:
   ```bash
   cd /volume1/docker/streamarr  # Your stack location
   docker-compose pull
   docker-compose up -d
   ```

## Checking Current Version

```bash
# Docker
docker exec streamarr_streamarr_1 /app/bin/server --version

# Non-Docker
curl http://localhost:8080/api/v1/version
```

## Getting Help

If you encounter issues:
1. Check logs: `docker logs streamarr_streamarr_1` or `journalctl -u streamarr -n 100`
2. Verify database: `docker exec streamarr_postgres_1 psql -U streamarr -c '\dt'`
3. Open an issue on GitHub with logs and version info
