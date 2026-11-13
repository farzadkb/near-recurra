<?php
/**
 * Plugin Name: Recurra public demo hardening
 * Description: Must-use plugin for the hosted demo (docker/docker-compose.vps.yml): XML-RPC off, no user enumeration, shared judge account locked.
 *
 * @package Recurra
 */

defined( 'ABSPATH' ) || exit;

// XML-RPC and pingbacks off (Caddy also answers /xmlrpc.php with 404).
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'pings_open', '__return_false' );
add_filter(
	'wp_headers',
	static function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);

// No user enumeration for visitors: REST users endpoints and ?author=N archives.
add_filter(
	'rest_endpoints',
	static function ( $endpoints ) {
		if ( ! is_user_logged_in() ) {
			foreach ( array_keys( $endpoints ) as $route ) {
				if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
					unset( $endpoints[ $route ] );
				}
			}
		}
		return $endpoints;
	}
);
add_action(
	'template_redirect',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of a public query var.
		if ( ! is_user_logged_in() && ( is_author() || isset( $_GET['author'] ) ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
		}
	},
	1 // Before redirect_canonical(), which would reveal /author/<login>/.
);

/**
 * Whether a user is the shared judge login (credentials are public in the README).
 *
 * @param int $user_id User id.
 */
function recurra_public_is_judge( $user_id ) {
	$user = get_userdata( (int) $user_id );
	return $user && get_option( 'recurra_judge_login', 'customer' ) === $user->user_login;
}

// The shared judge account cannot change its password, email or name, and cannot reset its password,
// so one judge cannot lock the others out.
add_action(
	'woocommerce_save_account_details_errors',
	static function ( $errors, $user ) {
		if ( isset( $user->ID ) && recurra_public_is_judge( $user->ID ) ) {
			$errors->add( 'recurra_demo_account', __( 'This shared demo account cannot be changed.', 'recurra' ) );
		}
	},
	10,
	2
);
add_action(
	'user_profile_update_errors',
	static function ( $errors, $update, $user ) {
		if ( $update && isset( $user->ID ) && recurra_public_is_judge( $user->ID ) && ! current_user_can( 'manage_options' ) ) {
			$errors->add( 'recurra_demo_account', __( 'This shared demo account cannot be changed.', 'recurra' ) );
		}
	},
	10,
	3
);
add_filter(
	'allow_password_reset',
	static function ( $allow, $user_id ) {
		return recurra_public_is_judge( $user_id ) ? false : $allow;
	},
	10,
	2
);
