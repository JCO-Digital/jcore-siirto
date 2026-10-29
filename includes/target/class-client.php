<?php
/**
 * Signed requests to a source site.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Target;

use Jcore\Siirto\Crypto;
use Jcore\Siirto\Source\Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to the source endpoints on behalf of a job.
 */
final class Client {

	/**
	 * Constructor.
	 *
	 * @param Job $job The job whose key and sequence counter are used.
	 */
	public function __construct( private Job $job ) {
	}

	/**
	 * Calls a source action and returns the decrypted response body.
	 *
	 * @param string               $action Action name.
	 * @param array<string, mixed> $params Parameters.
	 *
	 * @return string
	 *
	 * @throws Remote_Exception On any transport, HTTP or authentication failure.
	 */
	public function call( string $action, array $params = array() ): string {
		$key = $this->job->key();
		if ( null === $key ) {
			throw new Remote_Exception( esc_html__( 'The migration key is no longer available on this site.', 'jcore-siirto' ) );
		}

		$route = '/' . Server::NAMESPACE . '/' . Server::BASE . '/' . $action;
		$body  = (string) wp_json_encode( (object) $params );
		$seq   = $this->job->next_seq();
		$time  = time();
		$cid   = $this->job->client_id();

		$response = wp_remote_post(
			self::endpoint( $key['url'], $route ),
			array(
				'timeout'     => 120,
				// A redirect would send the signed request somewhere the
				// administrator didn't choose.
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type'       => 'application/json',
					'X-Siirto-Key'       => $key['id'],
					'X-Siirto-Client'    => $cid,
					'X-Siirto-Seq'       => (string) $seq,
					'X-Siirto-Time'      => (string) $time,
					'X-Siirto-Signature' => Crypto::sign( $key['secret'], Crypto::canonical( $route, $key['id'], $cid, $seq, $time, $body ) ),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Remote_Exception( esc_html( $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			$error   = json_decode( $raw, true );
			$message = is_array( $error ) && isset( $error['message'] ) ? (string) $error['message'] : wp_strip_all_tags( substr( $raw, 0, 200 ) );

			/* translators: 1: HTTP status code, 2: error message */
			throw new Remote_Exception( esc_html( sprintf( __( 'The source site answered %1$d: %2$s', 'jcore-siirto' ), $status, $message ) ), (int) $status );
		}

		$plain = Crypto::open( $key['secret'], $raw, $key['id'] . "\n" . $seq . "\n" . $route );
		if ( null === $plain ) {
			throw new Remote_Exception( esc_html__( 'The response from the source site could not be authenticated. It may have been altered in transit.', 'jcore-siirto' ) );
		}

		return $plain;
	}

	/**
	 * Calls an action that answers with JSON.
	 *
	 * @param string               $action Action name.
	 * @param array<string, mixed> $params Parameters.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws Remote_Exception On failure or a malformed answer.
	 */
	public function json( string $action, array $params = array() ): array {
		$data = json_decode( $this->call( $action, $params ), true );
		if ( ! is_array( $data ) ) {
			throw new Remote_Exception( esc_html__( 'The source site sent a malformed answer.', 'jcore-siirto' ) );
		}

		return $data;
	}

	/**
	 * Builds the URL of a route on the source.
	 *
	 * Uses the `rest_route` query form, which works whatever the source's
	 * permalink settings are.
	 *
	 * @param string $base  Source home URL.
	 * @param string $route REST route.
	 *
	 * @return string
	 */
	private static function endpoint( string $base, string $route ): string {
		return add_query_arg( 'rest_route', rawurlencode( $route ), trailingslashit( $base ) );
	}

	/**
	 * Whether a source URL may be used.
	 *
	 * @param string $url Source home URL.
	 *
	 * @return bool
	 */
	public static function url_allowed( string $url ): bool {
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) || Server::allow_http();
	}
}
