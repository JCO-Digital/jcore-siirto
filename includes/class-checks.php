<?php
/**
 * Environment checks shown before a migration.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto;

use Jcore\Siirto\Source\Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports what this installation can do as a source or a target, and why not.
 */
final class Checks {

	/**
	 * Whether this site can act as a source at all.
	 *
	 * @return array{ok: bool, notices: array<int, array{level: string, message: string}>}
	 */
	public static function source(): array {
		$notices = array();
		$ok      = true;

		if ( is_multisite() ) {
			$ok        = false;
			$notices[] = self::notice( 'error', __( 'Multisite networks are not supported.', 'jcore-siirto' ) );
		}

		if ( ! is_ssl() && ! Server::allow_http() ) {
			$ok        = false;
			$notices[] = self::notice( 'error', __( 'This site must be served over HTTPS to send a migration.', 'jcore-siirto' ) );
		} elseif ( ! is_ssl() ) {
			$notices[] = self::notice( 'warning', __( 'JCORE_SIIRTO_ALLOW_HTTP is set. Data is still encrypted, but only use this for local development.', 'jcore-siirto' ) );
		}

		if ( ! extension_loaded( 'sodium' ) ) {
			$notices[] = self::notice( 'info', __( 'The PHP sodium extension is missing. Encryption falls back to a slower PHP implementation, so file transfers use smaller chunks.', 'jcore-siirto' ) );
		}

		return array(
			'ok'      => $ok,
			'notices' => $notices,
		);
	}

	/**
	 * What this site can receive.
	 *
	 * @return array{files: bool, database: bool, notices: array<int, array{level: string, message: string}>, free_bytes: int|null}
	 */
	public static function target(): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$notices  = array();
		$files    = true;
		$database = true;

		if ( is_multisite() ) {
			$files     = false;
			$database  = false;
			$notices[] = self::notice( 'error', __( 'Multisite networks are not supported.', 'jcore-siirto' ) );
		}

		if ( ! wp_is_file_mod_allowed( 'jcore_siirto' ) ) {
			$files     = false;
			$notices[] = self::notice( 'warning', __( 'File changes are disabled on this site (DISALLOW_FILE_MODS), so only the database can be migrated.', 'jcore-siirto' ) );
		} elseif ( 'direct' !== get_filesystem_method( array(), WP_CONTENT_DIR ) || ! wp_is_writable( WP_CONTENT_DIR ) ) {
			$files     = false;
			$notices[] = self::notice( 'warning', __( 'PHP cannot write to wp-content directly, so only the database can be migrated.', 'jcore-siirto' ) );
		}

		if ( ! Server::allow_http() ) {
			$notices[] = self::notice( 'info', __( 'The source site must use HTTPS.', 'jcore-siirto' ) );
		}

		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 && $limit < 20 ) {
			$notices[] = self::notice( 'info', __( 'PHP has a short time limit here. The migration still works, in smaller steps.', 'jcore-siirto' ) );
		}

		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Often disabled or restricted by open_basedir.

		return array(
			'files'      => $files,
			'database'   => $database,
			'notices'    => $notices,
			'free_bytes' => false === $free ? null : (int) $free,
		);
	}

	/**
	 * Builds a notice.
	 *
	 * @param string $level   `error`, `warning` or `info`.
	 * @param string $message Message.
	 *
	 * @return array{level: string, message: string}
	 */
	private static function notice( string $level, string $message ): array {
		return array(
			'level'   => $level,
			'message' => $message,
		);
	}
}
