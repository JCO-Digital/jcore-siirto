<?php
/**
 * Inventory of the source's files before anything is copied.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Target;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks the source to count files and bytes per top-level entry of each root:
 * each plugin, theme, uploads year and so on. The admin screen shows the
 * result as a checklist, and the totals drive the progress bar and the time
 * estimate during the copy.
 */
final class Scanner {

	/**
	 * Queues every root the source offers.
	 *
	 * @param Job      $job   The job.
	 * @param string[] $roots Roots to scan.
	 *
	 * @return void
	 */
	public static function init( Job $job, array $roots ): void {
		$job->state['scan'] = array(
			'queue'   => array_values( $roots ),
			'cursor'  => array(),
			'roots'   => array(),
			'skipped' => 0,
		);
	}

	/**
	 * Scans the next slice.
	 *
	 * @param Job    $job    The job.
	 * @param Client $client Client for the source.
	 *
	 * @return bool Whether there is more to scan.
	 */
	public static function work( Job $job, Client $client ): bool {
		$scan = &$job->state['scan'];

		if ( array() === $scan['queue'] ) {
			return false;
		}

		$root = (string) $scan['queue'][0];

		$job->state['current'] = $root . ( array() === $scan['cursor'] ? '' : '/' . implode( '/', $scan['cursor'] ) );

		$result = $client->json(
			'scan',
			array(
				'root'   => $root,
				'cursor' => $scan['cursor'],
			)
		);

		$scan['roots'][ $root ] ??= array();
		foreach ( (array) ( $result['entries'] ?? array() ) as $name => $entry ) {
			$name    = (string) $name;
			$current = $scan['roots'][ $root ][ $name ] ?? array( 0, 0, false );

			$scan['roots'][ $root ][ $name ] = array(
				$current[0] + (int) ( $entry[0] ?? 0 ),
				$current[1] + (int) ( $entry[1] ?? 0 ),
				$current[2] || ! empty( $entry[2] ),
			);
		}

		$scan['skipped'] += (int) ( $result['skipped'] ?? 0 );

		if ( null === ( $result['cursor'] ?? null ) ) {
			array_shift( $scan['queue'] );
			$scan['cursor'] = array();
		} else {
			$scan['cursor'] = array_map( 'strval', (array) $result['cursor'] );
		}

		return true;
	}

	/**
	 * Totals for what the administrator selected.
	 *
	 * @param Job                     $job   The job.
	 * @param string[]                $roots Selected roots.
	 * @param array<string, string[]> $skip  Top-level names left out, per root.
	 *
	 * @return array{files: int, bytes: int}
	 */
	public static function totals( Job $job, array $roots, array $skip ): array {
		$totals = array(
			'files' => 0,
			'bytes' => 0,
		);

		foreach ( $roots as $root ) {
			foreach ( $job->state['scan']['roots'][ $root ] ?? array() as $name => $entry ) {
				if ( ! in_array( (string) $name, $skip[ $root ] ?? array(), true ) ) {
					$totals['files'] += (int) $entry[0];
					$totals['bytes'] += (int) $entry[1];
				}
			}
		}

		return $totals;
	}
}
