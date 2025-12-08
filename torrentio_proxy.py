#!/usr/bin/env python3
"""
Torrentio Proxy Server
Run this locally to bypass Cloudflare blocking on your server.
Your server will call this proxy, which forwards requests to Torrentio.

Usage: python3 torrentio_proxy.py
Then configure your server to use: http://your-local-ip:8765
"""

from http.server import HTTPServer, BaseHTTPRequestHandler
import urllib.request
import urllib.parse
import json
import ssl

PORT = 8765
RD_API_KEY = "OBKG7SQBEMDHIHI5MC6MXP4RU4G46NNCP223ETIINTFN2EWYGBGA"

class TorrentioProxy(BaseHTTPRequestHandler):
    def do_GET(self):
        try:
            # Parse the path
            # Expected: /stream/movie/{imdb}.json or /stream/series/{imdb}:{season}:{episode}.json
            path = self.path
            
            if path.startswith('/stream/'):
                # Build Torrentio URL with RD key
                torrentio_url = f"https://torrentio.strem.fun/realdebrid={RD_API_KEY}{path}"
                
                # Fetch from Torrentio
                req = urllib.request.Request(torrentio_url, headers={
                    'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
                })
                
                ctx = ssl.create_default_context()
                with urllib.request.urlopen(req, context=ctx, timeout=30) as response:
                    data = response.read()
                    
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Access-Control-Allow-Origin', '*')
                self.end_headers()
                self.wfile.write(data)
                
            elif path.startswith('/resolve/'):
                # Resolve URL - follow redirects to get final RD URL
                # Expected: /resolve/{infohash}/{fileIdx}/{filename}
                parts = path.split('/resolve/')[1]
                torrentio_url = f"https://torrentio.strem.fun/resolve/realdebrid/{RD_API_KEY}/{parts}"
                
                req = urllib.request.Request(torrentio_url, headers={
                    'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
                })
                
                ctx = ssl.create_default_context()
                # Follow redirects to get final URL
                with urllib.request.urlopen(req, context=ctx, timeout=30) as response:
                    final_url = response.url
                    
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Access-Control-Allow-Origin', '*')
                self.end_headers()
                self.wfile.write(json.dumps({'url': final_url}).encode())
                
            elif path == '/health':
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({'status': 'ok'}).encode())
                
            else:
                self.send_response(404)
                self.end_headers()
                self.wfile.write(b'Not Found')
                
        except Exception as e:
            self.send_response(500)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps({'error': str(e)}).encode())
    
    def log_message(self, format, *args):
        print(f"[{self.log_date_time_string()}] {args[0]}")

if __name__ == '__main__':
    print(f"Starting Torrentio Proxy on port {PORT}")
    print(f"Endpoints:")
    print(f"  GET /stream/movie/{{imdb}}.json - Get movie streams")
    print(f"  GET /stream/series/{{imdb}}:{{season}}:{{episode}}.json - Get series streams")  
    print(f"  GET /resolve/{{infohash}}/{{fileIdx}}/{{filename}} - Resolve to RD URL")
    print(f"  GET /health - Health check")
    print()
    
    server = HTTPServer(('0.0.0.0', PORT), TorrentioProxy)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down...")
        server.shutdown()
