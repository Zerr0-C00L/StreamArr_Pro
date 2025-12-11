package providers

import (
	"fmt"
	"log"

	"github.com/Zerr0-C00L/StreamArr/internal/services"
)

type StreamProvider interface {
	GetMovieStreams(imdbID string) ([]TorrentioStream, error)
	GetSeriesStreams(imdbID string, season, episode int) ([]TorrentioStream, error)
}

type MultiProvider struct {
	Providers     []StreamProvider
	ProviderNames []string
}

func NewMultiProvider(rdAPIKey string, providerNames []string, torrentioProviders string, cometIndexers []string, tmdbClient *services.TMDBClient) *MultiProvider {
	mp := &MultiProvider{
		Providers:     make([]StreamProvider, 0),
		ProviderNames: providerNames,
	}
	
	for _, name := range providerNames {
		switch name {
		case "comet":
			mp.Providers = append(mp.Providers, NewCometProvider(rdAPIKey, cometIndexers))
		case "mediafusion":
			mp.Providers = append(mp.Providers, NewMediaFusionProvider(rdAPIKey))
		case "torrentio":
			mp.Providers = append(mp.Providers, NewTorrentioProvider(rdAPIKey, torrentioProviders))
		case "vidsrc":
			if tmdbClient != nil {
				mp.Providers = append(mp.Providers, NewVidSrcAdapter(tmdbClient))
			} else {
				log.Printf("Warning: VidSrc requires TMDB client, skipping")
			}
		case "autoembed":
			if tmdbClient != nil {
				mp.Providers = append(mp.Providers, NewAutoEmbedAdapter(tmdbClient))
			} else {
				log.Printf("Warning: AutoEmbed requires TMDB client, skipping")
			}
		default:
			log.Printf("Warning: Unknown provider '%s', skipping", name)
		}
	}
	
	if len(mp.Providers) == 0 {
		// Default to Torrentio if no providers configured
		mp.Providers = append(mp.Providers, NewTorrentioProvider(rdAPIKey, torrentioProviders))
		mp.ProviderNames = []string{"torrentio"}
	}
	
	return mp
}

func (mp *MultiProvider) GetMovieStreams(imdbID string) ([]TorrentioStream, error) {
	var lastErr error
	var allStreams []TorrentioStream
	
	for i, provider := range mp.Providers {
		providerName := mp.ProviderNames[i]
		
		streams, err := provider.GetMovieStreams(imdbID)
		if err != nil {
			log.Printf("Provider %s failed for movie %s: %v", providerName, imdbID, err)
			lastErr = err
			continue
		}
		
		if len(streams) > 0 {
			log.Printf("Provider %s returned %d streams for movie %s", providerName, len(streams), imdbID)
			allStreams = append(allStreams, streams...)
		}
	}
	
	if len(allStreams) == 0 && lastErr != nil {
		return nil, fmt.Errorf("all providers failed, last error: %w", lastErr)
	}
	
	return allStreams, nil
}

func (mp *MultiProvider) GetSeriesStreams(imdbID string, season, episode int) ([]TorrentioStream, error) {
	var lastErr error
	var allStreams []TorrentioStream
	
	for i, provider := range mp.Providers {
		providerName := mp.ProviderNames[i]
		
		streams, err := provider.GetSeriesStreams(imdbID, season, episode)
		if err != nil {
			log.Printf("Provider %s failed for series %s S%02dE%02d: %v", providerName, imdbID, season, episode, err)
			lastErr = err
			continue
		}
		
		if len(streams) > 0 {
			log.Printf("Provider %s returned %d streams for series %s S%02dE%02d", providerName, len(streams), imdbID, season, episode)
			allStreams = append(allStreams, streams...)
		}
	}
	
	if len(allStreams) == 0 && lastErr != nil {
		return nil, fmt.Errorf("all providers failed, last error: %w", lastErr)
	}
	
	return allStreams, nil
}

func (mp *MultiProvider) GetBestStream(imdbID string, season, episode *int, maxQuality int) (*TorrentioStream, error) {
	var streams []TorrentioStream
	var err error
	
	if season != nil && episode != nil {
		streams, err = mp.GetSeriesStreams(imdbID, *season, *episode)
	} else {
		streams, err = mp.GetMovieStreams(imdbID)
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
