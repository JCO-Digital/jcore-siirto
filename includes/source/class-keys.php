<?php
/**
 * Migration keys issued by the source site.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Source;

use Jcore\Siirto\Crypto;
use Jcore\Siirto\Database;
use Jcore\Siirto\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, checks and revokes the keys that let a target site read this one.
 *
 * A key is short-lived, scoped (files and/or database), bound to the first
 * target that uses it, and revoked when that target reports it has finished.
 */
final class Keys {

	/**
	 * Scopes a key can grant.
	 */
	public const SCOPES = array( 'files', 'database' );

	/**
	 * Longest lifetime an administrator can choose, in hours.
	 */
	public const MAX_HOURS = 72;

	/**
	 * Failed signature checks after which a key is revoked.
	 */
	private const MAX_FAILURES = 10;

	/**
	 * Creates a key and returns the string to paste into the target.
	 *
	 * @param int      $hours  Lifetime in hours.
	 * @param string[] $scopes Granted scopes.
	 *
	 * @return string
	 */
	public static function create( int $hours, array $scopes ): string {
		global $wpdb;

		$id      = Crypto::new_id();
		$secret  = Crypto::new_secret();
		$hours   = max( 1, min( self::MAX_HOURS, $hours ) );
		$expires = time() + $hours * HOUR_IN_SECONDS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$wpdb->insert(
			Database::table( 'keys' ),
			array(
				'id'         => $id,
				'secret'     => Crypto::wrap( $secret ),
				'scopes'     => implode( ',', array_intersect( self::SCOPES, $scopes ) ),
				'created_by' => get_current_user_id(),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $expires ),
			)
		);

		self::refresh_window();

		Log::add(
			'source',
			'key_created',
			sprintf( 'Scopes: %s. Expires %s UTC.', implode( ', ', $scopes ), gmdate( 'Y-m-d H:i', $expires ) ),
			$id
		);

		return Crypto::encode_key( home_url(), $id, $secret );
	}

	/**
	 * Returns a usable key: known, not revoked, not expired, not locked out.
	 *
	 * @param string $id Key ID.
	 *
	 * @return array<string, mixed>|null Row with the raw secret under `secret`.
	 */
	public static function find_active( string $id ): ?array {
		global $wpdb;

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must not be cached.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %s AND revoked = 0 AND expires_at > %s AND failures < %d',
				Database::table( 'keys' ),
				$id,
				gmdate( 'Y-m-d H:i:s' ),
				self::MAX_FAILURES
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$secret = Crypto::unwrap( (string) $row['secret'] );
		if ( null === $secret ) {
			return null;
		}

		$row['secret'] = $secret;
		$row['scopes'] = array_filter( explode( ',', (string) $row['scopes'] ) );

		return $row;
	}

	/**
	 * Binds a key to the first target client that presents a valid signature.
	 *
	 * @param string $id        Key ID.
	 * @param string $client_id Client ID from the request.
	 *
	 * @return bool Whether the key is (now) bound to this client.
	 */
	public static function bind( string $id, string $client_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set on a custom table.
		$bound = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET client_id = %s WHERE id = %s AND client_id = ''",
				Database::table( 'keys' ),
				$client_id,
				$id
			)
		);

		if ( 1 === $bound ) {
			Log::add( 'source', 'key_bound', 'A target site connected with this key.', $id );
			self::notify_first_use( $id );

			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must not be cached.
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT client_id FROM %i WHERE id = %s', Database::table( 'keys' ), $id ) );

		return hash_equals( (string) $current, $client_id );
	}

	/**
	 * Consumes a sequence number. Each may be used once, and only in increasing
	 * order, which makes a captured request worthless.
	 *
	 * @param string $id  Key ID.
	 * @param int    $seq Sequence number from the request.
	 *
	 * @return bool
	 */
	public static function consume_seq( string $id, int $seq ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set on a custom table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET last_seq = %d, last_used_at = %s, last_ip = %s WHERE id = %s AND last_seq < %d',
				Database::table( 'keys' ),
				$seq,
				gmdate( 'Y-m-d H:i:s' ),
				Log::ip(),
				$id,
				$seq
			)
		);

		return 1 === $updated;
	}

	/**
	 * Counts a failed signature check against a key.
	 *
	 * @param string $id Key ID.
	 *
	 * @return void
	 */
	public static function record_failure( string $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET failures = failures + 1 WHERE id = %s', Database::table( 'keys' ), $id ) );
	}

	/**
	 * Revokes a key.
	 *
	 * @param string $id     Key ID.
	 * @param string $reason Logged reason.
	 *
	 * @return bool Whether a key was revoked.
	 */
	public static function revoke( string $id, string $reason ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked = 1 WHERE id = %s AND revoked = 0', Database::table( 'keys' ), $id ) );

		self::refresh_window();

		if ( $updated ) {
			Log::add( 'source', 'key_revoked', $reason, $id );
		}

		return (bool) $updated;
	}

	/**
	 * Lists keys for the admin screen, without secrets.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, scopes, client_id, failures, revoked, created_by, created_at, expires_at, last_used_at, last_ip FROM %i ORDER BY created_at DESC LIMIT 50',
				Database::table( 'keys' )
			),
			ARRAY_A
		);

		$now = gmdate( 'Y-m-d H:i:s' );

		return array_map(
			static function ( array $row ) use ( $now ): array {
				$status = 'active';
				if ( (int) $row['revoked'] ) {
					$status = 'revoked';
				} elseif ( $row['expires_at'] <= $now ) {
					$status = 'expired';
				} elseif ( (int) $row['failures'] >= self::MAX_FAILURES ) {
					$status = 'locked';
				}

				return array(
					'id'           => $row['id'],
					'scopes'       => array_filter( explode( ',', (string) $row['scopes'] ) ),
					'bound'        => '' !== $row['client_id'],
					'status'       => $status,
					'created_by'   => (int) $row['created_by'],
					'created_at'   => $row['created_at'],
					'expires_at'   => $row['expires_at'],
					'last_used_at' => $row['last_used_at'],
					'last_ip'      => $row['last_ip'],
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Stores until when any key could still be valid.
	 *
	 * The source endpoints are only registered before that time, so a site
	 * that isn't being migrated exposes no migration routes at all.
	 *
	 * @return void
	 */
	public static function refresh_window(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$latest = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(expires_at) FROM %i WHERE revoked = 0 AND expires_at > %s',
				Database::table( 'keys' ),
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		if ( $latest ) {
			update_option( Database::SOURCE_UNTIL_OPTION, strtotime( $latest . ' UTC' ), true );
		} else {
			delete_option( Database::SOURCE_UNTIL_OPTION );
		}
	}

	/**
	 * Whether any key might currently be valid.
	 *
	 * @return bool
	 */
	public static function window_open(): bool {
		return (int) get_option( Database::SOURCE_UNTIL_OPTION, 0 ) > time();
	}

	/**
	 * Emails the site administrator when a key is first used, so a leaked key
	 * being used by someone else doesn't go unnoticed.
	 *
	 * @param string $id Key ID.
	 *
	 * @return void
	 */
	private static function notify_first_use( string $id ): void {
		wp_mail(
			get_option( 'admin_email' ),
			/* translators: %s: site name */
			sprintf( __( '[%s] A site is copying this site with JCORE Siirto', 'jcore-siirto' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf(
				/* translators: 1: site URL, 2: key ID, 3: IP address, 4: admin URL */
				__( "A target site has started copying %1\$s using migration key %2\$s, from IP address %3\$s.\n\nIf you did not start a migration, revoke the key immediately:\n%4\$s", 'jcore-siirto' ),
				home_url(),
				substr( $id, 0, 8 ),
				Log::ip(),
				admin_url( 'tools.php?page=jcore-siirto' )
			)
		);
	}
}
