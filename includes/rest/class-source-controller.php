<?php
/**
 * Admin routes for sending this site.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Rest;

use Jcore\Siirto\Checks;
use Jcore\Siirto\Log;
use Jcore\Siirto\Source\Keys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues and revokes migration keys and shows the source audit log.
 */
final class Source_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'send';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array( $this->route( \WP_REST_Server::READABLE, 'get_status' ) )
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/keys',
			array(
				$this->route(
					\WP_REST_Server::CREATABLE,
					'create_key',
					array(
						'hours'    => array(
							'type'     => 'integer',
							'minimum'  => 1,
							'maximum'  => Keys::MAX_HOURS,
							'required' => true,
						),
						'scopes'   => array(
							'type'     => 'array',
							'items'    => array(
								'type' => 'string',
								'enum' => Keys::SCOPES,
							),
							'minItems' => 1,
							'required' => true,
						),
						'password' => $this->password_arg(),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/keys/(?P<id>[a-f0-9]{32})',
			array( $this->route( \WP_REST_Server::DELETABLE, 'revoke_key' ) )
		);
	}

	/**
	 * GET /send — checks, keys and log.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'checks' => Checks::source(),
				'keys'   => Keys::all(),
				'log'    => Log::recent( 'source' ),
			)
		);
	}

	/**
	 * POST /send/keys — issues a key. The key is returned once and never again.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_key( \WP_REST_Request $request ) {
		$auth = $this->reauthenticate( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( ! Checks::source()['ok'] ) {
			return new \WP_Error( 'jcore_siirto_unsupported', __( 'This site cannot send a migration. See the notices above.', 'jcore-siirto' ), array( 'status' => 400 ) );
		}

		$key = Keys::create( (int) $request['hours'], (array) $request['scopes'] );

		$response = rest_ensure_response( array( 'key' => $key ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * DELETE /send/keys/{id} — revokes a key.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function revoke_key( \WP_REST_Request $request ): \WP_REST_Response {
		Keys::revoke( (string) $request['id'], 'Revoked by an administrator.' );

		return $this->get_status( $request );
	}
}
