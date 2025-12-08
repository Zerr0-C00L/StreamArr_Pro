#!/usr/bin/env python3
"""
Split the large hashlist index into shards based on first 2 characters of hash.
This creates smaller files that can be loaded on-demand on a low-memory server.
"""

import json
import os
from collections import defaultdict

INPUT_FILE = "/Users/zeroq/tmdb-to-vod-playlist/all_hashlists_combined.json"
OUTPUT_DIR = "/Users/zeroq/tmdb-to-vod-playlist/hashlist_shards"

def main():
    print("Loading combined hashlist...")
    with open(INPUT_FILE, 'r') as f:
        all_hashes = json.load(f)
    
    print(f"Total hashes: {len(all_hashes)}")
    
    # Create output directory
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    
    # Split by first 2 characters of hash (256 possible shards: 00-ff)
    shards = defaultdict(dict)
    
    for hash_key, data in all_hashes.items():
        prefix = hash_key[:2].lower()
        shards[prefix][hash_key] = data
    
    print(f"Created {len(shards)} shards")
    
    # Save each shard
    shard_info = {}
    for prefix, hashes in shards.items():
        shard_file = os.path.join(OUTPUT_DIR, f"shard_{prefix}.json")
        with open(shard_file, 'w') as f:
            json.dump(hashes, f)
        
        size_kb = os.path.getsize(shard_file) / 1024
        shard_info[prefix] = {
            "count": len(hashes),
            "size_kb": round(size_kb, 2)
        }
        print(f"  Shard {prefix}: {len(hashes)} hashes ({size_kb:.1f} KB)")
    
    # Save shard metadata
    meta = {
        "total_hashes": len(all_hashes),
        "total_shards": len(shards),
        "shards": shard_info
    }
    
    meta_file = os.path.join(OUTPUT_DIR, "shards_meta.json")
    with open(meta_file, 'w') as f:
        json.dump(meta, f, indent=2)
    
    print(f"\nSaved shard metadata to {meta_file}")
    print(f"Average shard size: {sum(s['size_kb'] for s in shard_info.values()) / len(shard_info):.1f} KB")
    print(f"Max shard size: {max(s['size_kb'] for s in shard_info.values()):.1f} KB")
    
    # Create tar archive for easy upload
    print("\nCreating tar archive...")
    os.system(f"cd {OUTPUT_DIR} && tar -czf ../hashlist_shards.tar.gz *")
    tar_size = os.path.getsize("/Users/zeroq/tmdb-to-vod-playlist/hashlist_shards.tar.gz") / 1024 / 1024
    print(f"Archive size: {tar_size:.1f} MB")
    
    print("\nTo upload to server:")
    print("  scp -i ssh-key-2025-12-06.key hashlist_shards.tar.gz ubuntu@158.179.24.84:/var/www/html/cache/personal_hashlists/")
    print("  ssh ubuntu@158.179.24.84 'cd /var/www/html/cache/personal_hashlists && tar -xzf hashlist_shards.tar.gz'")

if __name__ == "__main__":
    main()
