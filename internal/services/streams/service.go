package streams

import (
	"context"
	"fmt"
	"log/slog"
	"sort"
	"strings"

	"github.com/Zerr0-C00L/StreamArr/internal/models"
	"github.com/Zerr0-C00L/StreamArr/internal/services/debrid"
)

// StreamService manages stream selection and caching
type StreamService struct {
	debrid debrid.DebridService
	logger *slog.Logger
}

// NewStreamService creates a new stream service
func NewStreamService(debridService debrid.DebridService, logger *slog.Logger) *StreamService {
	if logger == nil {
		logger = slog.Default()
	}
	
	return &StreamService{
		debrid: debridService,
		logger: logger,
	}
}

// FilterToDebridCached filters streams to only those cached on debrid service
// This is the core function that ensures INSTANT PLAYBACK - only cached streams pass through
func (s *StreamService) FilterToDebridCached(ctx context.Context, streams []models.TorrentStream) ([]models.TorrentStream, error) {
	if len(streams) == 0 {
		return []models.TorrentStream{}, nil
	}
	
	// Extract all hashes for batch checking
	hashes := make([]string, len(streams))
	hashToStream := make(map[string]*models.TorrentStream)
	
	for i := range streams {
		hashes[i] = streams[i].Hash
		hashToStream[streams[i].Hash] = &streams[i]
	}
	
	// Batch check debrid cache status (single API call)
	s.logger.Info("Checking debrid cache", "total_streams", len(streams))
	cached, err := s.debrid.CheckCache(ctx, hashes)
	if err != nil {
		s.logger.Error("Debrid cache check failed", "error", err)
		return nil, fmt.Errorf("debrid cache check failed: %w", err)
	}
	
	// Filter to cached only
	var cachedStreams []models.TorrentStream
	for hash, isCached := range cached {
		if isCached {
			if stream, exists := hashToStream[hash]; exists {
				cachedStreams = append(cachedStreams, *stream)
			}
		}
	}
	
	s.logger.Info("Filtered to debrid-cached streams", 
		"total", len(streams), 
		"cached", len(cachedStreams),
		"filtered_out", len(streams)-len(cachedStreams))
	
	return cachedStreams, nil
}

// ScoreAndRankStreams calculates quality scores and sorts streams by score (highest first)
func (s *StreamService) ScoreAndRankStreams(streams []models.TorrentStream) []models.TorrentStream {
	// Calculate quality score for each stream
	for i := range streams {
		quality := StreamQuality{
			Resolution:  streams[i].Resolution,
			HDRType:     streams[i].HDRType,
			AudioFormat: streams[i].AudioFormat,
			Source:      streams[i].Source,
			Codec:       streams[i].Codec,
			SizeGB:      streams[i].SizeGB,
			Seeders:     streams[i].Seeders,
		}
		
		scoreBreakdown := CalculateScore(quality)
		streams[i].QualityScore = scoreBreakdown.TotalScore
		
		s.logger.Debug("Stream scored",
			"title", streams[i].Title,
			"score", scoreBreakdown.TotalScore,
			"resolution", scoreBreakdown.ResolutionScore,
			"hdr", scoreBreakdown.HDRScore,
			"audio", scoreBreakdown.AudioScore,
			"source", scoreBreakdown.SourceScore,
			"seeders", scoreBreakdown.SeedersScore,
			"penalty", scoreBreakdown.SizePenalty)
	}
	
	// Sort by quality score (highest first)
	sort.Slice(streams, func(i, j int) bool {
		return streams[i].QualityScore > streams[j].QualityScore
	})
	
	return streams
}

// FindBestCachedStream combines filtering and ranking to find the best debrid-cached stream
// Returns nil if no cached streams available
func (s *StreamService) FindBestCachedStream(ctx context.Context, streams []models.TorrentStream) (*models.TorrentStream, error) {
	// Filter to debrid-cached only
	cachedStreams, err := s.FilterToDebridCached(ctx, streams)
	if err != nil {
		return nil, err
	}
	
	if len(cachedStreams) == 0 {
		s.logger.Warn("No debrid-cached streams available")
		return nil, nil
	}
	
	// Score and rank
	rankedStreams := s.ScoreAndRankStreams(cachedStreams)
	
	// Return best (highest score)
	best := &rankedStreams[0]
	
	s.logger.Info("Selected best debrid-cached stream",
		"title", best.Title,
		"score", best.QualityScore,
		"resolution", best.Resolution,
		"hdr", best.HDRType,
		"audio", best.AudioFormat,
		"source", best.Source,
		"size_gb", best.SizeGB,
		"seeders", best.Seeders)
	
	return best, nil
}

// ShouldExcludeByQualityType checks if a stream should be excluded based on quality type settings
// Supported exclusion types: remux, hdr, dv, dvhdr, 3d, scr, cam, unknown
func (s *StreamService) ShouldExcludeByQualityType(streamTitle, resolution, hdrType string, excludedQualities string) bool {
	if excludedQualities == "" {
		return false
	}
	
	titleUpper := strings.ToUpper(streamTitle)
	hdrUpper := strings.ToUpper(hdrType)
	exclusions := strings.Split(strings.ToLower(excludedQualities), ",")
	
	for _, excl := range exclusions {
		excl = strings.TrimSpace(excl)
		
		switch excl {
		case "remux":
			// Check for REMUX in title
			if strings.Contains(titleUpper, "REMUX") || strings.Contains(titleUpper, "BDREMUX") {
				s.logger.Debug("Stream excluded by quality type", "stream", streamTitle, "reason", "REMUX")
				return true
			}
		case "hdr":
			// HDR, HDR10, HDR10+
			if hdrUpper == "HDR" || hdrUpper == "HDR10" || hdrUpper == "HDR10+" ||
				strings.Contains(titleUpper, "HDR10+") || strings.Contains(titleUpper, "HDR10") ||
				(strings.Contains(titleUpper, "HDR") && !strings.Contains(titleUpper, "DOLBY")) {
				s.logger.Debug("Stream excluded by quality type", "stream", streamTitle, "reason", "HDR")
				return true
			}
		case "dv":
			// Dolby Vision only (not DV+HDR)
			if (hdrUpper == "DV" || hdrUpper == "DOLBY VISION" || strings.Contains(titleUpper, "DOLBY.VISION") ||
				strings.Contains(titleUpper, "DV") || strings.Contains(titleUpper, "DOVI")) &&
				!strings.Contains(titleUpper, "DV.HDR") && !strings.Contains(titleUpper, "DVHDR") {
				s.logger.Debug("Stream excluded by quality type", "stream", streamTitle, "reason", "Dolby Vision")
				return true
			}
		case "dvhdr":
			// Dolby Vision + HDR hybrid
			if strings.Contains(titleUpper, "DV.HDR") || strings.Contains(titleUpper, "DVHDR") ||
				strings.Contains(titleUpper, "DV HDR") || hdrUpper == "DV+HDR" {
				s.logger.Debug("Stream excluded by quality type", "stream", streamTitle, "reason", "DV+HDR")
				return true
			}
		case "3d":
			// 3D content
			if strings.Contains(titleUpper, "3D") || strings.Contains(titleUpper, "SBS") ||
				strings.Contains(titleUpper, "HSBS") || strings.Contains(titleUpper, "OU") {
				s.logger.Debug("Stream excluded by quality type", "stream", streamTitle, "reason", "3D")
				return true
			}
		case "scr":
			// Screeners
			if strings.Contains(titleUpper, "SCR") || strings.Contains(titleUpper, "SCREENER") ||
				strings.Contains(titleUpper, "DVDSCR") || strings.Contains(titleUpper, "BDSCR") {
				s.logger.Debug("Stream excluded by quality type", "stream", streamTitle, "reason", "Screener")
				return true
			}
		case "cam":
			// CAM and Telecine
			if strings.Contains(titleUpper, "CAM") || strings.Contains(titleUpper, "HDCAM") ||
				strings.Contains(titleUpper, "TS") || strings.Contains(titleUpper, "HDTS") ||
				strings.Contains(titleUpper, "TELESYNC") || strings.Contains(titleUpper, "TC") ||
				strings.Contains(titleUpper, "TELECINE") {
				// Avoid false positives from words containing "TS" or "TC"
				// Check if TS/TC appears as a standalone tag
				if (strings.Contains(titleUpper, ".TS.") || strings.Contains(titleUpper, ".TC.") ||
					strings.Contains(titleUpper, " TS ") || strings.Contains(titleUpper, " TC ") ||
					strings.HasSuffix(titleUpper, ".TS") || strings.HasSuffix(titleUpper, ".TC") ||
					strings.Contains(titleUpper, "CAM") || strings.Contains(titleUpper, "HDCAM") ||
					strings.Contains(titleUpper, "HDTS") || strings.Contains(titleUpper, "TELESYNC") ||
					strings.Contains(titleUpper, "TELECINE")) {
					s.logger.Debug("Stream excluded by quality type", "stream", streamTitle, "reason", "CAM/TS")
					return true
				}
			}
		case "unknown":
			// Unknown quality
			if resolution == "" || resolution == "Unknown" || strings.Contains(titleUpper, "UNKNOWN") {
				s.logger.Debug("Stream excluded by quality type", "stream", streamTitle, "reason", "Unknown quality")
				return true
			}
		}
	}
	
	return false
}

// ShouldFilterStream checks if a stream should be filtered out based on user settings
func (s *StreamService) ShouldFilterStream(stream models.TorrentStream, excludedGroups, excludedQualities, excludedLanguages string) bool {
	if excludedGroups == "" && excludedQualities == "" && excludedLanguages == "" {
		return false // No filters configured
	}
	
	torrentNameUpper := strings.ToUpper(stream.TorrentName)
	
	// Check excluded release groups
	if excludedGroups != "" {
		groups := strings.Split(excludedGroups, ",")
		for _, group := range groups {
			group = strings.TrimSpace(strings.ToUpper(group))
			if group != "" && strings.Contains(torrentNameUpper, group) {
				s.logger.Debug("Stream filtered by release group",
					"stream", stream.TorrentName,
					"blocked_group", group)
				return true
			}
		}
	}
	
	// Check excluded qualities (CAM, HDTS, etc.)
	if excludedQualities != "" {
		qualities := strings.Split(excludedQualities, ",")
		for _, quality := range qualities {
			quality = strings.TrimSpace(strings.ToUpper(quality))
			if quality != "" && strings.Contains(torrentNameUpper, quality) {
				s.logger.Debug("Stream filtered by quality",
					"stream", stream.TorrentName,
					"blocked_quality", quality)
				return true
			}
		}
	}
	
	// Check excluded language tags
	if excludedLanguages != "" {
		languages := strings.Split(excludedLanguages, ",")
		for _, lang := range languages {
			lang = strings.TrimSpace(strings.ToUpper(lang))
			if lang != "" && strings.Contains(torrentNameUpper, lang) {
				s.logger.Debug("Stream filtered by language",
					"stream", stream.TorrentName,
					"blocked_language", lang)
				return true
			}
		}
	}
	
	return false
}

// ParseStreamFromTorrentName creates a Stream from torrent name and metadata
func (s *StreamService) ParseStreamFromTorrentName(torrentName, hash, indexer string, seeders int) models.TorrentStream {
	quality := ParseQualityFromTorrentName(torrentName)
	sizeGB := ExtractSizeFromTorrentName(torrentName)
	
	// Set seeders in quality struct for scoring
	quality.Seeders = seeders
	quality.SizeGB = sizeGB
	
	stream := models.TorrentStream{
		Hash:        hash,
		Title:       torrentName,
		TorrentName: torrentName,
		Resolution:  quality.Resolution,
		HDRType:     quality.HDRType,
		AudioFormat: quality.AudioFormat,
		Source:      quality.Source,
		Codec:       quality.Codec,
		SizeGB:      sizeGB,
		Seeders:     seeders,
		Indexer:     indexer,
	}
	
	return stream
}

// GetTopNStreams returns the top N debrid-cached streams by quality score
func (s *StreamService) GetTopNStreams(ctx context.Context, streams []models.TorrentStream, n int) ([]models.TorrentStream, error) {
	// Filter to debrid-cached only
	cachedStreams, err := s.FilterToDebridCached(ctx, streams)
	if err != nil {
		return nil, err
	}
	
	if len(cachedStreams) == 0 {
		return []models.TorrentStream{}, nil
	}
	
	// Score and rank
	rankedStreams := s.ScoreAndRankStreams(cachedStreams)
	
	// Return top N
	if n > len(rankedStreams) {
		n = len(rankedStreams)
	}
	
	return rankedStreams[:n], nil
}

// FilterByMinimumQuality filters streams to minimum quality requirements
func (s *StreamService) FilterByMinimumQuality(streams []models.TorrentStream, minResolution string, minScore int) []models.TorrentStream {
	var filtered []models.TorrentStream
	
	resolutionPriority := map[string]int{
		"2160p": 4,
		"4K":    4,
		"UHD":   4,
		"1080p": 3,
		"FHD":   3,
		"720p":  2,
		"HD":    2,
		"576p":  1,
		"480p":  1,
		"SD":    0,
	}
	
	minResPriority, exists := resolutionPriority[minResolution]
	if !exists {
		minResPriority = 0
	}
	
	for _, stream := range streams {
		streamResPriority := resolutionPriority[stream.Resolution]
		
		// Must meet both resolution and score requirements
		if streamResPriority >= minResPriority && stream.QualityScore >= minScore {
			filtered = append(filtered, stream)
		}
	}
	
	s.logger.Info("Filtered by minimum quality",
		"original", len(streams),
		"filtered", len(filtered),
		"min_resolution", minResolution,
		"min_score", minScore)
	
	return filtered
}

// GroupByResolution groups streams by resolution for quality variant selection
func (s *StreamService) GroupByResolution(streams []models.TorrentStream) map[string][]models.TorrentStream {
	groups := make(map[string][]models.TorrentStream)
	
	for _, stream := range streams {
		resolution := stream.Resolution
		groups[resolution] = append(groups[resolution], stream)
	}
	
	// Sort each group by quality score
	for resolution := range groups {
		sort.Slice(groups[resolution], func(i, j int) bool {
			return groups[resolution][i].QualityScore > groups[resolution][j].QualityScore
		})
	}
	
	return groups
}

// GetBestPerResolution returns the best stream for each resolution
// Useful for offering quality variants (4K, 1080p, 720p options)
func (s *StreamService) GetBestPerResolution(ctx context.Context, streams []models.TorrentStream) (map[string]*models.TorrentStream, error) {
	// Filter to debrid-cached only
	cachedStreams, err := s.FilterToDebridCached(ctx, streams)
	if err != nil {
		return nil, err
	}
	
	if len(cachedStreams) == 0 {
		return map[string]*models.TorrentStream{}, nil
	}
	
	// Score all streams
	scoredStreams := s.ScoreAndRankStreams(cachedStreams)
	
	// Group by resolution
	groups := s.GroupByResolution(scoredStreams)
	
	// Get best from each group
	bestPerResolution := make(map[string]*models.TorrentStream)
	for resolution, resStreams := range groups {
		if len(resStreams) > 0 {
			best := resStreams[0]
			bestPerResolution[resolution] = &best
		}
	}
	
	s.logger.Info("Selected best streams per resolution",
		"resolutions", len(bestPerResolution))
	
	return bestPerResolution, nil
}

// ShouldUpgrade determines if a new stream is significantly better than current
func (s *StreamService) ShouldUpgrade(current, new models.TorrentStream, minImprovement int) bool {
	improvement := new.QualityScore - current.QualityScore
	
	if improvement < minImprovement {
		return false
	}
	
	// Never downgrade from 4K
	if (current.Resolution == "2160p" || current.Resolution == "4K") && 
	   (new.Resolution != "2160p" && new.Resolution != "4K") {
		return false
	}
	
	// Never downgrade from REMUX
	if current.Source == "REMUX" && new.Source != "REMUX" {
		return false
	}
	
	// Never lose Dolby Vision
	if current.HDRType == "DV" && new.HDRType != "DV" {
		return false
	}
	
	s.logger.Info("Upgrade evaluation",
		"should_upgrade", true,
		"improvement", improvement,
		"current_score", current.QualityScore,
		"new_score", new.QualityScore)
	
	return true
}
