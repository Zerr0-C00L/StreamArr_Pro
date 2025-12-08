#!/usr/bin/env python3
"""
Bulk import all DMM hashlists from local files.
Processes all .html files and outputs a combined JSON index.
"""

import os
import sys
import json
import re
import urllib.parse
import lzstring
from pathlib import Path
from concurrent.futures import ProcessPoolExecutor, as_completed
import multiprocessing

def decode_hashlist_file(filepath):
    """Decode a single hashlist file and return torrents."""
    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            html = f.read()
        
        # Try to extract LZ-String data
        lz_data = None
        
        # Pattern 1: iframe src with hash fragment (newer format)
        match = re.search(r'<iframe[^>]+src="[^"]+#([^"]+)"', html)
        if match:
            lz_data = urllib.parse.unquote(match.group(1))
        
        # Pattern 2: data:text/html with embedded LZString call
        if not lz_data:
            match = re.search(r'"src":"data:text/html,(.+?)"', html)
            if not match:
                match = re.search(r'src="data:text/html,(.+?)"', html)
            
            if match:
                encoded = match.group(1)
                decoded = urllib.parse.unquote(encoded)
                lz_match = re.search(r'LZString\.decompressFromBase64\("(.+?)"\)', decoded)
                if lz_match:
                    lz_data = lz_match.group(1)
        
        if not lz_data:
            return None, f"No LZ data found in {filepath}"
        
        # Decompress
        x = lzstring.LZString()
        
        # Try URI component first (most common for new format)
        result = None
        try:
            result = x.decompressFromEncodedURIComponent(lz_data)
        except:
            pass
        
        if not result:
            try:
                result = x.decompressFromBase64(lz_data)
            except:
                pass
        
        if not result:
            try:
                # Convert URI-safe to standard base64
                standard_b64 = lz_data.replace('-', '+').replace('_', '/')
                result = x.decompressFromBase64(standard_b64)
            except:
                pass
        
        if not result:
            return None, f"Decompression failed for {filepath}"
        
        # Parse JSON
        data = json.loads(result)
        
        # Handle both formats
        if isinstance(data, dict) and "torrents" in data:
            return data["torrents"], None
        elif isinstance(data, list):
            return data, None
        else:
            return None, f"Unknown data format in {filepath}"
            
    except Exception as e:
        return None, f"Error processing {filepath}: {str(e)}"


def process_file(filepath):
    """Process a single file and return results."""
    torrents, error = decode_hashlist_file(filepath)
    filename = os.path.basename(filepath)
    if torrents:
        return filename, torrents, None
    return filename, None, error


def main():
    # Get hashlist directory
    hashlist_dir = "/Users/zeroq/Downloads/hashlists/hashlists"
    output_file = "/Users/zeroq/tmdb-to-vod-playlist/all_hashlists_combined.json"
    
    # Get all HTML files
    html_files = list(Path(hashlist_dir).glob("*.html"))
    total_files = len(html_files)
    print(f"Found {total_files} hashlist files to process")
    
    # Process in parallel
    all_hashes = {}
    processed = 0
    errors = 0
    duplicates = 0
    
    # Use multiprocessing for speed
    num_workers = multiprocessing.cpu_count()
    print(f"Using {num_workers} workers")
    
    with ProcessPoolExecutor(max_workers=num_workers) as executor:
        futures = {executor.submit(process_file, str(f)): f for f in html_files}
        
        for future in as_completed(futures):
            processed += 1
            filename, torrents, error = future.result()
            
            if error:
                errors += 1
                if processed % 1000 == 0:
                    print(f"Progress: {processed}/{total_files} (errors: {errors})")
                continue
            
            if torrents:
                for t in torrents:
                    h = t.get("hash", "").lower()
                    if not h:
                        continue
                    
                    if h in all_hashes:
                        duplicates += 1
                        continue
                    
                    fn = t.get("filename", "")
                    bytes_size = t.get("bytes", 0)
                    
                    # Detect quality
                    quality = "unknown"
                    if re.search(r"2160p|4K|UHD", fn, re.I):
                        quality = "2160p"
                    elif re.search(r"1080p", fn, re.I):
                        quality = "1080p"
                    elif re.search(r"720p", fn, re.I):
                        quality = "720p"
                    elif re.search(r"480p", fn, re.I):
                        quality = "480p"
                    
                    # Detect type
                    media_type = "movie"
                    if re.search(r"S\d{1,2}E\d{1,2}", fn, re.I):
                        media_type = "episode"
                    elif re.search(r"Season|Complete|S\d{1,2}", fn, re.I):
                        media_type = "series"
                    
                    # Extract IMDB if present
                    imdb = None
                    imdb_match = re.search(r"tt(\d{7,})", fn, re.I)
                    if imdb_match:
                        imdb = "tt" + imdb_match.group(1)
                    
                    all_hashes[h] = {
                        "hash": h,
                        "filename": fn,
                        "bytes": bytes_size,
                        "imdb_id": imdb,
                        "type": media_type,
                        "quality": quality
                    }
            
            if processed % 500 == 0:
                print(f"Progress: {processed}/{total_files} - Hashes: {len(all_hashes)} - Duplicates: {duplicates}")
    
    # Calculate stats
    total_bytes = sum(h.get("bytes", 0) for h in all_hashes.values())
    
    print(f"\n=== Final Results ===")
    print(f"Files processed: {processed}")
    print(f"Errors: {errors}")
    print(f"Unique hashes: {len(all_hashes)}")
    print(f"Duplicates skipped: {duplicates}")
    print(f"Total size: {total_bytes / 1024/1024/1024/1024:.2f} TB")
    
    # Quality breakdown
    by_quality = {}
    by_type = {}
    for h in all_hashes.values():
        q = h.get("quality", "unknown")
        t = h.get("type", "unknown")
        by_quality[q] = by_quality.get(q, 0) + 1
        by_type[t] = by_type.get(t, 0) + 1
    
    print("\nBy Quality:")
    for q, c in sorted(by_quality.items(), key=lambda x: -x[1]):
        print(f"  {q}: {c}")
    
    print("\nBy Type:")
    for t, c in sorted(by_type.items(), key=lambda x: -x[1]):
        print(f"  {t}: {c}")
    
    # Save to file
    print(f"\nSaving to {output_file}...")
    with open(output_file, 'w') as f:
        json.dump(all_hashes, f)
    
    print(f"Done! File size: {os.path.getsize(output_file) / 1024/1024:.2f} MB")
    print(f"\nTo upload to server, run:")
    print(f"  scp -i ssh-key-2025-12-06.key {output_file} ubuntu@158.179.24.84:/var/www/html/cache/personal_hashlists/index.json")


if __name__ == "__main__":
    main()
