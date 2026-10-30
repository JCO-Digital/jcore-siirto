<?php
/**
 * Puts a staged migration live, rolls it back, or cleans up after it.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Target;

use Jcore\Siirto\Database;
use Jcore\Siirto\Log;
use Jcore\Siirto\Roots;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Renaming and dropping tables the job created; every name is built from the job's tag and validated.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Same-filesystem rename() is what makes the switch-over near-atomic; see Staging.

/**
 * The irreversible-looking steps, each made reversible.
 *
 * Every live directory or table that gets replaced is renamed aside rather
 * than deleted, and every move is recorded in the job before the next one, so
 * a migration can be rolled back until the administrator cleans up.
 */
final class Finalizer {

	/**
	 * Replaces the live site with the staged copy.
	 *
	 * Resumable: if the request dies midway, calling it again continues from
	 * the last recorded move.
	 *
	 * @param Job $job The job.
	 *
	 * @return void
	 */
	public static function finalize( Job $job ): void {
		$job->status              = 'finalizing';
		$job->error               = '';
		$job->state['finalize'] ??= array(
			'files'   => false,
			'db'      => false,
			'moved'   => array(),
			'swapped' => array(),
		);
		$job->save();

		Log::add( 'target', 'finalize', 'Replacing this site with ' . ( $job->state['key']['url'] ?? '' ) );

		if ( ! $job->state['finalize']['files'] ) {
			self::move_files( $job );
			$job->state['finalize']['files'] = true;
			$job->save();
		}

		if ( ! $job->state['finalize']['db'] && ! empty( $job->state['options']['database'] ) ) {
			self::swap_tables( $job );
			$job->state['finalize']['db'] = true;
			$job->save();
			self::after_swap( $job );
		}

		self::flush_caches();

		try {
			( new Client( $job ) )->call( 'finish' );
		} catch ( \RuntimeException $e ) {
			// The key expires on its own; failing to revoke it early isn't fatal.
			Log::add( 'target', 'finish_failed', $e->getMessage() );
		}

		$job->forget_key();
		$job->status = 'done';
		$job->phase  = '';
		$job->save();

		Log::add( 'target', 'done', 'Migration finished.' );
	}

	/**
	 * Restores the files and tables that finalize() replaced.
	 *
	 * @param Job $job The job.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If something can't be moved back.
	 */
	public static function rollback( Job $job ): void {
		global $wpdb;

		$finalize = $job->state['finalize'] ?? array();
		$staging  = (string) $job->staging_dir();

		if ( ! empty( $finalize['swapped'] ) ) {
			$renames = array();
			foreach ( $finalize['swapped'] as list( $suffix, $had_old ) ) {
				$live      = $wpdb->prefix . $suffix;
				$renames[] = self::q( $live ) . ' TO ' . self::q( self::temp( $job, $suffix ) );
				if ( $had_old ) {
					$renames[] = self::q( Db_Importer::old_name( $job, $suffix ) ) . ' TO ' . self::q( $live );
				}
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Names quoted by q().
			if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) {
				throw new \RuntimeException( esc_html( 'Could not restore the database tables: ' . $wpdb->last_error ) );
			}

			$job->state['finalize']['swapped'] = array();
			$job->state['finalize']['db']      = false;
			$job->save();
		}

		$moved = $finalize['moved'] ?? array();
		while ( array() !== $moved ) {
			list( $root, $name, $had_backup ) = array_pop( $moved );

			$live   = Roots::dir( $root ) . '/' . $name;
			$staged = $staging . '/' . $root . '/' . $name;
			$backup = $staging . '/_backup/' . $root . '/' . $name;

			if ( ( file_exists( $live ) || is_link( $live ) ) && ! rename( $live, $staged ) ) {
				throw new \RuntimeException( esc_html( 'Could not move aside ' . $live ) );
			}

			if ( $had_backup && ! rename( $backup, $live ) ) {
				throw new \RuntimeException( esc_html( 'Could not restore ' . $live ) );
			}

			$job->state['finalize']['moved'] = $moved;
			$job->save();
		}

		self::flush_caches();

		$job->state['finalize']['files'] = false;
		$job->state['outcome']           = 'rolled_back';
		$job->status                     = 'rolled_back';
		$job->save();

		Log::add( 'target', 'rolled_back', 'Migration rolled back.' );
	}

	/**
	 * Drops the job's temporary and set-aside tables and deletes staging.
	 *
	 * @param Job   $job      The job.
	 * @param float $deadline microtime( true ) to stop at.
	 *
	 * @return bool Whether cleanup is complete.
	 */
	public static function cleanup( Job $job, float $deadline ): bool {
		global $wpdb;

		if ( 'cleaning' !== $job->status ) {
			$job->state['outcome'] ??= 'done' === $job->status ? 'migrated' : 'cancelled';
			$job->status             = 'cleaning';
			$job->save();
		}

		foreach ( $job->state['db']['created'] ?? array() as $temp ) {
			$suffix = substr( (string) $temp, strlen( 'jt' . $job->state['tag'] . '_' ) );
			foreach ( array( self::temp( $job, $suffix ), Db_Importer::old_name( $job, $suffix ) ) as $table ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			}
		}

		$staging = $job->staging_dir();
		if ( null !== $staging && ! Staging::delete( $staging, $deadline ) ) {
			return false;
		}

		$job->forget_key();
		$job->status = 'cleaned';
		$job->save();

		Log::add( 'target', 'cleaned', 'Temporary tables and files removed.' );

		return true;
	}

	/**
	 * Moves each staged top-level entry into place, setting aside what it replaces.
	 *
	 * @param Job $job The job.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If a move fails.
	 */
	private static function move_files( Job $job ): void {
		$staging = (string) $job->staging_dir();

		foreach ( $job->state['options']['roots'] ?? array() as $root ) {
			$from_base = $staging . '/' . $root;
			if ( ! is_dir( $from_base ) ) {
				continue;
			}

			if ( 'mu-plugins' === $root ) {
				wp_mkdir_p( WPMU_PLUGIN_DIR );
			}

			$to_base = Roots::dir( $root );
			if ( null === $to_base ) {
				throw new \RuntimeException( esc_html( 'Destination directory for ' . $root . ' is missing.' ) );
			}

			foreach ( array_diff( (array) scandir( $from_base ), array( '.', '..' ) ) as $name ) {
				$from = $from_base . '/' . $name;

				if ( is_link( $from ) || ! Roots::valid_relative( $name ) || Roots::is_excluded( $root, $name, is_dir( $from ) ) ) {
					continue;
				}

				$job->state['current'] = $root . '/' . $name;

				$to         = $to_base . '/' . $name;
				$backup     = $staging . '/_backup/' . $root . '/' . $name;
				$had_backup = file_exists( $to ) || is_link( $to );

				if ( $had_backup ) {
					wp_mkdir_p( dirname( $backup ) );
					if ( ! rename( $to, $backup ) ) {
						throw new \RuntimeException( esc_html( 'Could not move aside ' . $to ) );
					}
				}

				if ( ! rename( $from, $to ) ) {
					if ( $had_backup ) {
						rename( $backup, $to );
					}
					throw new \RuntimeException( esc_html( 'Could not move ' . $root . '/' . $name . ' into place.' ) );
				}

				$job->state['finalize']['moved'][] = array( $root, $name, $had_backup );
				$job->save();
			}
		}
	}

	/**
	 * Swaps the temporary tables in with one atomic RENAME TABLE.
	 *
	 * @param Job $job The job.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the swap fails; nothing has changed then.
	 */
	private static function swap_tables( Job $job ): void {
		global $wpdb;

		$renames = array();
		$swapped = array();

		foreach ( $job->state['db']['created'] ?? array() as $temp ) {
			$suffix = substr( (string) $temp, strlen( 'jt' . $job->state['tag'] . '_' ) );
			$live   = $wpdb->prefix . $suffix;
			$old    = Db_Importer::old_name( $job, $suffix );

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) ) ) {
				throw new \RuntimeException( esc_html( 'Table ' . $old . ' already exists.' ) );
			}

			$has_live = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $live ) ) );
			if ( $has_live ) {
				$renames[] = self::q( $live ) . ' TO ' . self::q( $old );
			}
			$renames[] = self::q( (string) $temp ) . ' TO ' . self::q( $live );
			$swapped[] = array( $suffix, $has_live );
		}

		if ( array() === $renames ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Names quoted by q().
		if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) {
			throw new \RuntimeException( esc_html( 'Could not swap the database tables: ' . $wpdb->last_error ) );
		}

		$job->state['finalize']['swapped'] = $swapped;
	}

	/**
	 * Fixes up the freshly swapped-in options.
	 *
	 * Uses SQL rather than the options API, whose caches still hold the old
	 * site's values in this request.
	 *
	 * @param Job $job The job.
	 *
	 * @return void
	 */
	private static function after_swap( Job $job ): void {
		global $wpdb;

		$options = $wpdb->prefix . 'options';
		$target  = $job->state['target'];

		foreach ( array( 'home', 'siteurl' ) as $name ) {
			$wpdb->update( $options, array( 'option_value' => $target[ $name ] ), array( 'option_name' => $name ) );
		}

		$wpdb->delete( $options, array( 'option_name' => 'rewrite_rules' ) );

		// Keep this plugin active, whatever its folder was called on the source.
		$raw     = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $options, 'active_plugins' ) );
		$plugins = is_serialized( $raw ) ? unserialize( $raw, array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Objects are refused.
		$plugins = is_array( $plugins ) ? array_values( array_filter( $plugins, 'is_string' ) ) : array();
		$own     = plugin_basename( JCORE_SIIRTO_FILE );

		$plugins = array_values(
			array_filter(
				$plugins,
				static fn( string $plugin ): bool => ! str_ends_with( $plugin, '/' . basename( JCORE_SIIRTO_FILE ) ) || $plugin === $own
			)
		);
		if ( ! in_array( $own, $plugins, true ) ) {
			$plugins[] = $own;
		}

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
				$options,
				'active_plugins',
				serialize( $plugins ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The format WordPress stores this option in.
				'yes'
			)
		);

		wp_cache_flush();
		Database::install();
	}

	/**
	 * Clears caches that would keep serving the old site.
	 *
	 * @return void
	 */
	private static function flush_caches(): void {
		wp_cache_flush();

		if ( function_exists( 'opcache_reset' ) ) {
			opcache_reset();
		}
	}

	/**
	 * Name of a table's temporary copy.
	 *
	 * @param Job    $job    The job.
	 * @param string $suffix Unprefixed table name.
	 *
	 * @return string
	 */
	private static function temp( Job $job, string $suffix ): string {
		return 'jt' . $job->state['tag'] . '_' . $suffix;
	}

	/**
	 * Quotes an identifier.
	 *
	 * @param string $name Identifier.
	 *
	 * @return string
	 */
	private static function q( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}
}
