<?php
/**
 * The directory files are downloaded into before they replace anything.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Target;

use Jcore\Siirto\Crypto;
use Jcore\Siirto\Roots;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem can't append byte ranges or rename directories atomically, and a migration has to run without FTP credentials; Checks::environment() requires the direct method.

/**
 * File operations confined to one job's staging directory.
 *
 * The staging directory lives inside wp-content, so moving staged files into
 * place is a same-filesystem rename(). Its name has 128 random bits, and it
 * carries deny rules for servers that honour them.
 */
final class Staging {

	/**
	 * Creates a staging directory and returns its name.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException If it can't be created.
	 */
	public static function create(): string {
		$name = Roots::STAGING_PREFIX . Crypto::new_id();
		$path = untrailingslashit( WP_CONTENT_DIR ) . '/' . $name;

		if ( ! wp_mkdir_p( $path ) ) {
			throw new \RuntimeException( esc_html__( 'Could not create the staging directory in wp-content.', 'jcore-siirto' ) );
		}

		// Not the plugin folder: wp-content/jcore-siirto-*. It can't live in
		// uploads, which is often another filesystem, and the final rename()
		// into plugins/ and themes/ must stay on one.
		file_put_contents( $path . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
		file_put_contents( $path . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite

		return $name;
	}

	/**
	 * Writes a chunk of a staged file.
	 *
	 * @param string $staging Staging directory.
	 * @param string $root    Root the file belongs to.
	 * @param string $rel     Path relative to the root.
	 * @param int    $offset  Byte offset of the chunk; 0 starts the file afresh.
	 * @param string $data    Bytes.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException On an unsafe path or a write failure.
	 */
	public static function write( string $staging, string $root, string $rel, int $offset, string $data ): void {
		$path = self::path( $staging, $root, $rel );

		if ( ! wp_mkdir_p( dirname( $path ) ) ) {
			throw new \RuntimeException( esc_html( 'Could not create directory for ' . $root . '/' . $rel ) );
		}

		if ( is_link( $path ) || ( $offset > 0 && ( ! is_file( $path ) || filesize( $path ) !== $offset ) ) ) {
			throw new \RuntimeException( esc_html( 'Staged file is out of step: ' . $root . '/' . $rel ) );
		}

		clearstatcache( true, $path );
		$handle = fopen( $path, 0 === $offset ? 'wb' : 'ab' );
		if ( false === $handle ) {
			throw new \RuntimeException( esc_html( 'Could not write ' . $root . '/' . $rel ) );
		}

		$written = fwrite( $handle, $data );
		fclose( $handle );

		if ( strlen( $data ) !== $written ) {
			throw new \RuntimeException( esc_html( 'Could not write ' . $root . '/' . $rel . '. Is the disk full?' ) );
		}
	}

	/**
	 * Creates a staged directory that has nothing in it on the source.
	 *
	 * @param string $staging Staging directory.
	 * @param string $root    Root the directory belongs to.
	 * @param string $rel     Path relative to the root.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException On an unsafe path.
	 */
	public static function mkdir( string $staging, string $root, string $rel ): void {
		wp_mkdir_p( self::path( $staging, $root, $rel ) );
	}

	/**
	 * Sets a staged file's modification time.
	 *
	 * @param string $staging Staging directory.
	 * @param string $root    Root.
	 * @param string $rel     Relative path.
	 * @param int    $mtime   Unix time.
	 *
	 * @return void
	 */
	public static function touch( string $staging, string $root, string $rel, int $mtime ): void {
		if ( $mtime > 0 ) {
			touch( self::path( $staging, $root, $rel ), $mtime );
		}
	}

	/**
	 * Maps a root-relative path into the staging directory, refusing anything
	 * that could land outside it.
	 *
	 * @param string $staging Staging directory.
	 * @param string $root    Root.
	 * @param string $rel     Relative path.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException On an unsafe path.
	 */
	public static function path( string $staging, string $root, string $rel ): string {
		if ( ! in_array( $root, Roots::ALL, true ) || '' === $rel || ! Roots::valid_relative( $rel ) ) {
			throw new \RuntimeException( esc_html( 'Refusing unsafe path ' . $root . '/' . $rel ) );
		}

		if ( ! str_starts_with( basename( $staging ), Roots::STAGING_PREFIX ) ) {
			throw new \RuntimeException( 'Invalid staging directory.' );
		}

		$path = $staging . '/' . $root . '/' . $rel;

		// valid_relative() rules out "..", but a symlink planted inside the
		// staging directory could still redirect a write. Check every level.
		$check = $staging;
		foreach ( explode( '/', $root . '/' . $rel ) as $segment ) {
			$check .= '/' . $segment;
			if ( is_link( $check ) ) {
				throw new \RuntimeException( esc_html( 'Refusing to write through a symlink: ' . $root . '/' . $rel ) );
			}
		}

		return $path;
	}

	/**
	 * Deletes a directory tree without following symlinks, within a time limit.
	 *
	 * @param string $dir      Directory; must be a staging directory.
	 * @param float  $deadline microtime( true ) to stop at.
	 *
	 * @return bool Whether the directory is gone.
	 */
	public static function delete( string $dir, float $deadline ): bool {
		$content = untrailingslashit( WP_CONTENT_DIR ) . '/';
		if ( ! str_starts_with( $dir, $content . Roots::STAGING_PREFIX ) || str_contains( substr( $dir, strlen( $content ) ), '/' ) ) {
			return false;
		}

		if ( ! file_exists( $dir ) ) {
			return true;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( microtime( true ) > $deadline ) {
				return false;
			}

			$path = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		return rmdir( $dir );
	}
}
