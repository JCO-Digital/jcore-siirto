<?php
/**
 * Plugin bootstrap.
 *
 * @package Jcore\Siirto
 */

namespace Jcore\Siirto;

use Jcore\Update\Config\UpdateConfig;
use Jcore\Update\Hooks\PluginUpdateHooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every component to its hooks.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers every hook. Called once on `plugins_loaded`.
	 *
	 * @return void
	 */
	public function boot(): void {
		Database::maybe_upgrade();
		$this->register_updater();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( is_admin() ) {
			Admin\Menu::register();
			add_filter( 'plugin_action_links_' . plugin_basename( JCORE_SIIRTO_FILE ), array( $this, 'action_links' ) );
		}
	}

	/**
	 * Hooks the plugin into the J&Co Digital update service.
	 *
	 * The library is vendored into the release; a source checkout without a
	 * `composer install` simply runs without update checks.
	 *
	 * @return void
	 */
	private function register_updater(): void {
		if ( ! class_exists( UpdateConfig::class ) ) {
			return;
		}

		$config = new UpdateConfig(
			pluginFile: JCORE_SIIRTO_FILE,
			slug: 'jcore-siirto',
			version: JCORE_SIIRTO_VERSION,
			apiBaseUrl: 'https://update.jcore.fi/v1',
		);

		( new PluginUpdateHooks( $config ) )->register();
	}

	/**
	 * Loads the PHP translations shipped in languages/.
	 *
	 * Just-in-time loading only looks in wp-content/languages, which these
	 * plugins never ship to, so the bundled files have to be loaded explicitly.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Not hosted on WordPress.org; translations ship in this plugin's languages/ folder.
		load_plugin_textdomain( 'jcore-siirto', false, dirname( plugin_basename( JCORE_SIIRTO_FILE ) ) . '/languages' );
	}

	/**
	 * Registers the REST routes: the admin app's, and the signed source
	 * endpoints while a migration key is valid.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new Rest\Source_Controller() )->register_routes();
		( new Rest\Target_Controller() )->register_routes();
		( new Source\Server() )->register_routes();
	}

	/**
	 * Adds a link to the migration screen on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 *
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( Admin\Menu::url() ),
				esc_html__( 'Migrate', 'jcore-siirto' )
			)
		);

		return $links;
	}
}
