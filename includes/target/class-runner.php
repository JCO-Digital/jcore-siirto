<?php
/**
 * Drives a migration forward in short, resumable steps.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Target;

use Jcore\Siirto\Crypto;
use Jcore\Siirto\Log;
use Jcore\Siirto\Roots;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Starts a job and advances it one time-boxed step per admin request.
 *
 * The admin screen calls step() in a loop. Nothing depends on WP-Cron or on a
 * single long request, so the migration works on hosts with short execution
 * limits, and closing the tab simply pauses it.
 */
final class Runner {

	/**
	 * Consecutive transport failures tolerated before the job stops.
	 */
	private const MAX_RETRIES = 5;

	/**
	 * Table names that are safe to use as identifiers after prefixing.
	 */
	private const TABLE_SUFFIX = '/^[A-Za-z0-9_$]{1,50}$/';

	/**
	 * Connects to the source and stores what it reports.
	 *
	 * @param Job $job A new job.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the source can't be used.
	 */
	public static function connect( Job $job ): void {
		$hello = ( new Client( $job ) )->json( 'hello' );

		if ( Crypto::VERSION !== ( $hello['protocol'] ?? '' ) ) {
			throw new \RuntimeException( esc_html__( 'The source site runs an incompatible version of JCORE Siirto. Update the plugin on both sites.', 'jcore-siirto' ) );
		}

		foreach ( array( 'home', 'siteurl', 'prefix' ) as $field ) {
			if ( ! is_string( $hello[ $field ] ?? null ) || '' === $hello[ $field ] ) {
				throw new \RuntimeException( esc_html__( 'The source site sent incomplete information.', 'jcore-siirto' ) );
			}
		}

		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $hello['prefix'] ) ) {
			throw new \RuntimeException( esc_html__( 'The source site uses an unsupported table prefix.', 'jcore-siirto' ) );
		}

		$job->state['source'] = $hello;
		$job->status          = 'connected';
		$job->save();

		Log::add( 'target', 'connected', 'Connected to ' . $hello['home'] );
	}

	/**
	 * Starts copying with the administrator's choices.
	 *
	 * @param Job                  $job     A connected job.
	 * @param array<string, mixed> $options `roots`, `database`, `skip_executables`, `skip_transients`, `exclude`.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If nothing was selected.
	 */
	public static function start( Job $job, array $options ): void {
		global $wpdb;

		$source = $job->state['source'];
		$roots  = array_values( array_intersect( Roots::ALL, (array) ( $options['roots'] ?? array() ), (array) ( $source['roots'] ?? array() ) ) );
		$db     = ! empty( $options['database'] ) && in_array( 'database', (array) ( $source['scopes'] ?? array() ), true );

		if ( array() === $roots && ! $db ) {
			throw new \RuntimeException( esc_html__( 'Select something to migrate.', 'jcore-siirto' ) );
		}

		$exclude = array_values(
			array_filter(
				array_map( 'trim', (array) ( $options['exclude'] ?? array() ) ),
				static fn( $pattern ) => '' !== $pattern
			)
		);

		$uploads = wp_get_upload_dir();

		$job->state['options'] = array(
			'roots'            => $roots,
			'database'         => $db,
			'skip_executables' => ! empty( $options['skip_executables'] ),
			'skip_transients'  => ! empty( $options['skip_transients'] ),
			'exclude'          => $exclude,
		);
		$job->state['target']  = array(
			'home'         => home_url(),
			'siteurl'      => site_url(),
			'abspath'      => untrailingslashit( ABSPATH ),
			'content'      => untrailingslashit( WP_CONTENT_DIR ),
			'uploads_path' => $uploads['basedir'],
			'uploads_url'  => $uploads['baseurl'],
			'prefix'       => $wpdb->prefix,
		);
		$job->state['tag']     = substr( Crypto::new_id(), 0, 6 );
		$job->state['stats']   = array();
		$job->state['skipped'] = array();

		if ( array() !== $roots ) {
			$job->state['staging'] = Staging::create();
			File_Importer::init( $job, $roots );
		}

		$tables = array();
		if ( $db ) {
			foreach ( array_keys( (array) ( $source['tables'] ?? array() ) ) as $table ) {
				$suffix = substr( (string) $table, strlen( $source['prefix'] ) );
				if ( str_starts_with( (string) $table, $source['prefix'] ) && preg_match( self::TABLE_SUFFIX, $suffix ) && strlen( $wpdb->prefix . $suffix ) <= 64 ) {
					$tables[] = (string) $table;
				} else {
					$job->skip( (string) $table, 'unsupported table name' );
				}
			}
		}
		Db_Importer::init( $job, $tables );

		$job->status = 'running';
		$job->phase  = array() !== $roots ? 'files' : 'database';
		$job->save();

		Log::add( 'target', 'started', 'Copying ' . implode( ', ', array_merge( $roots, $db ? array( 'database' ) : array() ) ) . ' from ' . $source['home'] );
	}

	/**
	 * Advances a running job until the time budget runs out.
	 *
	 * @param Job $job The job.
	 *
	 * @return void
	 */
	public static function step( Job $job ): void {
		if ( 'running' !== $job->status || ! self::lock() ) {
			return;
		}

		$deadline = microtime( true ) + self::budget();
		$client   = new Client( $job );

		try {
			while ( 'running' === $job->status && microtime( true ) < $deadline ) {
				if ( 'files' === $job->phase ) {
					if ( ! ( new File_Importer( $job, $client ) )->work() ) {
						$job->phase = 'database';
					}
				} elseif ( ! ( new Db_Importer( $job, $client ) )->work() ) {
					$job->status           = 'ready';
					$job->phase            = '';
					$job->state['current'] = '';
					Log::add( 'target', 'staged', 'All selected data copied; waiting for confirmation.' );
				}

				$job->state['retries'] = 0;
				$job->error            = '';
			}
		} catch ( Remote_Exception $e ) {
			$job->state['retries'] = (int) ( $job->state['retries'] ?? 0 ) + 1;
			$job->error            = $e->getMessage();

			if ( $job->state['retries'] >= self::MAX_RETRIES || in_array( $e->getCode(), array( 401, 403, 404 ), true ) ) {
				$job->status = 'failed';
				Log::add( 'target', 'failed', $e->getMessage() );
			}
		} catch ( \Throwable $e ) {
			$job->status = 'failed';
			$job->error  = $e->getMessage();
			Log::add( 'target', 'failed', $e->getMessage() );
		}

		$job->save();
		self::unlock();
	}

	/**
	 * Seconds of work per step, leaving headroom below the PHP time limit.
	 *
	 * @return float
	 */
	public static function budget(): float {
		if ( function_exists( 'set_time_limit' ) ) {
			// Best effort, and silenced because some hosts disable it; the
			// budget below still respects whatever limit is in force.
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
			@set_time_limit( 120 );
		}

		$limit = (int) ini_get( 'max_execution_time' );

		return $limit > 0 ? max( 3.0, min( 20.0, $limit / 2 ) ) : 20.0;
	}

	/**
	 * Takes the per-site job lock, so two tabs can't step the same job.
	 *
	 * @return bool
	 */
	public static function lock(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) );
	}

	/**
	 * Releases the job lock.
	 *
	 * @return void
	 */
	public static function unlock(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}

	/**
	 * Lock name, unique per database and table prefix.
	 *
	 * @return string
	 */
	private static function lock_name(): string {
		global $wpdb;

		return 'jcore_siirto_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 16 );
	}
}
