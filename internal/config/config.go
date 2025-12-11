package config

import (
	"os"
	"strconv"
)

type Config struct {
	// Server
	ServerPort int
	Host       string

	// Database
	DatabaseURL string

	// Redis
	RedisURL string

	// API Keys
	TMDBAPIKey        string
	RealDebridAPIKey  string
	PremiumizeAPIKey  string
	MDBListAPIKey     string

	// Services
	TorrentioURL string
	CometURL     string
	MediaFusionURL string

	// Features
	EnableNotifications bool
	DiscordWebhookURL   string
	TelegramBotToken    string
	TelegramChatID      string

	// Playlist Settings
	TotalPages                 int
	MinYear                    int
	MinRuntime                 int
	Language                   string
	SeriesOriginCountry        string
	MoviesOriginCountry        string
	MaxResolution              int
	MaxFileSize                int
	AutoCacheIntervalHours     int
	UserCreatePlaylist         bool
	IncludeAdultVOD            bool
	EnableQualityVariants      bool
	ShowFullStreamName         bool

	// Provider Settings
	UseRealDebrid             bool
	UsePremiumize             bool
	StreamProviders           []string
	TorrentioProviders        string
	CometIndexers             []string

	// Proxy Settings
	HTTPProxy      string
	UseHTTPProxy   bool

	// HeadlessVidX Settings
	HeadlessVidXAddress    string
	HeadlessVidXMaxThreads int

	// Debug
	Debug bool
}

func Load() *Config {
	return &Config{
		ServerPort:             getEnvInt("SERVER_PORT", 8080),
		Host:                   getEnv("HOST", "0.0.0.0"),
		DatabaseURL:            getEnv("DATABASE_URL", "postgres://streamarr:streamarr@localhost:5432/streamarr?sslmode=disable"),
		RedisURL:               getEnv("REDIS_URL", "redis://localhost:6379/0"),
		TMDBAPIKey:             getEnv("TMDB_API_KEY", ""),
		RealDebridAPIKey:       getEnv("REALDEBRID_API_KEY", ""),
		PremiumizeAPIKey:       getEnv("PREMIUMIZE_API_KEY", ""),
		MDBListAPIKey:          getEnv("MDBLIST_API_KEY", ""),
		TorrentioURL:           getEnv("TORRENTIO_URL", "https://torrentio.strem.fun"),
		CometURL:               getEnv("COMET_URL", "https://comet.elfhosted.com"),
		MediaFusionURL:         getEnv("MEDIAFUSION_URL", "https://mediafusion.elfhosted.com"),
		EnableNotifications:    getEnvBool("ENABLE_NOTIFICATIONS", false),
		DiscordWebhookURL:      getEnv("DISCORD_WEBHOOK_URL", ""),
		TelegramBotToken:       getEnv("TELEGRAM_BOT_TOKEN", ""),
		TelegramChatID:         getEnv("TELEGRAM_CHAT_ID", ""),
		TotalPages:             getEnvInt("TOTAL_PAGES", 5),
		MinYear:                getEnvInt("MIN_YEAR", 1970),
		MinRuntime:             getEnvInt("MIN_RUNTIME", 30),
		Language:               getEnv("LANGUAGE", "en-US"),
		SeriesOriginCountry:    getEnv("SERIES_ORIGIN_COUNTRY", "US"),
		MoviesOriginCountry:    getEnv("MOVIES_ORIGIN_COUNTRY", "US"),
		MaxResolution:          getEnvInt("MAX_RESOLUTION", 2160),
		MaxFileSize:            getEnvInt("MAX_FILE_SIZE", 50000),
		AutoCacheIntervalHours: getEnvInt("AUTO_CACHE_INTERVAL_HOURS", 6),
		UserCreatePlaylist:     getEnvBool("USER_CREATE_PLAYLIST", true),
		IncludeAdultVOD:        getEnvBool("INCLUDE_ADULT_VOD", false),
		EnableQualityVariants:  getEnvBool("ENABLE_QUALITY_VARIANTS", false),
		ShowFullStreamName:     getEnvBool("SHOW_FULL_STREAM_NAME", false),
		UseRealDebrid:          getEnvBool("USE_REALDEBRID", true),
		UsePremiumize:          getEnvBool("USE_PREMIUMIZE", false),
		StreamProviders:        getEnvSlice("STREAM_PROVIDERS", []string{"comet", "mediafusion", "torrentio"}),
		TorrentioProviders:     getEnv("TORRENTIO_PROVIDERS", "yts,eztv,rarbg,1337x,thepiratebay,kickasstorrents,torrentgalaxy,magnetdl"),
		CometIndexers:          getEnvSlice("COMET_INDEXERS", []string{"bktorrent", "thepiratebay", "yts", "eztv"}),
		HTTPProxy:              getEnv("HTTP_PROXY", ""),
		UseHTTPProxy:           getEnvBool("USE_HTTP_PROXY", false),
		HeadlessVidXAddress:    getEnv("HEADLESS_VIDX_ADDRESS", "localhost:3202"),
		HeadlessVidXMaxThreads: getEnvInt("HEADLESS_VIDX_MAX_THREADS", 5),
		Debug:                  getEnvBool("DEBUG", false),
	}
}

func getEnv(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}

func getEnvInt(key string, fallback int) int {
	if value := os.Getenv(key); value != "" {
		i, err := strconv.Atoi(value)
		if err == nil {
			return i
		}
	}
	return fallback
}

func getEnvBool(key string, fallback bool) bool {
	if value := os.Getenv(key); value != "" {
		b, err := strconv.ParseBool(value)
		if err == nil {
			return b
		}
	}
	return fallback
}

func getEnvSlice(key string, fallback []string) []string {
	if value := os.Getenv(key); value != "" {
		// Split by comma and trim spaces
		parts := make([]string, 0)
		for _, part := range splitString(value, ",") {
			if trimmed := trimSpace(part); trimmed != "" {
				parts = append(parts, trimmed)
			}
		}
		if len(parts) > 0 {
			return parts
		}
	}
	return fallback
}

func splitString(s, sep string) []string {
	result := make([]string, 0)
	start := 0
	for i := 0; i < len(s); i++ {
		if string(s[i]) == sep {
			result = append(result, s[start:i])
			start = i + 1
		}
	}
	result = append(result, s[start:])
	return result
}

func trimSpace(s string) string {
	start := 0
	end := len(s)
	for start < end && (s[start] == ' ' || s[start] == '\t' || s[start] == '\n' || s[start] == '\r') {
		start++
	}
	for end > start && (s[end-1] == ' ' || s[end-1] == '\t' || s[end-1] == '\n' || s[end-1] == '\r') {
		end--
	}
	return s[start:end]
}
