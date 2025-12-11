package providers

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"
)

type MediaFusionProvider struct {
	BaseURL          string
	RealDebridAPIKey string
	Client           *http.Client
	Cache            map[string]*MediaFusionCachedResponse
}

type MediaFusionConfig struct {
	StreamingProvider struct {
		Token   string `json:"token"`
		Service string `json:"service"`
	} `json:"streaming_provider"`
	SelectedCatalogs []string `json:"selected_catalogs"`
	EnableCatalogs   bool     `json:"enable_catalogs"`
}

type MediaFusionStream struct {
	Name     string `json:"name"`
	InfoHash string `json:"infoHash"`
	FileIdx  int    `json:"fileIdx,omitempty"`
	URL      string `json:"url"`
}

type MediaFusionResponse struct {
	Streams []MediaFusionStream `json:"streams"`
}

type MediaFusionCachedResponse struct {
	Data      *MediaFusionResponse
	Timestamp time.Time
}

func NewMediaFusionProvider(rdAPIKey string) *MediaFusionProvider {
	return &MediaFusionProvider{
		BaseURL:          "https://mediafusion.elfhosted.com",
		RealDebridAPIKey: rdAPIKey,
		Client: &http.Client{
			Timeout: 15 * time.Second,
		},
		Cache: make(map[string]*MediaFusionCachedResponse),
	}
}

func (m *MediaFusionProvider) GetMovieStreams(imdbID string) ([]TorrentioStream, error) {
	config := MediaFusionConfig{
		SelectedCatalogs: []string{"torrentio_streams"},
		EnableCatalogs:   false,
	}
	config.StreamingProvider.Token = m.RealDebridAPIKey
	config.StreamingProvider.Service = "realdebrid"
	
	configJSON, err := json.Marshal(config)
	if err != nil {
		return nil, fmt.Errorf("marshal config: %w", err)
	}
	
	configBase64 := base64.StdEncoding.EncodeToString(configJSON)
	url := fmt.Sprintf("%s/%s/stream/movie/%s.json", m.BaseURL, configBase64, imdbID)
	
	return m.fetchStreams(url, fmt.Sprintf("movie_%s", imdbID))
}

func (m *MediaFusionProvider) GetSeriesStreams(imdbID string, season, episode int) ([]TorrentioStream, error) {
	config := MediaFusionConfig{
		SelectedCatalogs: []string{"torrentio_streams"},
		EnableCatalogs:   false,
	}
	config.StreamingProvider.Token = m.RealDebridAPIKey
	config.StreamingProvider.Service = "realdebrid"
	
	configJSON, err := json.Marshal(config)
	if err != nil {
		return nil, fmt.Errorf("marshal config: %w", err)
	}
	
	configBase64 := base64.StdEncoding.EncodeToString(configJSON)
	url := fmt.Sprintf("%s/%s/stream/series/%s:%d:%d.json", m.BaseURL, configBase64, imdbID, season, episode)
	
	return m.fetchStreams(url, fmt.Sprintf("series_%s_%d_%d", imdbID, season, episode))
}

func (m *MediaFusionProvider) fetchStreams(url, cacheKey string) ([]TorrentioStream, error) {
	// Check cache
	if cached, ok := m.Cache[cacheKey]; ok {
		if time.Since(cached.Timestamp) < 30*time.Minute {
			return m.convertToTorrentioStreams(cached.Data.Streams), nil
		}
	}
	
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return nil, fmt.Errorf("create request: %w", err)
	}
	
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36")
	
	resp, err := m.Client.Do(req)
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
	
	var response MediaFusionResponse
	if err := json.Unmarshal(body, &response); err != nil {
		return nil, fmt.Errorf("decode response: %w", err)
	}
	
	// Cache the response
	m.Cache[cacheKey] = &MediaFusionCachedResponse{
		Data:      &response,
		Timestamp: time.Now(),
	}
	
	return m.convertToTorrentioStreams(response.Streams), nil
}

func (m *MediaFusionProvider) convertToTorrentioStreams(mfStreams []MediaFusionStream) []TorrentioStream {
	streams := make([]TorrentioStream, len(mfStreams))
	
	for i, mf := range mfStreams {
		stream := TorrentioStream{
			Name:     mf.Name,
			Title:    mf.Name,
			InfoHash: mf.InfoHash,
			FileIdx:  mf.FileIdx,
			URL:      mf.URL,
			Source:   "mediafusion",
		}
		
		// Parse stream info
		parseStreamInfoForComet(&stream) // Reuse comet parser
		streams[i] = stream
	}
	
	return streams
}
