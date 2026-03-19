<?php
/**
 * WP Ability Scanner — CLI tool
 *
 * Scans a WordPress plugin directory, extracts REST routes, custom post types,
 * and public CRUD methods, then outputs a WP 6.9 Abilities API adapter stub.
 *
 * Usage:
 *   php wp-ability-scanner.php /path/to/plugin [--output=file.php]
 *
 * @license GPL-2.0-or-later
 * @since   1.0.0
 */

declare( strict_types=1 );

// ──────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ──────────────────────────────────────────────────────────────────────────────

if ( PHP_MAJOR_VERSION < 8 || ( PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION < 1 ) ) {
    fwrite( STDERR, "Error: PHP 8.1+ required (running " . PHP_VERSION . ")\n" );
    exit( 1 );
}

if ( $argc < 2 ) {
    fwrite( STDERR, "Usage: php wp-ability-scanner.php /path/to/plugin [--output=file.php]\n" );
    exit( 1 );
}

$plugin_dir  = rtrim( $argv[1], '/' );
$output_file = null;

foreach ( array_slice( $argv, 2 ) as $arg ) {
    if ( str_starts_with( $arg, '--output=' ) ) {
        $output_file = substr( $arg, strlen( '--output=' ) );
    }
}

if ( ! is_dir( $plugin_dir ) ) {
    fwrite( STDERR, "Error: Directory not found: {$plugin_dir}\n" );
    exit( 1 );
}

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Recursively collect all .php files under $dir.
 *
 * @return list<string>
 */
function collect_php_files( string $dir ): array {
    $files    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ( $iterator as $file ) {
        if ( $file->isFile() && $file->getExtension() === 'php' ) {
            $files[] = $file->getRealPath();
        }
    }

    sort( $files );
    return $files;
}

/**
 * Derive a slug from the plugin directory name.
 * Strips version numbers, lowercases, keeps dashes.
 */
function derive_plugin_slug( string $plugin_dir ): string {
    $basename = basename( $plugin_dir );
    // Remove trailing version numbers like -1.2.3 or _1.2.3
    $slug     = preg_replace( '/[\s_]+/', '-', $basename );
    $slug     = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $slug ) );
    $slug     = preg_replace( '/-+/', '-', trim( $slug, '-' ) );
    return $slug ?: 'plugin';
}

/**
 * Derive a human-readable plugin name from the main plugin file header,
 * falling back to titlising the slug.
 */
function derive_plugin_name( string $plugin_dir, string $slug ): string {
    // Try to find Plugin Name: header in top-level .php files
    foreach ( glob( $plugin_dir . '/*.php' ) ?: [] as $file ) {
        $header = file_get_contents( $file, false, null, 0, 8192 );
        if ( $header === false ) {
            continue;
        }
        if ( preg_match( '/Plugin Name\s*:\s*(.+)/i', $header, $m ) ) {
            return trim( $m[1] );
        }
    }
    // Fallback: titlise slug
    return ucwords( str_replace( '-', ' ', $slug ) );
}

// ──────────────────────────────────────────────────────────────────────────────
// WP_REST_Server constant → HTTP method mapping
// ──────────────────────────────────────────────────────────────────────────────

const WP_REST_SERVER_MAP = [
    'READABLE'   => 'GET',
    'CREATABLE'  => 'POST',
    'EDITABLE'   => 'PUT, PATCH',
    'DELETABLE'  => 'DELETE',
    'ALLMETHODS' => 'GET, POST, PUT, PATCH, DELETE',
];

/**
 * Resolve a raw methods value (string literal or WP_REST_Server::CONSTANT)
 * into a normalised HTTP methods string.
 */
function resolve_http_methods( string $raw ): string {
    $raw = trim( $raw, " \t\n\r\0\x0B'\"\\" );

    // WP_REST_Server::CONSTANT
    if ( preg_match( '/WP_REST_Server::([A-Z]+)/', $raw, $m ) ) {
        return WP_REST_SERVER_MAP[ $m[1] ] ?? strtoupper( $m[1] );
    }

    return strtoupper( $raw );
}

/**
 * Map an HTTP method (or comma-separated list) to an ability action prefix.
 * Uses the first method when multiple are listed.
 */
function http_methods_to_action( string $methods ): string {
    $first = strtoupper( explode( ',', str_replace( ' ', ',', $methods ) )[0] );
    return match ( $first ) {
        'GET'                   => 'get',
        'POST'                  => 'create',
        'PUT', 'PATCH'          => 'update',
        'DELETE'                => 'delete',
        default                 => 'call',
    };
}

/**
 * Convert a REST route pattern like /contacts/(?P<id>\d+) to a slug fragment.
 * Strips regex capture groups, leading slash, converts / and _ to -.
 */
function route_to_slug( string $route ): string {
    // Remove regex capture groups e.g. (?P<id>\d+)
    $slug = preg_replace( '/\(\?P<[^>]+>[^)]+\)/', 'id', $route );
    // Remove any remaining parens / regex chars
    $slug = preg_replace( '/[^a-zA-Z0-9\-\/]/', '', $slug );
    $slug = strtolower( trim( $slug, '/' ) );
    $slug = str_replace( '/', '-', $slug );
    $slug = preg_replace( '/-+/', '-', $slug );
    $slug = trim( $slug, '-' );
    return $slug !== '' ? $slug : 'unknown';
}

/**
 * Make an ability name component safe: only lowercase letters, numbers, dashes.
 */
function sanitize_ability_component( string $s ): string {
    $s = strtolower( $s );
    $s = preg_replace( '/[^a-z0-9\-]/', '-', $s );
    $s = preg_replace( '/-+/', '-', $s );
    return trim( $s, '-' );
}

// ──────────────────────────────────────────────────────────────────────────────
// Extraction: REST routes
// ──────────────────────────────────────────────────────────────────────────────

/**
 * @return list<array{namespace:string, route:string, methods:string}>
 */
function extract_rest_routes( string $source ): array {
    $routes = [];

    /*
     * Match register_rest_route( <namespace>, <route>, [ 'methods' => <value> ] )
     * We handle both single-quoted and double-quoted strings for namespace and route.
     * The methods value may be a quoted string OR WP_REST_Server::CONSTANT.
     *
     * Because PHP source can span multiple lines we work on the full source,
     * stripped of comments to avoid false positives.
     */
    $clean = remove_php_comments( $source );

    /*
     * Pattern: register_rest_route( 'namespace' , 'route' , array|[ ... 'methods' => VALUE ... ] )
     * We extract namespace, route, and the methods value in a two-pass approach:
     *   Pass 1 – locate all register_rest_route call sites.
     *   Pass 2 – find a 'methods' key inside the found argument block.
     */

    // Pass 1: find call sites with their positions
    $pattern_call = '/register_rest_route\s*\(\s*([\'"])(?P<ns>[^\'"]+)\1\s*,\s*([\'"])(?P<route>[^\'"]+)\3\s*,/';
    if ( ! preg_match_all( $pattern_call, $clean, $calls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
        // Warn if register_rest_route() calls exist but use variables/constants/concatenation
        if ( preg_match( '/register_rest_route\s*\(/', $clean ) ) {
            fwrite( STDERR, "  [warn] register_rest_route() detected but namespace/route are not string literals — routes skipped.\n" );
        }
        return $routes;
    }

    foreach ( $calls as $call ) {
        $ns         = $call['ns'][0];
        $route      = $call['route'][0];
        $call_end   = $call[0][1] + strlen( $call[0][0] );

        // Extract the argument block after the first two arguments (grab up to 2000 chars)
        $arg_block = substr( $clean, $call_end, 2000 );

        // Find 'methods' value — quoted string or WP_REST_Server::CONSTANT
        $methods_raw = 'GET'; // default
        if ( preg_match(
            "/['\"]methods['\"]\s*=>\s*(?:(?P<const>WP_REST_Server::[A-Z]+)|(?P<str>['\"](?P<strval>[^'\"]*)['\"]))/",
            $arg_block,
            $mm
        ) ) {
            if ( ! empty( $mm['const'] ) ) {
                $methods_raw = $mm['const'];
            } elseif ( isset( $mm['strval'] ) ) {
                $methods_raw = $mm['strval'];
            }
        }

        $routes[] = [
            'namespace' => $ns,
            'route'     => $route,
            'methods'   => resolve_http_methods( $methods_raw ),
        ];
    }

    return $routes;
}

// ──────────────────────────────────────────────────────────────────────────────
// Extraction: Custom post types
// ──────────────────────────────────────────────────────────────────────────────

const WP_BUILTIN_POST_TYPES = [
    'post', 'page', 'attachment', 'revision', 'nav_menu_item',
    'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
    'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles',
    'wp_navigation', 'wp_font_face', 'wp_font_family',
];

/**
 * @return list<string>
 */
function extract_post_types( string $source ): array {
    $clean = remove_php_comments( $source );
    $types = [];

    if ( preg_match_all(
        '/register_post_type\s*\(\s*([\'"])(?P<type>[a-zA-Z0-9_\-]{1,32})\1/',
        $clean,
        $matches
    ) ) {
        foreach ( $matches['type'] as $type ) {
            if ( ! in_array( $type, WP_BUILTIN_POST_TYPES, true ) ) {
                $types[] = $type;
            }
        }
    }

    return array_unique( $types );
}

// ──────────────────────────────────────────────────────────────────────────────
// Extraction: Public CRUD methods
// ──────────────────────────────────────────────────────────────────────────────

const CRUD_PREFIXES = [
    'get_', 'list_', 'create_', 'update_', 'delete_', 'search_', 'find_',
];

/**
 * @return list<array{class:string, method:string}>
 */
function extract_crud_methods( string $source ): array {
    $clean   = remove_php_comments( $source );
    $found   = [];
    $current = '';

    // Capture current class name
    $lines = explode( "\n", $clean );
    foreach ( $lines as $line ) {
        if ( preg_match( '/^\s*(?:abstract\s+|final\s+)?class\s+(\w+)/', $line, $cm ) ) {
            $current = $cm[1];
        }

        // public [static] function prefix_something(
        if ( preg_match(
            '/^\s*public\s+(?:static\s+)?function\s+(?P<method>(?:get|list|create|update|delete|search|find)_\w+)\s*\(/i',
            $line,
            $mm
        ) ) {
            $found[] = [
                'class'  => $current,
                'method' => $mm['method'],
            ];
        }
    }

    return $found;
}

// ──────────────────────────────────────────────────────────────────────────────
// Strip PHP comments (line // and block /* */ and docblocks)
// ──────────────────────────────────────────────────────────────────────────────

function remove_php_comments( string $source ): string {
    $tokens = token_get_all( $source );
    $out    = '';
    foreach ( $tokens as $token ) {
        if ( is_array( $token ) ) {
            if ( in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
                // Replace with spaces to preserve character offsets as close as possible
                $out .= str_repeat( ' ', strlen( $token[1] ) );
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
}

// ──────────────────────────────────────────────────────────────────────────────
// De-duplicate abilities
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Given a desired ability name and a set of already-used names, append a
 * numeric suffix until the name is unique.
 */
function unique_ability_name( string $name, array &$used ): string {
    $candidate = $name;
    $i         = 2;
    while ( in_array( $candidate, $used, true ) ) {
        $candidate = $name . '-' . $i;
        ++$i;
    }
    $used[] = $candidate;
    return $candidate;
}

// ──────────────────────────────────────────────────────────────────────────────
// Code generation
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Escape a string for embedding inside a single-quoted PHP string literal.
 * Only backslash and single-quote need escaping.
 */
function esc_single_quoted( string $s ): string {
    return str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $s );
}

/**
 * @param list<array{namespace:string, route:string, methods:string}> $routes
 * @param list<string>                                                 $cpts
 * @param list<array{class:string, method:string}>                     $crud_methods
 */
function generate_adapter(
    string $plugin_slug,
    string $plugin_name,
    array  $routes,
    array  $cpts,
    array  $crud_methods,
    string $date
): string {
    $used_names = [];
    $abilities  = [];

    // ── From REST routes ─────────────────────────────────────────────────────
    foreach ( $routes as $r ) {
        $action       = http_methods_to_action( $r['methods'] );
        $route_slug   = sanitize_ability_component( route_to_slug( $r['route'] ) );
        $raw_name     = $plugin_slug . '/' . $action . '-' . $route_slug;
        $ability_name = unique_ability_name( $raw_name, $used_names );

        $label       = ucfirst( $action ) . ' ' . ucwords( str_replace( '-', ' ', $route_slug ) );
        $description = sprintf(
            '%s %s via %s endpoint.',
            ucfirst( $action ),
            str_replace( '-', ' ', $route_slug ),
            $r['methods']
        );

        $ns_full    = trim( $r['namespace'] . $r['route'], '/' );
        $endpoint   = $r['methods'] . ' /wp-json/' . $ns_full;

        $schema_props = generate_schema_for_action( $action );

        $abilities[] = compact( 'ability_name', 'label', 'description', 'schema_props', 'endpoint', 'action' );
    }

    // ── From CPTs ────────────────────────────────────────────────────────────
    foreach ( $cpts as $cpt ) {
        $cpt_slug    = sanitize_ability_component( $cpt );
        $raw_name    = $plugin_slug . '/list-' . $cpt_slug;
        $ability_name = unique_ability_name( $raw_name, $used_names );

        $label        = 'List ' . ucwords( str_replace( '-', '_', $cpt_slug ) );
        $description  = "List custom post type: {$cpt}.";
        $endpoint     = 'WP_Query CPT: ' . $cpt;

        $schema_props = generate_schema_for_action( 'list' );
        $action       = 'list';

        $abilities[] = compact( 'ability_name', 'label', 'description', 'schema_props', 'endpoint', 'action' );
    }

    // ── From CRUD methods ────────────────────────────────────────────────────
    foreach ( $crud_methods as $m ) {
        $action       = strtolower( explode( '_', $m['method'] )[0] );
        $rest         = substr( $m['method'], strlen( $action ) + 1 );
        $resource     = sanitize_ability_component( str_replace( '_', '-', $rest ) );
        $raw_name     = $plugin_slug . '/' . $action . '-' . $resource;
        $ability_name = unique_ability_name( $raw_name, $used_names );

        $label        = ucfirst( $action ) . ' ' . ucwords( str_replace( '-', ' ', $resource ) );
        $description  = sprintf(
            '%s %s via %s::%s().',
            ucfirst( $action ),
            str_replace( '-', ' ', $resource ),
            $m['class'] ?: 'API',
            $m['method']
        );
        $endpoint     = ( $m['class'] ? $m['class'] . '::' : '' ) . $m['method'] . '()';

        $schema_props = generate_schema_for_action( $action );

        $abilities[] = compact( 'ability_name', 'label', 'description', 'schema_props', 'endpoint', 'action' );
    }

    // ── Render ───────────────────────────────────────────────────────────────
    $total_routes  = count( $routes );
    $total_cpts    = count( $cpts );
    $total_methods = count( $crud_methods );
    $total_out     = count( $abilities );

    $abilities_code = '';
    foreach ( $abilities as $ab ) {
        $abilities_code .= render_ability( $ab, $plugin_slug, $plugin_name );
    }

    $esc_slug = esc_single_quoted( $plugin_slug );
    $esc_name = esc_single_quoted( $plugin_name );

    return <<<PHP
<?php
/**
 * {$plugin_name} — WP Abilities API adapter
 * Generated by wp-ability-scanner on {$date}
 * Drop into wp-content/mu-plugins/ — zero changes to {$plugin_name} required.
 * @license GPL-2.0-or-later
 */
defined( 'ABSPATH' ) || exit;

// Categories use wp_abilities_api_categories_init hook
add_action( 'wp_abilities_api_categories_init', function(): void {
    wp_register_ability_category( '{$esc_slug}', [
        'label'       => __( '{$esc_name}', '{$esc_slug}' ),
        'description' => __( 'AI abilities for {$esc_name}.', '{$esc_slug}' ),
    ] );
} );

// Abilities use wp_abilities_api_init hook
add_action( 'wp_abilities_api_init', function(): void {
{$abilities_code}
} );
// Scanner summary: {$total_routes} REST routes, {$total_cpts} CPTs, {$total_methods} CRUD methods → {$total_out} abilities generated.
PHP;
}

/**
 * Generate a minimal input schema based on the CRUD action.
 */
function generate_schema_for_action( string $action ): string {
    return match ( $action ) {
        'get' => <<<'SCHEMA'
            'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer', 'description' => 'Item ID' ],
                ],
                'required'   => [ 'id' ],
SCHEMA,
        'list', 'search', 'find' => <<<'SCHEMA'
            'type'       => 'object',
                'properties' => [
                    'per_page' => [ 'type' => 'integer', 'description' => 'Results per page', 'default' => 10 ],
                    'page'     => [ 'type' => 'integer', 'description' => 'Page number',       'default' => 1  ],
                    'search'   => [ 'type' => 'string',  'description' => 'Search term'                       ],
                ],
SCHEMA,
        'create' => <<<'SCHEMA'
            'type'       => 'object',
                'properties' => [
                    'data' => [ 'type' => 'object', 'description' => 'Item data to create' ],
                ],
                'required'   => [ 'data' ],
SCHEMA,
        'update' => <<<'SCHEMA'
            'type'       => 'object',
                'properties' => [
                    'id'   => [ 'type' => 'integer', 'description' => 'Item ID'             ],
                    'data' => [ 'type' => 'object',  'description' => 'Updated item fields' ],
                ],
                'required'   => [ 'id', 'data' ],
SCHEMA,
        'delete' => <<<'SCHEMA'
            'type'       => 'object',
                'properties' => [
                    'id'    => [ 'type' => 'integer', 'description' => 'Item ID'               ],
                    'force' => [ 'type' => 'boolean', 'description' => 'Bypass trash', 'default' => false ],
                ],
                'required'   => [ 'id' ],
SCHEMA,
        default => <<<'SCHEMA'
            'type' => 'object',
SCHEMA,
    };
}

/**
 * Render a single wp_register_ability() block.
 */
function render_ability( array $ab, string $plugin_slug, string $plugin_name ): string {
    $ability_name = $ab['ability_name'];
    $label        = esc_single_quoted( $ab['label'] );
    $description  = esc_single_quoted( $ab['description'] );
    $endpoint     = esc_single_quoted( $ab['endpoint'] );
    $schema_props = $ab['schema_props'];
    $esc_slug     = esc_single_quoted( $plugin_slug );
    $esc_name     = esc_single_quoted( $plugin_name );

    return <<<PHP

    wp_register_ability( '{$ability_name}', [
        'label'               => __( '{$label}', '{$esc_slug}' ),
        'description'         => __( '{$description}', '{$esc_slug}' ),
        'category'            => '{$esc_slug}',
        'input_schema'        => [
            {$schema_props}
        ],
        'execute_callback'    => function( array \$input ): array|\\WP_Error {
            // TODO: implement using {$esc_name}'s PHP API
            // Endpoint: {$endpoint}
            return [ 'error' => 'Callback not yet implemented' ];
        },
        'permission_callback' => function(): bool {
            return is_user_logged_in();
        },
    ] );

PHP;
}

// ──────────────────────────────────────────────────────────────────────────────
// Main
// ──────────────────────────────────────────────────────────────────────────────

fwrite( STDERR, "wp-ability-scanner v1.0\n" );
fwrite( STDERR, "Scanning: {$plugin_dir}\n" );

$plugin_slug = derive_plugin_slug( $plugin_dir );
$plugin_name = derive_plugin_name( $plugin_dir, $plugin_slug );

fwrite( STDERR, "Plugin slug: {$plugin_slug}\n" );
fwrite( STDERR, "Plugin name: {$plugin_name}\n" );

$php_files = collect_php_files( $plugin_dir );
$file_count = count( $php_files );
fwrite( STDERR, "PHP files found: {$file_count}\n" );

$all_routes  = [];
$all_cpts    = [];
$all_methods = [];

foreach ( $php_files as $path ) {
    $source = @file_get_contents( $path );
    if ( $source === false ) {
        fwrite( STDERR, "  [skip] Cannot read: {$path}\n" );
        continue;
    }

    // Only process files that look relevant (avoid scanning every file fully)
    $routes  = extract_rest_routes( $source );
    $cpts    = extract_post_types( $source );
    $methods = extract_crud_methods( $source );

    $all_routes  = array_merge( $all_routes, $routes );
    $all_cpts    = array_merge( $all_cpts, $cpts );
    $all_methods = array_merge( $all_methods, $methods );
}

// De-duplicate CPTs
$all_cpts = array_values( array_unique( $all_cpts ) );

$total_routes  = count( $all_routes );
$total_cpts    = count( $all_cpts );
$total_methods = count( $all_methods );

fwrite( STDERR, "REST routes found:  {$total_routes}\n" );
fwrite( STDERR, "Custom post types:  {$total_cpts}\n" );
fwrite( STDERR, "CRUD methods found: {$total_methods}\n" );

if ( $total_routes === 0 && $total_cpts === 0 && $total_methods === 0 ) {
    fwrite( STDERR, "Warning: Nothing detected. The plugin may use non-standard patterns.\n" );
}

$date   = date( 'Y-m-d' );
$output = generate_adapter( $plugin_slug, $plugin_name, $all_routes, $all_cpts, $all_methods, $date );

if ( $output_file !== null ) {
    $output_dir = dirname( $output_file );
    if ( ! is_dir( $output_dir ) ) {
        fwrite( STDERR, "Error: directory does not exist: {$output_dir}\n" );
        exit( 1 );
    }
    if ( file_put_contents( $output_file, $output ) === false ) {
        fwrite( STDERR, "Error: Could not write to {$output_file}\n" );
        exit( 1 );
    }
    fwrite( STDERR, "Written to: {$output_file}\n" );
    fwrite(
        STDERR,
        "Summary: {$total_routes} REST routes, {$total_cpts} CPTs, {$total_methods} CRUD methods.\n"
    );
} else {
    echo $output;
}

exit( 0 );
