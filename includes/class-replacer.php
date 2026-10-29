<?php
/**
 * Search and replace that understands PHP serialized data.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites URLs and paths in database values.
 *
 * Serialized values are walked token by token and their string lengths are
 * recomputed, instead of being passed through unserialize(). That keeps the
 * replacement exact for objects of classes that aren't loaded, and means data
 * from the other site can never instantiate an object here.
 */
final class Replacer {

	/**
	 * Maximum nesting depth of serialized data that is walked.
	 */
	private const MAX_DEPTH = 256;

	/**
	 * Replacement pairs, from => to.
	 *
	 * @var array<string, string>
	 */
	private array $pairs;

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $pairs Replacement pairs. Pairs whose two sides are equal are dropped.
	 */
	public function __construct( array $pairs ) {
		$this->pairs = array_filter(
			$pairs,
			static fn( $to, $from ) => '' !== (string) $from && (string) $from !== $to,
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Builds the pairs that move a site from one address and path to another.
	 *
	 * Covers the plain, protocol-relative, JSON-escaped and URL-encoded forms of
	 * the address, because each appears in real WordPress databases.
	 *
	 * @param array<string, string> $urls  Source URL => target URL (home, siteurl, uploads URL…).
	 * @param array<string, string> $paths Source path => target path.
	 *
	 * @return array<string, string>
	 */
	public static function pairs_for( array $urls, array $paths ): array {
		$pairs = array();

		foreach ( $urls as $from => $to ) {
			$from = untrailingslashit( $from );
			$to   = untrailingslashit( $to );
			$bare = preg_replace( '#^https?:#i', '', $from );
			$dest = preg_replace( '#^https?:#i', '', $to );

			$variants = array(
				'http:' . $bare  => $to,
				'https:' . $bare => $to,
				$bare            => $dest,
			);

			foreach ( $variants as $variant_from => $variant_to ) {
				$pairs[ $variant_from ]                            = $variant_to;
				$pairs[ str_replace( '/', '\\/', $variant_from ) ] = str_replace( '/', '\\/', $variant_to );
				$pairs[ rawurlencode( $variant_from ) ]            = rawurlencode( $variant_to );
			}
		}

		foreach ( $paths as $from => $to ) {
			$pairs[ untrailingslashit( $from ) ] = untrailingslashit( $to );
		}

		return $pairs;
	}

	/**
	 * Whether there is anything to replace at all.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->pairs;
	}

	/**
	 * Replaces in a single database value.
	 *
	 * @param string $value Value.
	 *
	 * @return string
	 */
	public function replace( string $value ): string {
		return $this->replace_value( $value, 0 );
	}

	/**
	 * Replaces in a value that may itself be serialized.
	 *
	 * @param string $value Value.
	 * @param int    $depth Current nesting depth.
	 *
	 * @return string
	 */
	private function replace_value( string $value, int $depth ): string {
		if ( ! $this->contains_any( $value ) ) {
			return $value;
		}

		if ( is_serialized( $value, false ) ) {
			$pos    = 0;
			$result = $this->walk( $value, $pos, $depth );

			if ( null !== $result && '' === trim( substr( $value, $pos ) ) ) {
				return $result . substr( $value, $pos );
			}

			// Looks serialized but doesn't parse. Changing its bytes could
			// only make it worse, so it is left exactly as it was.
			return $value;
		}

		return strtr( $value, $this->pairs );
	}

	/**
	 * Whether any search string occurs in the value.
	 *
	 * @param string $value Value.
	 *
	 * @return bool
	 */
	private function contains_any( string $value ): bool {
		foreach ( $this->pairs as $from => $to ) {
			if ( str_contains( $value, (string) $from ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Re-emits one serialized token, replacing inside its strings.
	 *
	 * @param string $s     Serialized data.
	 * @param int    $pos   Read position, advanced past the token.
	 * @param int    $depth Current nesting depth.
	 *
	 * @return string|null Null if the data is malformed.
	 */
	private function walk( string $s, int &$pos, int $depth ): ?string {
		if ( $depth > self::MAX_DEPTH || ! isset( $s[ $pos ] ) ) {
			return null;
		}

		switch ( $s[ $pos ] ) {
			case 'N':
				if ( 'N;' !== substr( $s, $pos, 2 ) ) {
					return null;
				}
				$pos += 2;
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				if ( ! preg_match( '/\G[bidrR]:[^;:{}"]*;/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$pos += strlen( $m[0] );
				return $m[0];

			case 's':
				if ( ! preg_match( '/\Gs:(\d+):"/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$length = (int) $m[1];
				$start  = $pos + strlen( $m[0] );
				if ( '";' !== substr( $s, $start + $length, 2 ) ) {
					return null;
				}
				$pos = $start + $length + 2;
				$new = $this->replace_value( substr( $s, $start, $length ), $depth + 1 );
				return 's:' . strlen( $new ) . ':"' . $new . '";';

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$pos += strlen( $m[0] );
				return $this->walk_members( $s, $pos, $depth, (int) $m[1], $m[0] );

			case 'O':
				if ( ! preg_match( '/\GO:(\d+):"([^"]*)":(\d+):\{/', $s, $m, 0, $pos ) || strlen( $m[2] ) !== (int) $m[1] ) {
					return null;
				}
				$pos += strlen( $m[0] );
				return $this->walk_members( $s, $pos, $depth, (int) $m[3], $m[0] );

			case 'C':
				// Custom serialization: the payload format belongs to the
				// class, so it is copied byte for byte.
				if ( ! preg_match( '/\GC:(\d+):"([^"]*)":(\d+):\{/', $s, $m, 0, $pos ) || strlen( $m[2] ) !== (int) $m[1] ) {
					return null;
				}
				$end = $pos + strlen( $m[0] ) + (int) $m[3];
				if ( '}' !== substr( $s, $end, 1 ) ) {
					return null;
				}
				$token = substr( $s, $pos, $end - $pos + 1 );
				$pos   = $end + 1;
				return $token;

			case 'E':
				if ( ! preg_match( '/\GE:(\d+):"/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$end = $pos + strlen( $m[0] ) + (int) $m[1];
				if ( '";' !== substr( $s, $end, 2 ) ) {
					return null;
				}
				$token = substr( $s, $pos, $end - $pos + 2 );
				$pos   = $end + 2;
				return $token;
		}

		return null;
	}

	/**
	 * Re-emits the key/value pairs of an array or object and its closing brace.
	 *
	 * @param string $s      Serialized data.
	 * @param int    $pos    Read position, just after the opening brace.
	 * @param int    $depth  Current nesting depth.
	 * @param int    $count  Number of members.
	 * @param string $header The already-read header, opening brace included.
	 *
	 * @return string|null
	 */
	private function walk_members( string $s, int &$pos, int $depth, int $count, string $header ): ?string {
		$out = $header;

		for ( $i = 0; $i < $count * 2; $i++ ) {
			$token = $this->walk( $s, $pos, $depth + 1 );
			if ( null === $token ) {
				return null;
			}
			$out .= $token;
		}

		if ( '}' !== substr( $s, $pos, 1 ) ) {
			return null;
		}
		++$pos;

		return $out . '}';
	}
}
