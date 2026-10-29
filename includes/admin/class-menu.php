<?php
/**
 * The migration screen and the React app it hosts.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Tools screen and enqueues its assets.
 */
final class Menu {

	/**
	 * Page slug, also the script and style handle.
	 */
	public const SLUG = 'jcore-siirto';

	/**
	 * Hook suffix `add_management_page()` returns for this screen.
	 */
	private const HOOK = 'tools_page_' . self::SLUG;

	/**
	 * Build entry the screen runs on.
	 */
	private const ENTRY = 'admin';

	/**
	 * Hooks the page and its assets.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Returns the admin URL of the migration screen.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'tools.php?page=' . self::SLUG );
	}

	/**
	 * Registers the Tools sub-page. Not on multisite, which isn't supported.
	 *
	 * @return void
	 */
	public static function add_page(): void {
		if ( is_multisite() ) {
			return;
		}

		add_management_page(
			__( 'JCORE Siirto', 'jcore-siirto' ),
			__( 'Site migration', 'jcore-siirto' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Outputs the mount point the React app renders into.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		require JCORE_SIIRTO_PATH . 'views/admin/page.php';
	}

	/**
	 * Enqueues the React app, on this screen only.
	 *
	 * @param string $hook The current admin page hook.
	 *
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( self::HOOK !== $hook ) {
			return;
		}

		$asset_file = JCORE_SIIRTO_PATH . 'build/' . self::ENTRY . '.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::SLUG,
			JCORE_SIIRTO_URL . 'build/' . self::ENTRY . '.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( self::SLUG, 'jcore-siirto', JCORE_SIIRTO_PATH . 'languages' );

		$style_file = JCORE_SIIRTO_PATH . 'build/style-' . self::ENTRY . '.css';
		if ( is_readable( $style_file ) ) {
			wp_enqueue_style(
				self::SLUG,
				JCORE_SIIRTO_URL . 'build/style-' . self::ENTRY . '.css',
				array( 'wp-components' ),
				$asset['version']
			);
		}
	}
}
