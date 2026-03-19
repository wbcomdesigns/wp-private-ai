# nginx Gateway for WP Private AI (Deployment Model 1)

This nginx configuration implements a shared gateway for multiple WordPress sites accessing a single Ollama instance. Each site authenticates via Bearer token, with per-site rate limiting and proxy to a local Ollama service.

## Server Requirements

- **Minimum spec**: 16 GB RAM, 4 vCPU
- **Storage**: 20 GB for model files (llama3.1:8b ~5 GB)
- **OS**: Ubuntu 20.04 LTS or later, Debian 11+
- **nginx**: 1.24+ (for http2 support)

## Installation

### 1. Install Ollama

```bash
curl -fsSL https://ollama.com/install.sh | sh
```

This installs the `ollama` command and creates a systemd service.

### 2. Configure Ollama to Listen Locally Only

Edit `/etc/systemd/system/ollama.service` and add:

```ini
[Service]
Environment="OLLAMA_HOST=127.0.0.1:11434"
```

Then reload and restart:

```bash
sudo systemctl daemon-reload
sudo systemctl restart ollama
```

Verify it's listening only on localhost:

```bash
sudo netstat -tlnp | grep ollama
```

Should show: `127.0.0.1:11434`

### 3. Pull a Model

```bash
ollama pull llama3.1:8b
```

This downloads ~5 GB. Check status:

```bash
ollama list
```

### 4. Install and Configure nginx

Install nginx (if not already present):

```bash
sudo apt update
sudo apt install -y nginx
sudo systemctl enable nginx
```

Copy `ollama-gateway.conf` to `/etc/nginx/sites-available/`:

```bash
sudo cp ollama-gateway.conf /etc/nginx/sites-available/
```

Edit the file to replace:
- `ollama.yourdomain.com` → your actual domain
- `REPLACE_SITE_1_TOKEN`, `REPLACE_SITE_2_TOKEN` → real tokens

Test nginx configuration:

```bash
sudo nginx -t
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/ollama-gateway /etc/nginx/sites-enabled/
sudo systemctl reload nginx
```

### 5. Set Up TLS with Certbot

Install certbot:

```bash
sudo apt install -y certbot python3-certbot-nginx
```

Generate certificate:

```bash
sudo certbot --nginx -d ollama.yourdomain.com
```

Certbot automatically updates nginx config with SSL directives and sets up auto-renewal.

### 6. Configure Firewall

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

## Generating Per-Site Tokens

For each WordPress site, generate a unique 64-character hex token:

```bash
openssl rand -hex 32
```

Example output:
```
a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2
```

Add to the `map $http_authorization $api_key_valid` block in nginx config:

```nginx
"Bearer a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2" "1";
```

Reload nginx after adding tokens:

```bash
sudo systemctl reload nginx
```

## Adding a New WordPress Site

1. Generate a new token: `openssl rand -hex 32`
2. Edit `/etc/nginx/sites-available/ollama-gateway`
3. Add the token to the `map $http_authorization $api_key_valid` block
4. Test nginx: `sudo nginx -t`
5. Reload: `sudo systemctl reload nginx`
6. In WordPress, set WP Agent endpoint to `https://ollama.yourdomain.com/api/chat`
7. Set WP Agent authorization token to the generated token

## Testing the Gateway

### Without Authentication (should fail)

```bash
curl -X POST https://ollama.yourdomain.com/api/chat \
  -H "Content-Type: application/json" \
  -d '{"model":"llama3.1:8b","messages":[{"role":"user","content":"hello"}]}'
```

Expected response: `{"error":"Authorization header required"}` (401)

### With Valid Token

```bash
SITE_TOKEN="a1b2c3d4..."
curl -X POST https://ollama.yourdomain.com/api/chat \
  -H "Authorization: Bearer $SITE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"model":"llama3.1:8b","messages":[{"role":"user","content":"hello"}]}'
```

Should stream model responses (NDJSON format).

### With Invalid Token (should fail)

```bash
curl -X POST https://ollama.yourdomain.com/api/chat \
  -H "Authorization: Bearer invalid_token_here" \
  -H "Content-Type: application/json" \
  -d '{"model":"llama3.1:8b","messages":[{"role":"user","content":"hello"}]}'
```

Expected response: `{"error":"Invalid or unknown API key"}` (403)

## Rate Limiting

The gateway enforces rate limits per site (per Bearer token):
- **Sustained rate**: 10 requests/minute
- **Burst**: up to 20 requests allowed
- **Status on limit exceeded**: HTTP 429

Adjust `rate=10r/m` and `burst=20` in the nginx config if needed.

## Monitoring

Check Ollama performance:

```bash
ollama ps
```

Shows current running models and memory usage.

View nginx access/error logs:

```bash
sudo tail -f /var/log/nginx/access.log
sudo tail -f /var/log/nginx/error.log
```

## Upgrading Models

To replace llama3.1:8b with a larger model (e.g., llama2:13b):

```bash
ollama pull llama2:13b
ollama rm llama3.1:8b  # optional, to free space
```

Then instruct WordPress to switch to the new model name in WP Agent settings.

## Troubleshooting

**Ollama service won't start:**
- Check service logs: `sudo journalctl -u ollama -n 50`
- Ensure systemd config has `OLLAMA_HOST=127.0.0.1:11434`

**nginx won't reload:**
- Test config: `sudo nginx -t`
- Check for syntax errors in `ollama-gateway.conf`

**"Connection refused" errors from WordPress:**
- Verify Ollama is listening: `sudo netstat -tlnp | grep 11434`
- Check firewall: `sudo ufw status`

**Token not working:**
- Ensure token is in `map $http_authorization $api_key_valid` block
- Run `sudo nginx -t && sudo systemctl reload nginx`
- Test with curl (see Testing section above)

## Architecture Notes

- **Single Ollama instance**: All sites share one model in RAM
- **Bearer token isolation**: Each site has unique token; nginx validates per-request
- **Rate limiting**: Per-site token prevents any one site from monopolizing resources
- **HTTPS only**: TLS required for token security; HTTP redirects to HTTPS
- **Localhost binding**: Ollama only listens on 127.0.0.1; nginx is the only client
