# Wiring the Ollama Provider into WP Agent

These are manual steps to be applied inside the private `wp-agent` repository.
No changes are required to this POC repo — only copy the one PHP file and make
the four targeted edits described below.

---

## Step 1 — Copy the provider class

```bash
cp ollama-provider/class-wp-agent-ai-ollama.php \
   wp-agent/includes/ai/class-wp-agent-ai-ollama.php
```

The file is a standalone class; it contains no autoloader entry and no
`require` statement. WP Agent's existing `includes/ai/` loader will pick it up
automatically if the directory is scanned on `plugins_loaded`.

---

## Step 2 — Register settings defaults

**File:** `wp-agent/includes/class-wp-agent-settings.php`

Locate the `$defaults` array (the one passed to `wp_parse_args`) and append
the four Ollama keys:

```php
'ollama_endpoint_url' => '',
'ollama_api_key'      => '',   // _api_key suffix → AES-256-CBC encrypted at rest
'ollama_model'        => 'llama3.1:8b',
'ollama_site_id'      => '',
```

> **Why `_api_key`?** WP Agent's settings class detects the `_api_key` suffix
> and transparently encrypts / decrypts the value with AES-256-CBC. No extra
> code is needed — the suffix alone triggers the behaviour.

---

## Step 3 — Register the service and add it to the AI router

**File:** `wp-agent/includes/class-wp-agent-container.php`

### 3a — Register the Ollama service

Inside the method that wires up AI provider services (look for the block that
registers `ai_openai`, `ai_anthropic`, `ai_gemini`, etc.) add:

```php
$this->register(
    'ai_ollama',
    fn( $c ) => new WP_Agent_AI_Ollama( $c->get('settings') )
);
```

### 3b — Add Ollama to the AI router

In the closure that builds the `ai_router` (or `ai_provider_router`) service,
add the Ollama entry alongside the other providers:

```php
'ollama' => $c->get('ai_ollama'),
```

---

## Step 4 — Expose the settings in the admin UI

**File:** `wp-agent/admin/class-wp-agent-admin.php`

### 4a — Provider `<select>` dropdowns

Both the "Primary provider" and "Fallback provider" `<select>` elements need a
new `<option>`. Add the following line to **each** of them:

```html
<option value="ollama">Ollama (Self-hosted)</option>
```

### 4b — Settings rows

Add four new rows in the Ollama settings section (create the section if it does
not exist, following the pattern used for OpenAI / Anthropic / Gemini):

| Label | Input type | `name` attribute | Notes |
|---|---|---|---|
| Endpoint URL | `text` | `ollama_endpoint_url` | Full URL, e.g. `https://ollama.example.com/api/chat` |
| API Key | `password` | `ollama_api_key` | Stored encrypted; reveal toggle recommended |
| Model | `text` | `ollama_model` | Placeholder / default: `llama3.1:8b` |
| Site ID | `text` | `ollama_site_id` | Forwarded as `X-Site-Id` header for nginx logs |

### 4c — Save handler

In the `save_settings()` (or equivalent) method, register the new keys so they
are included in the sanitise-and-persist pass:

```php
// Plain-text fields — add alongside the other $fields entries:
$fields[] = 'ollama_model';
$fields[] = 'ollama_endpoint_url';
$fields[] = 'ollama_site_id';

// Encrypted fields — add alongside the other $api_keys entries:
$api_keys[] = 'ollama_api_key';
```

---

## Verification checklist

After applying all four steps, confirm:

- [ ] WP Agent admin > AI Settings shows "Ollama (Self-hosted)" in both
      provider dropdowns.
- [ ] Saving Ollama credentials with valid values causes `is_configured()` to
      return `true` (check with `wp eval`).
- [ ] A non-streaming chat call (`chat()`) reaches the configured endpoint and
      returns a `content` string.
- [ ] A streaming call (`chat_stream()`) fires the `content_delta` callback
      for each NDJSON line that contains `message.content`.
- [ ] `estimate_cost()` always returns `0.0` regardless of token counts.
- [ ] The `Authorization: Bearer …` and `X-Site-Id` headers appear in the
      nginx access log for the Ollama reverse-proxy vhost.
