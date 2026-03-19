# Access Control

WP Private AI has four layers of access control. Each layer is independent — a failure at one layer does not bypass the others.

```
Layer 1: WordPress role check       → who sees the chat widget
Layer 2: permission_callback        → who can call each ability
Layer 3: System prompt restrictions → what topics the AI addresses
Layer 4: nginx Bearer token         → which sites can reach the Ollama server
```

---

## Layer 1: WordPress Role Mapping

The chat widget is only rendered for logged-in users by default:

```php
// class-wp-agent-plugin.php
public function enqueue_frontend_assets(): void {
    if ( ! is_user_logged_in() ) {
        return;
    }
    // ...
}
```

You can restrict further by role using the `wp_agent_can_use_chat` filter:

```php
add_filter( 'wp_agent_can_use_chat', function( bool $can, int $user_id ): bool {
    // Only allow subscribers and above — exclude pending members
    $user = get_userdata( $user_id );
    return in_array( 'subscriber', (array) $user->roles, true )
        || in_array( 'administrator', (array) $user->roles, true );
}, 10, 2 );
```

For BuddyPress sites, you might restrict to active members only:

```php
add_filter( 'wp_agent_can_use_chat', function( bool $can, int $user_id ): bool {
    if ( function_exists( 'bp_get_member_type' ) ) {
        // Only allow members who have completed profile setup
        return (bool) xprofile_get_field_data( 'Full Name', $user_id );
    }
    return $can;
}, 10, 2 );
```

---

## Layer 2: Ability-Level Permissions

Every registered ability has a `permission_callback` that runs before `execute_callback`. The generated adapters default to `is_user_logged_in()`:

```php
wp_register_ability(
    'wp-job-manager/list-jobs',
    [
        'execute_callback'    => function( array $input ): array { /* ... */ },
        'permission_callback' => function(): bool {
            return is_user_logged_in();
        },
    ]
);
```

For admin-only abilities, use `current_user_can()`:

```php
'permission_callback' => function(): bool {
    return current_user_can( 'manage_options' );
},
```

For member-owns-data abilities (e.g. viewing own bookings only):

```php
'permission_callback' => function(): bool {
    return is_user_logged_in();
},
'execute_callback'    => function( array $input ): array {
    $user_id = get_current_user_id();
    // Always scope queries to the current user — never expose other users' data
    return get_posts( [
        'post_type'   => 'booking',
        'author'      => $user_id,
        'numberposts' => $input['limit'] ?? 10,
    ] );
},
```

The `permission_callback` runs at the WordPress layer — before the AI ever sees the data. If `permission_callback` returns `false`, the ability returns a `WP_Error` and the AI is told the ability is not available.

---

## Layer 3: Topic Restrictions via System Prompt

The system prompt in `class-wp-agent-system-prompt.php` already tells the model:

> "Use the available tools/abilities to look up real data. Never guess or fabricate information."
> "Respect privacy: only show users their own data unless they have admin privileges."

You can add site-specific restrictions to `WP_Agent_System_Prompt::build()`:

```php
// Example: membership site — restrict to member topics only
$prompt .= <<<RESTRICT

## Scope
Only answer questions about this site: membership, bookings, courses, events, and account management.
Do not answer general knowledge questions, coding questions, or anything unrelated to this site.
If asked about other topics, politely redirect: "I'm here to help with your membership and bookings on [site name]."

RESTRICT;
```

```php
// Example: e-commerce site — restrict to orders and products
$prompt .= <<<RESTRICT

## Scope
Only answer questions about orders, products, shipping, and account on this store.
Never provide pricing advice for competitor stores.
For refund requests, direct users to: [refund policy URL]

RESTRICT;
```

The model is instructed to stay within scope. Combined with `permission_callback` (which prevents ability execution for out-of-scope requests), this provides defence in depth.

---

## Layer 4: nginx Bearer Token Authentication

When using the shared VPS deployment model, the nginx gateway enforces per-site authentication before any request reaches Ollama.

Each WordPress site has a unique token generated with:
```bash
openssl rand -hex 32
```

The token is stored in:
- nginx `map` block on the VPS (as `Bearer <token>` → `1`)
- WP Agent settings on the WordPress site (as "Ollama API Key")

A request without a valid token never reaches Ollama:

```nginx
location /api/chat {
    if ($http_authorization = "") {
        return 401 '{"error":"Authorization header required"}';
    }
    if ($api_key_valid = "0") {
        return 403 '{"error":"Invalid or unknown API key"}';
    }
    # ...
}
```

**Rate limiting** is also enforced per-token:

```nginx
limit_req_zone $http_authorization zone=per_site:10m rate=10r/m;
limit_req zone=per_site burst=20 nodelay;
```

This prevents a single site (or compromised token) from flooding the shared Ollama server.

---

## Adding a New Site Token

1. Generate a token on the VPS:
   ```bash
   openssl rand -hex 32
   # e.g. a3f9b2c1d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1
   ```

2. Add to nginx `map` block:
   ```nginx
   "Bearer a3f9b2c1d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1" "1";
   ```

3. Reload nginx:
   ```bash
   nginx -t && systemctl reload nginx
   ```

4. Enter the token in WP Agent settings → Ollama → API Key.

---

## Revoking Access

To revoke a site's access:
1. Remove the token line from the nginx `map` block
2. Reload nginx

The site immediately loses the ability to reach Ollama. No restart of Ollama or WordPress required.

---

## Summary

| Layer | What it controls | Where configured |
|---|---|---|
| WordPress role check | Who sees the chat widget | `wp_agent_can_use_chat` filter |
| `permission_callback` | Which abilities a user can execute | Each adapter file |
| System prompt scope | What topics the AI addresses | `class-wp-agent-system-prompt.php` |
| nginx Bearer token | Which sites reach the Ollama server | nginx map block on VPS |
