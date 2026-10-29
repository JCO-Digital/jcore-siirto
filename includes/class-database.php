<?php
/**
 * Database setup and migrations.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the plugin's own tables.
 *
 * These tables are never exported to, or overwritten on, another site: they hold
 * migration keys and job state, which must stay with the installation that
 * created them.
 */
final class Database {

	private const DB_VERSION        = '1.0';
	private const DB_VERSION_OPTION = 'jcore_siirto_db_version';

	/**
	 * Option holding the time (Unix seconds) until which the source endpoints are
	 * registered. Autoloaded, because it is read on every REST request.
	 */
	public const SOURCE_UNTIL_OPTION = 'jcore_siirto_source_until';

	/**
	 * Prefix of every option, transient and table the plugin owns.
	 */
	public const NAME_PREFIX = 'jcore_siirto_';

	/**
	 * Unprefixed table names, keyed by the short name the rest of the plugin uses.
	 */
	private const TABLES = array(
		'keys' => 'jcore_siirto_keys',
		'jobs' => 'jcore_siirto_jobs',
		'log'  => 'jcore_siirto_log',
	);

	/**
	 * Returns the prefixed name of one of the plugin's tables.
	 *
	 * @param string $name One of `keys`, `jobs` or `log`.
	 *
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLES[ $name ];
	}

	/**
	 * Runs install() if the stored DB version is behind the current one.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		if ( get_transient( 'jcore_siirto_upgrading' ) ) {
			return;
		}
		set_transient( 'jcore_siirto_upgrading', true, 60 );

		self::install();

		delete_transient( 'jcore_siirto_upgrading' );
	}

	/**
	 * Creates the tables. Run on activation, upgrade and after a database swap.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( 'keys' ) . " (
			  id           VARCHAR(32)     NOT NULL,
			  secret       TEXT            NOT NULL,
			  scopes       VARCHAR(100)    NOT NULL DEFAULT '',
			  client_id    VARCHAR(64)     NOT NULL DEFAULT '',
			  last_seq     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			  failures     INT UNSIGNED    NOT NULL DEFAULT 0,
			  revoked      TINYINT(1)      NOT NULL DEFAULT 0,
			  created_by   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			  created_at   DATETIME        NOT NULL,
			  expires_at   DATETIME        NOT NULL,
			  last_used_at DATETIME        NULL DEFAULT NULL,
			  last_ip      VARCHAR(45)     NOT NULL DEFAULT '',
			  PRIMARY KEY  (id)
			) " . $charset . ';'
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'jobs' ) . " (
			  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			  status     VARCHAR(20)     NOT NULL DEFAULT '',
			  phase      VARCHAR(20)     NOT NULL DEFAULT '',
			  client_id  VARCHAR(64)     NOT NULL DEFAULT '',
			  seq        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			  state      LONGTEXT        NOT NULL,
			  error      TEXT            NOT NULL,
			  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			  created_at DATETIME        NOT NULL,
			  updated_at DATETIME        NOT NULL,
			  PRIMARY KEY  (id),
			  KEY status (status)
			) " . $charset . ';'
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'log' ) . " (
			  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			  created_at DATETIME        NOT NULL,
			  role       VARCHAR(10)     NOT NULL DEFAULT '',
			  event      VARCHAR(40)     NOT NULL DEFAULT '',
			  key_id     VARCHAR(32)     NOT NULL DEFAULT '',
			  user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			  ip         VARCHAR(45)     NOT NULL DEFAULT '',
			  message    TEXT            NOT NULL,
			  PRIMARY KEY  (id),
			  KEY created_at (created_at)
			) " . $charset . ';'
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}
}
