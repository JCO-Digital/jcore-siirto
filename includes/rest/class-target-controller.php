<?php
/**
 * Admin routes for receiving a site.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Rest;

use Jcore\Siirto\Checks;
use Jcore\Siirto\Crypto;
use Jcore\Siirto\Log;
use Jcore\Siirto\Roots;
use Jcore\Siirto\Target\Client;
use Jcore\Siirto\Target\Finalizer;
use Jcore\Siirto\Target\Job;
use Jcore\Siirto\Target\Runner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects to a source, drives the copy, and confirms, rolls back or cleans up.
 *
 * There is at most one unfinished job at a time; every route acts on it.
 */
final class Target_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'receive';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$base = '/' . $this->rest_base;

		register_rest_route( $this->namespace, $base, array( $this->route( \WP_REST_Server::READABLE, 'get_status' ) ) );

		register_rest_route(
			$this->namespace,
			$base . '/connect',
			array(
				$this->route(
					\WP_REST_Server::CREATABLE,
					'connect',
					array(
						'key' => array(
							'type'     => 'string',
							'required' => true,
						),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/start',
			array(
				$this->route(
					\WP_REST_Server::CREATABLE,
					'start',
					array(
						'roots'            => array(
							'type'    => 'array',
							'items'   => array(
								'type' => 'string',
								'enum' => Roots::ALL,
							),
							'default' => array(),
						),
						'database'         => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'skip_executables' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'skip_transients'  => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'exclude'          => array( // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- File patterns, not a query argument.
							'type'      => 'string',
							'default'   => '',
							'maxLength' => 5000,
						),
					)
				),
			)
		);

		foreach ( array( 'step', 'resume', 'cleanup' ) as $action ) {
			register_rest_route( $this->namespace, $base . '/' . $action, array( $this->route( \WP_REST_Server::CREATABLE, $action ) ) );
		}

		register_rest_route(
			$this->namespace,
			$base . '/finalize',
			array(
				$this->route(
					\WP_REST_Server::CREATABLE,
					'finalize',
					array(
						'password' => $this->password_arg(),
						'confirm'  => array(
							'type'     => 'string',
							'required' => true,
						),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/rollback',
			array( $this->route( \WP_REST_Server::CREATABLE, 'rollback', array( 'password' => $this->password_arg() ) ) )
		);
	}

	/**
	 * GET /receive — checks, the current job and the log.
	 *
	 * @param \WP_REST_Request|null $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status( $request = null ): \WP_REST_Response {
		$job = Job::current();

		return rest_ensure_response(
			array(
				'checks'      => Checks::target(),
				'job'         => $job?->summary(),
				'log'         => Log::recent( 'target', 50 ),
				'site'        => array(
					'home'   => home_url(),
					'host'   => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					'prefix' => $GLOBALS['wpdb']->prefix,
				),
				'login_url'   => wp_login_url( admin_url( 'tools.php?page=jcore-siirto' ) ),
				'allow_http'  => \Jcore\Siirto\Source\Server::allow_http(),
				'own_version' => JCORE_SIIRTO_VERSION,
				'wp_version'  => get_bloginfo( 'version' ),
			)
		);
	}

	/**
	 * POST /receive/connect — checks a key against its source.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function connect( \WP_REST_Request $request ) {
		if ( null !== Job::current() ) {
			return $this->error( __( 'Another migration is still open. Cancel or clean it up first.', 'jcore-siirto' ), 409 );
		}

		$key = Crypto::decode_key( (string) $request['key'] );
		if ( null === $key ) {
			return $this->error( __( 'That is not a valid migration key. Copy the whole key from the source site.', 'jcore-siirto' ) );
		}

		if ( ! Client::url_allowed( $key['url'] ) ) {
			return $this->error( __( 'The source site must use HTTPS.', 'jcore-siirto' ) );
		}

		if ( untrailingslashit( $key['url'] ) === untrailingslashit( home_url() ) ) {
			return $this->error( __( 'This key belongs to this site. Paste it into the site you are migrating to.', 'jcore-siirto' ) );
		}

		$job = Job::create( $key );

		try {
			Runner::connect( $job );
		} catch ( \RuntimeException $e ) {
			$job->forget_key();
			$job->status = 'cleaned';
			$job->error  = $e->getMessage();
			$job->save();

			return $this->error( $e->getMessage() );
		}

		return $this->get_status();
	}

	/**
	 * POST /receive/start — begins copying.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start( \WP_REST_Request $request ) {
		$job = Job::current();
		if ( null === $job || 'connected' !== $job->status ) {
			return $this->error( __( 'Connect to a source site first.', 'jcore-siirto' ), 409 );
		}

		$checks = Checks::target();
		$roots  = (array) $request['roots'];

		if ( array() !== $roots && ! $checks['files'] ) {
			return $this->error( __( 'This site cannot receive files. See the notices above.', 'jcore-siirto' ) );
		}

		if ( $request['database'] && ! $checks['database'] ) {
			return $this->error( __( 'This site cannot receive a database.', 'jcore-siirto' ) );
		}

		try {
			Runner::start(
				$job,
				array(
					'roots'            => $roots,
					'database'         => (bool) $request['database'],
					'skip_executables' => (bool) $request['skip_executables'],
					'skip_transients'  => (bool) $request['skip_transients'],
					// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- File patterns, not a query argument.
					'exclude'          => preg_split( '/\R/', (string) $request['exclude'] ),
				)
			);
		} catch ( \RuntimeException $e ) {
			return $this->error( $e->getMessage() );
		}

		return $this->get_status();
	}

	/**
	 * POST /receive/step — advances the copy for a few seconds.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function step( \WP_REST_Request $request ): \WP_REST_Response {
		$job = Job::current();
		if ( null !== $job ) {
			Runner::step( $job );
		}

		return $this->get_status();
	}

	/**
	 * POST /receive/resume — retries a failed copy.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resume( \WP_REST_Request $request ) {
		$job = Job::current();
		if ( null === $job || 'failed' !== $job->status || isset( $job->state['finalize'] ) ) {
			return $this->error( __( 'There is nothing to resume.', 'jcore-siirto' ), 409 );
		}

		$job->status           = 'running';
		$job->error            = '';
		$job->state['retries'] = 0;
		$job->save();

		return $this->get_status();
	}

	/**
	 * POST /receive/finalize — replaces this site with the staged copy.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function finalize( \WP_REST_Request $request ) {
		$job = Job::current();
		if ( null === $job || ! ( 'ready' === $job->status || ( in_array( $job->status, array( 'failed', 'finalizing' ), true ) && isset( $job->state['finalize'] ) ) ) ) {
			return $this->error( __( 'The migration is not ready to be completed.', 'jcore-siirto' ), 409 );
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( strtolower( trim( (string) $request['confirm'] ) ) !== strtolower( $host ) ) {
			/* translators: %s: this site's host name */
			return $this->error( sprintf( __( 'Type %s to confirm.', 'jcore-siirto' ), $host ) );
		}

		$auth = $this->reauthenticate( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( ! Runner::lock() ) {
			return $this->error( __( 'The migration is busy in another tab. Try again in a moment.', 'jcore-siirto' ), 409 );
		}

		Runner::budget();
		ignore_user_abort( true );

		try {
			Finalizer::finalize( $job );
		} catch ( \Throwable $e ) {
			$job->status = 'failed';
			$job->error  = $e->getMessage();
			$job->save();
			Log::add( 'target', 'finalize_failed', $e->getMessage() );
		}

		Runner::unlock();

		return $this->get_status();
	}

	/**
	 * POST /receive/rollback — restores what finalize replaced.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rollback( \WP_REST_Request $request ) {
		$job = Job::current();
		if ( null === $job || ! isset( $job->state['finalize'] ) || ! in_array( $job->status, array( 'done', 'failed', 'finalizing' ), true ) ) {
			return $this->error( __( 'There is nothing to roll back.', 'jcore-siirto' ), 409 );
		}

		$auth = $this->reauthenticate( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( ! Runner::lock() ) {
			return $this->error( __( 'The migration is busy in another tab. Try again in a moment.', 'jcore-siirto' ), 409 );
		}

		Runner::budget();
		ignore_user_abort( true );

		try {
			Finalizer::rollback( $job );
		} catch ( \Throwable $e ) {
			$job->status = 'failed';
			$job->error  = $e->getMessage();
			$job->save();
			Log::add( 'target', 'rollback_failed', $e->getMessage() );
		}

		Runner::unlock();

		return $this->get_status();
	}

	/**
	 * POST /receive/cleanup — cancels an unfinished copy, or removes what a
	 * finished or rolled-back one left behind. Call until the job is gone.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cleanup( \WP_REST_Request $request ) {
		$job = Job::current();
		if ( null === $job ) {
			return $this->get_status();
		}

		if ( 'finalizing' === $job->status || ( 'failed' === $job->status && ! empty( $job->state['finalize']['moved'] ) ) || ( 'failed' === $job->status && ! empty( $job->state['finalize']['swapped'] ) ) ) {
			return $this->error( __( 'The migration stopped partway through replacing this site. Roll it back or complete it before cleaning up.', 'jcore-siirto' ), 409 );
		}

		if ( ! Runner::lock() ) {
			return $this->error( __( 'The migration is busy in another tab. Try again in a moment.', 'jcore-siirto' ), 409 );
		}

		if ( in_array( $job->status, array( 'connected', 'running', 'failed', 'ready' ), true ) ) {
			try {
				( new Client( $job ) )->call( 'finish' );
			} catch ( \RuntimeException $e ) {
				// Best effort: the key also expires on its own.
				unset( $e );
			}
		}

		Finalizer::cleanup( $job, microtime( true ) + Runner::budget() );
		Runner::unlock();

		return $this->get_status();
	}

	/**
	 * Builds an error response.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 *
	 * @return \WP_Error
	 */
	private function error( string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'jcore_siirto_error', $message, array( 'status' => $status ) );
	}
}
