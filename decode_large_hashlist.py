#!/usr/bin/env python3
import lzstring
import json
import sys
import re
import urllib.request
import urllib.parse

def decode_hashlist(filename):
    url = f"https://raw.githubusercontent.com/debridmediamanager/hashlists/main/{filename}"
    print(f"Downloading: {url}")
    
    with urllib.request.urlopen(url) as response:
        html = response.read().decode("utf-8")
    
    print(f"HTML size: {len(html)} bytes")
    
    # Try multiple patterns to extract the LZ-String data
    lz_data = None
    
    # Pattern 1: iframe src with hash fragment (newer format)
    # <iframe src="https://debridmediamanager.com/hashlist#N4IgLg...
    match = re.search(r'<iframe[^>]+src="[^"]+#([^"]+)"', html)
    if match:
        lz_data = urllib.parse.unquote(match.group(1))
        print(f"Found via iframe hash fragment, size: {len(lz_data)} bytes")
    
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
                print(f"Found via data:text/html pattern, size: {len(lz_data)} bytes")
    
    if not lz_data:
        print("Could not find LZ-String data in HTML")
        print(f"First 1000 chars: {html[:1000]}")
        return None
    
    print(f"LZ-String data size: {len(lz_data)} bytes")
    
    # Decompress - try different methods
    x = lzstring.LZString()
    result = None
    
    # Try URI-safe base64 first (uses - and _ instead of + and /)
    try:
        result = x.decompressFromEncodedURIComponent(lz_data)
        if result:
            print("Decoded using decompressFromEncodedURIComponent")
    except Exception as e:
        print(f"EncodedURIComponent failed: {e}")
    
    # Try regular base64
    if not result:
        try:
            result = x.decompressFromBase64(lz_data)
            if result:
                print("Decoded using decompressFromBase64")
        except Exception as e:
            print(f"Base64 failed: {e}")
    
    # Try converting URI-safe to standard base64 then decode
    if not result:
        try:
            # Convert URI-safe base64 to standard base64
            standard_b64 = lz_data.replace('-', '+').replace('_', '/')
            result = x.decompressFromBase64(standard_b64)
            if result:
                print("Decoded after converting URI-safe to standard base64")
        except Exception as e:
            print(f"Converted base64 failed: {e}")
    
    if not result:
        print("Decompression failed with all methods")
        return None
    
    print(f"Decompressed size: {len(result)} chars")
    
    # Parse JSON
    try:
        data = json.loads(result)
        
        # Handle both formats:
        # Format 1: {"title": "...", "torrents": [...]}
        # Format 2: [{"filename": "...", "hash": "...", "bytes": ...}, ...]
        
        if isinstance(data, dict) and "torrents" in data:
            title = data.get("title", "Unknown")
            torrents = data["torrents"]
            print(f"Title: {title}")
            print(f"Parsed {len(torrents)} torrents (dict format)")
            return torrents
        elif isinstance(data, list):
            print(f"Parsed {len(data)} torrents (list format)")
            return data
        else:
            print(f"Unknown data format: {type(data)}")
            return None
    except json.JSONDecodeError as e:
        print(f"JSON error: {e}")
        print(f"First 500: {result[:500]}")
        return None

if __name__ == "__main__":
    filename = sys.argv[1] if len(sys.argv) > 1 else "770f8f0e-8aa8-4896-a7d2-72cd88d43ae8.html"
    data = decode_hashlist(filename)
    
    if data:
        total_size = sum(t.get("bytes", 0) for t in data)
        print(f"\nTotal torrents: {len(data)}")
        print(f"Total size: {total_size / 1024/1024/1024/1024:.2f} TB")
        
        # Save to JSON
        output = f"/var/www/html/cache/dmm/{filename.replace('.html', '.json')}"
        with open(output, "w") as f:
            json.dump(data, f)
        print(f"Saved to: {output}")
        
        # Show first 3 items
        for i, t in enumerate(data[:3]):
            fn = t.get("filename", "unknown")[:80]
            h = t.get("hash", "unknown")[:20]
            sz = t.get("bytes", 0) / 1024/1024/1024
            print(f"  {i+1}. {fn}... [{h}...] ({sz:.2f} GB)")
