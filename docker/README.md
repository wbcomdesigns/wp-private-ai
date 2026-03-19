# Docker Container for WP Private AI (Deployment Model 2)

This Docker Compose configuration deploys a standalone Ollama container for a single WordPress site. Each site runs its own container with complete data isolation, making it ideal for agencies, SaaS platforms, or individual sites requiring full separation.

## Server Requirements

- **Minimum spec**: 16 GB RAM, 4 vCPU
- **Docker & Docker Compose**: Latest versions
- **Storage**: 20 GB SSD for model files
- **OS**: Ubuntu 20.04 LTS or later, Debian 11+, or macOS with Docker Desktop

## First-Time Setup

### 1. Start the Container

Navigate to the directory containing `docker-compose.yml` and run:

```bash
docker compose up -d
```

Verify the container is running:

```bash
docker compose ps
```

Should show: `wp-private-ai   ollama/ollama:latest   Up...`

### 2. Pull the LLM Model

Pull the default model (llama3.1:8b, ~5 GB):

```bash
docker compose exec ollama ollama pull llama3.1:8b
```

This downloads and caches the model inside the `ollama_models` volume. Only needs to run once.

Verify the model is installed:

```bash
docker compose exec ollama ollama list
```

Should show:
```
NAME             ID              SIZE    MODIFIED
llama3.1:8b      37...           4.7GB   4 minutes ago
```

### 3. Configure WordPress

In your WordPress WP Agent settings:

- **Endpoint**: `http://localhost:11434/api/chat`
- **Model**: `llama3.1:8b`
- **Port**: `11434` (no TLS — localhost is secure)

If WordPress runs in a separate Docker network, use the service name instead:
- **Endpoint**: `http://ollama:11434/api/chat`

## Configuration

### Memory & Performance Tuning

The `docker-compose.yml` is pre-configured with:

```yaml
OLLAMA_NUM_PARALLEL: "1"    # Process one request at a time
OLLAMA_KEEP_ALIVE: "5m"     # Unload model after 5 minutes idle
deploy:
  resources:
    limits:
      memory: 12G            # Reserve 12 GB for container
```

For smaller servers (8 GB RAM):
- Change `OLLAMA_KEEP_ALIVE: "2m"` (unload sooner)
- Reduce `memory: 8G`
- Use smaller model: `ollama pull mistral:7b` (3 GB)

For higher throughput (multiple concurrent requests):
- Change `OLLAMA_NUM_PARALLEL: "2"` or `"4"`
- Increase memory: `memory: 16G`

### Port Binding

Current config binds only to localhost:

```yaml
ports:
  - "127.0.0.1:11434:11434"   # localhost only
```

This is secure — the container is not reachable from the network. For HTTPS with external access, use nginx as a reverse proxy (see below).

## Upgrading the Model

### Switch to Qwen2.5:14b (Better Function Calling)

Qwen2.5:14b is more capable with function calling and structured outputs (~9 GB, requires 32 GB RAM minimum).

1. Pull the new model:
```bash
docker compose exec ollama ollama pull qwen2.5:14b
```

2. In WordPress WP Agent settings, change **Model** to `qwen2.5:14b`

3. (Optional) Remove old model to free space:
```bash
docker compose exec ollama ollama rm llama3.1:8b
```

### Other Model Options

- **Mistral:7b** (3 GB) — Lightweight, good for basic queries
- **Llama2:13b** (7 GB) — Medium size, more capable than 8b
- **Neural-chat:7b** (4 GB) — Optimized for conversation
- **Phi:2.7b** (1.6 GB) — Ultra-lightweight, good for edge devices

Pull any model:
```bash
docker compose exec ollama ollama pull <model_name>
```

## HTTPS with External Access

For production deployments with external access, put nginx in front:

```nginx
upstream ollama_backend {
    server 127.0.0.1:11434;
}

server {
    listen 443 ssl http2;
    server_name ai.example.com;

    location /api/chat {
        proxy_pass         http://ollama_backend;
        proxy_http_version 1.1;
        proxy_set_header   Connection "";
        proxy_read_timeout 120s;
        proxy_buffering    off;
        proxy_cache        off;
    }
}
```

Then configure WordPress to use `https://ai.example.com/api/chat`.

## Data Isolation

Each container has its own:
- **Volume mount** (`ollama_models:/root/.ollama`) — models, chat history, configuration
- **Network isolation** — no access to other containers by default
- **Resource limits** — memory capped at 12 GB, preventing resource hogging

To back up model data:

```bash
docker compose exec ollama tar czf /tmp/ollama-backup.tar.gz /root/.ollama
docker cp wp-private-ai:/tmp/ollama-backup.tar.gz ./ollama-backup.tar.gz
```

To restore:

```bash
docker cp ollama-backup.tar.gz wp-private-ai:/tmp/
docker compose exec ollama tar xzf /tmp/ollama-backup.tar.gz -C /
```

## Daily Operations

### Check Status

```bash
docker compose ps
docker compose logs ollama -f
```

### Monitor Resource Usage

```bash
docker stats wp-private-ai
```

Shows real-time CPU, memory, network, and I/O.

### View Running Models

```bash
docker compose exec ollama ollama ps
```

Displays currently loaded models and their memory footprint.

### Restart the Container

```bash
docker compose restart ollama
```

### Stop the Container

```bash
docker compose down
```

Models are preserved in the `ollama_models` volume. To delete everything:

```bash
docker compose down -v
```

## Troubleshooting

### "docker: command not found"

Install Docker Desktop (macOS/Windows) or Docker Engine (Linux):

```bash
curl -fsSL https://get.docker.com -o get-docker.sh | sudo sh
sudo usermod -aG docker $USER
newgrp docker
```

### Container exits immediately

Check logs:
```bash
docker compose logs ollama
```

Common causes:
- Insufficient disk space (models need 20+ GB)
- Memory allocation failed
- Port 11434 already in use

### Model download hangs

- Check internet connection
- Verify disk space: `docker compose exec ollama df -h`
- Retry pull: `docker compose exec ollama ollama pull llama3.1:8b`

### "Connection refused" from WordPress

- Verify container is running: `docker compose ps`
- Check port binding: `docker compose port ollama 11434`
- If WordPress is in Docker, use service name: `ollama:11434` instead of `127.0.0.1:11434`

### Out of memory (OOM) errors

Reduce model size or memory limit:
```yaml
OLLAMA_KEEP_ALIVE: "2m"      # Unload faster
memory: 8G                    # Lower cap
```

Or switch to lighter model:
```bash
docker compose exec ollama ollama pull mistral:7b
```

## Performance Tuning

### For Faster Inference

1. Use GPU acceleration (if available):
```yaml
# Add to docker-compose.yml
deploy:
  resources:
    reservations:
      devices:
        - driver: nvidia
          count: 1
          capabilities: [gpu]
```

2. Increase parallel processing:
```yaml
OLLAMA_NUM_PARALLEL: "2"
```

### For Lower Latency

1. Switch to smaller model: `mistral:7b` or `phi:2.7b`
2. Increase memory to keep model in RAM longer:
```yaml
OLLAMA_KEEP_ALIVE: "30m"
```

### For Multi-Site Setup

If running multiple WordPress instances, deploy multiple containers:

```bash
docker compose -f docker-compose.yml -p site1 up -d
docker compose -f docker-compose.yml -p site2 up -d
```

Each project gets its own container and ports:
- site1: `127.0.0.1:11434`
- site2: `127.0.0.1:11435` (modify `ports` in compose file)

## Architecture Notes

- **Complete isolation**: Each container has its own model, cache, and filesystem
- **Persistent storage**: Models stored in `ollama_models` volume survive container restarts
- **Memory management**: `OLLAMA_KEEP_ALIVE` unloads idle models to prevent RAM leaks
- **Rate limiting**: Sequential processing (`OLLAMA_NUM_PARALLEL: 1`) prevents OOM on small systems
- **Secure by default**: Listens only on localhost; no network exposure without explicit nginx proxy
