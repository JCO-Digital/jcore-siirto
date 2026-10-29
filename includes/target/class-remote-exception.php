<?php
/**
 * Failure talking to the source site.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Target;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when a request to the source fails. Usually worth retrying.
 */
class Remote_Exception extends \RuntimeException {
}
