<?php
/**
 * A migration into this site.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Target;

use Jcore\Siirto\Crypto;
use Jcore\Siirto\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent state of one migration, stored in the plugin's own jobs table so
 * it survives the database swap at the end.
 *
 * Status flow:
 * connected → running ⇄ failed → ready → finalizing → done → rolled_back
 * and from anything but finalizing: → cleaning → cleaned. Whether the job
 * was migrated, rolled back or cancelled is kept in the `outcome` state.
 */
final class Job {

	/**
	 * The status after which nothing more can happen to a job.
	 */
	public const DONE_WITH = 'cleaned';

	/**
	 * Row ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Current status.
	 *
	 * @var string
	 */
	public string $status = 'connected';

	/**
	 * Current phase inside the status.
	 *
	 * @var string
	 */
	public string $phase = '';

	/**
	 * Working state.
	 *
	 * @var array<string, mixed>
	 */
	public array $state = array();

	/**
	 * Last error, shown to the administrator.
	 *
	 * @var string
	 */
	public string $error = '';

	/**
	 * Random ID the source binds the key to.
	 *
	 * @var string
	 */
	private string $client_id = '';

	/**
	 * Creator's user ID.
	 *
	 * @var int
	 */
	private int $created_by = 0;

	/**
	 * Creation time, UTC.
	 *
	 * @var string
	 */
	private string $created_at = '';

	/**
	 * Creates a job for a decoded key.
	 *
	 * @param array{url: string, id: string, secret: string} $key Decoded key.
	 *
	 * @return Job
	 */
	public static function create( array $key ): Job {
		global $wpdb;

		$job             = new self();
		$job->client_id  = Crypto::new_id();
		$job->created_by = get_current_user_id();
		$job->created_at = gmdate( 'Y-m-d H:i:s' );
		$job->state      = array(
			'key' => array(
				'url'    => $key['url'],
				'id'     => $key['id'],
				'secret' => Crypto::wrap( $key['secret'] ),
			),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$wpdb->insert(
			Database::table( 'jobs' ),
			array(
				'status'     => $job->status,
				'phase'      => $job->phase,
				'client_id'  => $job->client_id,
				'seq'        => 0,
				'state'      => (string) wp_json_encode( $job->state ),
				'error'      => '',
				'created_by' => $job->created_by,
				'created_at' => $job->created_at,
				'updated_at' => $job->created_at,
			)
		);
		$job->id = (int) $wpdb->insert_id;

		return $job;
	}

	/**
	 * Loads a job.
	 *
	 * @param int $id Job ID.
	 *
	 * @return Job|null
	 */
	public static function find( int $id ): ?Job {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must not be cached.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Database::table( 'jobs' ), $id ), ARRAY_A );

		return is_array( $row ) ? self::from_row( $row ) : null;
	}

	/**
	 * Returns the most recent job that isn't finished with.
	 *
	 * @return Job|null
	 */
	public static function current(): ?Job {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must not be cached.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status <> %s ORDER BY id DESC LIMIT 1',
				Database::table( 'jobs' ),
				self::DONE_WITH
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::from_row( $row ) : null;
	}

	/**
	 * Builds a job from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 *
	 * @return Job
	 */
	private static function from_row( array $row ): Job {
		$job             = new self();
		$job->id         = (int) $row['id'];
		$job->status     = (string) $row['status'];
		$job->phase      = (string) $row['phase'];
		$job->client_id  = (string) $row['client_id'];
		$job->error      = (string) $row['error'];
		$job->created_by = (int) $row['created_by'];
		$job->created_at = (string) $row['created_at'];
		$state           = json_decode( (string) $row['state'], true );
		$job->state      = is_array( $state ) ? $state : array();

		return $job;
	}

	/**
	 * Writes the job back.
	 *
	 * @return void
	 */
	public function save(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			Database::table( 'jobs' ),
			array(
				'status'     => $this->status,
				'phase'      => $this->phase,
				'state'      => (string) wp_json_encode( $this->state ),
				'error'      => $this->error,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $this->id )
		);
	}

	/**
	 * The decrypted key, or null once it has been forgotten.
	 *
	 * @return array{url: string, id: string, secret: string}|null
	 */
	public function key(): ?array {
		$key = $this->state['key'] ?? null;
		if ( ! is_array( $key ) || empty( $key['secret'] ) ) {
			return null;
		}

		$secret = Crypto::unwrap( (string) $key['secret'] );

		return null === $secret ? null : array(
			'url'    => (string) $key['url'],
			'id'     => (string) $key['id'],
			'secret' => $secret,
		);
	}

	/**
	 * Drops the key secret once it is no longer needed.
	 *
	 * @return void
	 */
	public function forget_key(): void {
		unset( $this->state['key']['secret'] );
	}

	/**
	 * Random ID identifying this job to the source.
	 *
	 * @return string
	 */
	public function client_id(): string {
		return $this->client_id;
	}

	/**
	 * Atomically increments and returns the request sequence number.
	 *
	 * Persisted before the request is sent, so a request that dies midway
	 * never causes a number to be reused.
	 *
	 * @return int
	 */
	public function next_seq(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counter on a custom table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET seq = LAST_INSERT_ID(seq + 1) WHERE id = %d', Database::table( 'jobs' ), $this->id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same connection as the update above.
		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}

	/**
	 * Adds a skipped item to the report.
	 *
	 * @param string $what   What was skipped.
	 * @param string $reason Why.
	 *
	 * @return void
	 */
	public function skip( string $what, string $reason ): void {
		$this->state['stats']['skipped'] = (int) ( $this->state['stats']['skipped'] ?? 0 ) + 1;

		if ( count( $this->state['skipped'] ?? array() ) < 500 ) {
			$this->state['skipped'][] = array( $what, $reason );
		}
	}

	/**
	 * Adds to a numeric statistic.
	 *
	 * @param string $name  Statistic.
	 * @param int    $value Amount.
	 *
	 * @return void
	 */
	public function count( string $name, int $value = 1 ): void {
		$this->state['stats'][ $name ] = (int) ( $this->state['stats'][ $name ] ?? 0 ) + $value;
	}

	/**
	 * Absolute path of the staging directory, or null before it exists.
	 *
	 * @return string|null
	 */
	public function staging_dir(): ?string {
		$name = (string) ( $this->state['staging'] ?? '' );

		return '' === $name ? null : untrailingslashit( WP_CONTENT_DIR ) . '/' . $name;
	}

	/**
	 * What the admin screen shows. Never includes the key secret.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		return array(
			'id'               => $this->id,
			'status'           => $this->status,
			'phase'            => $this->phase,
			'error'            => $this->error,
			'created_at'       => $this->created_at,
			'created_by'       => $this->created_by,
			'source_url'       => (string) ( $this->state['key']['url'] ?? '' ),
			'source'           => $this->state['source'] ?? null,
			'options'          => $this->state['options'] ?? null,
			'target'           => $this->state['target'] ?? null,
			'stats'            => $this->state['stats'] ?? array(),
			'current'          => (string) ( $this->state['current'] ?? '' ),
			'skipped'          => $this->state['skipped'] ?? array(),
			// Once files or tables have started moving, the only ways out are
			// completing the migration or rolling it back.
			'finalize_started' => isset( $this->state['finalize'] ),
			'scan'             => $this->state['scan']['roots'] ?? null,
			'scanning'         => $this->state['scan']['queue'] ?? array(),
			'totals'           => $this->state['totals'] ?? null,
			'progress'         => $this->progress(),
		);
	}

	/**
	 * Progress towards the scanned totals, with a time estimate.
	 *
	 * Copy time depends on both bytes and the number of files (every batch is
	 * a round trip to the source), so progress is measured in "work": bytes
	 * plus a fixed cost per file, plus database bytes in proportion to rows
	 * copied. The estimate extrapolates the rate measured so far.
	 *
	 * @return array<string, mixed>
	 */
	private function progress(): array {
		$stats  = $this->state['stats'] ?? array();
		$totals = $this->state['totals'] ?? array();

		$files_total = (int) ( $totals['files'] ?? 0 );
		$bytes_total = (int) ( $totals['bytes'] ?? 0 );
		$rows_total  = (int) ( $totals['rows'] ?? 0 );
		$db_total    = (int) ( $totals['db_bytes'] ?? 0 );

		$files_done = min( $files_total, (int) ( $stats['files'] ?? 0 ) + (int) ( $stats['files_skipped'] ?? 0 ) );
		$bytes_done = min( $bytes_total, (int) ( $stats['bytes'] ?? 0 ) + (int) ( $stats['bytes_skipped'] ?? 0 ) );
		$rows_done  = (int) ( $stats['rows'] ?? 0 );

		$tables_total = count( $this->state['db']['tables'] ?? array() );
		$tables_done  = (int) ( $this->state['db']['i'] ?? 0 );

		// Row counts from SHOW TABLE STATUS are estimates for InnoDB, so the
		// database share is capped until the last table is done.
		$db_fraction = $tables_total > 0 && $tables_done >= $tables_total ? 1.0 : ( $rows_total > 0 ? min( 0.99, $rows_done / $rows_total ) : 0.0 );

		$per_file = 32 * KB_IN_BYTES;
		$work     = $bytes_total + $files_total * $per_file + $db_total;
		$done     = $bytes_done + $files_done * $per_file + $db_fraction * $db_total;
		$seconds  = (float) ( $stats['seconds'] ?? 0 );
		$fraction = $work > 0 ? min( 1.0, $done / $work ) : 0.0;

		return array(
			'files_done'   => $files_done,
			'files_total'  => $files_total,
			'bytes_done'   => $bytes_done,
			'bytes_total'  => $bytes_total,
			'rows_done'    => $rows_done,
			'rows_total'   => $rows_total,
			'tables_done'  => $tables_done,
			'tables_total' => $tables_total,
			'fraction'     => $fraction,
			'seconds'      => (int) $seconds,
			'bytes_per_s'  => $seconds > 0 ? (int) ( (int) ( $stats['bytes'] ?? 0 ) / $seconds ) : 0,
			// Too early to say until there is half a minute of data.
			'eta'          => $seconds >= 30 && $fraction > 0.001 ? (int) ( $seconds * ( 1 - $fraction ) / $fraction ) : null,
		);
	}
}
