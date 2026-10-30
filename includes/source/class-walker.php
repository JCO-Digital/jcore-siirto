<?php
/**
 * Resumable, sorted walk over one file root.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Source;

use Jcore\Siirto\Roots;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walks a root depth-first in byte order, stopping after a time or entry
 * budget and resuming after a cursor on the next request.
 *
 * The cursor is the path of the last entry handled. Because names are sorted,
 * resuming only has to re-read the directories on the way down to it, so a
 * whole tree can be listed in a handful of requests without the source keeping
 * any state between them.
 */
final class Walker {

	/**
	 * Entries visited so far.
	 *
	 * @var int
	 */
	private int $count = 0;

	/**
	 * Where the walk stopped, if it did.
	 *
	 * @var string[]|null
	 */
	private ?array $stop = null;

	/**
	 * Entries that couldn't be walked: `[ relative path, reason ]`.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public array $skipped = array();

	/**
	 * Constructor.
	 *
	 * @param string   $root     One of Roots::ALL.
	 * @param string[] $skip_top Top-level names to leave out entirely.
	 * @param float    $deadline microtime( true ) to stop at.
	 * @param int      $limit    Most entries to visit.
	 */
	public function __construct(
		private string $root,
		private array $skip_top,
		private float $deadline,
		private int $limit
	) {
	}

	/**
	 * Walks from just after the cursor.
	 *
	 * @param string[] $after Cursor from a previous run; empty to start at the beginning.
	 * @param callable $visit Called as `( string $rel, bool $is_dir, int $size, int $mtime )`.
	 *                        Directories are only reported when they have nothing to list.
	 *
	 * @return string[]|null Cursor to resume after, or null when the walk is complete.
	 *
	 * @throws \RuntimeException If the root doesn't exist.
	 */
	public function run( array $after, callable $visit ): ?array {
		$base = Roots::dir( $this->root );
		if ( null === $base ) {
			throw new \RuntimeException( 'Root not available.' );
		}

		return $this->walk( $base, '', array_values( $after ), $visit ) ? null : $this->stop;
	}

	/**
	 * Walks one directory.
	 *
	 * @param string   $path    Absolute directory path.
	 * @param string   $rel_dir Its path relative to the root.
	 * @param string[] $after   Remaining cursor segments for this level.
	 * @param callable $visit   Visitor.
	 *
	 * @return bool False if the budget ran out.
	 */
	private function walk( string $path, string $rel_dir, array $after, callable $visit ): bool {
		$names = scandir( $path );
		if ( false === $names ) {
			$this->skipped[] = array( $rel_dir, 'unreadable' );

			return true;
		}

		$names = array_values( array_diff( $names, array( '.', '..' ) ) );
		sort( $names, SORT_STRING );

		$first = $after[0] ?? null;

		foreach ( $names as $name ) {
			$rest = array();

			if ( null !== $first ) {
				$cmp = strcmp( $name, $first );
				if ( $cmp < 0 ) {
					continue;
				}

				if ( 0 === $cmp ) {
					$rest = array_slice( $after, 1 );
					if ( array() === $rest ) {
						// The cursor itself: handled on the previous request.
						$first = null;
						continue;
					}
				}

				$first = null;
			}

			$rel = '' === $rel_dir ? $name : $rel_dir . '/' . $name;

			if ( '' === $rel_dir && in_array( $name, $this->skip_top, true ) ) {
				continue;
			}

			$full = $path . '/' . $name;

			if ( ! Roots::valid_relative( $rel ) ) {
				$this->skipped[] = array( $rel, 'name' );
			} elseif ( is_link( $full ) ) {
				$this->skipped[] = array( $rel, 'symlink' );
			} elseif ( is_dir( $full ) ) {
				if ( Roots::is_excluded( $this->root, $rel, true ) ) {
					continue;
				}

				$before = $this->count;
				if ( ! $this->walk( $full, $rel, $rest, $visit ) ) {
					return false;
				}

				if ( $before === $this->count && array() === $rest ) {
					$visit( $rel, true, 0, 0 );
					++$this->count;
				}
			} elseif ( is_file( $full ) && ! Roots::is_excluded( $this->root, $rel, false ) ) {
				if ( is_readable( $full ) ) {
					$visit( $rel, false, (int) filesize( $full ), (int) filemtime( $full ) );
					++$this->count;
				} else {
					$this->skipped[] = array( $rel, 'unreadable' );
				}
			}

			if ( $this->count >= $this->limit || microtime( true ) > $this->deadline ) {
				$this->stop = explode( '/', $rel );

				return false;
			}
		}

		return true;
	}
}
