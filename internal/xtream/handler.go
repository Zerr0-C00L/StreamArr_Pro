package xtream

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"strconv"
	"time"

	"github.com/Zerr0-C00L/StreamArr/internal/config"
	"github.com/Zerr0-C00L/StreamArr/internal/providers"
	"github.com/Zerr0-C00L/StreamArr/internal/services"
	"github.com/gorilla/mux"
)

type XtreamHandler struct {
	cfg          *config.Config
	db           *sql.DB
	tmdb         *services.TMDBClient
	rdClient     *services.RealDebridClient
	multiProvider *providers.MultiProvider
	baseURL      string
}

func NewXtreamHandler(cfg *config.Config, db *sql.DB, tmdb *services.TMDBClient, rdClient *services.RealDebridClient) *XtreamHandler {
	multiProvider := providers.NewMultiProvider(
		cfg.RealDebridAPIKey,
		cfg.StreamProviders,
		cfg.TorrentioProviders,
		cfg.CometIndexers,
		tmdb,
	)
	
	return &XtreamHandler{
		cfg:           cfg,
		db:            db,
		tmdb:          tmdb,
		rdClient:      rdClient,
		multiProvider: multiProvider,
		baseURL:       fmt.Sprintf("http://%s:%d", cfg.Host, cfg.ServerPort),
	}
}

func (h *XtreamHandler) RegisterRoutes(r *mux.Router) {
	// Xtream Codes API endpoints
	r.HandleFunc("/player_api.php", h.handlePlayerAPI).Methods("GET")
	r.HandleFunc("/xmltv.php", h.handleXMLTV).Methods("GET")
	r.HandleFunc("/play.php", h.handlePlay).Methods("GET")
	r.HandleFunc("/get.php", h.handleGetPlaylist).Methods("GET")
}

func (h *XtreamHandler) handlePlayerAPI(w http.ResponseWriter, r *http.Request) {
	action := r.URL.Query().Get("action")
	
	log.Printf("Xtream API: action=%s", action)
	
	switch action {
	case "get_vod_categories":
		h.getVODCategories(w, r)
	case "get_vod_streams":
		h.getVODStreams(w, r)
	case "get_vod_info":
		h.getVODInfo(w, r)
	case "get_series_categories":
		h.getSeriesCategories(w, r)
	case "get_series":
		h.getSeries(w, r)
	case "get_series_info":
		h.getSeriesInfo(w, r)
	case "get_live_categories":
		h.getLiveCategories(w, r)
	case "get_live_streams":
		h.getLiveStreams(w, r)
	default:
		h.getServerInfo(w, r)
	}
}

func (h *XtreamHandler) getServerInfo(w http.ResponseWriter, r *http.Request) {
	info := map[string]interface{}{
		"user_info": map[string]interface{}{
			"username":        "streamarr",
			"status":          "Active",
			"exp_date":        "1893456000",
			"is_trial":        "0",
			"active_cons":     "1",
			"created_at":      time.Now().Unix(),
			"max_connections": "1",
			"allowed_output_formats": []string{"m3u8", "ts"},
		},
		"server_info": map[string]interface{}{
			"url":            h.baseURL,
			"port":           h.cfg.ServerPort,
			"https_port":     "",
			"server_protocol": "http",
			"rtmp_port":      "",
			"timezone":       "America/New_York",
			"timestamp_now":  time.Now().Unix(),
			"time_now":       time.Now().Format("2006-01-02 15:04:05"),
		},
	}
	
	json.NewEncoder(w).Encode(info)
}

func (h *XtreamHandler) getVODCategories(w http.ResponseWriter, r *http.Request) {
	categories := []map[string]interface{}{
		{"category_id": "1", "category_name": "Now Playing", "parent_id": 0},
		{"category_id": "2", "category_name": "Popular Movies", "parent_id": 0},
		{"category_id": "3", "category_name": "Action", "parent_id": 0},
		{"category_id": "4", "category_name": "Comedy", "parent_id": 0},
		{"category_id": "5", "category_name": "Drama", "parent_id": 0},
		{"category_id": "6", "category_name": "Horror", "parent_id": 0},
		{"category_id": "7", "category_name": "Sci-Fi", "parent_id": 0},
		{"category_id": "8", "category_name": "Thriller", "parent_id": 0},
	}
	
	json.NewEncoder(w).Encode(categories)
}

func (h *XtreamHandler) getVODStreams(w http.ResponseWriter, r *http.Request) {
	categoryID := r.URL.Query().Get("category_id")
	
	// Query movies from database
	query := `
		SELECT id, tmdb_id, title, year, metadata
		FROM library_movies
		WHERE monitored = true
		LIMIT 100
	`
	
	rows, err := h.db.Query(query)
	if err != nil {
		log.Printf("Error querying movies: %v", err)
		http.Error(w, "Internal server error", http.StatusInternalServerError)
		return
	}
	defer rows.Close()
	
	streams := make([]map[string]interface{}, 0)
	
	for rows.Next() {
		var id, tmdbID int64
		var title string
		var year sql.NullInt64
		var metadataJSON []byte
		
		if err := rows.Scan(&id, &tmdbID, &title, &year, &metadataJSON); err != nil {
			continue
		}
		
		var metadata map[string]interface{}
		json.Unmarshal(metadataJSON, &metadata)
		
		stream := map[string]interface{}{
			"num":          id,
			"stream_id":    id,
			"name":         title,
			"title":        title,
			"year":         year.Int64,
			"stream_type":  "movie",
			"stream_icon":  metadata["poster_path"],
			"rating":       metadata["vote_average"],
			"category_id":  categoryID,
			"container_extension": "mp4",
		}
		
		streams = append(streams, stream)
	}
	
	json.NewEncoder(w).Encode(streams)
}

func (h *XtreamHandler) getVODInfo(w http.ResponseWriter, r *http.Request) {
	vodID := r.URL.Query().Get("vod_id")
	if vodID == "" {
		http.Error(w, "Missing vod_id", http.StatusBadRequest)
		return
	}
	
	id, err := strconv.ParseInt(vodID, 10, 64)
	if err != nil {
		http.Error(w, "Invalid vod_id", http.StatusBadRequest)
		return
	}
	
	// Query movie from database
	var tmdbID int64
	var title string
	var year sql.NullInt64
	var metadataJSON []byte
	
	query := `SELECT tmdb_id, title, year, metadata FROM library_movies WHERE id = $1`
	err = h.db.QueryRow(query, id).Scan(&tmdbID, &title, &year, &metadataJSON)
	if err != nil {
		http.Error(w, "Movie not found", http.StatusNotFound)
		return
	}
	
	var metadata map[string]interface{}
	json.Unmarshal(metadataJSON, &metadata)
	
	info := map[string]interface{}{
		"info": map[string]interface{}{
			"tmdb_id":      tmdbID,
			"name":         title,
			"title":        title,
			"year":         year.Int64,
			"rating":       metadata["vote_average"],
			"plot":         metadata["overview"],
			"cast":         metadata["cast"],
			"director":     metadata["director"],
			"genre":        metadata["genres"],
			"releaseDate":  metadata["release_date"],
			"duration":     metadata["runtime"],
			"backdrop_path": []string{fmt.Sprintf("%v", metadata["backdrop_path"])},
			"cover":        metadata["poster_path"],
			"stream_type":  "movie",
		},
		"movie_data": map[string]interface{}{
			"stream_id":    id,
			"name":         title,
			"container_extension": "mp4",
		},
	}
	
	json.NewEncoder(w).Encode(info)
}

func (h *XtreamHandler) getSeriesCategories(w http.ResponseWriter, r *http.Request) {
	categories := []map[string]interface{}{
		{"category_id": "101", "category_name": "Popular Series", "parent_id": 0},
		{"category_id": "102", "category_name": "Action & Adventure", "parent_id": 0},
		{"category_id": "103", "category_name": "Comedy", "parent_id": 0},
		{"category_id": "104", "category_name": "Drama", "parent_id": 0},
		{"category_id": "105", "category_name": "Sci-Fi & Fantasy", "parent_id": 0},
	}
	
	json.NewEncoder(w).Encode(categories)
}

func (h *XtreamHandler) getSeries(w http.ResponseWriter, r *http.Request) {
	categoryID := r.URL.Query().Get("category_id")
	
	query := `
		SELECT id, tmdb_id, title, year, metadata
		FROM library_series
		WHERE monitored = true
		LIMIT 100
	`
	
	rows, err := h.db.Query(query)
	if err != nil {
		log.Printf("Error querying series: %v", err)
		http.Error(w, "Internal server error", http.StatusInternalServerError)
		return
	}
	defer rows.Close()
	
	series := make([]map[string]interface{}, 0)
	
	for rows.Next() {
		var id, tmdbID int64
		var title string
		var year sql.NullInt64
		var metadataJSON []byte
		
		if err := rows.Scan(&id, &tmdbID, &title, &year, &metadataJSON); err != nil {
			continue
		}
		
		var metadata map[string]interface{}
		json.Unmarshal(metadataJSON, &metadata)
		
		s := map[string]interface{}{
			"num":          id,
			"series_id":    id,
			"name":         title,
			"title":        title,
			"year":         year.Int64,
			"cover":        metadata["poster_path"],
			"rating":       metadata["vote_average"],
			"category_id":  categoryID,
		}
		
		series = append(series, s)
	}
	
	json.NewEncoder(w).Encode(series)
}

func (h *XtreamHandler) getSeriesInfo(w http.ResponseWriter, r *http.Request) {
	seriesID := r.URL.Query().Get("series_id")
	if seriesID == "" {
		http.Error(w, "Missing series_id", http.StatusBadRequest)
		return
	}
	
	id, err := strconv.ParseInt(seriesID, 10, 64)
	if err != nil {
		http.Error(w, "Invalid series_id", http.StatusBadRequest)
		return
	}
	
	// Query series from database
	var tmdbID int64
	var title string
	var year sql.NullInt64
	var metadataJSON []byte
	
	query := `SELECT tmdb_id, title, year, metadata FROM library_series WHERE id = $1`
	err = h.db.QueryRow(query, id).Scan(&tmdbID, &title, &year, &metadataJSON)
	if err != nil {
		http.Error(w, "Series not found", http.StatusNotFound)
		return
	}
	
	var metadata map[string]interface{}
	json.Unmarshal(metadataJSON, &metadata)
	
	// Get episodes
	episodesQuery := `
		SELECT season_number, episode_number, title, air_date
		FROM library_episodes
		WHERE series_id = $1
		ORDER BY season_number, episode_number
	`
	
	rows, err := h.db.Query(episodesQuery, id)
	if err != nil {
		log.Printf("Error querying episodes: %v", err)
	}
	defer rows.Close()
	
	episodes := make(map[string][]map[string]interface{})
	
	for rows.Next() {
		var seasonNum, episodeNum int
		var episodeTitle string
		var airDate sql.NullTime
		
		if err := rows.Scan(&seasonNum, &episodeNum, &episodeTitle, &airDate); err != nil {
			continue
		}
		
		seasonKey := fmt.Sprintf("%d", seasonNum)
		
		ep := map[string]interface{}{
			"id":             fmt.Sprintf("%d_%d_%d", id, seasonNum, episodeNum),
			"episode_num":    episodeNum,
			"title":          episodeTitle,
			"container_extension": "mp4",
			"info": map[string]interface{}{
				"air_date": airDate.Time.Format("2006-01-02"),
			},
		}
		
		episodes[seasonKey] = append(episodes[seasonKey], ep)
	}
	
	info := map[string]interface{}{
		"info": map[string]interface{}{
			"name":         title,
			"title":        title,
			"year":         year.Int64,
			"rating":       metadata["vote_average"],
			"plot":         metadata["overview"],
			"cast":         metadata["cast"],
			"genre":        metadata["genres"],
			"cover":        metadata["poster_path"],
			"backdrop_path": []string{fmt.Sprintf("%v", metadata["backdrop_path"])},
		},
		"seasons":  []interface{}{}, // Populated from episodes
		"episodes": episodes,
	}
	
	json.NewEncoder(w).Encode(info)
}

func (h *XtreamHandler) getLiveCategories(w http.ResponseWriter, r *http.Request) {
	categories := []map[string]interface{}{
		{"category_id": "201", "category_name": "Sports", "parent_id": 0},
		{"category_id": "202", "category_name": "News", "parent_id": 0},
		{"category_id": "203", "category_name": "Entertainment", "parent_id": 0},
		{"category_id": "204", "category_name": "Movies", "parent_id": 0},
	}
	
	json.NewEncoder(w).Encode(categories)
}

func (h *XtreamHandler) getLiveStreams(w http.ResponseWriter, r *http.Request) {
	// Return empty for now - Live TV implementation would go here
	json.NewEncoder(w).Encode([]map[string]interface{}{})
}

func (h *XtreamHandler) handleXMLTV(w http.ResponseWriter, r *http.Request) {
	// Basic XMLTV EPG structure
	w.Header().Set("Content-Type", "application/xml")
	
	xmltv := `<?xml version="1.0" encoding="UTF-8"?>
<tv generator-info-name="StreamArr">
</tv>`
	
	w.Write([]byte(xmltv))
}

func (h *XtreamHandler) handlePlay(w http.ResponseWriter, r *http.Request) {
	vodID := r.URL.Query().Get("stream_id")
	seriesID := r.URL.Query().Get("series_id")
	season := r.URL.Query().Get("season")
	episode := r.URL.Query().Get("episode")
	
	if vodID != "" {
		h.playMovie(w, r, vodID)
	} else if seriesID != "" && season != "" && episode != "" {
		h.playEpisode(w, r, seriesID, season, episode)
	} else {
		http.Error(w, "Missing required parameters", http.StatusBadRequest)
	}
}

func (h *XtreamHandler) playMovie(w http.ResponseWriter, r *http.Request, vodID string) {
	id, _ := strconv.ParseInt(vodID, 10, 64)
	
	// Get IMDB ID from database
	var imdbID sql.NullString
	query := `SELECT imdb_id FROM library_movies WHERE id = $1`
	err := h.db.QueryRow(query, id).Scan(&imdbID)
	if err != nil || !imdbID.Valid {
		http.Error(w, "Movie not found", http.StatusNotFound)
		return
	}
	
	// Get stream from providers
	stream, err := h.multiProvider.GetBestStream(imdbID.String, nil, nil, h.cfg.MaxResolution)
	if err != nil {
		log.Printf("Error getting stream: %v", err)
		http.Error(w, "Stream not available", http.StatusNotFound)
		return
	}
	
	// Resolve through Real-Debrid if needed
	if stream.URL == "" && stream.InfoHash != "" {
		// Add magnet to RD and get URL
		// This would need Real-Debrid integration
		http.Error(w, "Stream resolution not implemented", http.StatusNotImplemented)
		return
	}
	
	// Redirect to stream URL
	http.Redirect(w, r, stream.URL, http.StatusFound)
}

func (h *XtreamHandler) playEpisode(w http.ResponseWriter, r *http.Request, seriesID, seasonStr, episodeStr string) {
	id, _ := strconv.ParseInt(seriesID, 10, 64)
	seasonNum, _ := strconv.Atoi(seasonStr)
	episodeNum, _ := strconv.Atoi(episodeStr)
	
	// Get IMDB ID from database
	var imdbID sql.NullString
	query := `SELECT imdb_id FROM library_series WHERE id = $1`
	err := h.db.QueryRow(query, id).Scan(&imdbID)
	if err != nil || !imdbID.Valid {
		http.Error(w, "Series not found", http.StatusNotFound)
		return
	}
	
	// Get stream from providers
	stream, err := h.multiProvider.GetBestStream(imdbID.String, &seasonNum, &episodeNum, h.cfg.MaxResolution)
	if err != nil {
		log.Printf("Error getting stream: %v", err)
		http.Error(w, "Stream not available", http.StatusNotFound)
		return
	}
	
	// Redirect to stream URL
	if stream.URL != "" {
		http.Redirect(w, r, stream.URL, http.StatusFound)
	} else {
		http.Error(w, "Stream URL not available", http.StatusNotFound)
	}
}

func (h *XtreamHandler) handleGetPlaylist(w http.ResponseWriter, r *http.Request) {
	outputType := r.URL.Query().Get("type")
	if outputType == "" {
		outputType = "m3u_plus"
	}

	w.Header().Set("Content-Type", "audio/x-mpegurl")
	w.Header().Set("Content-Disposition", "attachment; filename=\"playlist.m3u\"")

	fmt.Fprintf(w, "#EXTM3U\n")

	// Add VOD streams (movies)
	movieRows, err := h.db.Query(`
		SELECT id, title, year, metadata
		FROM library_movies
		WHERE monitored = true
		ORDER BY title
	`)
	if err == nil {
		defer movieRows.Close()
		for movieRows.Next() {
			var id int64
			var title string
			var year sql.NullInt64
			var metadataJSON []byte
			if err := movieRows.Scan(&id, &title, &year, &metadataJSON); err != nil {
				continue
			}

			var metadata map[string]interface{}
			json.Unmarshal(metadataJSON, &metadata)

			logo := ""
			if poster, ok := metadata["poster_path"].(string); ok && poster != "" {
				logo = fmt.Sprintf("https://image.tmdb.org/t/p/w500%s", poster)
			}

			yearStr := ""
			if year.Valid {
				yearStr = fmt.Sprintf(" (%d)", year.Int64)
			}

			fmt.Fprintf(w, "#EXTINF:-1 tvg-id=\"movie_%d\" tvg-name=\"%s%s\" tvg-logo=\"%s\" group-title=\"Movies\",%s%s\n",
				id, title, yearStr, logo, title, yearStr)
			fmt.Fprintf(w, "%s/play.php?type=movie&id=%d\n", h.baseURL, id)
		}
	}

	// Add Series
	seriesRows, err := h.db.Query(`
		SELECT id, title, year, metadata
		FROM library_series
		WHERE monitored = true
		ORDER BY title
	`)
	if err == nil {
		defer seriesRows.Close()
		for seriesRows.Next() {
			var id int64
			var title string
			var year sql.NullInt64
			var metadataJSON []byte
			if err := seriesRows.Scan(&id, &title, &year, &metadataJSON); err != nil {
				continue
			}

			var metadata map[string]interface{}
			json.Unmarshal(metadataJSON, &metadata)

			logo := ""
			if poster, ok := metadata["poster_path"].(string); ok && poster != "" {
				logo = fmt.Sprintf("https://image.tmdb.org/t/p/w500%s", poster)
			}

			yearStr := ""
			if year.Valid {
				yearStr = fmt.Sprintf(" (%d)", year.Int64)
			}

			fmt.Fprintf(w, "#EXTINF:-1 tvg-id=\"series_%d\" tvg-name=\"%s%s\" tvg-logo=\"%s\" group-title=\"Series\",%s%s\n",
				id, title, yearStr, logo, title, yearStr)
			fmt.Fprintf(w, "%s/series/%d\n", h.baseURL, id)
		}
	}
}
