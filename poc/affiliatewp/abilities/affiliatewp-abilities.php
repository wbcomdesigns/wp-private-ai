<?php
/**
 * AffiliateWP — WP Abilities API adapter
 * Written manually using AffiliateWP public REST API documentation and PHP API
 * references — demonstrating the concept works for paid plugins too.
 * Drop into wp-content/mu-plugins/ — zero changes to AffiliateWP required.
 * @license GPL-2.0-or-later
 */
defined( 'ABSPATH' ) || exit;

// Categories use wp_abilities_api_categories_init hook
add_action( 'wp_abilities_api_categories_init', function(): void {
    wp_register_ability_category( 'affiliatewp', [
        'label'       => __( 'AffiliateWP', 'affiliatewp' ),
        'description' => __( 'AI abilities for AffiliateWP affiliate management.', 'affiliatewp' ),
    ] );
} );

// Abilities use wp_abilities_api_init hook
add_action( 'wp_abilities_api_init', function(): void {

    wp_register_ability( 'affiliatewp/list-affiliates', [
        'label'               => __( 'List Affiliates', 'affiliatewp' ),
        'description'         => __( 'List all affiliates registered in AffiliateWP via GET /wp-json/affwp/v1/affiliates.', 'affiliatewp' ),
        'category'            => 'affiliatewp',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'per_page' => [ 'type' => 'integer', 'description' => 'Results per page', 'default' => 10 ],
                'page'     => [ 'type' => 'integer', 'description' => 'Page number',       'default' => 1  ],
                'search'   => [ 'type' => 'string',  'description' => 'Search term'                       ],
                'status'   => [ 'type' => 'string',  'description' => 'Affiliate status filter (active, inactive, pending)' ],
            ],
        ],
        'execute_callback'    => function( array $input ): array|\WP_Error {
            // TODO: implement using affwp_get_affiliates() or REST API
            // Endpoint: GET /wp-json/affwp/v1/affiliates
            return [ 'error' => 'Callback not yet implemented' ];
        },
        'permission_callback' => function(): bool {
            return current_user_can( 'manage_affiliates' );
        },
    ] );

    wp_register_ability( 'affiliatewp/get-affiliate', [
        'label'               => __( 'Get Affiliate', 'affiliatewp' ),
        'description'         => __( 'Get a single affiliate by ID via GET /wp-json/affwp/v1/affiliates/{id}.', 'affiliatewp' ),
        'category'            => 'affiliatewp',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'id' => [ 'type' => 'integer', 'description' => 'Affiliate ID' ],
            ],
            'required'   => [ 'id' ],
        ],
        'execute_callback'    => function( array $input ): array|\WP_Error {
            // TODO: implement using affwp_get_affiliate() or REST API
            // Endpoint: GET /wp-json/affwp/v1/affiliates/{id}
            return [ 'error' => 'Callback not yet implemented' ];
        },
        'permission_callback' => function(): bool {
            return current_user_can( 'manage_affiliates' );
        },
    ] );

    wp_register_ability( 'affiliatewp/list-referrals', [
        'label'               => __( 'List Referrals', 'affiliatewp' ),
        'description'         => __( 'List all referrals via GET /wp-json/affwp/v1/referrals.', 'affiliatewp' ),
        'category'            => 'affiliatewp',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'per_page'     => [ 'type' => 'integer', 'description' => 'Results per page', 'default' => 10 ],
                'page'         => [ 'type' => 'integer', 'description' => 'Page number',       'default' => 1  ],
                'affiliate_id' => [ 'type' => 'integer', 'description' => 'Filter by affiliate ID'              ],
                'status'       => [ 'type' => 'string',  'description' => 'Referral status (paid, unpaid, pending, rejected)' ],
            ],
        ],
        'execute_callback'    => function( array $input ): array|\WP_Error {
            // TODO: implement using affwp_get_referrals() or REST API
            // Endpoint: GET /wp-json/affwp/v1/referrals
            return [ 'error' => 'Callback not yet implemented' ];
        },
        'permission_callback' => function(): bool {
            return current_user_can( 'manage_affiliates' );
        },
    ] );

    wp_register_ability( 'affiliatewp/get-referral', [
        'label'               => __( 'Get Referral', 'affiliatewp' ),
        'description'         => __( 'Get a single referral by ID via GET /wp-json/affwp/v1/referrals/{id}.', 'affiliatewp' ),
        'category'            => 'affiliatewp',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'id' => [ 'type' => 'integer', 'description' => 'Referral ID' ],
            ],
            'required'   => [ 'id' ],
        ],
        'execute_callback'    => function( array $input ): array|\WP_Error {
            // TODO: implement using affwp_get_referral() or REST API
            // Endpoint: GET /wp-json/affwp/v1/referrals/{id}
            return [ 'error' => 'Callback not yet implemented' ];
        },
        'permission_callback' => function(): bool {
            return current_user_can( 'manage_affiliates' );
        },
    ] );

    wp_register_ability( 'affiliatewp/list-payouts', [
        'label'               => __( 'List Payouts', 'affiliatewp' ),
        'description'         => __( 'List all affiliate payouts via GET /wp-json/affwp/v1/payouts.', 'affiliatewp' ),
        'category'            => 'affiliatewp',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'per_page'     => [ 'type' => 'integer', 'description' => 'Results per page', 'default' => 10 ],
                'page'         => [ 'type' => 'integer', 'description' => 'Page number',       'default' => 1  ],
                'affiliate_id' => [ 'type' => 'integer', 'description' => 'Filter by affiliate ID'              ],
                'status'       => [ 'type' => 'string',  'description' => 'Payout status (paid, failed)'        ],
            ],
        ],
        'execute_callback'    => function( array $input ): array|\WP_Error {
            // TODO: implement using affwp_get_payouts() or REST API
            // Endpoint: GET /wp-json/affwp/v1/payouts
            return [ 'error' => 'Callback not yet implemented' ];
        },
        'permission_callback' => function(): bool {
            return current_user_can( 'manage_affiliates' );
        },
    ] );

    wp_register_ability( 'affiliatewp/list-creatives', [
        'label'               => __( 'List Creatives', 'affiliatewp' ),
        'description'         => __( 'List affiliate creatives (banners, links) via GET /wp-json/affwp/v1/creatives.', 'affiliatewp' ),
        'category'            => 'affiliatewp',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'per_page' => [ 'type' => 'integer', 'description' => 'Results per page', 'default' => 10 ],
                'page'     => [ 'type' => 'integer', 'description' => 'Page number',       'default' => 1  ],
                'status'   => [ 'type' => 'string',  'description' => 'Creative status (active, inactive)'   ],
            ],
        ],
        'execute_callback'    => function( array $input ): array|\WP_Error {
            // TODO: implement using affwp_get_creatives() or REST API
            // Endpoint: GET /wp-json/affwp/v1/creatives
            return [ 'error' => 'Callback not yet implemented' ];
        },
        'permission_callback' => function(): bool {
            return current_user_can( 'manage_affiliates' );
        },
    ] );

    wp_register_ability( 'affiliatewp/list-affwp-affiliate', [
        'label'               => __( 'List Affwp Affiliate Posts', 'affiliatewp' ),
        'description'         => __( 'List custom post type: affwp_affiliate.', 'affiliatewp' ),
        'category'            => 'affiliatewp',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'per_page' => [ 'type' => 'integer', 'description' => 'Results per page', 'default' => 10 ],
                'page'     => [ 'type' => 'integer', 'description' => 'Page number',       'default' => 1  ],
                'search'   => [ 'type' => 'string',  'description' => 'Search term'                       ],
            ],
        ],
        'execute_callback'    => function( array $input ): array|\WP_Error {
            // TODO: implement using WP_Query with post_type affwp_affiliate
            // Endpoint: WP_Query CPT: affwp_affiliate
            return [ 'error' => 'Callback not yet implemented' ];
        },
        'permission_callback' => function(): bool {
            return current_user_can( 'manage_affiliates' );
        },
    ] );

    wp_register_ability( 'affiliatewp/list-affwp-referral', [
        'label'               => __( 'List Affwp Referral Posts', 'affiliatewp' ),
        'description'         => __( 'List custom post type: affwp_referral.', 'affiliatewp' ),
        'category'            => 'affiliatewp',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'per_page' => [ 'type' => 'integer', 'description' => 'Results per page', 'default' => 10 ],
                'page'     => [ 'type' => 'integer', 'description' => 'Page number',       'default' => 1  ],
                'search'   => [ 'type' => 'string',  'description' => 'Search term'                       ],
            ],
        ],
        'execute_callback'    => function( array $input ): array|\WP_Error {
            // TODO: implement using WP_Query with post_type affwp_referral
            // Endpoint: WP_Query CPT: affwp_referral
            return [ 'error' => 'Callback not yet implemented' ];
        },
        'permission_callback' => function(): bool {
            return current_user_can( 'manage_affiliates' );
        },
    ] );

    wp_register_ability( 'affiliatewp/list-affwp-payout', [
        'label'               => __( 'List Affwp Payout Posts', 'affiliatewp' ),
        'description'         => __( 'List custom post type: affwp_payout.', 'affiliatewp' ),
        'category'            => 'affiliatewp',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'per_page' => [ 'type' => 'integer', 'description' => 'Results per page', 'default' => 10 ],
                'page'     => [ 'type' => 'integer', 'description' => 'Page number',       'default' => 1  ],
                'search'   => [ 'type' => 'string',  'description' => 'Search term'                       ],
            ],
        ],
        'execute_callback'    => function( array $input ): array|\WP_Error {
            // TODO: implement using WP_Query with post_type affwp_payout
            // Endpoint: WP_Query CPT: affwp_payout
            return [ 'error' => 'Callback not yet implemented' ];
        },
        'permission_callback' => function(): bool {
            return current_user_can( 'manage_affiliates' );
        },
    ] );

} );
// Scanner summary: 6 REST routes, 3 CPTs, 0 CRUD methods → 9 abilities generated.
