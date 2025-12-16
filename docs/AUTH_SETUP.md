# StreamArr Pro Authentication Setup

## Overview

StreamArr Pro uses JWT-based authentication with a modern login page. This provides secure access control for your media management system.

## Architecture

### Backend
- **JWT Tokens**: JSON Web Tokens for stateless authentication
- **Password Hashing**: bcrypt for secure password storage
- **Session Middleware**: Validates tokens on all API requests
- **Token Expiration**: 24 hours (30 days with "Remember Me")

### Frontend
- **Login Page**: Clean, modern UI similar to Plex/Jellyfin
- **Protected Routes**: Automatic redirect to login if not authenticated
- **Axios Interceptor**: Automatically includes auth token in all API requests
- **401 Handler**: Clears session and redirects to login on authentication failure

## Initial Setup

### 1. Deploy the System

```bash
# On your server
cd /path/to/StreamArrPro
docker-compose up -d --build
```

### 2. Create First Admin Account

When you first access StreamArr Pro, you'll see the setup screen.

#### Option A: Using the Web UI (Recommended)

1. Navigate to your StreamArr Pro URL
2. You'll see "Create your admin account to get started"
3. Enter a username and password
4. Click "Create Admin Account"

#### Option B: Using the API

```bash
curl -X POST http://YOUR_SERVER/api/v1/auth/setup \
  -H "Content-Type: application/json" \
  -d '{
    "username": "admin",
    "password": "YourSecurePassword123!"
  }'
```

## API Endpoints

### Public Endpoints (No Auth Required)
- `GET /api/v1/auth/status` - Check if setup is required
- `POST /api/v1/auth/setup` - Create first admin user (only works when no users exist)
- `POST /api/v1/auth/login` - Authenticate and receive JWT token
- `GET /api/v1/auth/verify` - Verify token validity
- `GET /api/v1/health` - Health check

### Protected Endpoints
All other `/api/v1/*` endpoints require a valid JWT token in the Authorization header:

```
Authorization: Bearer <your_jwt_token>
```

## Login Flow

1. User submits credentials to `/api/v1/auth/login`
2. Server validates credentials against bcrypt hash
3. Server generates JWT token with user claims
4. Token is returned and stored in localStorage
5. All subsequent API requests include the token

## Token Structure

```json
{
  "user_id": 1,
  "username": "admin",
  "is_admin": true,
  "exp": 1702828800
}
```

## Password Requirements

- Minimum 6 characters
- Hashed with bcrypt (cost factor 10)

## Troubleshooting

### "Unauthorized - No token provided"
- Ensure you're logged in
- Check that the token is stored in localStorage
- Clear cookies/storage and log in again

### "Setup already completed"
- Admin account already exists
- Use the login form instead

### Token Expired
- Tokens expire after 24 hours (or 30 days with "Remember Me")
- Log in again to get a new token

## Security Best Practices

1. Use a strong password for admin account
2. Deploy behind HTTPS (use nginx/reverse proxy)
3. Consider IP whitelisting for production
4. Regularly rotate passwords
