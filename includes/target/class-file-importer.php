<?php
/**
 * Copies files from the source into staging.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Target;

use Jcore\Siirto\Roots;
use Jcore\Siirto\Source\Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walks the source's file roots breadth-first and downloads each file.
 *
 * Only a queue of directories and the current page of one directory are kept
 * in the job state, so memory use doesn't grow with the size of the site.
 */
final class File_Importer {

	/**
	 * Directory entries requested per listing page.
	 */
	private const PAGE = 1000;

	/**
	 * Most files requested in one read.
	 */
	private const FILES_PER_READ = 200;

	/**
	 * Roots in which executable files are skipped when the option is on. These
	 * hold uploaded content, where a PHP file is far more likely a backdoor
	 * than something the site needs.
	 */
	private const CONTENT_ROOTS = array( 'uploads', 'content' );

	/**
	 * Constructor.
	 *
	 * @param Job    $job    The job.
	 * @param Client $client Client for the source.
	 */
	public function __construct( private Job $job, private Client $client ) {
	}

	/**
	 * Seeds the queue with the selected roots.
	 *
	 * @param Job      $job   The job.
	 * @param string[] $roots Roots to copy.
	 *
	 * @return void
	 */
	public static function init( Job $job, array $roots ): void {
		$job->state['files'] = array(
			'queue' => array_map( static fn( string $root ): array => array( $root, '' ), $roots ),
			'cur'   => null,
		);
	}

	/**
	 * Does one unit of work.
	 *
	 * @return bool Whether there is more to do.
	 */
	public function work(): bool {
		$files = &$this->job->state['files'];

		if ( null === $files['cur'] ) {
			if ( array() === $files['queue'] ) {
				return false;
			}

			list( $root, $dir ) = array_shift( $files['queue'] );
			$files['cur']       = array(
				'root'    => $root,
				'dir'     => $dir,
				'next'    => 0,
				'items'   => array(),
				'i'       => 0,
				'partial' => 0,
			);
		}

		$cur = &$files['cur'];

		if ( $cur['i'] >= count( $cur['items'] ) ) {
			if ( null === $cur['next'] ) {
				$files['cur'] = null;

				return true;
			}

			$this->list_page( $cur );

			return true;
		}

		$this->download( $cur );

		return true;
	}

	/**
	 * Fetches the next page of the current directory.
	 *
	 * @param array<string, mixed> $cur Current directory state.
	 *
	 * @return void
	 */
	private function list_page( array &$cur ): void {
		$root = (string) $cur['root'];
		$dir  = (string) $cur['dir'];

		$this->job->state['current'] = $root . '/' . $dir;

		$page = $this->client->json(
			'list',
			array(
				'root'   => $root,
				'dir'    => $dir,
				'offset' => (int) $cur['next'],
				'limit'  => self::PAGE,
			)
		);

		$options  = $this->job->state['options'];
		$patterns = $options['exclude'] ?? array();
		$items    = array();

		foreach ( $page['entries'] ?? array() as $entry ) {
			$name = (string) ( $entry['n'] ?? '' );
			$rel  = '' === $dir ? $name : $dir . '/' . $name;
			$type = (string) ( $entry['t'] ?? '' );

			// Re-check everything the source already checked: the target
			// decides for itself what it is willing to write.
			if ( '' === $name || str_contains( $name, '/' ) || ! Roots::valid_relative( $rel ) ) {
				$this->job->skip( $root . '/' . $rel, 'invalid name' );
				continue;
			}

			if ( Roots::is_excluded( $root, $rel, 'd' === $type ) ) {
				continue;
			}

			if ( Roots::matches_pattern( $root, $rel, $patterns ) ) {
				$this->job->skip( $root . '/' . $rel, 'excluded by pattern' );
				continue;
			}

			if ( 'd' === $type ) {
				$this->job->state['files']['queue'][] = array( $root, $rel );
			} elseif ( 'f' === $type ) {
				if ( ! empty( $options['skip_executables'] ) && in_array( $root, self::CONTENT_ROOTS, true ) && Roots::is_executable( $name ) ) {
					$this->job->skip( $root . '/' . $rel, 'executable file in content' );
					continue;
				}

				$items[] = array( $rel, max( 0, (int) ( $entry['s'] ?? 0 ) ), (int) ( $entry['m'] ?? 0 ) );
				$this->job->count( 'files_listed' );
				$this->job->count( 'bytes_listed', max( 0, (int) ( $entry['s'] ?? 0 ) ) );
			}
		}

		foreach ( $page['skipped'] ?? array() as $skipped ) {
			$this->job->skip( $root . '/' . (string) ( $skipped[0] ?? '' ), 'source: ' . (string) ( $skipped[1] ?? '' ) );
		}

		$cur['items']   = $items;
		$cur['i']       = 0;
		$cur['partial'] = 0;
		$cur['next']    = isset( $page['next'] ) ? (int) $page['next'] : null;
	}

	/**
	 * Downloads the next batch of files from the current page.
	 *
	 * @param array<string, mixed> $cur Current directory state.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException On a malformed response.
	 */
	private function download( array &$cur ): void {
		$root    = (string) $cur['root'];
		$staging = (string) $this->job->staging_dir();
		$budget  = Server::chunk_size();

		$request = array();
		$last    = min( count( $cur['items'] ), $cur['i'] + self::FILES_PER_READ );
		for ( $i = $cur['i']; $i < $last && $budget > 0; $i++ ) {
			list( $rel, $size ) = $cur['items'][ $i ];
			$offset             = $i === $cur['i'] ? (int) $cur['partial'] : 0;
			$request[]          = array(
				'root'   => $root,
				'path'   => $rel,
				'offset' => $offset,
				'length' => $size - $offset,
			);
			$budget            -= max( 1, $size - $offset );
		}

		$this->job->state['current'] = $root . '/' . $request[0]['path'];

		$data   = $this->client->call( 'read', array( 'files' => $request ) );
		$pos    = 0;
		$length = strlen( $data );

		foreach ( $request as $wanted ) {
			if ( $pos >= $length ) {
				// The source stopped at its size budget; carry on next time.
				break;
			}

			$header_length = unpack( 'N', substr( $data, $pos, 4 ) )[1] ?? 0;
			$header        = json_decode( substr( $data, $pos + 4, $header_length ), true );
			$pos          += 4 + $header_length;

			if ( ! is_array( $header ) || ( $header['root'] ?? null ) !== $root || ( $header['path'] ?? null ) !== $wanted['path'] || (int) ( $header['offset'] ?? -1 ) !== $wanted['offset'] ) {
				throw new \RuntimeException( esc_html__( 'The source sent files out of order.', 'jcore-siirto' ) );
			}

			$bytes = (int) ( $header['length'] ?? 0 );
			$chunk = substr( $data, $pos, $bytes );
			$pos  += $bytes;

			if ( strlen( $chunk ) !== $bytes ) {
				throw new \RuntimeException( esc_html__( 'The source sent a truncated file.', 'jcore-siirto' ) );
			}

			if ( isset( $header['error'] ) ) {
				$this->job->skip( $root . '/' . $wanted['path'], 'source: ' . (string) $header['error'] );
				$this->advance( $cur );
				continue;
			}

			Staging::write( $staging, $root, $wanted['path'], $wanted['offset'], $chunk );
			$this->job->count( 'bytes', $bytes );

			$size = (int) ( $header['size'] ?? 0 );
			$done = $wanted['offset'] + $bytes;

			if ( $done >= $size ) {
				Staging::touch( $staging, $root, $wanted['path'], (int) ( $header['mtime'] ?? 0 ) );
				$this->job->count( 'files' );
				$this->advance( $cur );
			} elseif ( 0 === $bytes ) {
				$this->job->skip( $root . '/' . $wanted['path'], 'source returned no data' );
				$this->advance( $cur );
			} else {
				$cur['partial'] = $done;
				break;
			}
		}
	}

	/**
	 * Moves to the next file of the current page.
	 *
	 * @param array<string, mixed> $cur Current directory state.
	 *
	 * @return void
	 */
	private function advance( array &$cur ): void {
		++$cur['i'];
		$cur['partial'] = 0;
	}
}
