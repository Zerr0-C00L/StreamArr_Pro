package providers

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

type TorrentioProvider struct {
	BaseURL           string
	RealDebridAPIKey  string
	Providers         string
	Client            *http.Client
	Cache             map[string]*CachedResponse
}

type TorrentioStream struct {
	Name        string `json:"name"`
	Title       string `json:"title"`
	InfoHash    string `json:"infoHash"`
	FileIdx     int    `json:"fileIdx,omitempty"`
	URL         string `json:"url"`
	Quality     string `json:"quality"`
	Size        int64  `json:"size"`
	Seeders     int    `json:"seeders,omitempty"`
	Cached      bool   `json:"cached"`
	Source      string `json:"source"`
}

type TorrentioResponse struct {
	Streams []TorrentioStream `json:"streams"`
}

type CachedResponse struct {
	Data      *TorrentioResponse
	Timestamp time.Time
}

func NewTorrentioProvider(rdAPIKey string, providers string) *TorrentioProvider {
	if providers == "" {
		providers = "yts,eztv,rarbg,1337x,thepiratebay,kickasstorrents,torrentgalaxy,magnetdl"
	}
	
	return &TorrentioProvider{
		BaseURL:          "https://torrentio.strem.fun",
		RealDebridAPIKey: rdAPIKey,
		Providers:        providers,
		Client: &http.Client{
			Timeout: 15 * time.Second,
		},
		Cache: make(map[string]*CachedResponse),
	}
}

func (t *TorrentioProvider) GetMovieStreams(imdbID string) ([]TorrentioStream, error) {
	config := fmt.Sprintf("providers=%s|sort=qualitysize|debridoptions=nodownloadlinks,nocatalog|realdebrid=%s", 
		t.Providers, t.RealDebridAPIKey)
	url := fmt.Sprintf("%s/%s/stream/movie/%s.json", t.BaseURL, config, imdbID)
	
	return t.fetchStreams(url, fmt.Sprintf("movie_%s", imdbID))
}

func (t *TorrentioProvider) GetSeriesStreams(imdbID string, season, episode int) ([]TorrentioStream, error) {
	config := fmt.Sprintf("providers=%s|sort=qualitysize|debridoptions=nodownloadlinks,nocatalog|realdebrid=%s", 
		t.Providers, t.RealDebridAPIKey)
	url := fmt.Sprintf("%s/%s/stream/series/%s:%d:%d.json", t.BaseURL, config, imdbID, season, episode)
	
	return t.fetchStreams(url, fmt.Sprintf("series_%s_%d_%d", imdbID, season, episode))
}

func (t *TorrentioProvider) fetchStreams(url, cacheKey string) ([]TorrentioStream, error) {
	// Check cache (30 min TTL)
	if cached, ok := t.Cache[cacheKey]; ok {
		if time.Since(cached.Timestamp) < 30*time.Minute {
			return cached.Data.Streams, nil
		}
	}
	
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return nil, fmt.Errorf("create request: %w", err)
	}
	
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36")
	
	resp, err := t.Client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("fetch streams: %w", err)
	}
	defer resp.Body.Close()
	
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("unexpected status code: %d", resp.StatusCode)
	}
	
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("read response: %w", err)
	}
	
	// Check for Cloudflare block
	if strings.Contains(string(body), "Cloudflare") || strings.Contains(string(body), "Attention Required") {
		return nil, fmt.Errorf("cloudflare block detected")
	}
	
	var response TorrentioResponse
	if err := json.Unmarshal(body, &response); err != nil {
		return nil, fmt.Errorf("decode response: %w", err)
	}
	
	// Parse and enrich stream data
	for i := range response.Streams {
		t.parseStreamInfo(&response.Streams[i])
		response.Streams[i].Source = "torrentio"
	}
	
	// Cache the response
	t.Cache[cacheKey] = &CachedResponse{
		Data:      &response,
		Timestamp: time.Now(),
	}
	
	return response.Streams, nil
}

func (t *TorrentioProvider) parseStreamInfo(stream *TorrentioStream) {
	name := stream.Name
	if name == "" {
		name = stream.Title
	}
	
	// Extract quality
	qualityPatterns := []string{"2160p", "4K", "1080p", "720p", "480p"}
	for _, q := range qualityPatterns {
		if strings.Contains(name, q) {
			stream.Quality = strings.ToLower(strings.TrimSuffix(q, "p"))
			if q == "4K" {
				stream.Quality = "2160"
			}
			break
		}
	}
	
	// Extract file size from name
	if strings.Contains(name, "GB") || strings.Contains(name, "MB") {
		parts := strings.Split(name, " ")
		for i, part := range parts {
			if strings.Contains(part, "GB") {
				if i > 0 {
					var size float64
					fmt.Sscanf(parts[i-1], "%f", &size)
					stream.Size = int64(size * 1024 * 1024 * 1024)
				}
			} else if strings.Contains(part, "MB") {
				if i > 0 {
					var size float64
					fmt.Sscanf(parts[i-1], "%f", &size)
					stream.Size = int64(size * 1024 * 1024)
				}
			}
		}
	}
	
	// Check if cached (RD+ or similar indicators)
	stream.Cached = strings.Contains(name, "[RD+]") || 
	                strings.Contains(name, "[RD⚡]") ||
	                strings.Contains(name, "⚡")
}

func (t *TorrentioProvider) GetBestStream(imdbID string, season, episode *int, maxQuality int) (*TorrentioStream, error) {
	var streams []TorrentioStream
	var err error
	
	if season != nil && episode != nil {
		streams, err = t.GetSeriesStreams(imdbID, *season, *episode)
	} else {
		streams, err = t.GetMovieStreams(imdbID)
	}
	
	if err != nil {
		return nil, err
	}
	
	if len(streams) == 0 {
		return nil, fmt.Errorf("no streams found")
	}
	
	// Filter by max quality and cached status
	filteredStreams := make([]TorrentioStream, 0)
	for _, s := range streams {
		if s.Cached {
			quality := parseQualityInt(s.Quality)
			if quality <= maxQuality {
				filteredStreams = append(filteredStreams, s)
			}
		}
	}
	
	if len(filteredStreams) == 0 {
		// No cached streams, return best uncached
		return &streams[0], nil
	}
	
	// Sort by quality (descending) and seeders (descending)
	best := filteredStreams[0]
	for _, s := range filteredStreams[1:] {
		sQuality := parseQualityInt(s.Quality)
		bestQuality := parseQualityInt(best.Quality)
		
		if sQuality > bestQuality || (sQuality == bestQuality && s.Seeders > best.Seeders) {
			best = s
		}
	}
	
	return &best, nil
}

func parseQualityInt(quality string) int {
	quality = strings.ToLower(quality)
	switch {
	case strings.Contains(quality, "2160") || strings.Contains(quality, "4k"):
		return 2160
	case strings.Contains(quality, "1080"):
		return 1080
	case strings.Contains(quality, "720"):
		return 720
	case strings.Contains(quality, "480"):
		return 480
	default:
		return 0
	}
}
