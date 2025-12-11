package services

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/Zerr0-C00L/StreamArr/internal/models"
)

const (
	torrentioBaseURL = "https://torrentio.strem.fun"
)

type TorrentioClient struct {
	httpClient *http.Client
}

type torrentioStream struct {
	Title      string `json:"title"`
	InfoHash   string `json:"infoHash"`
	FileIdx    int    `json:"fileIdx"`
	BehaviorHints struct {
		BingeGroup string `json:"bingeGroup"`
	} `json:"behaviorHints"`
}

type torrentioResponse struct {
	Streams []torrentioStream `json:"streams"`
}

func NewTorrentioClient() *TorrentioClient {
	return &TorrentioClient{
		httpClient: &http.Client{
			Timeout: 15 * time.Second,
		},
	}
}

// GetMovieStreams retrieves available streams for a movie
func (c *TorrentioClient) GetMovieStreams(ctx context.Context, imdbID string) ([]*models.Stream, error) {
	endpoint := fmt.Sprintf("%s/stream/movie/%s.json", torrentioBaseURL, imdbID)
	
	data, err := c.makeRequest(ctx, endpoint)
	if err != nil {
		return nil, err
	}

	var response torrentioResponse
	if err := json.Unmarshal(data, &response); err != nil {
		return nil, fmt.Errorf("failed to unmarshal torrentio response: %w", err)
	}

	streams := make([]*models.Stream, 0, len(response.Streams))
	for _, ts := range response.Streams {
		stream := c.parseStream(&ts, "movie", 0)
		if stream != nil {
			streams = append(streams, stream)
		}
	}

	return streams, nil
}

// GetSeriesStreams retrieves available streams for a series episode
func (c *TorrentioClient) GetSeriesStreams(ctx context.Context, imdbID string, season, episode int) ([]*models.Stream, error) {
	endpoint := fmt.Sprintf("%s/stream/series/%s:%d:%d.json", torrentioBaseURL, imdbID, season, episode)
	
	data, err := c.makeRequest(ctx, endpoint)
	if err != nil {
		return nil, err
	}

	var response torrentioResponse
	if err := json.Unmarshal(data, &response); err != nil {
		return nil, fmt.Errorf("failed to unmarshal torrentio response: %w", err)
	}

	streams := make([]*models.Stream, 0, len(response.Streams))
	for _, ts := range response.Streams {
		stream := c.parseStream(&ts, "series", 0)
		if stream != nil {
			streams = append(streams, stream)
		}
	}

	return streams, nil
}

// parseStream converts a Torrentio stream to internal model
func (c *TorrentioClient) parseStream(ts *torrentioStream, contentType string, contentID int64) *models.Stream {
	// Extract info from title
	quality := c.extractQuality(ts.Title)
	codec := c.extractCodec(ts.Title)
	source := c.extractSource(ts.Title)
	seeders := c.extractSeeders(ts.Title)
	size := c.extractSize(ts.Title)

	// Extract tracker from title (e.g., "YTS", "RARBG", etc.)
	tracker := c.extractTracker(ts.Title)

	return &models.Stream{
		ContentType: contentType,
		ContentID:   contentID,
		InfoHash:    ts.InfoHash,
		Title:       ts.Title,
		SizeBytes:   size,
		Quality:     quality,
		Codec:       codec,
		Source:      source,
		Seeders:     seeders,
		Tracker:     tracker,
		Metadata: models.Metadata{
			"file_idx": ts.FileIdx,
			"raw_title": ts.Title,
		},
	}
}

// extractQuality extracts quality from stream title
func (c *TorrentioClient) extractQuality(title string) string {
	qualityRegex := regexp.MustCompile(`(?i)(2160p|1080p|720p|480p)`)
	matches := qualityRegex.FindStringSubmatch(title)
	if len(matches) > 0 {
		return strings.ToLower(matches[1])
	}
	return "unknown"
}

// extractCodec extracts codec from stream title
func (c *TorrentioClient) extractCodec(title string) string {
	codecRegex := regexp.MustCompile(`(?i)(x265|x264|h265|h264|hevc|avc)`)
	matches := codecRegex.FindStringSubmatch(title)
	if len(matches) > 0 {
		codec := strings.ToLower(matches[1])
		// Normalize codec names
		if codec == "x265" || codec == "h265" {
			return "hevc"
		}
		if codec == "x264" || codec == "h264" {
			return "avc"
		}
		return codec
	}
	return "unknown"
}

// extractSource extracts source from stream title
func (c *TorrentioClient) extractSource(title string) string {
	sourceRegex := regexp.MustCompile(`(?i)(BluRay|BRRip|WEBRip|WEB-DL|HDRip|HDTV|DVDRip)`)
	matches := sourceRegex.FindStringSubmatch(title)
	if len(matches) > 0 {
		return matches[1]
	}
	return "unknown"
}

// extractSeeders extracts seeder count from stream title
func (c *TorrentioClient) extractSeeders(title string) int {
	// Torrentio format: "👤 123" for seeders
	seederRegex := regexp.MustCompile(`👤\s*(\d+)`)
	matches := seederRegex.FindStringSubmatch(title)
	if len(matches) > 1 {
		if count, err := strconv.Atoi(matches[1]); err == nil {
			return count
		}
	}
	return 0
}

// extractSize extracts file size from stream title
func (c *TorrentioClient) extractSize(title string) int64 {
	// Torrentio format: "💾 12.3 GB" for size
	sizeRegex := regexp.MustCompile(`💾\s*([\d.]+)\s*(GB|MB)`)
	matches := sizeRegex.FindStringSubmatch(title)
	if len(matches) > 2 {
		if size, err := strconv.ParseFloat(matches[1], 64); err == nil {
			unit := strings.ToUpper(matches[2])
			if unit == "GB" {
				return int64(size * 1024 * 1024 * 1024)
			} else if unit == "MB" {
				return int64(size * 1024 * 1024)
			}
		}
	}
	return 0
}

// extractTracker extracts tracker name from stream title
func (c *TorrentioClient) extractTracker(title string) string {
	// Common tracker patterns
	trackerRegex := regexp.MustCompile(`(?i)\[(YTS|RARBG|EZTV|1337x|TGx|ETTV)\]`)
	matches := trackerRegex.FindStringSubmatch(title)
	if len(matches) > 1 {
		return matches[1]
	}

	// Try to find tracker in different format
	if strings.Contains(strings.ToUpper(title), "YTS") {
		return "YTS"
	}
	if strings.Contains(strings.ToUpper(title), "RARBG") {
		return "RARBG"
	}
	if strings.Contains(strings.ToUpper(title), "EZTV") {
		return "EZTV"
	}

	return "unknown"
}

// makeRequest performs an HTTP GET request to Torrentio
func (c *TorrentioClient) makeRequest(ctx context.Context, endpoint string) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, "GET", endpoint, nil)
	if err != nil {
		return nil, fmt.Errorf("failed to create request: %w", err)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("failed to make request: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("Torrentio API returned status %d", resp.StatusCode)
	}

	data, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("failed to read response: %w", err)
	}

	return data, nil
}
