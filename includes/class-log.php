<?php
/**
 * Audit log.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records security-relevant events on both sides of a migration.
 */
final class Log {

	/**
	 * Days entries are kept.
	 */
	private const RETENTION_DAYS = 90;

	/**
	 * Writes an entry.
	 *
	 * @param string $role    `source` or `target`.
	 * @param string $event   Short machine-readable event name.
	 * @param string $message Human-readable detail. Never include secrets.
	 * @param string $key_id  Related key ID, if any.
	 *
	 * @return void
	 */
	public static function add( string $role, string $event, string $message = '', string $key_id = '' ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$wpdb->insert(
			Database::table( 'log' ),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'role'       => $role,
				'event'      => $event,
				'key_id'     => $key_id,
				'user_id'    => get_current_user_id(),
				'ip'         => self::ip(),
				'message'    => $message,
			)
		);

		if ( 0 === wp_rand( 0, 49 ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE created_at < %s',
					Database::table( 'log' ),
					gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS )
				)
			);
		}
	}

	/**
	 * Returns the most recent entries.
	 *
	 * @param string $role  `source` or `target`.
	 * @param int    $limit Maximum number of entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( string $role, int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, event, key_id, user_id, ip, message FROM %i WHERE role = %s ORDER BY id DESC LIMIT %d',
				Database::table( 'log' ),
				$role,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns the client IP as the web server saw it.
	 *
	 * Forwarded headers are deliberately ignored: they are trivially spoofed and
	 * this value is only used for the audit trail and rate limiting.
	 *
	 * @return string
	 */
	public static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip;
	}
}
