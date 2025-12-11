package main

import (
	"database/sql"
	"fmt"
	"log"
	"os"

	"github.com/joho/godotenv"
	_ "github.com/lib/pq"

	"github.com/Zerr0-C00L/StreamArr/internal/config"
)

func main() {
	// Load .env file
	if err := godotenv.Load(); err != nil {
		log.Println("Warning: .env file not found, using environment variables")
	}

	log.Println("StreamArr Database Migration Tool")

	if len(os.Args) < 2 {
		log.Fatal("Usage: migrate [up|down]")
	}

	command := os.Args[1]

	// Load configuration
	cfg := config.Load()

	// Connect to database
	db, err := sql.Open("postgres", cfg.DatabaseURL)
	if err != nil {
		log.Fatalf("Failed to connect to database: %v", err)
	}
	defer db.Close()

	// Test connection
	if err := db.Ping(); err != nil {
		log.Fatalf("Failed to ping database: %v", err)
	}

	switch command {
	case "up":
		if err := migrateUp(db); err != nil {
			log.Fatalf("Migration failed: %v", err)
		}
		log.Println("Migration completed successfully")
	case "down":
		if err := migrateDown(db); err != nil {
			log.Fatalf("Migration rollback failed: %v", err)
		}
		log.Println("Migration rolled back successfully")
	default:
		log.Fatalf("Unknown command: %s. Use 'up' or 'down'", command)
	}
}

func migrateUp(db *sql.DB) error {
	log.Println("Running migrations...")

	// Read migration file
	migration, err := os.ReadFile("migrations/001_initial_schema.up.sql")
	if err != nil {
		return fmt.Errorf("failed to read migration file: %w", err)
	}

	// Execute migration
	if _, err := db.Exec(string(migration)); err != nil {
		return fmt.Errorf("failed to execute migration: %w", err)
	}

	return nil
}

func migrateDown(db *sql.DB) error {
	log.Println("Rolling back migrations...")

	// Drop all tables in reverse order
	tables := []string{
		"watch_history",
		"activity_log",
		"available_streams",
		"library_episodes",
		"library_series",
		"library_movies",
	}

	for _, table := range tables {
		log.Printf("Dropping table %s...", table)
		if _, err := db.Exec(fmt.Sprintf("DROP TABLE IF EXISTS %s CASCADE", table)); err != nil {
			return fmt.Errorf("failed to drop table %s: %w", table, err)
		}
	}

	return nil
}
