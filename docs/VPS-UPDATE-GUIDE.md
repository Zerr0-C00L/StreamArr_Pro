# StreamArr Pro - VPS Update Guide

## Quick Update (SSH Method)

SSH into your VPS and run:

```bash
cd /path/to/StreamArr\ Pro
git pull origin main
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

## Or use the update script:

```bash
cd /path/to/StreamArr\ Pro
./update-docker.sh
```

## Check logs:

```bash
tail -f logs/server.log
```

## Troubleshooting

### Issue: "git pull" fails with permission errors
**Solution:**
```bash
sudo chown -R $USER:$USER /path/to/StreamArr\ Pro
git config --global --add safe.directory /path/to/StreamArr\ Pro
```

### Issue: Docker socket permission denied
**Solution:**
```bash
sudo usermod -aG docker $USER
# Then log out and back in
```

### Issue: Port already in use
**Solution:**
```bash
docker-compose down
docker ps -a | grep streamarr
docker rm -f streamarr streamarr-db
docker-compose up -d
```

## Alternative: Manual Pull Without Git

If git isn't working, download directly:

```bash
cd /path/to
wget https://github.com/Zerr0-C00L/StreamArr_Pro/archive/refs/heads/main.zip
unzip main.zip
mv "StreamArr Pro"/"StreamArr Pro.old"
mv StreamArr_Pro-main "StreamArr Pro"
cd "StreamArr Pro"
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

## Web UI Update Button

The web UI update button works automatically if:
1. Running in Docker
2. Docker socket is mounted: `/var/run/docker.sock:/var/run/docker.sock`
3. Git repository is accessible from container

If these conditions aren't met, use SSH method above.
