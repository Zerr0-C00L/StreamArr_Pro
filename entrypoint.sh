#!/bin/sh

# StreamArr Docker Entrypoint
# Starts both the API server and background worker processes

set -e

echo "╔════════════════════════════════════════╗"
echo "║     StreamArr Container Starting       ║"
echo "╚════════════════════════════════════════╝"
echo ""

# Wait for database to be ready
echo "⏳ Waiting for database..."
max_retries=30
retry_count=0

while [ $retry_count -lt $max_retries ]; do
    if /app/bin/migrate -check 2>/dev/null; then
        echo "✅ Database is ready"
        break
    fi
    retry_count=$((retry_count + 1))
    echo "   Attempt $retry_count/$max_retries..."
    sleep 2
done

if [ $retry_count -eq $max_retries ]; then
    echo "❌ Database connection timeout"
    exit 1
fi

# Run database migrations
echo "🔄 Running database migrations..."
/app/bin/migrate -up
echo "✅ Migrations complete"
echo ""

# Start worker process in background
echo "🤖 Starting background workers..."
/app/bin/worker > /app/logs/worker.log 2>&1 &
WORKER_PID=$!
echo "   Worker PID: $WORKER_PID"
echo "   Logs: /app/logs/worker.log"
echo ""

# Start server process (foreground)
echo "🚀 Starting API server..."
exec /app/bin/server
