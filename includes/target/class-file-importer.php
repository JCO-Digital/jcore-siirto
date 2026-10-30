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
 * Lists each selected root a few thousand files at a time and downloads them
 * in batches.
 *
 * The source walks whole trees per listing request, so the number of requests
 * depends on the number of files and bytes, not on how many directories the
 * site has. Only the current page of the listing is kept in the job state.
 */
final class File_Importer {

	/**
	 * Most files requested in one read.
	 */
	private const FILES_PER_READ = 1000;

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
	 * Prepares the copy of the selected roots.
	 *
	 * @param Job                     $job   The job.
	 * @param string[]                $roots Roots to copy.
	 * @param array<string, string[]> $skip  Top-level names to leave out, per root.
	 *
	 * @return void
	 */
	public static function init( Job $job, array $roots, array $skip ): void {
		$job->state['files'] = array(
			'roots'   => array_map(
				static fn( string $root ): array => array( $root, array_values( $skip[ $root ] ?? array() ) ),
				array_values( $roots )
			),
			'i'       => 0,
			'cursor'  => array(),
			'listed'  => false,
			'items'   => array(),
			'n'       => 0,
			'partial' => 0,
		);
	}

	/**
	 * Does one unit of work: list the next page, or download the next batch.
	 *
	 * @return bool Whether there is more to do.
	 */
	public function work(): bool {
		$files = &$this->job->state['files'];

		if ( $files['i'] >= count( $files['roots'] ) ) {
			return false;
		}

		if ( $files['n'] < count( $files['items'] ) ) {
			$this->download( $files );

			return true;
		}

		if ( $files['listed'] ) {
			++$files['i'];
			$files['cursor'] = array();
			$files['listed'] = false;
			$files['items']  = array();
			$files['n']      = 0;

			return true;
		}

		$this->list_page( $files );

		return true;
	}

	/**
	 * Fetches the next page of the current root's file list.
	 *
	 * @param array<string, mixed> $files File state.
	 *
	 * @return void
	 */
	private function list_page( array &$files ): void {
		list( $root, $skip ) = $files['roots'][ $files['i'] ];

		$this->job->state['current'] = $root . ( array() === $files['cursor'] ? '' : '/' . implode( '/', $files['cursor'] ) );

		$page = $this->client->json(
			'files',
			array(
				'root'   => $root,
				'cursor' => $files['cursor'],
				'skip'   => $skip,
			)
		);

		$options  = $this->job->state['options'];
		$patterns = $options['exclude'] ?? array();
		$staging  = (string) $this->job->staging_dir();
		$items    = array();

		foreach ( (array) ( $page['files'] ?? array() ) as $entry ) {
			$rel  = (string) ( $entry[0] ?? '' );
			$size = (int) ( $entry[1] ?? 0 );
			$dir  = $size < 0;
			$top  = explode( '/', $rel, 2 )[0];

			// Re-check everything the source already checked: the target
			// decides for itself what it is willing to write.
			if ( '' === $rel || ! Roots::valid_relative( $rel ) ) {
				$this->skip_file( $root . '/' . $rel, 'invalid name', $size );
				continue;
			}

			// The source leaves these out already, and the scan never counted them.
			if ( in_array( $top, $skip, true ) || Roots::is_excluded( $root, $rel, $dir ) ) {
				continue;
			}

			if ( Roots::matches_pattern( $root, $rel, $patterns ) ) {
				$this->skip_file( $root . '/' . $rel, 'excluded by pattern', $size );
				continue;
			}

			if ( $dir ) {
				Staging::mkdir( $staging, $root, $rel );
				continue;
			}

			if ( ! empty( $options['skip_executables'] ) && in_array( $root, self::CONTENT_ROOTS, true ) && Roots::is_executable( basename( $rel ) ) ) {
				$this->skip_file( $root . '/' . $rel, 'executable file in content', $size );
				continue;
			}

			$items[] = array( $rel, max( 0, $size ), (int) ( $entry[2] ?? 0 ) );
		}

		foreach ( (array) ( $page['skipped'] ?? array() ) as $skipped ) {
			$this->job->skip( $root . '/' . (string) ( $skipped[0] ?? '' ), 'source: ' . (string) ( $skipped[1] ?? '' ) );
		}

		$cursor = $page['cursor'] ?? null;

		$files['items']   = $items;
		$files['n']       = 0;
		$files['partial'] = 0;
		$files['listed']  = null === $cursor;
		$files['cursor']  = null === $cursor ? array() : array_map( 'strval', (array) $cursor );
	}

	/**
	 * Downloads the next batch of the current page.
	 *
	 * @param array<string, mixed> $files File state.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException On a malformed response.
	 */
	private function download( array &$files ): void {
		$root    = (string) $files['roots'][ $files['i'] ][0];
		$staging = (string) $this->job->staging_dir();
		$max     = Server::chunk_size();
		$budget  = $max;

		$request = array();
		$last    = min( count( $files['items'] ), $files['n'] + self::FILES_PER_READ );
		for ( $i = $files['n']; $i < $last && $budget > 0; $i++ ) {
			list( $rel, $size ) = $files['items'][ $i ];
			$offset             = $i === $files['n'] ? (int) $files['partial'] : 0;
			$request[]          = array(
				'root'   => $root,
				'path'   => $rel,
				'offset' => $offset,
				'length' => $size - $offset,
			);
			$budget            -= max( 1, $size - $offset );
		}

		$this->job->state['current'] = $root . '/' . $request[0]['path'];

		$data   = $this->client->call(
			'read',
			array(
				'files' => $request,
				'max'   => $max,
			)
		);
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

			$listed_size = (int) $files['items'][ $files['n'] ][1];

			if ( isset( $header['error'] ) ) {
				$this->skip_file( $root . '/' . $wanted['path'], 'source: ' . (string) $header['error'], $listed_size - $wanted['offset'] );
				$this->advance( $files );
				continue;
			}

			Staging::write( $staging, $root, $wanted['path'], $wanted['offset'], $chunk );
			$this->job->count( 'bytes', $bytes );

			$size = (int) ( $header['size'] ?? 0 );
			$done = $wanted['offset'] + $bytes;

			if ( $done >= $size ) {
				Staging::touch( $staging, $root, $wanted['path'], (int) ( $header['mtime'] ?? 0 ) );
				$this->job->count( 'files' );
				$this->advance( $files );
			} elseif ( 0 === $bytes ) {
				$this->skip_file( $root . '/' . $wanted['path'], 'source returned no data', $size - $wanted['offset'] );
				$this->advance( $files );
			} else {
				$files['partial'] = $done;
				break;
			}
		}
	}

	/**
	 * Counts a file that won't be copied, so progress still reaches the end.
	 *
	 * @param string $what   Path for the report.
	 * @param string $reason Why.
	 * @param int    $size   Its size; negative for directories.
	 *
	 * @return void
	 */
	private function skip_file( string $what, string $reason, int $size ): void {
		if ( $size >= 0 ) {
			$this->job->count( 'files_skipped' );
			$this->job->count( 'bytes_skipped', $size );
		}

		$this->job->skip( $what, $reason );
	}

	/**
	 * Moves to the next file of the current page.
	 *
	 * @param array<string, mixed> $files File state.
	 *
	 * @return void
	 */
	private function advance( array &$files ): void {
		++$files['n'];
		$files['partial'] = 0;
	}
}
