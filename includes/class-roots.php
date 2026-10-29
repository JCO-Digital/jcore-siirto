<?php
/**
 * The directories a migration may read from and write to.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps the transferable file groups ("roots") to directories and decides which
 * paths inside them are off limits.
 *
 * Every path that crosses the wire is relative to one of these roots, and is
 * validated on both sides: the source refuses to read outside a root, the
 * target refuses to write outside its staging copy of one.
 */
final class Roots {

	/**
	 * The file groups, in transfer order.
	 */
	public const ALL = array( 'themes', 'plugins', 'mu-plugins', 'uploads', 'content', 'root' );

	/**
	 * Path segments never transferred, at any depth.
	 */
	private const SKIP_ANYWHERE = array( '.git', '.svn', '.hg', 'node_modules', '.DS_Store' );

	/**
	 * Top-level entries of wp-content never transferred: caches, backups, logs
	 * and host-specific drop-ins that would break or bloat the target.
	 */
	private const SKIP_CONTENT = array(
		'cache',
		'upgrade',
		'upgrade-temp-backup',
		'backup',
		'backups',
		'backup-db',
		'backups-dup-lite',
		'ai1wm-backups',
		'updraft',
		'wpvividbackups',
		'wflogs',
		'et-cache',
		'litespeed',
		'w3tc-config',
		'debug.log',
		'object-cache.php',
		'advanced-cache.php',
		'db.php',
		'db-error.php',
		'maintenance.php',
	);

	/**
	 * Files in the WordPress root that belong to core or configure the server.
	 * Only other plain files there (robots.txt, verification files) are moved.
	 */
	private const SKIP_ROOT = array(
		'index.php',
		'xmlrpc.php',
		'license.txt',
		'readme.html',
		'.htaccess',
		'.user.ini',
		'php.ini',
		'web.config',
		'error_log',
		'.env',
		'.maintenance',
	);

	/**
	 * Matches file names a web server may execute or obey as configuration.
	 * Also catches double extensions such as `shell.php.jpg`.
	 */
	private const EXECUTABLE = '/(\.(php\d?|phtml|phar|pht|phps|pl|py|cgi|sh|asp|aspx|jsp|shtml)(\.|$))|^(\.htaccess|\.user\.ini|php\.ini|web\.config)$/i';

	/**
	 * Staging directories the target creates inside wp-content.
	 */
	public const STAGING_PREFIX = 'jcore-siirto-';

	/**
	 * Returns the canonical absolute directory of a root, without trailing slash.
	 *
	 * @param string $root One of ALL.
	 *
	 * @return string|null Null if the root is unknown or its directory is missing.
	 */
	public static function dir( string $root ): ?string {
		switch ( $root ) {
			case 'themes':
				$dir = get_theme_root();
				break;
			case 'plugins':
				$dir = WP_PLUGIN_DIR;
				break;
			case 'mu-plugins':
				$dir = WPMU_PLUGIN_DIR;
				break;
			case 'uploads':
				$dir = wp_get_upload_dir()['basedir'];
				break;
			case 'content':
				$dir = WP_CONTENT_DIR;
				break;
			case 'root':
				$dir = ABSPATH;
				break;
			default:
				return null;
		}

		$real = realpath( $dir );

		return false === $real || ! is_dir( $real ) ? null : untrailingslashit( $real );
	}

	/**
	 * Checks that a relative path has no traversal or other surprises.
	 *
	 * @param string $rel Path relative to a root; `''` is the root itself.
	 *
	 * @return bool
	 */
	public static function valid_relative( string $rel ): bool {
		if ( '' === $rel ) {
			return true;
		}

		if ( strlen( $rel ) > 1024 || str_starts_with( $rel, '/' ) || preg_match( '/[\x00-\x1f\\\\]/', $rel ) ) {
			return false;
		}

		foreach ( explode( '/', $rel ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolves a relative path to an existing absolute path inside a root.
	 *
	 * Refuses symlinks anywhere below the root, so a link can't be used to
	 * reach files outside it.
	 *
	 * @param string $root One of ALL.
	 * @param string $rel  Relative path.
	 *
	 * @return string|null
	 */
	public static function resolve( string $root, string $rel ): ?string {
		$base = self::dir( $root );
		if ( null === $base || ! self::valid_relative( $rel ) ) {
			return null;
		}

		if ( '' === $rel ) {
			return $base;
		}

		$path = $base;
		foreach ( explode( '/', $rel ) as $segment ) {
			$path .= '/' . $segment;
			if ( is_link( $path ) ) {
				return null;
			}
		}

		$real = realpath( $path );
		if ( false === $real || ! str_starts_with( $real, $base . '/' ) ) {
			return null;
		}

		return $real;
	}

	/**
	 * Whether a path is excluded from every migration, regardless of options.
	 *
	 * Applied by the source when listing and reading, and again by the target
	 * before writing, so neither side has to trust the other on this.
	 *
	 * @param string $root   One of ALL.
	 * @param string $rel    Relative path.
	 * @param bool   $is_dir Whether the path is a directory.
	 *
	 * @return bool
	 */
	public static function is_excluded( string $root, string $rel, bool $is_dir ): bool {
		if ( '' === $rel ) {
			return false;
		}

		$segments = explode( '/', $rel );
		$top      = $segments[0];
		$name     = (string) end( $segments );

		foreach ( $segments as $segment ) {
			if ( in_array( $segment, self::SKIP_ANYWHERE, true ) ) {
				return true;
			}
		}

		switch ( $root ) {
			case 'plugins':
				// This plugin runs the migration and must survive it unchanged.
				return self::own_plugin_dir() === $top;

			case 'content':
				return in_array( strtolower( $top ), self::SKIP_CONTENT, true )
					|| str_starts_with( $top, self::STAGING_PREFIX )
					|| in_array( $top, self::content_subroots(), true );

			case 'root':
				// Only plain files directly in the WordPress root.
				return $is_dir
					|| count( $segments ) > 1
					|| str_starts_with( $name, 'wp-' )
					|| in_array( strtolower( $name ), self::SKIP_ROOT, true )
					|| self::is_executable( $name );
		}

		return false;
	}

	/**
	 * Whether a file name is something a web server could execute.
	 *
	 * @param string $name File name.
	 *
	 * @return bool
	 */
	public static function is_executable( string $name ): bool {
		return 1 === preg_match( self::EXECUTABLE, $name );
	}

	/**
	 * Whether a path matches one of the administrator's extra exclusion patterns.
	 *
	 * Patterns are shell globs matched against `root/relative/path`, for example
	 * `uploads/2015/*` or `*.zip`.
	 *
	 * @param string   $root     One of ALL.
	 * @param string   $rel      Relative path.
	 * @param string[] $patterns Glob patterns.
	 *
	 * @return bool
	 */
	public static function matches_pattern( string $root, string $rel, array $patterns ): bool {
		$full = $root . '/' . $rel;
		$name = basename( $rel );

		foreach ( $patterns as $pattern ) {
			if ( fnmatch( $pattern, $full ) || fnmatch( $pattern, $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Directory name of this plugin inside the plugins directory.
	 *
	 * @return string
	 */
	public static function own_plugin_dir(): string {
		return dirname( plugin_basename( JCORE_SIIRTO_FILE ) );
	}

	/**
	 * Names of the top-level wp-content entries that are roots of their own.
	 *
	 * @return string[]
	 */
	private static function content_subroots(): array {
		static $names = null;
		if ( null === $names ) {
			$names = self::children_of( 'content', array( 'themes', 'plugins', 'mu-plugins', 'uploads' ) );
		}

		return $names;
	}

	/**
	 * Returns the names of the given roots that sit directly inside a parent root.
	 *
	 * @param string   $parent_root Parent root.
	 * @param string[] $children    Candidate child roots.
	 *
	 * @return string[]
	 */
	private static function children_of( string $parent_root, array $children ): array {
		$parent_dir = self::dir( $parent_root );
		$names      = array();

		foreach ( $children as $child ) {
			$dir = self::dir( $child );
			if ( null !== $dir && null !== $parent_dir && dirname( $dir ) === $parent_dir ) {
				$names[] = basename( $dir );
			}
		}

		return $names;
	}
}
