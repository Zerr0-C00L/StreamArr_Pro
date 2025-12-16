# 🔒 StreamArr Pro Security Setup Guide

## 🚨 Critical Security Issue Detected

Your StreamArr instance was compromised because it had **no authentication** protecting the API endpoints. Someone accessed your VPS and modified your M3U sources on **2025-12-14 at 00:45 UTC**.

## 🛡️ Security Measures Implemented

### 1. Authentication Middleware

Added three layers of security:
- **API Key Authentication** - For programmatic access
- **Basic HTTP Authentication** - For browser access  
- **IP Whitelisting** - Optional IP-based access control

### 2. Protected Endpoints

All API endpoints now require authentication EXCEPT:
- `/api/v1/health` - Health check endpoint
- `/player_api.php` - Xtream Codes API (configure separately)
- `/get.php` - Playlist generation (configure separately)

## 📋 Setup Instructions

### Step 1: Configure Authentication

1. **Copy the security template:**
   ```bash
   cp .env.security .env
   ```

2. **Generate a secure API key:**
   ```bash
   openssl rand -hex 32
   ```

3. **Edit `.env` and set your credentials:**
   ```bash
   STREAMARR_API_KEY=<your-generated-key>
   STREAMARR_USERNAME=admin
   STREAMARR_PASSWORD=<strong-password>
   ```

### Step 2: Update Docker Configuration

1. **Edit your `docker-compose.yml` to load the `.env` file:**
   ```yaml
   services:
     streamarr:
       environment:
         - STREAMARR_API_KEY=${STREAMARR_API_KEY}
         - STREAMARR_USERNAME=${STREAMARR_USERNAME}
         - STREAMARR_PASSWORD=${STREAMARR_PASSWORD}
         - STREAMARR_IP_WHITELIST=${STREAMARR_IP_WHITELIST:-}
   ```

2. **Or add to your docker run command:**
   ```bash
   docker run -d \
     -e STREAMARR_API_KEY="your-key" \
     -e STREAMARR_USERNAME="admin" \
     -e STREAMARR_PASSWORD="your-password" \
     ...
   ```

### Step 3: Rebuild and Deploy

```bash
# Rebuild Docker image with new security code
./docker-build.sh

# On VPS: Pull and restart with new env vars
ssh streamarr-vps << 'EOF'
  cd /root/StreamArrPro
  
  # Create .env file
  cat > .env << 'ENVEOF'
STREAMARR_API_KEY=<your-api-key>
STREAMARR_USERNAME=admin  
STREAMARR_PASSWORD=<your-password>
STREAMARR_IP_WHITELIST=
ENVEOF

  # Update and restart
  docker-compose down
  docker-compose pull
  docker-compose up -d
EOF
```

### Step 4: Configure Firewall

Block direct access to port 8080 from the internet:

```bash
ssh streamarr-vps << 'EOF'
  # Enable firewall if not already
  sudo ufw --force enable
  
  # Allow SSH
  sudo ufw allow 22/tcp
  
  # Allow HTTP/HTTPS (for nginx)
  sudo ufw allow 80/tcp
  sudo ufw allow 443/tcp
  
  # Block direct access to StreamArr port from internet
  sudo ufw deny 8080/tcp
  
  # Allow localhost access (for nginx proxy)
  sudo ufw allow from 127.0.0.1 to any port 8080
  
  sudo ufw status
EOF
```

### Step 5: Set Up Nginx Reverse Proxy (Recommended)

1. **Install nginx:**
   ```bash
   ssh streamarr-vps "sudo apt update && sudo apt install -y nginx"
   ```

2. **Copy nginx configuration:**
   ```bash
   scp nginx-security.conf streamarr-vps:/tmp/
   ssh streamarr-vps "sudo mv /tmp/nginx-security.conf /etc/nginx/sites-available/streamarr"
   ```

3. **Enable and test:**
   ```bash
   ssh streamarr-vps << 'EOF'
     sudo ln -sf /etc/nginx/sites-available/streamarr /etc/nginx/sites-enabled/
     sudo rm -f /etc/nginx/sites-enabled/default
     sudo nginx -t
     sudo systemctl reload nginx
EOF
   ```

### Step 6: Clean Up Unauthorized Changes

Remove the unauthorized "Trex" source:

```bash
ssh streamarr-vps << 'EOF'
  docker exec streamarr-db psql -U streamarr -d streamarr -c "
    UPDATE settings 
    SET value = replace(
      value::text, 
      ',{\"name\":\"Trex\",\"url\":\"https://drive.google.com/uc?export=download&id=1HuKp215-9WyFXiELDnSIjn89_pVeKwW7&confirm=true\",\"enabled\":true}',
      ''
    )::jsonb 
    WHERE key = 'app_settings';
  "
  
  # Restart to apply changes
  docker restart streamarr
EOF
```

## 🔑 Using the API with Authentication

### Using API Key (Recommended for scripts/apps):
```bash
curl -H "X-API-Key: your-api-key" http://your-ip/api/v1/movies
```

### Using Basic Auth (Recommended for browsers):
```bash
curl -u admin:your-password http://your-ip/api/v1/movies
```

### Updating UI to use authentication:
The UI will automatically prompt for credentials when accessing protected endpoints.

## 🌐 Optional: SSL/HTTPS Setup

For production, set up Let's Encrypt SSL:

```bash
ssh streamarr-vps << 'EOF'
  sudo apt install -y certbot python3-certbot-nginx
  sudo certbot --nginx -d your-domain.com
  sudo systemctl reload nginx
EOF
```

## 🔍 Monitoring Access

Check who's accessing your server:

```bash
ssh streamarr-vps "tail -f /var/log/nginx/streamarr-access.log"
```

Check for authentication failures:

```bash
ssh streamarr-vps "docker logs streamarr 2>&1 | grep -i 'unauthorized\|forbidden'"
```

## ⚠️ Important Notes

1. **Never commit `.env` file to git** - It's already in `.gitignore`
2. **Use strong passwords** - Minimum 16 characters with mixed case, numbers, and symbols
3. **Rotate API keys regularly** - Change them every 90 days
4. **Monitor access logs** - Watch for suspicious activity
5. **Keep software updated** - Regularly update StreamArr and system packages

## 🆘 Emergency: Breach Detection

If you suspect unauthorized access:

1. **Immediately change all credentials**
2. **Check database for unauthorized changes**
3. **Review access logs** 
4. **Consider resetting the database** if data integrity is compromised
5. **Report any security issues** to the StreamArr repository

## 📞 Support

For security concerns, open an issue on GitHub with the `security` label.
