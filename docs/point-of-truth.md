# Point of Truth

## The Problem with General-Purpose AI

A general-purpose LLM knows a lot. It knows what WooCommerce is, how BuddyPress works, what job boards look like. If you ask it "how many orders do I have?", it will give you a plausible-sounding answer based on its training data — not your actual data.

This is the core problem with AI assistants in WordPress: without a mechanism to ground the model in your site's actual data, it will fabricate answers that look correct but are completely wrong.

---

## What "Point of Truth" Means

The point-of-truth pattern means the AI only answers from data it can actually retrieve from your site via registered abilities. It does not use its general training knowledge to fill gaps. If no ability returns relevant data, it says so clearly.

This is enforced at two levels:

**1. System prompt instruction:**
```
Use the available tools/abilities to look up real data.
Never guess or fabricate information.
```

**2. Site facts injection (WP_Agent_Site_Indexer):**
The system prompt includes a block of database-verified facts before every conversation:
```
## Site Facts (verified from WordPress database — these are exact numbers, never contradict them)
- Site name: Meeting
- Total registered members: 27
- Active plugins: BuddyPress, WP Job Manager, WPConnectPress, ...
- WordPress version: 6.9.4
```

The model is told these are authoritative. It cannot invent a different member count because the correct count is already in front of it.

---

## The System Prompt Pattern

The full pattern used in `class-wp-agent-system-prompt.php`:

```
You are the AI assistant for [site name]. You help members with their account,
activities, bookings, courses, orders, and more.

## Guidelines
- Use the available tools/abilities to look up real data. Never guess or fabricate information.
- When a user asks about their data, always call the appropriate ability to fetch current information.
- Respect privacy: only show users their own data unless they have admin privileges.

## Site Facts (verified from WordPress database — these are exact numbers, never contradict them)
- Site name: [name]
- Total registered members: [count from wp_users]
- Active plugins: [list from get_option('active_plugins')]
- [BuddyPress/WooCommerce/LearnDash facts if active]

## Member Context
Current user: [name] (ID: [id], Roles: [roles])
[Plugin-specific context from adapters]
```

---

## What It Prevents

### Hallucinated Records
Without point-of-truth, asking "show me my recent orders" on a WooCommerce site might get a fabricated list of order numbers. With point-of-truth, the model calls `woocommerce/list-orders` ability which returns the actual database records — or returns empty if there are none.

### Wrong Counts
"How many members do we have?" → without site indexer: model guesses based on training data. With site indexer: exact count from `wp_users` is in the system prompt.

### Cross-Site Contamination
The model knows about many WordPress sites from its training data. Without explicit grounding, it might answer about another site's features or policies. The system prompt establishes this specific site's context clearly.

### Stale Training Data
Models are trained on data with a cutoff date. Plugin features change, site settings change. The abilities API always returns current data — not what the plugin did when the model was trained.

---

## Configuring Per Site Type

### Membership Sites (BuddyPress / MemberPress)
```php
$prompt .= "\nScope: Only answer questions about this community — members, groups, activity, events, and account. Do not answer general questions unrelated to this community.";
```

Key abilities to register:
- `get-member-profile` — user's own profile data
- `list-groups` — groups the user belongs to
- `list-activity` — recent activity feed
- `get-membership-status` — current plan, expiry

### E-commerce (WooCommerce)
```php
$prompt .= "\nScope: Help customers with their orders, products, account, and shipping. For refunds, direct to: " . get_option('refund_policy_url') . ".";
```

Key abilities to register:
- `list-orders` — scoped to current user
- `get-order` — single order detail
- `list-products` — published products
- `get-account-status` — subscription, balance

### Job Boards (WP Job Manager)
```php
$prompt .= "\nScope: Help members find jobs, manage applications, and update their resume. For employer enquiries, direct to the job listing contact form.";
```

Key abilities to register:
- `list-jobs` — published job listings with filters
- `list-applications` — current user's applications
- `get-job` — single job detail

### Course Platforms (LearnDash / LifterLMS)
```php
$prompt .= "\nScope: Help students with their enrolled courses, progress, assignments, and certificates. Do not reveal other students' progress.";
```

Key abilities to register:
- `list-enrolled-courses` — scoped to current user
- `get-course-progress` — current user's progress only
- `list-quizzes` — available quizzes for enrolled courses

---

## The Site Indexer as Ground Truth

`WP_Agent_Site_Indexer` is the mechanism that ensures factual accuracy for site-wide data. It:

1. Runs on plugin activation — so the very first conversation has real data
2. Refreshes every 6 hours — counts stay current as the site grows
3. Busts on plugin changes — the active plugins list is always accurate

The indexer queries the actual WordPress database. It doesn't estimate or approximate. A site with 27 members will show 27 in every system prompt until a new member registers and the cache refreshes.

For real-time member data (e.g. "is John Smith currently active?"), register a dedicated ability that queries the database live — the site indexer is for site-wide aggregate facts, not per-user live lookups.

---

## Ability Execute Callbacks: The Last Mile

The system prompt and site indexer provide context. The ability callbacks provide live data. Together they make the AI's answers both grounded and current.

A well-implemented callback:
```php
'execute_callback' => function( array $input ): array {
    $user_id = get_current_user_id();  // Always scope to current user
    $orders  = wc_get_orders( [
        'customer_id' => $user_id,
        'limit'       => $input['limit'] ?? 5,
        'status'      => $input['status'] ?? 'any',
        'orderby'     => 'date',
        'order'       => 'DESC',
    ] );

    return array_map( fn( $order ) => [
        'id'     => $order->get_id(),
        'status' => $order->get_status(),
        'total'  => $order->get_formatted_order_total(),
        'date'   => $order->get_date_created()->format( 'Y-m-d' ),
    ], $orders );
},
```

The AI calls this ability, gets back structured data, and answers from it — not from its training. This is the full point-of-truth chain.
