<?php
/**
 * Shared base for the plugin's REST controllers.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the namespace, the capability check every route shares, and password
 * re-authentication for the dangerous ones.
 */
abstract class Controller extends \WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'jcore-siirto/v1';

	/**
	 * Wrong passwords per user before re-authentication is locked for a while.
	 */
	private const MAX_PASSWORD_FAILURES = 5;

	/**
	 * Permission callback for the administrator-only routes.
	 *
	 * @return bool
	 */
	public function admin_permission(): bool {
		return current_user_can( 'manage_options' ) && ! is_multisite();
	}

	/**
	 * Builds a route definition with the administrator permission already set.
	 *
	 * @param string               $method   One of the `WP_REST_Server` method constants.
	 * @param string               $callback Method name on this controller.
	 * @param array<string, mixed> $args     Argument schema.
	 *
	 * @return array<string, mixed>
	 */
	protected function route( string $method, string $callback, array $args = array() ): array {
		return array(
			'methods'             => $method,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => $args,
		);
	}

	/**
	 * Schema for the password argument of re-authenticated routes.
	 *
	 * @return array<string, mixed>
	 */
	protected function password_arg(): array {
		return array(
			'type'     => 'string',
			'required' => true,
		);
	}

	/**
	 * Checks the current user's password again.
	 *
	 * A stolen session cookie or a CSRF-able nonce alone must not be enough to
	 * hand out the whole site or overwrite it.
	 *
	 * @param \WP_REST_Request $request Request with a `password` parameter.
	 *
	 * @return true|\WP_Error
	 */
	protected function reauthenticate( \WP_REST_Request $request ) {
		$user    = wp_get_current_user();
		$counter = 'jcore_siirto_pw_' . $user->ID;
		$fails   = (int) get_transient( $counter );

		if ( $fails >= self::MAX_PASSWORD_FAILURES ) {
			return new \WP_Error( 'jcore_siirto_locked', __( 'Too many wrong passwords. Try again in 15 minutes.', 'jcore-siirto' ), array( 'status' => 429 ) );
		}

		if ( ! $user->exists() || ! wp_check_password( (string) $request['password'], $user->user_pass, $user->ID ) ) {
			set_transient( $counter, $fails + 1, 15 * MINUTE_IN_SECONDS );

			return new \WP_Error( 'jcore_siirto_password', __( 'The password is incorrect.', 'jcore-siirto' ), array( 'status' => 403 ) );
		}

		delete_transient( $counter );

		return true;
	}
}
