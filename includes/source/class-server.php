<?php
/**
 * The endpoints a target site calls to read this site.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Source;

use Jcore\Siirto\Crypto;
use Jcore\Siirto\Database;
use Jcore\Siirto\Log;
use Jcore\Siirto\Roots;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves file listings, file contents, table schemas and rows to a target that
 * holds a valid migration key.
 *
 * Nothing here relies on a WordPress login. Every request is authenticated by
 * an HMAC over its route, body, timestamp and a sequence number, and every
 * response is encrypted and authenticated for that one request. The routes
 * only exist while an unexpired key does.
 */
final class Server {

	/**
	 * Route namespace and base.
	 */
	public const NAMESPACE = 'jcore-siirto/v1';
	public const BASE      = 'source';

	/**
	 * Accepted clock difference between the sites, in seconds.
	 */
	private const MAX_SKEW = 300;

	/**
	 * Failed requests per IP and hour before the IP is refused outright.
	 */
	private const MAX_IP_FAILURES = 30;

	/**
	 * Most entries returned by one directory listing.
	 */
	private const MAX_LIST = 2000;

	/**
	 * Most rows returned by one rows request.
	 */
	private const MAX_ROWS = 5000;

	/**
	 * Scope each action needs. `null` means any valid key.
	 */
	private const ACTIONS = array(
		'hello'  => null,
		'list'   => 'files',
		'read'   => 'files',
		'schema' => 'database',
		'rows'   => 'database',
		'finish' => null,
	);

	/**
	 * The key that authenticated the current request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $key = null;

	/**
	 * The request authorize() accepted.
	 *
	 * @var \WP_REST_Request|null
	 */
	private ?\WP_REST_Request $request = null;

	/**
	 * Registers the routes, but only while a key might be valid.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		if ( ! Keys::window_open() ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/(?P<action>' . implode( '|', array_keys( self::ACTIONS ) ) . ')',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dispatch' ),
				// Not a WordPress login: authorize() verifies the request signature.
				'permission_callback' => array( $this, 'authorize' ),
			)
		);

		add_filter( 'rest_pre_serve_request', array( $this, 'serve_raw' ), 10, 4 );
	}

	/**
	 * Verifies a request. Runs before any action.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	public function authorize( \WP_REST_Request $request ) {
		// WordPress runs permission callbacks again after dispatch to build
		// the Allow header. The sequence number is spent by then, so repeat
		// the earlier verdict instead of verifying twice.
		if ( $request === $this->request ) {
			return true;
		}

		if ( is_multisite() ) {
			return $this->deny( 'Multisite is not supported.', '', 400 );
		}

		if ( ! is_ssl() && ! self::allow_http() ) {
			return $this->deny( 'HTTPS is required.', '', 403 );
		}

		$ip_key = 'jcore_siirto_fail_' . md5( Log::ip() );
		if ( (int) get_transient( $ip_key ) >= self::MAX_IP_FAILURES ) {
			return new \WP_Error( 'jcore_siirto_rate_limited', 'Too many failed requests.', array( 'status' => 429 ) );
		}

		$key_id    = (string) $request->get_header( 'x-siirto-key' );
		$client_id = (string) $request->get_header( 'x-siirto-client' );
		$seq       = (int) $request->get_header( 'x-siirto-seq' );
		$time      = (int) $request->get_header( 'x-siirto-time' );
		$signature = (string) $request->get_header( 'x-siirto-signature' );

		$key = Keys::find_active( $key_id );
		if ( null === $key ) {
			return $this->fail( $ip_key, 'Unknown, expired or revoked key.', $key_id );
		}

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $client_id ) || $seq < 1 ) {
			return $this->fail( $ip_key, 'Malformed request.', $key_id );
		}

		if ( abs( time() - $time ) > self::MAX_SKEW ) {
			return $this->fail( $ip_key, 'Request timestamp out of range. Check both servers\' clocks.', $key_id );
		}

		$expected = Crypto::sign(
			$key['secret'],
			Crypto::canonical( $request->get_route(), $key_id, $client_id, $seq, $time, $request->get_body() )
		);

		if ( ! hash_equals( $expected, $signature ) ) {
			Keys::record_failure( $key_id );

			return $this->fail( $ip_key, 'Invalid signature.', $key_id );
		}

		if ( ! Keys::bind( $key_id, $client_id ) ) {
			return $this->fail( $ip_key, 'Key is already in use by another target site.', $key_id );
		}

		if ( ! Keys::consume_seq( $key_id, $seq ) ) {
			return $this->fail( $ip_key, 'Replayed or out-of-order request.', $key_id );
		}

		$action = (string) $request['action'];
		$scope  = self::ACTIONS[ $action ] ?? null;
		if ( ! array_key_exists( $action, self::ACTIONS ) || ( null !== $scope && ! in_array( $scope, $key['scopes'], true ) ) ) {
			return $this->deny( 'This key does not allow that.', $key_id, 403 );
		}

		$this->key     = $key;
		$this->request = $request;

		return true;
	}

	/**
	 * Runs the requested action and seals its result.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function dispatch( \WP_REST_Request $request ) {
		$params = json_decode( $request->get_body(), true );
		$params = is_array( $params ) ? $params : array();
		$action = (string) $request['action'];

		try {
			switch ( $action ) {
				case 'hello':
					$payload = (string) wp_json_encode( $this->hello() );
					break;
				case 'list':
					$payload = (string) wp_json_encode( $this->list_dir( $params ) );
					break;
				case 'read':
					$payload = $this->read_files( $params );
					break;
				case 'schema':
					$payload = (string) wp_json_encode( $this->schema( (string) ( $params['table'] ?? '' ) ) );
					break;
				case 'rows':
					$payload = (string) wp_json_encode( $this->rows( $params ) );
					break;
				default:
					Keys::revoke( $this->key['id'], 'Target reported the migration finished.' );
					$payload = (string) wp_json_encode( array( 'ok' => true ) );
			}
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'jcore_siirto_source_error', $e->getMessage(), array( 'status' => 400 ) );
		}

		if ( 'hello' === $action ) {
			Log::add( 'source', 'hello', 'Target site read site information.', $this->key['id'] );
		}

		$context = $this->key['id'] . "\n" . (int) $request->get_header( 'x-siirto-seq' ) . "\n" . $request->get_route();
		$sealed  = Crypto::seal( $this->key['secret'], $payload, $context );

		$response = new \WP_REST_Response( $sealed, 200 );
		$response->header( 'Content-Type', 'application/octet-stream' );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return $response;
	}

	/**
	 * Sends sealed responses as raw bytes instead of JSON.
	 *
	 * @param bool              $served  Whether the request was already served.
	 * @param \WP_HTTP_Response $result  Result.
	 * @param \WP_REST_Request  $request Request.
	 *
	 * @return bool
	 */
	public function serve_raw( $served, $result, $request ): bool {
		if ( $served || ! str_starts_with( $request->get_route(), '/' . self::NAMESPACE . '/' . self::BASE . '/' ) ) {
			return (bool) $served;
		}

		if ( 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encrypted binary payload, not HTML.
		echo $result->get_data();

		return true;
	}

	/**
	 * Describes the site so the target can plan the migration.
	 *
	 * @return array<string, mixed>
	 */
	private function hello(): array {
		global $wpdb, $wp_version;

		$uploads = wp_get_upload_dir();

		$roots = array();
		foreach ( Roots::ALL as $root ) {
			if ( null !== Roots::dir( $root ) ) {
				$roots[] = $root;
			}
		}

		return array(
			'protocol' => Crypto::VERSION,
			'plugin'   => JCORE_SIIRTO_VERSION,
			'name'     => get_bloginfo( 'name' ),
			'wp'       => $wp_version,
			'php'      => PHP_VERSION,
			'db'       => $wpdb->db_server_info(),
			'home'     => home_url(),
			'siteurl'  => site_url(),
			'uploads'  => array(
				'url'  => $uploads['baseurl'],
				'path' => $uploads['basedir'],
			),
			'paths'    => array(
				'abspath' => untrailingslashit( ABSPATH ),
				'content' => untrailingslashit( WP_CONTENT_DIR ),
			),
			'prefix'   => $wpdb->prefix,
			'scopes'   => array_values( $this->key['scopes'] ),
			'roots'    => in_array( 'files', $this->key['scopes'], true ) ? $roots : array(),
			'tables'   => in_array( 'database', $this->key['scopes'], true ) ? self::tables() : array(),
			'sodium'   => extension_loaded( 'sodium' ),
		);
	}

	/**
	 * Lists one page of a directory.
	 *
	 * @param array<string, mixed> $params `root`, `dir`, `offset`, `limit`.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException On an invalid path.
	 */
	private function list_dir( array $params ): array {
		$root   = (string) ( $params['root'] ?? '' );
		$dir    = (string) ( $params['dir'] ?? '' );
		$offset = max( 0, (int) ( $params['offset'] ?? 0 ) );
		$limit  = max( 1, min( self::MAX_LIST, (int) ( $params['limit'] ?? self::MAX_LIST ) ) );

		$path = Roots::resolve( $root, $dir );
		if ( null === $path || ! is_dir( $path ) || Roots::is_excluded( $root, $dir, true ) ) {
			throw new \RuntimeException( 'Directory not available.' );
		}

		$names = scandir( $path );
		$names = false === $names ? array() : array_values( array_diff( $names, array( '.', '..' ) ) );
		$total = count( $names );

		$entries = array();
		$skipped = array();

		foreach ( array_slice( $names, $offset, $limit ) as $name ) {
			$rel  = '' === $dir ? $name : $dir . '/' . $name;
			$full = $path . '/' . $name;

			if ( ! Roots::valid_relative( $rel ) ) {
				$skipped[] = array( $rel, 'name' );
			} elseif ( is_link( $full ) ) {
				$skipped[] = array( $rel, 'symlink' );
			} elseif ( is_dir( $full ) ) {
				if ( ! Roots::is_excluded( $root, $rel, true ) ) {
					$entries[] = array(
						'n' => $name,
						't' => 'd',
					);
				}
			} elseif ( is_file( $full ) && ! Roots::is_excluded( $root, $rel, false ) ) {
				if ( is_readable( $full ) ) {
					$entries[] = array(
						'n' => $name,
						't' => 'f',
						's' => (int) filesize( $full ),
						'm' => (int) filemtime( $full ),
					);
				} else {
					$skipped[] = array( $rel, 'unreadable' );
				}
			}
		}

		return array(
			'entries' => $entries,
			'skipped' => $skipped,
			'next'    => $offset + $limit < $total ? $offset + $limit : null,
		);
	}

	/**
	 * Reads file contents, whole or in byte ranges.
	 *
	 * The result is a sequence of frames: a 4-byte big-endian header length,
	 * a JSON header, then the bytes the header announces.
	 *
	 * @param array<string, mixed> $params `files`: list of `root`, `path`, `offset`, `length`.
	 *
	 * @return string
	 */
	private function read_files( array $params ): string {
		$budget = self::chunk_size();
		$out    = '';
		$files  = is_array( $params['files'] ?? null ) ? array_slice( $params['files'], 0, 500 ) : array();

		foreach ( $files as $file ) {
			if ( $budget <= 0 ) {
				break;
			}

			$root   = (string) ( $file['root'] ?? '' );
			$rel    = (string) ( $file['path'] ?? '' );
			$offset = max( 0, (int) ( $file['offset'] ?? 0 ) );
			$header = array(
				'root'   => $root,
				'path'   => $rel,
				'offset' => $offset,
			);
			$data   = '';

			$full = Roots::resolve( $root, $rel );
			if ( '' === $rel || null === $full || ! is_file( $full ) || Roots::is_excluded( $root, $rel, false ) ) {
				$header['error'] = 'not available';
			} else {
				$size   = (int) filesize( $full );
				$length = min( max( 0, (int) ( $file['length'] ?? $size ) ), max( 0, $size - $offset ), $budget );

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a byte range; WP_Filesystem can't.
				$handle = fopen( $full, 'rb' );
				if ( false === $handle ) {
					$header['error'] = 'unreadable';
				} else {
					if ( $length > 0 && 0 === fseek( $handle, $offset ) ) {
						$remaining = $length;
						while ( $remaining > 0 && ! feof( $handle ) ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- See above.
							$chunk = fread( $handle, $remaining );
							if ( false === $chunk || '' === $chunk ) {
								break;
							}
							$data      .= $chunk;
							$remaining -= strlen( $chunk );
						}
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See above.
					fclose( $handle );

					$header['size']  = $size;
					$header['mtime'] = (int) filemtime( $full );
				}
			}

			$header['length'] = strlen( $data );
			$json             = (string) wp_json_encode( $header );
			$out             .= pack( 'N', strlen( $json ) ) . $json . $data;
			$budget          -= max( 1, strlen( $data ) );
		}

		return $out;
	}

	/**
	 * Describes a table.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException If the table isn't one this site exports.
	 */
	private function schema( string $table ): array {
		global $wpdb;

		$info = self::tables()[ $table ] ?? null;
		if ( null === $info ) {
			throw new \RuntimeException( 'Table not available.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- SHOW CREATE TABLE only reads the schema, for export.
		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );

		return array(
			'create'  => is_array( $create ) ? (string) $create[1] : '',
			'columns' => self::columns( $table ),
			'pk'      => self::primary_key( $table ),
			'avg_row' => $info['avg_row'],
		);
	}

	/**
	 * Returns one batch of rows.
	 *
	 * Tables with a single-column primary key are paged by key (fast at any
	 * depth); others by offset.
	 *
	 * @param array<string, mixed> $params `table`, `after`, `offset`, `limit`, `skip_transients`.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException If the table isn't one this site exports.
	 */
	private function rows( array $params ): array {
		global $wpdb;

		$table = (string) ( $params['table'] ?? '' );
		if ( ! isset( self::tables()[ $table ] ) ) {
			throw new \RuntimeException( 'Table not available.' );
		}

		$columns = self::columns( $table );
		$pk      = self::primary_key( $table );
		$limit   = max( 1, min( self::MAX_ROWS, (int) ( $params['limit'] ?? 500 ) ) );
		$offset  = max( 0, (int) ( $params['offset'] ?? 0 ) );
		$after   = isset( $params['after'] ) ? (string) $params['after'] : null;
		$keyset  = 1 === count( $pk );

		$where = array( '1=1' );
		$args  = array( $table );

		if ( $wpdb->prefix . 'options' === $table ) {
			// This plugin's own options never leave the site: they include the
			// state that decides whether these endpoints exist.
			$where[] = 'option_name NOT LIKE %s';
			$args[]  = $wpdb->esc_like( Database::NAME_PREFIX ) . '%';

			if ( ! empty( $params['skip_transients'] ) ) {
				$where[] = 'option_name NOT LIKE %s AND option_name NOT LIKE %s';
				$args[]  = $wpdb->esc_like( '_transient_' ) . '%';
				$args[]  = $wpdb->esc_like( '_site_transient_' ) . '%';
			}
		}

		if ( $keyset && null !== $after ) {
			$where[] = '%i > %s';
			$args[]  = $pk[0];
			$args[]  = $after;
		}

		$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where );

		if ( array() !== $pk ) {
			$sql .= ' ORDER BY ' . implode( ', ', array_fill( 0, count( $pk ), '%i' ) );
			$args = array_merge( $args, $pk );
		}

		$sql   .= ' LIMIT %d';
		$args[] = $limit;

		if ( ! $keyset ) {
			$sql   .= ' OFFSET %d';
			$args[] = $offset;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exporting rows; the query is assembled from placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_N );
		if ( ! is_array( $rows ) ) {
			throw new \RuntimeException( esc_html( 'Query failed: ' . $wpdb->last_error ) );
		}

		$binary = array();
		foreach ( $columns as $index => $column ) {
			$binary[ $index ] = (bool) preg_match( '/blob|binary|bit\b|geometry|point|polygon|linestring/i', $column['type'] );
		}

		$pk_index = $keyset ? array_search( $pk[0], array_column( $columns, 'name' ), true ) : false;
		$last     = null;

		foreach ( $rows as $r => $row ) {
			if ( false !== $pk_index ) {
				$last = $row[ $pk_index ];
			}

			foreach ( $row as $c => $value ) {
				if ( null !== $value && ( $binary[ $c ] || 1 !== preg_match( '//u', $value ) ) ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary column value in JSON.
					$rows[ $r ][ $c ] = array( 'b' => base64_encode( $value ) );
				}
			}
		}

		return array(
			'rows'   => $rows,
			'after'  => $last,
			'offset' => $offset + count( $rows ),
			'done'   => count( $rows ) < $limit,
		);
	}

	/**
	 * Returns the tables this site exports: base tables with the site's prefix,
	 * except the plugin's own and leftovers of earlier migrations.
	 *
	 * @return array<string, array{rows: int, bytes: int, avg_row: int}>
	 */
	public static function tables(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Listing tables for export.
		$status = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A );

		$tables = array();
		foreach ( is_array( $status ) ? $status : array() as $row ) {
			$name = (string) $row['Name'];

			if ( null === $row['Engine'] || ! str_starts_with( $name, $wpdb->prefix ) ) {
				continue;
			}

			if ( str_starts_with( $name, $wpdb->prefix . Database::NAME_PREFIX ) ) {
				continue;
			}

			$tables[ $name ] = array(
				'rows'    => (int) $row['Rows'],
				'bytes'   => (int) $row['Data_length'] + (int) $row['Index_length'],
				'avg_row' => (int) $row['Avg_row_length'],
			);
		}

		return $tables;
	}

	/**
	 * Column names and types, in table order.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, array{name: string, type: string, extra: string}>
	 */
	private static function columns( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the schema for export.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );

		return array_map(
			static fn( array $row ): array => array(
				'name'  => (string) $row['Field'],
				'type'  => (string) $row['Type'],
				'extra' => (string) $row['Extra'],
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Primary key columns, in key order.
	 *
	 * @param string $table Table name.
	 *
	 * @return string[]
	 */
	private static function primary_key( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the schema for export.
		$keys = $wpdb->get_results( $wpdb->prepare( "SHOW KEYS FROM %i WHERE Key_name = 'PRIMARY'", $table ), ARRAY_A );
		$keys = is_array( $keys ) ? $keys : array();

		usort( $keys, static fn( $a, $b ) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );

		return array_map( static fn( array $row ): string => (string) $row['Column_name'], $keys );
	}

	/**
	 * Most bytes of file data in one response. Smaller without the sodium
	 * extension, as the pure-PHP fallback is much slower.
	 *
	 * @return int
	 */
	public static function chunk_size(): int {
		return extension_loaded( 'sodium' ) ? 4 * MB_IN_BYTES : MB_IN_BYTES;
	}

	/**
	 * Whether plain HTTP is allowed, for local development only.
	 *
	 * @return bool
	 */
	public static function allow_http(): bool {
		return defined( 'JCORE_SIIRTO_ALLOW_HTTP' ) && true === JCORE_SIIRTO_ALLOW_HTTP;
	}

	/**
	 * Logs and counts a failed request.
	 *
	 * @param string $ip_key Transient counting this IP's failures.
	 * @param string $reason Logged reason.
	 * @param string $key_id Key ID from the request.
	 *
	 * @return \WP_Error
	 */
	private function fail( string $ip_key, string $reason, string $key_id ): \WP_Error {
		set_transient( $ip_key, (int) get_transient( $ip_key ) + 1, HOUR_IN_SECONDS );

		return $this->deny( $reason, preg_match( '/^[a-f0-9]{32}$/', $key_id ) ? $key_id : '', 403 );
	}

	/**
	 * Logs a refused request and returns the error.
	 *
	 * @param string $reason Logged reason.
	 * @param string $key_id Key ID, if known.
	 * @param int    $status HTTP status.
	 *
	 * @return \WP_Error
	 */
	private function deny( string $reason, string $key_id, int $status ): \WP_Error {
		Log::add( 'source', 'denied', $reason, $key_id );

		// The reasons carry no secrets, and the target's administrator needs
		// them to fix things like clock skew or a key used elsewhere.
		return new \WP_Error( 'jcore_siirto_denied', $reason, array( 'status' => $status ) );
	}
}
