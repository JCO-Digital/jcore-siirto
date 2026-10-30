<?php
/**
 * Copies the source database into temporary tables.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Target;

use Jcore\Siirto\Replacer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Importing a database is direct queries by definition; every identifier goes through %i and every value through esc_sql() or a hex literal.

/**
 * Recreates each source table under a temporary name and fills it in batches.
 *
 * The live tables are not touched until the administrator confirms; then
 * Finalizer swaps the temporary tables in with a single atomic RENAME TABLE.
 *
 * URLs and paths are rewritten, and table-prefix-dependent keys renamed, as the
 * rows arrive, so no second pass over the data is needed.
 */
final class Db_Importer {

	/**
	 * Target size of one INSERT statement, in bytes.
	 */
	private const INSERT_BYTES = MB_IN_BYTES;

	/**
	 * Target size of one rows response, in bytes.
	 */
	private const FETCH_BYTES = 2 * MB_IN_BYTES;

	/**
	 * Replacer for the current job.
	 *
	 * @var Replacer|null
	 */
	private ?Replacer $replacer = null;

	/**
	 * Constructor.
	 *
	 * @param Job    $job    The job.
	 * @param Client $client Client for the source.
	 */
	public function __construct( private Job $job, private Client $client ) {
	}

	/**
	 * Prepares the table list.
	 *
	 * @param Job      $job    The job.
	 * @param string[] $tables Source table names.
	 *
	 * @return void
	 */
	public static function init( Job $job, array $tables ): void {
		$job->state['db'] = array(
			'tables'  => array_values( $tables ),
			'i'       => 0,
			'started' => false,
			'created' => array(),
		);
	}

	/**
	 * Name a source table is staged under.
	 *
	 * @param Job    $job   The job.
	 * @param string $table Source table name.
	 *
	 * @return string
	 */
	public static function temp_name( Job $job, string $table ): string {
		return 'jt' . $job->state['tag'] . '_' . self::suffix( $job, $table );
	}

	/**
	 * Name a replaced live table is kept under until cleanup.
	 *
	 * @param Job    $job    The job.
	 * @param string $suffix Unprefixed table name.
	 *
	 * @return string
	 */
	public static function old_name( Job $job, string $suffix ): string {
		return 'jo' . $job->state['tag'] . '_' . $suffix;
	}

	/**
	 * The table name without the source prefix.
	 *
	 * @param Job    $job   The job.
	 * @param string $table Source table name.
	 *
	 * @return string
	 */
	public static function suffix( Job $job, string $table ): string {
		return substr( $table, strlen( (string) $job->state['source']['prefix'] ) );
	}

	/**
	 * Does one unit of work: create a table, or copy one batch of rows.
	 *
	 * @return bool Whether there is more to do.
	 */
	public function work(): bool {
		$db = &$this->job->state['db'];

		if ( $db['i'] >= count( $db['tables'] ) ) {
			return false;
		}

		$table = (string) $db['tables'][ $db['i'] ];

		$this->prepare_session();

		$this->job->state['current'] = $table;

		if ( ! $db['started'] ) {
			$this->create_table( $table );

			return true;
		}

		$this->copy_rows( $table );

		return true;
	}

	/**
	 * Sets the session options an import needs, once per request.
	 *
	 * Foreign keys are checked by the source already, and rows may arrive in
	 * any table order. NO_AUTO_VALUE_ON_ZERO keeps rows whose ID is 0.
	 *
	 * @return void
	 */
	private function prepare_session(): void {
		global $wpdb;

		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		$wpdb->query( 'SET SESSION foreign_key_checks = 0' );
		$wpdb->query( "SET SESSION sql_mode = TRIM(BOTH ',' FROM CONCAT(@@SESSION.sql_mode, ',NO_AUTO_VALUE_ON_ZERO'))" );
	}

	/**
	 * Creates the temporary copy of a table.
	 *
	 * @param string $table Source table name.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the table can't be created safely.
	 */
	private function create_table( string $table ): void {
		global $wpdb;

		$db     = &$this->job->state['db'];
		$schema = $this->client->json( 'schema', array( 'table' => $table ) );
		$temp   = self::temp_name( $this->job, $table );

		// Never drop or reuse a table this job didn't create.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $temp ) ) ) ) {
			throw new \RuntimeException( esc_html( 'Temporary table ' . $temp . ' already exists.' ) );
		}

		$create = $this->rewrite_create( (string) ( $schema['create'] ?? '' ), $table, $temp );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema from the authenticated source, table name rewritten and validated above.
		if ( false === $wpdb->query( $create ) ) {
			throw new \RuntimeException( esc_html( 'Could not create ' . $temp . ': ' . $wpdb->last_error ) );
		}

		$db['created'][] = $temp;
		$db['started']   = true;
		$db['after']     = null;
		$db['offset']    = 0;
		$db['pk']        = $schema['pk'] ?? array();
		$db['columns']   = array();
		$db['limit']     = max( 1, min( 5000, intdiv( self::FETCH_BYTES, max( 1, (int) ( $schema['avg_row'] ?? 0 ) ) ) ) );

		foreach ( $schema['columns'] ?? array() as $column ) {
			$db['columns'][] = array(
				'name'      => (string) $column['name'],
				'generated' => (bool) preg_match( '/\bGENERATED\b/i', (string) ( $column['extra'] ?? '' ) ),
			);
		}

		$this->job->count( 'tables' );
	}

	/**
	 * Copies one batch of rows.
	 *
	 * @param string $table Source table name.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException On an insert failure.
	 */
	private function copy_rows( string $table ): void {
		global $wpdb;

		$db     = &$this->job->state['db'];
		$result = $this->client->json(
			'rows',
			array(
				'table'           => $table,
				'after'           => $db['after'],
				'offset'          => $db['offset'],
				'limit'           => $db['limit'],
				'skip_transients' => ! empty( $this->job->state['options']['skip_transients'] ),
			)
		);

		$rows    = is_array( $result['rows'] ?? null ) ? $result['rows'] : array();
		$temp    = self::temp_name( $this->job, $table );
		$suffix  = self::suffix( $this->job, $table );
		$columns = $db['columns'];

		$names = array();
		foreach ( $columns as $column ) {
			if ( ! $column['generated'] ) {
				$names[] = '`' . str_replace( '`', '``', $column['name'] ) . '`';
			}
		}

		$head  = 'INSERT INTO `' . $temp . '` (' . implode( ', ', $names ) . ') VALUES ';
		$batch = array();
		$size  = 0;

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $index => $column ) {
				if ( ! $column['generated'] ) {
					$values[] = $this->literal( $suffix, $column['name'], $row[ $index ] ?? null );
				}
			}

			$tuple = '(' . implode( ', ', $values ) . ')';

			if ( array() !== $batch && $size + strlen( $tuple ) > self::INSERT_BYTES ) {
				$this->insert( $head, $batch, $temp );
				$batch = array();
				$size  = 0;
			}

			$batch[] = $tuple;
			$size   += strlen( $tuple ) + 2;
		}

		if ( array() !== $batch ) {
			$this->insert( $head, $batch, $temp );
		}

		$this->job->count( 'rows', count( $rows ) );

		$db['after']  = $result['after'] ?? null;
		$db['offset'] = (int) ( $result['offset'] ?? 0 );

		if ( ! empty( $result['done'] ) ) {
			++$db['i'];
			$db['started'] = false;
			unset( $db['after'], $db['offset'], $db['pk'], $db['columns'], $db['limit'] );
		}
	}

	/**
	 * Runs one multi-row INSERT.
	 *
	 * @param string   $head   Statement head.
	 * @param string[] $tuples Value tuples.
	 * @param string   $temp   Table name, for the error message.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException On failure.
	 */
	private function insert( string $head, array $tuples, string $temp ): void {
		global $wpdb;

		// The charset check rewrites the query and costs a round trip per
		// column. The values were escaped for this connection already.
		$wpdb->check_current_query = false;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Built from escaped literals in literal().
		if ( false === $wpdb->query( $head . implode( ', ', $tuples ) ) ) {
			throw new \RuntimeException( esc_html( 'Insert into ' . $temp . ' failed: ' . $wpdb->last_error ) );
		}
	}

	/**
	 * Turns one value into an SQL literal, applying the migration rewrites.
	 *
	 * @param string $suffix Unprefixed table name.
	 * @param string $column Column name.
	 * @param mixed  $value  Value: null, a string, or `{ b: base64 }` for binary.
	 *
	 * @return string
	 */
	private function literal( string $suffix, string $column, $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}

		if ( is_array( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary column value from JSON.
			$raw = base64_decode( (string) ( $value['b'] ?? '' ), true );

			return false === $raw || '' === $raw ? "''" : '0x' . bin2hex( $raw );
		}

		$value = (string) $value;

		// The GUID identifies a post forever; WordPress says not to change it.
		if ( ! ( 'posts' === $suffix && 'guid' === $column ) ) {
			$value = $this->replacer()->replace( $value );
		}

		$value = $this->rename_prefixed_key( $suffix, $column, $value );

		return "'" . esc_sql( $value ) . "'";
	}

	/**
	 * Renames the keys WordPress builds from the table prefix, such as
	 * `wp_user_roles` and `wp_capabilities`, when the prefixes differ.
	 *
	 * @param string $suffix Unprefixed table name.
	 * @param string $column Column name.
	 * @param string $value  Value.
	 *
	 * @return string
	 */
	private function rename_prefixed_key( string $suffix, string $column, string $value ): string {
		$from = (string) $this->job->state['source']['prefix'];
		$to   = (string) $this->job->state['target']['prefix'];

		if ( $from === $to ) {
			return $value;
		}

		if ( 'options' === $suffix && 'option_name' === $column && $from . 'user_roles' === $value ) {
			return $to . 'user_roles';
		}

		if ( 'usermeta' === $suffix && 'meta_key' === $column && str_starts_with( $value, $from ) ) {
			return $to . substr( $value, strlen( $from ) );
		}

		return $value;
	}

	/**
	 * Returns the replacer built from the job's URLs and paths.
	 *
	 * @return Replacer
	 */
	private function replacer(): Replacer {
		if ( null === $this->replacer ) {
			$source = $this->job->state['source'];
			$target = $this->job->state['target'];

			$this->replacer = new Replacer(
				Replacer::pairs_for(
					array(
						$source['uploads']['url'] => $target['uploads_url'],
						$source['siteurl']        => $target['siteurl'],
						$source['home']           => $target['home'],
					),
					array(
						$source['uploads']['path']  => $target['uploads_path'],
						$source['paths']['content'] => $target['content'],
						$source['paths']['abspath'] => $target['abspath'],
					)
				)
			);
		}

		return $this->replacer;
	}

	/**
	 * Adapts a source CREATE TABLE statement to this server.
	 *
	 * @param string $create CREATE TABLE statement from the source.
	 * @param string $table  Source table name.
	 * @param string $temp   Temporary table name.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException If the statement isn't a plain CREATE TABLE.
	 */
	private function rewrite_create( string $create, string $table, string $temp ): string {
		$quoted = preg_quote( '`' . str_replace( '`', '``', $table ) . '`', '/' );
		$sql    = preg_replace( '/^CREATE TABLE ' . $quoted . ' \(/', 'CREATE TABLE `' . $temp . '` (', $create, 1, $count );

		if ( 1 !== $count || ! is_string( $sql ) ) {
			throw new \RuntimeException( esc_html( 'Unexpected schema for ' . $table . '.' ) );
		}

		// Constraint names are unique per database and would clash with the
		// live table's; let MySQL name them.
		$sql = (string) preg_replace( '/CONSTRAINT `(?:[^`]|``)+` /', '', $sql );

		$source_prefix = (string) $this->job->state['source']['prefix'];
		$sql           = (string) preg_replace_callback(
			'/REFERENCES `((?:[^`]|``)+)`/',
			fn( array $m ): string => str_starts_with( $m[1], $source_prefix )
				? 'REFERENCES `' . self::temp_name( $this->job, $m[1] ) . '`'
				: $m[0],
			$sql
		);

		return $this->adapt_to_server( $sql );
	}

	/**
	 * Replaces storage engines and collations this server lacks, typically
	 * MySQL 8's utf8mb4_0900_* collations on MariaDB.
	 *
	 * @param string $sql CREATE TABLE statement.
	 *
	 * @return string
	 */
	private function adapt_to_server( string $sql ): string {
		global $wpdb;

		static $collations = null;
		static $engines    = null;

		if ( null === $collations ) {
			$collations = array_map( 'strtolower', (array) $wpdb->get_col( 'SHOW COLLATION' ) );
			$engines    = array();
			foreach ( (array) $wpdb->get_results( 'SHOW ENGINES', ARRAY_A ) as $engine ) {
				if ( in_array( strtoupper( (string) $engine['Support'] ), array( 'YES', 'DEFAULT' ), true ) ) {
					$engines[] = strtolower( (string) $engine['Engine'] );
				}
			}
		}

		$fallback = in_array( 'utf8mb4_unicode_520_ci', $collations, true ) ? 'utf8mb4_unicode_520_ci' : 'utf8mb4_unicode_ci';

		$sql = (string) preg_replace_callback(
			'/\bCOLLATE[= ]([A-Za-z0-9_]+)/',
			static function ( array $m ) use ( $collations, $fallback ): string {
				if ( in_array( strtolower( $m[1] ), $collations, true ) ) {
					return $m[0];
				}

				return 'COLLATE ' . ( str_starts_with( strtolower( $m[1] ), 'utf8mb4' ) ? $fallback : 'utf8mb4_unicode_ci' );
			},
			$sql
		);

		return (string) preg_replace_callback(
			'/\bENGINE=([A-Za-z0-9_]+)/',
			static fn( array $m ): string => in_array( strtolower( $m[1] ), $engines, true ) ? $m[0] : 'ENGINE=InnoDB',
			$sql
		);
	}
}
