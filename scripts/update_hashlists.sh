#!/bin/bash
# Auto-update DMM hashlists
# Run via cron: 0 4 * * 0 /var/www/html/scripts/update_hashlists.sh
# (Every Sunday at 4am)

SCRIPT_DIR="/var/www/html"
CACHE_DIR="/var/www/html/cache/dmm"
LOG_FILE="/var/www/html/logs/hashlist_update.log"

echo "$(date): Starting hashlist update..." >> "$LOG_FILE"

# Download latest hashlist index from DMM GitHub
cd "$CACHE_DIR"

# Get list of hashlist files (GitHub API)
HASHLIST_URL="https://api.github.com/repos/debridmediamanager/hashlists/contents"
curl -s -H "User-Agent: HashlistUpdater" "$HASHLIST_URL" > /tmp/dmm_files.json

# Count new files
NEW_COUNT=$(python3 << 'PYEOF'
import json
import os

cache_dir = "/var/www/html/cache/dmm"
with open("/tmp/dmm_files.json") as f:
    files = json.load(f)

new_files = []
for f in files:
    if f.get("name", "").endswith(".html"):
        cache_file = os.path.join(cache_dir, f["name"].replace(".html", ".json"))
        if not os.path.exists(cache_file):
            new_files.append(f["name"])

print(len(new_files))
PYEOF
)

echo "$(date): Found $NEW_COUNT new hashlist files" >> "$LOG_FILE"

if [ "$NEW_COUNT" -gt 0 ]; then
    # Download and decode new files using the Python decoder
    python3 "$SCRIPT_DIR/decode_large_hashlist.py" --batch /tmp/dmm_files.json >> "$LOG_FILE" 2>&1
    
    echo "$(date): Update complete" >> "$LOG_FILE"
else
    echo "$(date): No new files to process" >> "$LOG_FILE"
fi
