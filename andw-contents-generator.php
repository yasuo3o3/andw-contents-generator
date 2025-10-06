<?php
/**
 * Plugin Name: andW Contents Generator
 * Description: Gutenberg向けにAI下書き生成とHTMLインポート変換を提供する支援ツールです。
 * Version: 0.0.1
 * Author: yasuo3o3
 * Author URI: https://yasuo-o.xyz/
 * Contributors: yasuo3o3
 * License: GPLv2 or later
 * Text Domain: andw-contents-generator
 */

defined( 'ABSPATH' ) || exit;

define( 'ANDW_CONTENTS_GENERATOR_VERSION', '0.0.1' );
define( 'ANDW_CONTENTS_GENERATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ANDW_CONTENTS_GENERATOR_URL', plugin_dir_url( __FILE__ ) );
define( 'ANDW_CONTENTS_GENERATOR_BASENAME', plugin_basename( __FILE__ ) );

require_once ANDW_CONTENTS_GENERATOR_PATH . 'core/class-andw-logger.php';
require_once ANDW_CONTENTS_GENERATOR_PATH . 'core/class-andw-permissions.php';
require_once ANDW_CONTENTS_GENERATOR_PATH . 'core/class-andw-settings.php';
require_once ANDW_CONTENTS_GENERATOR_PATH . 'admin/class-andw-settings-page.php';
require_once ANDW_CONTENTS_GENERATOR_PATH . 'module-ai/class-andw-ai-service.php';
require_once ANDW_CONTENTS_GENERATOR_PATH . 'module-ai/class-andw-ai-rest.php';
require_once ANDW_CONTENTS_GENERATOR_PATH . 'module-ai/class-andw-ai-sidebar.php';
require_once ANDW_CONTENTS_GENERATOR_PATH . 'module-ai/class-andw-ai-list-actions.php';
require_once ANDW_CONTENTS_GENERATOR_PATH . 'module-html/class-andw-html-importer.php';
require_once ANDW_CONTENTS_GENERATOR_PATH . 'module-html/class-andw-html-rest.php';
require_once ANDW_CONTENTS_GENERATOR_PATH . 'module-html/class-andw-html-sidebar.php';

/**
 * Main bootstrap for the plugin.
 */
final class Andw_Contents_Generator {
	/**
	 * Singleton instance.
	 *
	 * @var Andw_Contents_Generator|null
	 */
	protected static $instance = null;

	/**
	 * Logger instance.
	 *
	 * @var Andw_Contents_Generator_Logger
	 */
	protected $logger;

	/**
	 * Settings manager.
	 *
	 * @var Andw_Contents_Generator_Settings
	 */
	protected $settings;

	/**
	 * Settings page renderer.
	 *
	 * @var Andw_Contents_Generator_Settings_Page|null
	 */
	protected $settings_page = null;

	/**
	 * AI service handler.
	 *
	 * @var Andw_Contents_Generator_AI_Service|null
	 */
	protected $ai_service = null;

	/**
	 * HTML importer handler.
	 *
	 * @var Andw_Contents_Generator_HTML_Importer|null
	 */
	protected $html_importer = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->boot();
	}

	/**
	 * Retrieve singleton instance.
	 *
	 * @return Andw_Contents_Generator
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Core bootstrap actions.
	 */
	private function boot() {
		$this->logger   = new Andw_Contents_Generator_Logger( ANDW_CONTENTS_GENERATOR_PATH . 'logs' );
		$this->settings = new Andw_Contents_Generator_Settings( $this->logger );

		add_action( 'init', array( 'Andw_Contents_Generator_Permissions', 'ensure_caps' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . ANDW_CONTENTS_GENERATOR_BASENAME, array( $this, 'add_settings_link' ) );

		$this->boot_modules();
	}

	/**
	 * Register settings page.
	 */
	public function register_settings_page() {
		if ( null === $this->settings_page ) {
			$this->settings_page = new Andw_Contents_Generator_Settings_Page( $this->settings, $this->logger );
		}

		$this->settings_page->register();
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		$this->settings->register();
	}

	/**
	 * Add settings link to plugin list.
	 *
	 * @param array $links Existing links.
	 *
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'options-general.php?page=andw-contents-generator' ) ),
			esc_html__( '設定', 'andw-contents-generator' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Bootstrap sub modules.
	 */
	private function boot_modules() {
		$this->ai_service    = new Andw_Contents_Generator_AI_Service( $this->settings, $this->logger );
		$this->html_importer = new Andw_Contents_Generator_HTML_Importer( $this->settings, $this->logger );

		new Andw_Contents_Generator_AI_Rest( $this->ai_service, $this->logger );
		new Andw_Contents_Generator_HTML_Rest( $this->html_importer, $this->logger );

		if ( is_admin() ) {
			new Andw_Contents_Generator_AI_Sidebar( $this->settings );
			new Andw_Contents_Generator_AI_List_Actions( $this->ai_service, $this->logger );
			new Andw_Contents_Generator_HTML_Sidebar( $this->settings );
		}
	}

	/**
	 * Retrieve logger.
	 *
	 * @return Andw_Contents_Generator_Logger
	 */
	public function logger() {
		return $this->logger;
	}

	/**
	 * Retrieve settings manager.
	 *
	 * @return Andw_Contents_Generator_Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Retrieve AI service instance.
	 *
	 * @return Andw_Contents_Generator_AI_Service
	 */
	public function ai_service() {
		return $this->ai_service;
	}

	/**
	 * Retrieve HTML importer instance.
	 *
	 * @return Andw_Contents_Generator_HTML_Importer
	 */
	public function html_importer() {
		return $this->html_importer;
	}
}

/**
 * Activate plugin and seed capabilities.
 */
function andw_contents_generator_activate() {
	Andw_Contents_Generator_Permissions::register_caps();
	$logger = new Andw_Contents_Generator_Logger( ANDW_CONTENTS_GENERATOR_PATH . 'logs' );
	$logger->log( 'Plugin activated' );
}
register_activation_hook( __FILE__, 'andw_contents_generator_activate' );

/**
 * Bootstrap plugin.
 */
function andw_contents_generator() {
	return Andw_Contents_Generator::get_instance();
}
andw_contents_generator();
