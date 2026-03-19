=== WP Ability Scanner ===
Plugin: AffiliateWP
Note: AffiliateWP is a commercial plugin (not available on wordpress.org).
This adapter was written manually using their public REST API documentation
and PHP API references — demonstrating the concept works for paid plugins too.

REST routes identified from public docs:
- GET /wp-json/affwp/v1/affiliates
- GET /wp-json/affwp/v1/affiliates/{id}
- GET /wp-json/affwp/v1/referrals
- GET /wp-json/affwp/v1/referrals/{id}
- GET /wp-json/affwp/v1/payouts
- GET /wp-json/affwp/v1/creatives

Post types found: affwp_affiliate, affwp_referral, affwp_payout
Abilities generated: 9
