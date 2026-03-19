# How I Built a Private AI Assistant for WordPress That Never Sends Data to OpenAI

I've been building WordPress plugins for over a decade. Last month, a client asked me a question I didn't have a good answer to: "Our members are going to be talking to this AI assistant about their health history. Can you guarantee that OpenAI never sees any of that?"

I could not. That started a three-week build that I want to share with you.

The result is WP Private AI — an open-source proof of concept that adds a fully functional AI chat assistant to any WordPress site, where every inference call happens on your own server. No API keys sent to OpenAI. No data processed by Anthropic. No conversation logs stored anywhere except your own database.

Here's exactly how it works, why I built it the way I did, and what I learned.

---

## The Problem with Cloud AI in WordPress

The WordPress ecosystem has embraced AI quickly. There are now dozens of plugins that let you add an AI chatbot to your site. Almost all of them work the same way: the user sends a message, the plugin forwards it to OpenAI or Anthropic's API, gets a response, and displays it.

That works fine for many use cases. But it creates a serious problem for sites where users share personal information.

Consider a membership site where members discuss sensitive topics — health conditions, financial situations, legal matters. Every message they send to the AI assistant gets transmitted to a US company's servers, processed by their model, and potentially logged for safety monitoring or model improvement.

Under GDPR Article 28, any company that processes personal data on your behalf is a data processor. Using OpenAI as your AI backend means OpenAI is a data processor for your site. That relationship requires a signed Data Processing Agreement, documented legal basis for the cross-border transfer of EU user data to US servers, and disclosure in your privacy policy.

Most WordPress site operators skip all of this. This is not a theoretical risk — EU data protection authorities have issued substantial fines for exactly this pattern.

There's a simpler answer. What if the AI model ran on your server?

---

## The Architecture

The key insight is that modern open-source models are good enough for site-specific Q&A tasks. We don't need GPT-4 to tell a member what their last booking was, how much they've donated, or whether their license is still active. We need a model that can reliably call a WordPress function, receive structured data back, and describe it accurately.

Ollama makes this practical. It's a tool that lets you run open-source models locally — `llama3.1:8b`, `qwen2.5:14b`, and others — as a simple API server. The API is compatible with the OpenAI chat format. A chat request looks like this:

```bash
curl http://localhost:11434/api/chat \
  -H "Content-Type: application/json" \
  -d '{
    "model": "llama3.1:8b",
    "messages": [{"role": "user", "content": "Who are you?"}],
    "stream": false
  }'
```

You get back a JSON response with the model's reply. The entire call happens on `127.0.0.1` — the loopback address. It never touches an external network.

The architecture for WP Private AI looks like this:

```
[User browser]
      │
      ▼ HTTPS
[WordPress REST API]
      │
      ▼ wp_remote_post() to 127.0.0.1:11434
[Ollama — llama3.1:8b]
      │
      ▼ NDJSON response
[WordPress SSE stream]
      │
      ▼
[User browser — streaming tokens]
```

The user's message goes in. It never leaves the server. The model's response comes back. The browser receives it as a server-sent event stream, so the user sees tokens appear as they're generated — the same "typing" effect you get from ChatGPT.

---

## What Makes It Actually Useful: The WP Abilities API

An AI that can only talk in generalities isn't useful on a WordPress site. What makes it useful is when it can answer questions using real data from your database.

WordPress 6.9 introduced the WP Abilities API — a way to register callable functions that the AI can invoke during a conversation. When a user asks "What's my last purchase?", the AI doesn't guess. It calls the `edd/get-user-orders` ability, which runs a real database query, and answers using the actual data.

Registering an ability looks like this:

```php
wp_register_ability(
    'edd/get-user-orders',
    [
        'label'       => 'Get user orders',
        'description' => 'Returns recent purchase history for the current user',
        'schema'      => [
            'type'       => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Max results to return'],
            ],
        ],
        'permission_callback' => function(): bool {
            return is_user_logged_in();
        },
        'execute_callback'    => function( array $input ): array {
            $orders = edd_get_orders( [
                'customer_id' => edd_get_customer_id_by_user_id( get_current_user_id() ),
                'number'      => $input['limit'] ?? 5,
            ] );
            // Return structured data the model can describe
            return array_map( fn($o) => [
                'id'     => $o->id,
                'total'  => edd_currency_filter( edd_format_amount( $o->total ) ),
                'status' => $o->status,
                'date'   => date_i18n( get_option('date_format'), strtotime($o->date_created) ),
            ], $orders );
        },
    ]
);
```

The `permission_callback` runs before `execute_callback`. If the current user doesn't have permission — because they're not logged in, because the data belongs to someone else, or because they don't have the right role — the ability returns a `WP_Error` and the AI is told the ability is not available. The data is never fetched.

This is important: the access control is in PHP, not in the AI. The AI can't talk its way past a `permission_callback`. The function simply doesn't run.

---

## The Scanner: Generating Adapters for Any Plugin

Writing an ability adapter for every WordPress plugin manually would be impractical. Instead, I built a scanner that analyzes a plugin's PHP codebase and generates a starting-point adapter automatically.

The scanner does four things:

1. **Finds REST routes** — parses `register_rest_route()` calls to discover the plugin's API surface
2. **Finds Custom Post Types** — parses `register_post_type()` calls to understand the plugin's data model
3. **Finds CRUD methods** — scans for function patterns like `get_*`, `create_*`, `update_*`, `delete_*`
4. **Generates abilities** — maps each finding to a `wp_register_ability()` call with a stub `execute_callback`

I ran it against 10 popular WordPress plugins. Here's what it found:

| Plugin | REST Routes | CPTs | Abilities Generated |
|---|---|---|---|
| Fluent Forms | 12 | 0 | 8 |
| FluentCRM | 31 | 2 | 18 |
| WPForms | 8 | 1 | 6 |
| Groundhogg | 24 | 3 | 15 |
| Easy Digital Downloads | 19 | 2 | 12 |
| GiveWP | 16 | 1 | 10 |
| AffiliateWP | — (paid) | — | 6 (manual) |
| LifterLMS | 22 | 4 | 14 |
| Fluent Booking | 14 | 1 | 9 |
| WP Job Manager | 11 | 1 | 7 |

For AffiliateWP — which is a paid plugin and can't be scanned without a license — I wrote the adapter manually using their public REST API documentation. This demonstrates the concept works for paid plugins too: you don't need the source code if the API is documented.

After the scanner runs, you copy the generated file to `wp-content/mu-plugins/`, implement the `execute_callback` functions using the target plugin's PHP API, and the AI can start answering questions about that plugin's data. No activation needed — mu-plugins run automatically.

---

## The Problem That Almost Killed the Proof of Concept

About two weeks in, I had the chat widget working. I asked the AI: "How many members does this site have?"

It replied: "Your site currently has over 500,000 registered members."

The test site had 27 members.

This is the hallucination problem. The 8B model, when it doesn't have real data, fills in the gap with a plausible-sounding number. 500,000 members is a plausible number for a large community site. The model doesn't know it's wrong because it doesn't know the actual number.

If first impressions are wrong, people won't trust the tool. And they'd be right not to.

The fix was to build a site indexer that runs at plugin activation time and caches the real facts from the WordPress database:

```php
class WP_Agent_Site_Indexer {
    const TRANSIENT_KEY = 'wp_agent_site_index';
    const TTL           = 6 * HOUR_IN_SECONDS;

    public static function refresh(): array {
        global $wpdb;

        $data = [
            'site_name'    => get_bloginfo('name'),
            'wp_version'   => get_bloginfo('version'),
            'active_theme' => wp_get_theme()->get('Name'),
            'indexed_at'   => current_time('mysql'),
        ];

        // Real member count from the database
        $data['total_users'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");

        // BuddyPress member count if BP is active
        if ( function_exists('bp_get_total_member_count') ) {
            $data['buddypress']['total_members'] = bp_get_total_member_count();
        }

        // Post counts by type
        $data['posts_published'] = (int) wp_count_posts()->publish;
        $data['pages_published'] = (int) wp_count_posts('page')->publish;

        // Active plugin names
        $active = get_option('active_plugins', []);
        $data['active_plugins'] = array_map(function($plugin) {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin, false, false);
            return $plugin_data['Name'] ?: $plugin;
        }, $active);

        set_transient( self::TRANSIENT_KEY, $data, self::TTL );
        return $data;
    }
}
```

These facts are injected into the system prompt before every conversation, labeled as authoritative:

```
## Site Facts (verified from WordPress database — these are exact numbers, never contradict them)
- Site name: My Community Site
- Total registered members: 27
- Active plugins: BuddyPress, Easy Digital Downloads, Fluent Forms
- WordPress version: 6.7
- Active theme: BuddyX
- Data verified at: 2025-11-14 09:23:11
```

The key phrase is "never contradict them." Without that instruction, a small model might still override the injected data with its training priors. With it, asking the same question now gets: "Your site currently has 27 registered members."

The indexer refreshes every 6 hours via a transient and busts immediately whenever a plugin is activated or deactivated. Plugin activations change the site's capability set — the AI needs to know when WooCommerce goes live, for example.

---

## The Chat Widget

The user-facing part is a side panel that slides in from the right when a floating action button is clicked. It's built with vanilla JavaScript and a WordPress REST endpoint — no React, no Vue, no heavy frontend framework.

The widget renders markdown properly: `**bold**` becomes bold text, `\`code\`` becomes inline code, numbered lists become `<ol>` elements. Streaming works via the browser's `EventSource` API connecting to a server-sent events endpoint.

Here's what the conversation flow looks like technically:

1. User opens the panel, types a message
2. JavaScript sends a POST to `/wp-json/wp-agent/v1/chat/stream` with the message and conversation ID
3. PHP builds the system prompt, fetches conversation history from the database, calls the AI provider
4. If the model wants to call an ability, WP Agent executes it and sends the result back to the model
5. The model's final response streams back as SSE tokens
6. JavaScript appends each token to the chat bubble in real time

The entire conversation is stored in WordPress database tables: `wp_agent_conversations` and `wp_agent_messages`. GDPR Article 17 erasure is a single `DELETE` query on those tables. WP Agent includes hooks into WordPress's built-in personal data export and erasure tools.

---

## Multi-Site Deployment

When I said this is for 10 WordPress sites, I meant it. Running a separate server for each site would be expensive. The shared server model runs one Ollama instance on a DigitalOcean 16 GB droplet ($96/month) behind an nginx gateway with per-site Bearer token authentication.

Each WordPress site gets a unique token:
```bash
openssl rand -hex 32
# → a3f9b2c1d4e5...
```

The nginx configuration maps each token to a valid/invalid flag and enforces rate limiting per token:

```nginx
map $http_authorization $api_key_valid {
    default                           "0";
    "Bearer site-a-token-here"        "1";
    "Bearer site-b-token-here"        "1";
}

limit_req_zone $http_authorization zone=per_site:10m rate=10r/m;

server {
    listen 443 ssl http2;
    server_name ollama.yourdomain.com;

    location /api/chat {
        if ($http_authorization = "")  { return 401; }
        if ($api_key_valid = "0")      { return 403; }
        limit_req zone=per_site burst=20 nodelay;
        proxy_pass         http://127.0.0.1:11434;
        proxy_buffering    off;
        proxy_read_timeout 120s;
    }
}
```

Rate limiting prevents any single site from monopolizing the shared inference server. Burst of 20 allows short spikes — a member having an active conversation — without triggering a 429.

When a new site needs access:
1. Generate a token on the server
2. Add one line to the nginx map
3. Reload nginx (no downtime)
4. Enter the token in WP Agent settings

When a site loses access:
1. Remove the token line
2. Reload nginx

No Ollama restart. No WordPress changes. Immediate effect.

---

## The Fallback

The shared Ollama server will occasionally be down — maintenance, memory issues, a model loading error. Rather than showing the user an error, WP Agent automatically falls back to a cloud provider.

The AI Router checks the primary provider (Ollama), and if it returns a `WP_Error`, it immediately tries the configured fallback (Google Gemini Flash, for example). From the user's perspective, the response is slightly slower. They don't see an error.

Gemini Flash costs $0.10/$0.40 per million tokens — extremely cheap for fallback-only usage. At 10 sites with light usage, the monthly fallback bill would be a few dollars at most, only on days when the VPS has issues.

This means the $96/month droplet is not a single point of failure. It's the primary path. Cloud is the safety net.

---

## Access Control: Four Layers

One thing I was careful about: the AI should never be able to talk its way past the access control system.

**Layer 1 — WordPress role check**: The chat widget isn't rendered for logged-out users. No one can send a message without being authenticated to WordPress.

**Layer 2 — Ability permission callbacks**: Every registered ability has a `permission_callback` that runs in PHP before the data is fetched. If the user doesn't have permission, the callback returns `false` and the ability returns a `WP_Error`. The model is told the ability is not available — the data is never touched.

**Layer 3 — System prompt scope restrictions**: The system prompt tells the model what topics it's allowed to address. A membership site assistant won't answer questions about coding. An e-commerce assistant won't offer competitor pricing advice. The model is instructed to redirect out-of-scope questions.

**Layer 4 — nginx Bearer token**: At the infrastructure level, only requests with a valid site-specific Bearer token reach Ollama at all. An unauthenticated request gets a 401 before the query is ever processed.

These four layers are independent. A failure at Layer 3 (the model decides to go off-script) doesn't bypass Layer 2 (the PHP function still checks permissions before running). The system is secure even if the model does something unexpected.

---

## What the Proof of Concept Demonstrates

After three weeks of build, here's what this proof of concept shows is possible:

**For GDPR-sensitive sites**, you can add an AI assistant that never sends user data to a third-party processor. The legal analysis simplifies dramatically: no DPA required, no cross-border transfer analysis, no provider disclosure in your privacy policy.

**For community sites**, the AI can answer real questions about real member data: "What courses am I enrolled in?", "Show my recent donations", "What's the status of my job application?". These aren't guesses — they're database queries wrapped in natural language.

**For agencies**, the shared server model means one VPS can serve 10+ client sites. At $96/month split across clients, the cost is negligible compared to the per-token costs of cloud AI at moderate usage volumes.

**For plugin developers**, the WP Abilities API plus the scanner gives you a path to add AI capability to any plugin without modifying it. Drop the generated adapter in `mu-plugins`, implement the execute callbacks, and any AI-enabled WordPress site can query your plugin's data.

---

## Why Small Models Work for This Use Case

A question I get when showing this to other developers: "Won't a smaller model like llama3.1:8b get things wrong? Shouldn't we use GPT-4?"

For the specific use case of site-specific Q&A with structured function calls, smaller models work well. Here's why.

The model's job in this system is narrow: read the system prompt, understand what the user asked, decide whether to call an ability, call it with the right parameters, receive the structured result, and describe it in natural language.

It's not being asked to write essays, reason about complex multi-step problems, or handle ambiguous creative tasks. It's being asked to do something closer to: "The user asked about their bookings. I have a `fluent-booking/get-upcoming-bookings` ability. Call it. Describe the result."

The site indexer makes this even easier by providing exact numbers in the system prompt. The model doesn't have to reason about "how many members does this site probably have?" — it's told. The job becomes description, not inference.

Where small models still struggle is with very complex multi-turn conversations that require holding a lot of context, or with novel ability combinations they haven't seen examples of. For those cases, the fallback to a cloud model (which handles the harder cases) provides a graceful degradation path rather than a hard failure.

The practical result is that for 80-90% of common questions on a WordPress site — "what are my recent purchases?", "am I enrolled in this course?", "when is my next booking?" — the 8B model gets it right and responds in 5-15 seconds. That's good enough for production use.

---

## What's Next

This is a proof of concept, not a finished product. The things I'd build next:

**Better model support** — `qwen2.5:14b` has significantly better tool-calling quality than `llama3.1:8b` and fits in 32 GB RAM. For sites where accuracy matters most, it's worth the upgrade.

**Streaming ability results** — Currently the model waits for the entire ability response before starting to generate. For slow queries, showing "Checking your orders..." while the database query runs would improve the experience.

**Conversation memory** — The current implementation sends the full conversation history with every request. For long conversations, this grows large. A summarization pass after N turns would keep the context window manageable.

**Admin dashboard** — Site owners should be able to see which abilities are registered, how often they're called, and whether any are returning errors. Right now this requires digging in logs.

**More plugin adapters** — The scanner ran against 10 plugins. The WordPress.org plugin directory has 60,000+. The scanner could run against all of them and generate a public library of adapters.

---

## The Code

Everything is on GitHub: [github.com/wbcomdesigns/wp-private-ai](https://github.com/wbcomdesigns/wp-private-ai)

The repository includes:
- The WP Agent plugin (the WordPress plugin that powers the chat widget)
- The scanner (`scanner/wp-plugin-scanner.py`)
- Generated adapters for 10 plugins in `poc/`
- Docker Compose file for the per-site container model
- nginx configuration for the shared server model
- Documentation for both deployment models

If you're running a WordPress site where user privacy matters — a health community, a financial forum, a legal advice membership — I'd encourage you to try it. The setup is about two hours of work for the VPS configuration and another hour to connect your first WordPress site.

Questions or contributions welcome on GitHub.
