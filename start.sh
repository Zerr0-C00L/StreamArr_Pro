#!/bin/bash

# StreamArr Quick Start Script
# Quickly sets up and starts the StreamArr system

set -e

echo "╔════════════════════════════════════════╗"
echo "║       StreamArr Quick Start            ║"
echo "╚════════════════════════════════════════╝"
echo ""

# Check if .env exists
if [ ! -f .env ]; then
    echo "📋 Creating .env file from template..."
    cp .env.example .env
    echo "⚠️  Please edit .env and add your API keys:"
    echo "   - TMDB_API_KEY"
    echo "   - REALDEBRID_API_KEY"
    echo "   - DATABASE_URL"
    echo ""
    echo "Then run this script again."
    exit 1
fi

# Load environment
source .env

# Check if binaries exist
if [ ! -f bin/server ] || [ ! -f bin/worker ] || [ ! -f bin/migrate ]; then
    echo "🔨 Building binaries..."
    go build -o bin/server cmd/server/main.go
    go build -o bin/worker cmd/worker/main.go
    go build -o bin/migrate cmd/migrate/main.go
    echo "✅ Binaries built successfully"
    echo ""
fi

# Check database connection
echo "🔍 Checking database connection..."
if psql "$DATABASE_URL" -c "SELECT 1" &>/dev/null; then
    echo "✅ Database connection successful"
else
    echo "❌ Cannot connect to database!"
    echo ""
    echo "Options:"
    echo "  1. Run: ./setup_database.sh"
    echo "  2. Or ensure PostgreSQL is running and DATABASE_URL is correct"
    exit 1
fi

# Check if migrations are needed
echo ""
echo "🗄️  Checking database schema..."
TABLE_COUNT=$(psql "$DATABASE_URL" -t -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public';" 2>/dev/null | tr -d ' ' || echo "0")

if [ "$TABLE_COUNT" -lt 6 ]; then
    echo "📦 Running migrations..."
    ./bin/migrate up
    echo "✅ Migrations applied"
else
    echo "✅ Database schema is up to date"
fi

# Check if API keys are set
echo ""
echo "🔑 Validating configuration..."
if [ -z "$TMDB_API_KEY" ] || [ "$TMDB_API_KEY" = "your_tmdb_api_key_here" ]; then
    echo "⚠️  Warning: TMDB_API_KEY not set in .env"
fi

if [ -z "$REALDEBRID_API_KEY" ] || [ "$REALDEBRID_API_KEY" = "your_real_debrid_api_key_here" ]; then
    echo "⚠️  Warning: REALDEBRID_API_KEY not set in .env"
fi

echo ""
echo "╔════════════════════════════════════════╗"
echo "║      Starting StreamArr Services       ║"
echo "╚════════════════════════════════════════╝"
echo ""

# Kill existing processes
pkill -f "bin/server" 2>/dev/null || true
pkill -f "bin/worker" 2>/dev/null || true

# Start server in background
echo "🚀 Starting API Server on port ${SERVER_PORT:-8080}..."
nohup ./bin/server > logs/server.log 2>&1 &
SERVER_PID=$!
echo "   PID: $SERVER_PID"

# Wait for server to start
sleep 2

# Test health endpoint
if curl -s http://localhost:${SERVER_PORT:-8080}/api/v1/health | grep -q "ok"; then
    echo "✅ API Server is running"
else
    echo "⚠️  API Server may not be responding"
fi

# Start worker in background
echo ""
echo "⚙️  Starting Background Worker..."
nohup ./bin/worker > logs/worker.log 2>&1 &
WORKER_PID=$!
echo "   PID: $WORKER_PID"

# Save PIDs
echo $SERVER_PID > logs/server.pid
echo $WORKER_PID > logs/worker.pid

echo ""
echo "╔════════════════════════════════════════╗"
echo "║          StreamArr is Ready!           ║"
echo "╚════════════════════════════════════════╝"
echo ""
echo "📍 API Endpoints:"
echo "   Health Check:  http://localhost:${SERVER_PORT:-8080}/api/v1/health"
echo "   Movies:        http://localhost:${SERVER_PORT:-8080}/api/v1/movies"
echo "   Search:        http://localhost:${SERVER_PORT:-8080}/api/v1/search/movies?q=query"
echo ""
echo "📊 Monitoring:"
echo "   Server logs:   tail -f logs/server.log"
echo "   Worker logs:   tail -f logs/worker.log"
echo ""
echo "🛑 To stop services:"
echo "   ./stop.sh"
echo ""
echo "📚 Documentation:"
echo "   API Guide:     README_STREAMARR.md"
echo "   Deployment:    DEPLOYMENT.md"
echo ""

# Test API
echo "🧪 Quick API Test:"
echo ""
curl -s http://localhost:${SERVER_PORT:-8080}/api/v1/health | jq '.' 2>/dev/null || curl -s http://localhost:${SERVER_PORT:-8080}/api/v1/health
echo ""
