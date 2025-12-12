# Build stage for Go backend
FROM golang:1.23-alpine AS backend-builder

WORKDIR /app

# Install build dependencies
RUN apk add --no-cache git

# Copy go mod files first for caching
COPY go.mod go.sum ./
RUN go mod download

# Copy source code
COPY . .

# Build binaries
RUN CGO_ENABLED=0 GOOS=linux go build -o bin/server cmd/server/main.go
RUN CGO_ENABLED=0 GOOS=linux go build -o bin/worker cmd/worker/main.go
RUN CGO_ENABLED=0 GOOS=linux go build -o bin/migrate cmd/migrate/main.go

# Build stage for React frontend
FROM node:20-alpine AS frontend-builder

WORKDIR /app/streamarr-ui

# Copy package files
COPY streamarr-ui/package*.json ./

# Install dependencies
RUN npm ci

# Copy source and build
COPY streamarr-ui/ ./
RUN npm run build

# Final stage
FROM alpine:3.19

WORKDIR /app

# Install runtime dependencies
RUN apk add --no-cache ca-certificates tzdata

# Copy binaries from builder
COPY --from=backend-builder /app/bin/server /app/bin/server
COPY --from=backend-builder /app/bin/worker /app/bin/worker
COPY --from=backend-builder /app/bin/migrate /app/bin/migrate

# Copy migrations
COPY --from=backend-builder /app/migrations /app/migrations

# Copy frontend build
COPY --from=frontend-builder /app/streamarr-ui/dist /app/streamarr-ui/dist

# Copy channel files and configs
COPY channels/ /app/channels/

# Create directories
RUN mkdir -p /app/logs /app/cache /app/sessions

# Expose port
EXPOSE 8080

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
  CMD wget --no-verbose --tries=1 --spider http://localhost:8080/api/v1/health || exit 1

# Default command
CMD ["/app/bin/server"]
