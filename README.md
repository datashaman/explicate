# Explicate

A collaborative workspace for teams to write, organise, and publish posts with AI agent assistance.

## Requirements

- PHP 8.5
- Node.js
- Redis
- SQLite (default) or MySQL/PostgreSQL

## Setup

```bash
composer setup
```

This installs dependencies, copies `.env.example` to `.env`, generates an app key, runs migrations, and builds frontend assets.

## Development

```bash
composer dev
```

Starts the Laravel server, queue worker, Reverb WebSocket server, Pail log viewer, and Vite dev server concurrently.

## Testing

```bash
composer test
```

Runs Pint (code style check) then the full Pest test suite.

## GitHub OAuth

GitHub OAuth enables **Sign in with GitHub** on the login page and **Connect GitHub** on the profile settings page. A connected GitHub account grants access to private repositories when adding workspace repositories.

### Setup

1. Create a GitHub OAuth app at <https://github.com/settings/developers>
2. Set the **Authorization callback URL** to `{APP_URL}/auth/github/callback`
3. Add the credentials to `.env`:

```env
GITHUB_CLIENT_ID=your_client_id
GITHUB_CLIENT_SECRET=your_client_secret
GITHUB_REDIRECT_URI=https://your-app.com/auth/github/callback
```

> **Note:** The GitHub OAuth app requests the `repo` scope to list private repositories. This grants read/write access to all repositories the authorising user can access.

## Key Services

| Service | Purpose |
|---------|---------|
| Redis | Queue, cache, broadcasting |
| Reverb | WebSocket server for real-time updates |
| Horizon | Queue dashboard (`/horizon`) |
| Passport | API OAuth server |
| MCP | Model Context Protocol server |
