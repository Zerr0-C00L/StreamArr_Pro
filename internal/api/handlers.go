package api

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"time"

	"github.com/Zerr0-C00L/StreamArr/internal/database"
	"github.com/Zerr0-C00L/StreamArr/internal/epg"
	"github.com/Zerr0-C00L/StreamArr/internal/livetv"
	"github.com/Zerr0-C00L/StreamArr/internal/models"
	"github.com/Zerr0-C00L/StreamArr/internal/services"
	"github.com/Zerr0-C00L/StreamArr/internal/settings"
)

type Handler struct {
	movieStore      *database.MovieStore
	seriesStore     *database.SeriesStore
	episodeStore    *database.EpisodeStore
	streamStore     *database.StreamStore
	settingsStore   *database.SettingsStore
	userStore       *database.UserStore
	tmdbClient      *services.TMDBClient
	rdClient        *services.RealDebridClient
	torrentio       *services.TorrentioClient
	channelManager  *livetv.ChannelManager
	settingsManager *settings.Manager
	epgManager      *epg.Manager
}

func NewHandler(
	movieStore *database.MovieStore,
	seriesStore *database.SeriesStore,
	episodeStore *database.EpisodeStore,
	streamStore *database.StreamStore,
	settingsStore *database.SettingsStore,
	userStore *database.UserStore,
	tmdbClient *services.TMDBClient,
	rdClient *services.RealDebridClient,
	torrentio *services.TorrentioClient,
) *Handler {
	return &Handler{
		movieStore:    movieStore,
		seriesStore:   seriesStore,
		episodeStore:  episodeStore,
		streamStore:   streamStore,
		settingsStore: settingsStore,
		tmdbClient:    tmdbClient,
		rdClient:      rdClient,
		torrentio:     torrentio,
	}
}

func NewHandlerWithComponents(
	movieStore *database.MovieStore,
	seriesStore *database.SeriesStore,
	episodeStore *database.EpisodeStore,
	streamStore *database.StreamStore,
	settingsStore *database.SettingsStore,
	userStore *database.UserStore,
	tmdbClient *services.TMDBClient,
	rdClient *services.RealDebridClient,
	torrentio *services.TorrentioClient,
	channelManager *livetv.ChannelManager,
	settingsManager *settings.Manager,
	epgManager *epg.Manager,
) *Handler {
	return &Handler{
		movieStore:      movieStore,
		seriesStore:     seriesStore,
		episodeStore:    episodeStore,
		streamStore:     streamStore,
		settingsStore:   settingsStore,
		userStore:       userStore,
		tmdbClient:      tmdbClient,
		rdClient:        rdClient,
		torrentio:       torrentio,
		channelManager:  channelManager,
		settingsManager: settingsManager,
		epgManager:      epgManager,
	}
}

// Response helpers
func respondJSON(w http.ResponseWriter, status int, data interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(data)
}

func respondError(w http.ResponseWriter, status int, message string) {
	respondJSON(w, status, map[string]string{"error": message})
}

// ListMovies handles GET /api/movies
func (h *Handler) ListMovies(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	// Parse query parameters
	offset, _ := strconv.Atoi(r.URL.Query().Get("offset"))
	limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
	if limit == 0 {
		limit = 50
	}

	var monitored *bool
	if m := r.URL.Query().Get("monitored"); m != "" {
		val := m == "true"
		monitored = &val
	}

	movies, err := h.movieStore.List(ctx, offset, limit, monitored)
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}

	respondJSON(w, http.StatusOK, movies)
}

// GetMovie handles GET /api/movies/{id}
func (h *Handler) GetMovie(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	
	id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
	if err != nil {
		respondError(w, http.StatusBadRequest, "invalid movie ID")
		return
	}

	movie, err := h.movieStore.Get(ctx, id)
	if err != nil {
		respondError(w, http.StatusNotFound, "movie not found")
		return
	}

	respondJSON(w, http.StatusOK, movie)
}

// AddMovie handles POST /api/movies
func (h *Handler) AddMovie(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	var req struct {
		TMDBID         int    `json:"tmdb_id"`
		Monitored      bool   `json:"monitored"`
		QualityProfile string `json:"quality_profile"`
	}

	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		respondError(w, http.StatusBadRequest, "invalid request body")
		return
	}

	// Check if movie already exists in library
	existingMovie, err := h.movieStore.GetByTMDBID(ctx, req.TMDBID)
	if err == nil && existingMovie != nil {
		// Movie already exists, return it with 200 OK
		respondJSON(w, http.StatusOK, existingMovie)
		return
	}

	// Fetch movie details from TMDB
	movie, err := h.tmdbClient.GetMovie(ctx, req.TMDBID)
	if err != nil {
		respondError(w, http.StatusInternalServerError, fmt.Sprintf("failed to fetch movie from TMDB: %v", err))
		return
	}

	movie.Monitored = req.Monitored
	movie.QualityProfile = req.QualityProfile

	// Add to database
	if err := h.movieStore.Add(ctx, movie); err != nil {
		respondError(w, http.StatusInternalServerError, "failed to add movie")
		return
	}

	respondJSON(w, http.StatusCreated, movie)
}

// UpdateMovie handles PUT /api/movies/{id}
func (h *Handler) UpdateMovie(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
	if err != nil {
		respondError(w, http.StatusBadRequest, "invalid movie ID")
		return
	}

	movie, err := h.movieStore.Get(ctx, id)
	if err != nil {
		respondError(w, http.StatusNotFound, "movie not found")
		return
	}

	var updates struct {
		Monitored      *bool   `json:"monitored"`
		QualityProfile *string `json:"quality_profile"`
	}

	if err := json.NewDecoder(r.Body).Decode(&updates); err != nil {
		respondError(w, http.StatusBadRequest, "invalid request body")
		return
	}

	if updates.Monitored != nil {
		movie.Monitored = *updates.Monitored
	}
	if updates.QualityProfile != nil {
		movie.QualityProfile = *updates.QualityProfile
	}

	if err := h.movieStore.Update(ctx, movie); err != nil {
		respondError(w, http.StatusInternalServerError, "failed to update movie")
		return
	}

	respondJSON(w, http.StatusOK, movie)
}

// DeleteMovie handles DELETE /api/movies/{id}
func (h *Handler) DeleteMovie(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
	if err != nil {
		respondError(w, http.StatusBadRequest, "invalid movie ID")
		return
	}

	if err := h.movieStore.Delete(ctx, id); err != nil {
		respondError(w, http.StatusInternalServerError, "failed to delete movie")
		return
	}

	respondJSON(w, http.StatusOK, map[string]string{"message": "movie deleted"})
}

// SearchMovies handles GET /api/search/movies
func (h *Handler) SearchMovies(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	query := r.URL.Query().Get("q")
	if query == "" {
		respondError(w, http.StatusBadRequest, "search query required")
		return
	}

	movies, err := h.tmdbClient.SearchMovies(ctx, query, 1)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "failed to search movies")
		return
	}

	respondJSON(w, http.StatusOK, movies)
}

// GetMovieStreams handles GET /api/movies/{id}/streams
func (h *Handler) GetMovieStreams(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
	if err != nil {
		respondError(w, http.StatusBadRequest, "invalid movie ID")
		return
	}

	streams, err := h.streamStore.ListByContent(ctx, "movie", id)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "failed to get streams")
		return
	}

	respondJSON(w, http.StatusOK, streams)
}

// PlayMovie handles GET /api/movies/{id}/play
func (h *Handler) PlayMovie(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
	if err != nil {
		respondError(w, http.StatusBadRequest, "invalid movie ID")
		return
	}

	movie, err := h.movieStore.Get(ctx, id)
	if err != nil {
		respondError(w, http.StatusNotFound, "movie not found")
		return
	}

	// Find best available stream
	stream, err := h.streamStore.FindBestStream(ctx, "movie", id, movie.QualityProfile)
	if err != nil {
		respondError(w, http.StatusNotFound, "no streams available")
		return
	}

	// Get Real-Debrid streaming URL
	streamURL, err := h.rdClient.GetStreamURL(ctx, stream.InfoHash)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "failed to get stream URL")
		return
	}

	respondJSON(w, http.StatusOK, map[string]string{
		"stream_url": streamURL,
		"quality":    stream.Quality,
		"codec":      stream.Codec,
		"source":     stream.Source,
	})
}

// ListSeries handles GET /api/series
func (h *Handler) ListSeries(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	offset, _ := strconv.Atoi(r.URL.Query().Get("offset"))
	limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
	if limit == 0 {
		limit = 50
	}

	var monitored *bool
	if m := r.URL.Query().Get("monitored"); m != "" {
		val := m == "true"
		monitored = &val
	}

	series, err := h.seriesStore.List(ctx, offset, limit, monitored)
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}

	respondJSON(w, http.StatusOK, series)
}

// GetSeries handles GET /api/series/{id}
func (h *Handler) GetSeries(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
	if err != nil {
		respondError(w, http.StatusBadRequest, "invalid series ID")
		return
	}

	series, err := h.seriesStore.Get(ctx, id)
	if err != nil {
		respondError(w, http.StatusNotFound, "series not found")
		return
	}

	respondJSON(w, http.StatusOK, series)
}

// AddSeries handles POST /api/series
func (h *Handler) AddSeries(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	var req struct {
		TMDBID         int64  `json:"tmdb_id"`
		Monitored      bool   `json:"monitored"`
		QualityProfile string `json:"quality_profile"`
	}

	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		respondError(w, http.StatusBadRequest, "invalid request body")
		return
	}

	// Check if series already exists in library
	existingSeries, err := h.seriesStore.GetByTMDBID(ctx, int(req.TMDBID))
	if err == nil && existingSeries != nil {
		// Series already exists, return it with 200 OK
		respondJSON(w, http.StatusOK, existingSeries)
		return
	}

	// Fetch series details from TMDB
	tmdbSeries, err := h.tmdbClient.GetSeries(ctx, int(req.TMDBID))
	if err != nil {
		respondError(w, http.StatusInternalServerError, fmt.Sprintf("failed to fetch series from TMDB: %v", err))
		return
	}

	// Create series model
	series := tmdbSeries
	series.Monitored = req.Monitored
	series.QualityProfile = req.QualityProfile

	// Add to library
	if err := h.seriesStore.Add(ctx, series); err != nil {
		respondError(w, http.StatusInternalServerError, fmt.Sprintf("failed to add series: %v", err))
		return
	}

	respondJSON(w, http.StatusCreated, series)
}

// UpdateSeries handles PUT /api/series/{id}
func (h *Handler) UpdateSeries(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
	if err != nil {
		respondError(w, http.StatusBadRequest, "invalid series ID")
		return
	}

	var updates struct {
		Monitored      *bool   `json:"monitored,omitempty"`
		QualityProfile *string `json:"quality_profile,omitempty"`
	}

	if err := json.NewDecoder(r.Body).Decode(&updates); err != nil {
		respondError(w, http.StatusBadRequest, "invalid request body")
		return
	}

	// Get existing series
	series, err := h.seriesStore.Get(ctx, id)
	if err != nil {
		respondError(w, http.StatusNotFound, "series not found")
		return
	}

	// Apply updates
	if updates.Monitored != nil {
		series.Monitored = *updates.Monitored
	}
	if updates.QualityProfile != nil {
		series.QualityProfile = *updates.QualityProfile
	}

	// Save changes
	if err := h.seriesStore.Update(ctx, series); err != nil {
		respondError(w, http.StatusInternalServerError, "failed to update series")
		return
	}

	respondJSON(w, http.StatusOK, series)
}

// DeleteSeries handles DELETE /api/series/{id}
func (h *Handler) DeleteSeries(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
	if err != nil {
		respondError(w, http.StatusBadRequest, "invalid series ID")
		return
	}

	if err := h.seriesStore.Delete(ctx, id); err != nil {
		respondError(w, http.StatusInternalServerError, "failed to delete series")
		return
	}

	w.WriteHeader(http.StatusNoContent)
}

// GetSeriesEpisodes handles GET /api/series/{id}/episodes
func (h *Handler) GetSeriesEpisodes(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
	if err != nil {
		respondError(w, http.StatusBadRequest, "invalid series ID")
		return
	}

	episodes, err := h.episodeStore.ListBySeries(ctx, id)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "failed to get episodes")
		return
	}

	respondJSON(w, http.StatusOK, episodes)
}

// PlayEpisode handles GET /api/episodes/{id}/play
func (h *Handler) PlayEpisode(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
	if err != nil {
		respondError(w, http.StatusBadRequest, "invalid episode ID")
		return
	}

	episode, err := h.episodeStore.Get(ctx, id)
	if err != nil {
		respondError(w, http.StatusNotFound, "episode not found")
		return
	}

	// If we already have a cached stream URL, return it
	if episode.StreamURL != nil && *episode.StreamURL != "" {
		respondJSON(w, http.StatusOK, map[string]string{
			"stream_url": *episode.StreamURL,
		})
		return
	}

	// Otherwise find and generate new stream
	series, err := h.seriesStore.Get(ctx, episode.SeriesID)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "failed to get series")
		return
	}

	stream, err := h.streamStore.FindBestStream(ctx, "series", episode.ID, series.QualityProfile)
	if err != nil {
		respondError(w, http.StatusNotFound, "no streams available")
		return
	}

	streamURL, err := h.rdClient.GetStreamURL(ctx, stream.InfoHash)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "failed to get stream URL")
		return
	}

	// Cache the stream URL
	episode.Available = true
	episode.StreamURL = &streamURL
	h.episodeStore.UpdateAvailability(ctx, episode.ID, true, &streamURL)

	respondJSON(w, http.StatusOK, map[string]string{
		"stream_url": streamURL,
		"quality":    stream.Quality,
		"codec":      stream.Codec,
	})
}

// RootHandler handles GET /
func (h *Handler) RootHandler(w http.ResponseWriter, r *http.Request) {
	respondJSON(w, http.StatusOK, map[string]interface{}{
		"name":    "StreamArr API",
		"version": "1.0.0",
		"status":  "running",
		"endpoints": map[string]string{
			"health":   "/api/v1/health",
			"settings": "/api/v1/settings",
			"movies":   "/api/v1/movies",
			"series":   "/api/v1/series",
			"search":   "/api/v1/search/movies?q=query",
		},
	})
}

// HealthCheck handles GET /health
func (h *Handler) HealthCheck(w http.ResponseWriter, r *http.Request) {
	respondJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

// SearchSeries handles GET /api/search/series
func (h *Handler) SearchSeries(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	query := r.URL.Query().Get("q")
	if query == "" {
		respondError(w, http.StatusBadRequest, "query parameter 'q' is required")
		return
	}

	page := 1
	if p := r.URL.Query().Get("page"); p != "" {
		page, _ = strconv.Atoi(p)
	}

	results, err := h.tmdbClient.SearchSeries(ctx, query, page)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "search failed")
		return
	}

	respondJSON(w, http.StatusOK, results)
}

// ListChannels handles GET /api/channels
func (h *Handler) ListChannels(w http.ResponseWriter, r *http.Request) {
	if h.channelManager == nil {
		respondError(w, http.StatusServiceUnavailable, "channel manager not initialized")
		return
	}

	// Get query parameters
	category := r.URL.Query().Get("category")
	query := r.URL.Query().Get("search")

	var channels []*livetv.Channel

	if query != "" {
		channels = h.channelManager.SearchChannels(query)
	} else if category != "" {
		channels = h.channelManager.GetChannelsByCategory(category)
	} else {
		channels = h.channelManager.GetAllChannels()
	}

	respondJSON(w, http.StatusOK, channels)
}

// GetChannelStats handles GET /api/channels/stats - returns categories and sources
func (h *Handler) GetChannelStats(w http.ResponseWriter, r *http.Request) {
	if h.channelManager == nil {
		respondError(w, http.StatusServiceUnavailable, "channel manager not initialized")
		return
	}

	channels := h.channelManager.GetAllChannels()
	
	// Collect unique categories and sources
	categoryMap := make(map[string]int)
	sourceMap := make(map[string]int)
	
	for _, ch := range channels {
		if ch.Category != "" {
			categoryMap[ch.Category]++
		}
		if ch.Source != "" {
			sourceMap[ch.Source]++
		}
	}
	
	// Convert to arrays with counts
	categories := make([]map[string]interface{}, 0, len(categoryMap))
	for name, count := range categoryMap {
		categories = append(categories, map[string]interface{}{
			"name":  name,
			"count": count,
		})
	}
	
	sources := make([]map[string]interface{}, 0, len(sourceMap))
	for name, count := range sourceMap {
		sources = append(sources, map[string]interface{}{
			"name":  name,
			"count": count,
		})
	}
	
	respondJSON(w, http.StatusOK, map[string]interface{}{
		"total_channels": len(channels),
		"categories":     categories,
		"sources":        sources,
	})
}

// GetChannel handles GET /api/channels/{id}
func (h *Handler) GetChannel(w http.ResponseWriter, r *http.Request) {
	if h.channelManager == nil {
		respondError(w, http.StatusServiceUnavailable, "channel manager not initialized")
		return
	}

	id := r.URL.Query().Get("id")
	if id == "" {
		respondError(w, http.StatusBadRequest, "missing channel ID")
		return
	}

	channel, err := h.channelManager.GetChannel(id)
	if err != nil {
		respondError(w, http.StatusNotFound, "channel not found")
		return
	}

	respondJSON(w, http.StatusOK, channel)
}

// GetChannelStream handles GET /api/channels/{id}/stream
func (h *Handler) GetChannelStream(w http.ResponseWriter, r *http.Request) {
	if h.channelManager == nil {
		respondError(w, http.StatusServiceUnavailable, "channel manager not initialized")
		return
	}

	id := r.URL.Query().Get("id")
	if id == "" {
		respondError(w, http.StatusBadRequest, "missing channel ID")
		return
	}

	channel, err := h.channelManager.GetChannel(id)
	if err != nil {
		respondError(w, http.StatusNotFound, "channel not found")
		return
	}

	// Return stream URL
	respondJSON(w, http.StatusOK, map[string]string{
		"stream_url": channel.StreamURL,
	})
}

// GetChannelEPG handles GET /api/channels/{id}/epg
func (h *Handler) GetChannelEPG(w http.ResponseWriter, r *http.Request) {
	if h.epgManager == nil {
		respondError(w, http.StatusServiceUnavailable, "EPG manager not initialized")
		return
	}

	id := r.URL.Query().Get("id")
	if id == "" {
		respondError(w, http.StatusBadRequest, "missing channel ID")
		return
	}

	// Parse date parameter (default to today)
	dateStr := r.URL.Query().Get("date")
	date := time.Now()
	if dateStr != "" {
		parsedDate, err := time.Parse("2006-01-02", dateStr)
		if err == nil {
			date = parsedDate
		}
	}

	programs := h.epgManager.GetEPG(id, date)
	respondJSON(w, http.StatusOK, programs)
}

// GetSettings handles GET /api/settings
func (h *Handler) GetSettings(w http.ResponseWriter, r *http.Request) {
	if h.settingsManager != nil {
		// Use new settings manager
		settings := h.settingsManager.Get()
		respondJSON(w, http.StatusOK, settings)
		return
	}

	// Fallback to database store
	ctx := r.Context()
	settings, err := h.settingsStore.GetAll(ctx)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "failed to get settings")
		return
	}

	respondJSON(w, http.StatusOK, settings)
}

// UpdateSettings handles PUT /api/settings
func (h *Handler) UpdateSettings(w http.ResponseWriter, r *http.Request) {
	if h.settingsManager != nil {
		// Use new settings manager
		var newSettings settings.Settings
		if err := json.NewDecoder(r.Body).Decode(&newSettings); err != nil {
			respondError(w, http.StatusBadRequest, "invalid settings data")
			return
		}

		if err := h.settingsManager.Update(&newSettings); err != nil {
			respondError(w, http.StatusInternalServerError, "failed to update settings")
			return
		}

		respondJSON(w, http.StatusOK, newSettings)
		return
	}

	// Fallback to database store
	ctx := r.Context()

	var settings models.SettingsResponse
	if err := json.NewDecoder(r.Body).Decode(&settings); err != nil {
		respondError(w, http.StatusBadRequest, "invalid request body")
		return
	}

	if err := h.settingsStore.SetAll(ctx, &settings); err != nil {
		respondError(w, http.StatusInternalServerError, "failed to update settings")
		return
	}

	respondJSON(w, http.StatusOK, settings)
}

// GetCalendar handles GET /api/calendar
func (h *Handler) GetCalendar(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	// Parse date range from query params
	startStr := r.URL.Query().Get("start")
	endStr := r.URL.Query().Get("end")

	if startStr == "" || endStr == "" {
		respondError(w, http.StatusBadRequest, "start and end date parameters are required")
		return
	}

	// Get upcoming movies
	movies, err := h.movieStore.GetUpcoming(ctx, startStr, endStr)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "failed to get upcoming movies")
		return
	}

	// Get upcoming episodes
	episodes, err := h.episodeStore.GetUpcoming(ctx, startStr, endStr)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "failed to get upcoming episodes")
		return
	}

	// Combine into calendar entries
	var entries []models.CalendarEntry
	
	for _, movie := range movies {
		entries = append(entries, models.CalendarEntry{
			ID:         movie.ID,
			Type:       "movie",
			Title:      movie.Title,
			Date:       movie.ReleaseDate,
			PosterPath: movie.PosterPath,
			Overview:   movie.Overview,
		})
	}
	
	for _, episode := range episodes {
		series, _ := h.seriesStore.Get(ctx, episode.SeriesID)
		seriesTitle := ""
		if series != nil {
			seriesTitle = series.Title
		}
		
		entries = append(entries, models.CalendarEntry{
			ID:            episode.ID,
			Type:          "episode",
			Title:         episode.Title,
			Date:          episode.AirDate,
			PosterPath:    episode.StillPath,
			Overview:      episode.Overview,
			SeriesID:      &episode.SeriesID,
			SeriesTitle:   seriesTitle,
			SeasonNumber:  &episode.SeasonNumber,
			EpisodeNumber: &episode.EpisodeNumber,
		})
	}

	respondJSON(w, http.StatusOK, entries)
}

// GetMDBListUserLists fetches the user's MDBList lists
func (h *Handler) GetMDBListUserLists(w http.ResponseWriter, r *http.Request) {
	// Get API key from query parameter first, fall back to settings
	apiKey := r.URL.Query().Get("apiKey")
	
	if apiKey == "" {
		ctx := r.Context()
		setting, err := h.settingsStore.Get(ctx, "mdblist_api_key")
		if err == nil && setting != nil {
			apiKey = setting.Value
		}
	}
	
	if apiKey == "" {
		respondJSON(w, http.StatusOK, map[string]interface{}{
			"success": false,
			"error":   "MDBList API key not configured",
		})
		return
	}
	
	// Create MDBList client and fetch user lists
	mdbClient := services.NewMDBListClient(apiKey, "./cache/mdblist")
	result, err := mdbClient.GetUserLists()
	if err != nil {
		respondJSON(w, http.StatusOK, map[string]interface{}{
			"success": false,
			"error":   "Failed to fetch user lists: " + err.Error(),
		})
		return
	}
	
	respondJSON(w, http.StatusOK, result)
}

// GetTrending handles GET /api/discover/trending
func (h *Handler) GetTrending(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	
	mediaType := r.URL.Query().Get("media_type")
	if mediaType == "" {
		mediaType = "all"
	}
	
	timeWindow := r.URL.Query().Get("time_window")
	if timeWindow == "" {
		timeWindow = "day"
	}
	
	items, err := h.tmdbClient.GetTrending(ctx, mediaType, timeWindow)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "Failed to get trending: "+err.Error())
		return
	}
	
	respondJSON(w, http.StatusOK, items)
}

// GetPopular handles GET /api/discover/popular
func (h *Handler) GetPopular(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	
	mediaType := r.URL.Query().Get("media_type")
	if mediaType == "" {
		mediaType = "movie"
	}
	
	items, err := h.tmdbClient.GetPopular(ctx, mediaType)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "Failed to get popular: "+err.Error())
		return
	}
	
	respondJSON(w, http.StatusOK, items)
}

// GetNowPlaying handles GET /api/discover/now-playing
func (h *Handler) GetNowPlaying(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	
	mediaType := r.URL.Query().Get("media_type")
	if mediaType == "" {
		mediaType = "movie"
	}
	
	items, err := h.tmdbClient.GetNowPlaying(ctx, mediaType)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "Failed to get now playing: "+err.Error())
		return
	}
	
	respondJSON(w, http.StatusOK, items)
}
