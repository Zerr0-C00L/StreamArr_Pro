#!/usr/bin/env python3
"""
Build IMDB index from sharded hashlists
Maps IMDB IDs to torrent hashes for fast lookups
"""

import json
import os
import re
from collections import defaultdict

# Local paths
SHARD_DIR = "/Users/zeroq/tmdb-to-vod-playlist/hashlist_shards"
OUTPUT_FILE = "/Users/zeroq/tmdb-to-vod-playlist/imdb_index.json"

# Common IMDB patterns in filenames
IMDB_PATTERNS = [
    r'\btt(\d{7,8})\b',  # tt1234567 or tt12345678
]

# Title patterns for common series
SERIES_PATTERNS = {
    # Format: regex pattern -> IMDB ID
    r'stargate\W*sg\W*1': 'tt0118480',
    r'stargate\W*atlantis': 'tt0374455',
    r'stargate\W*universe': 'tt1286039',
    r'breaking\W*bad': 'tt0903747',
    r'game\W*of\W*thrones': 'tt0944947',
    r'the\W*office': 'tt0386676',
    r'friends': 'tt0108778',
    r'the\W*mandalorian': 'tt8111088',
    r'house\W*of\W*the\W*dragon': 'tt11198330',
    r'the\W*last\W*of\W*us': 'tt3581920',
    r'wednesday': 'tt13443470',
    r'stranger\W*things': 'tt4574334',
    r'the\W*witcher': 'tt5180504',
    r'the\W*boys': 'tt1190634',
    r'peaky\W*blinders': 'tt2442560',
    r'better\W*call\W*saul': 'tt3032476',
    r'westworld': 'tt0475784',
    r'chernobyl': 'tt7366338',
    r'the\W*walking\W*dead': 'tt1520211',
    r'rick\W*and\W*morty': 'tt2861424',
    r'arcane': 'tt11126994',
    r'succession': 'tt7660850',
    r'severance': 'tt11280740',
    r'the\W*bear': 'tt14452776',
    r'shogun': 'tt2788316',
    r'fallout': 'tt12637874',
    r'baby\W*reindeer': 'tt13649112',
}

def extract_imdb_from_filename(filename):
    """Try to extract IMDB ID from filename"""
    filename_lower = filename.lower()
    
    # Try direct IMDB pattern
    for pattern in IMDB_PATTERNS:
        match = re.search(pattern, filename, re.IGNORECASE)
        if match:
            return f"tt{match.group(1)}"
    
    # Try series patterns
    for pattern, imdb_id in SERIES_PATTERNS.items():
        if re.search(pattern, filename_lower):
            return imdb_id
    
    return None

def build_index():
    """Build IMDB index from all shards"""
    imdb_index = defaultdict(list)
    total_hashes = 0
    matched_hashes = 0
    
    shard_files = sorted([f for f in os.listdir(SHARD_DIR) if f.startswith("shard_") and f.endswith(".json")])
    
    print(f"Processing {len(shard_files)} shards...")
    
    for shard_file in shard_files:
        shard_path = os.path.join(SHARD_DIR, shard_file)
        
        try:
            with open(shard_path, 'r') as f:
                shard_data = json.load(f)
        except Exception as e:
            print(f"Error loading {shard_file}: {e}")
            continue
        
        for hash_val, entry in shard_data.items():
            total_hashes += 1
            
            # Try to get IMDB from entry directly
            imdb_id = entry.get('imdb_id')
            
            # If not, try to extract from filename
            if not imdb_id:
                filename = entry.get('filename', '')
                imdb_id = extract_imdb_from_filename(filename)
            
            if imdb_id:
                matched_hashes += 1
                # Store minimal info in index
                imdb_index[imdb_id].append({
                    'h': hash_val,
                    'f': entry.get('filename', ''),
                    'b': entry.get('bytes', 0),
                    'q': entry.get('quality', 'unknown'),
                    't': entry.get('type', 'unknown')
                })
        
        print(f"  Processed {shard_file}: {len(shard_data)} hashes")
    
    print(f"\nTotal hashes: {total_hashes}")
    print(f"Matched to IMDB: {matched_hashes} ({100*matched_hashes/total_hashes:.1f}%)")
    print(f"Unique IMDB IDs: {len(imdb_index)}")
    
    # Sort each IMDB's torrents by size (larger = better quality usually)
    for imdb_id in imdb_index:
        imdb_index[imdb_id].sort(key=lambda x: x.get('b', 0), reverse=True)
    
    # Save index
    with open(OUTPUT_FILE, 'w') as f:
        json.dump(dict(imdb_index), f)
    
    file_size = os.path.getsize(OUTPUT_FILE) / 1024 / 1024
    print(f"\nSaved index to {OUTPUT_FILE} ({file_size:.2f} MB)")
    
    # Show some stats
    print("\nTop IMDB IDs by torrent count:")
    sorted_imdb = sorted(imdb_index.items(), key=lambda x: len(x[1]), reverse=True)[:20]
    for imdb_id, torrents in sorted_imdb:
        print(f"  {imdb_id}: {len(torrents)} torrents")

if __name__ == "__main__":
    build_index()
