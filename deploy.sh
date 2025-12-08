#!/bin/bash
# Automated deployment script for TMDB to Oracle Cloud

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m' # No Color

SERVER_IP="158.179.24.84"
SSH_KEY="$1"
PROJECT_DIR="/Users/zeroq/tmdb-to-vod-playlist"

echo -e "${BLUE}=== TMDB Oracle Cloud Deployment ===${NC}\n"

# Check if SSH key provided
if [ -z "$SSH_KEY" ]; then
    echo -e "${RED}Error: Please provide the SSH key path${NC}"
    echo "Usage: ./deploy.sh /path/to/your-key.pem"
    exit 1
fi

# Secure the key
chmod 400 "$SSH_KEY"

echo -e "${GREEN}Step 1: Testing SSH connection...${NC}"
ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no -o ConnectTimeout=10 ubuntu@$SERVER_IP "echo 'Connection successful!'" || {
    echo -e "${RED}Failed to connect. Please check your SSH key path.${NC}"
    exit 1
}

echo -e "\n${GREEN}Step 2: Installing PHP and dependencies...${NC}"
ssh -i "$SSH_KEY" ubuntu@$SERVER_IP << 'ENDSSH'
sudo apt update
sudo apt install -y php php-curl php-json php-mbstring php-xml
php --version
ENDSSH

echo -e "\n${GREEN}Step 3: Uploading project files...${NC}"
ssh -i "$SSH_KEY" ubuntu@$SERVER_IP "mkdir -p ~/tmdb-to-vod-playlist"
scp -i "$SSH_KEY" -r "$PROJECT_DIR"/* ubuntu@$SERVER_IP:~/tmdb-to-vod-playlist/

echo -e "\n${GREEN}Step 4: Configuring firewall...${NC}"
ssh -i "$SSH_KEY" ubuntu@$SERVER_IP << 'ENDSSH'
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 8000 -j ACCEPT
sudo netfilter-persistent save
ENDSSH

echo -e "\n${GREEN}Step 5: Creating systemd service for auto-start...${NC}"
ssh -i "$SSH_KEY" ubuntu@$SERVER_IP << 'ENDSSH'
sudo tee /etc/systemd/system/tmdb-server.service > /dev/null << 'EOF'
[Unit]
Description=TMDB VOD Playlist Server
After=network.target

[Service]
Type=simple
User=ubuntu
WorkingDirectory=/home/ubuntu/tmdb-to-vod-playlist
ExecStart=/usr/bin/php -S 0.0.0.0:8000 router.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable tmdb-server
sudo systemctl start tmdb-server
ENDSSH

echo -e "\n${GREEN}Step 6: Setting up daily playlist updates (5 PM Perth time)...${NC}"
ssh -i "$SSH_KEY" ubuntu@$SERVER_IP << 'ENDSSH'
(crontab -l 2>/dev/null; echo "0 9 * * * cd /home/ubuntu/tmdb-to-vod-playlist && /usr/bin/php create_playlist.php >> /home/ubuntu/tmdb-to-vod-playlist/logs/playlist_update.log 2>&1") | crontab -
mkdir -p ~/tmdb-to-vod-playlist/logs
ENDSSH

echo -e "\n${BLUE}=== Deployment Complete! ===${NC}"
echo -e "\n${GREEN}Your server is now running at: http://$SERVER_IP:8000${NC}"
echo -e "${GREEN}Update Chillio with:${NC}"
echo -e "  Server URL: http://$SERVER_IP:8000"
echo -e "  Username: Unlimited"
echo -e "  Password: vtRFuaSlij0bZIT"
echo -e "\n${BLUE}To check server status:${NC}"
echo -e "  ssh -i $SSH_KEY ubuntu@$SERVER_IP 'sudo systemctl status tmdb-server'"
