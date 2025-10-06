<?php
defined( 'ABSPATH' ) || exit;

/**
 * Block editor integration for HTML importer.
 */
class Andw_Contents_Generator_HTML_Sidebar {
	/**
	 * Settings manager.
	 *
	 * @var Andw_Contents_Generator_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Andw_Contents_Generator_Settings $settings Settings manager.
	 */
	public function __construct( Andw_Contents_Generator_Settings $settings ) {
		$this->settings = $settings;

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue editor assets for importer.
	 */
	public function enqueue_assets() {
		if ( ! Andw_Contents_Generator_Permissions::can_manage_html() ) {
			return;
		}

		$handle   = 'andw-html-importer';
		$asset_js = ANDW_CONTENTS_GENERATOR_PATH . 'assets/js/html-importer.js';
		$version  = file_exists( $asset_js ) ? filemtime( $asset_js ) : ANDW_CONTENTS_GENERATOR_VERSION;

		wp_register_script(
			$handle,
			ANDW_CONTENTS_GENERATOR_URL . 'assets/js/html-importer.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-notices', 'wp-blocks', 'wp-block-editor' ),
			$version,
			true
		);

		$html_settings = $this->settings->get_html_settings();

		wp_localize_script(
			$handle,
			'andwHtmlImporter',
			array(
				'restUrl'         => esc_url_raw( rest_url( 'andw/v1/html/convert' ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'defaultColumns'  => (bool) $html_settings['column_detection'],
				'defaultStrip'    => (bool) $html_settings['strip_attributes'],
				'defaultThreshold'=> (float) $html_settings['score_threshold'],
				'strings'         => array(
					'panelTitle'   => __( 'HTMLインポート', 'andw-contents-generator' ),
					'pasteLabel'   => __( '静的HTMLを貼り付け', 'andw-contents-generator' ),
					'preview'      => __( 'プレビュー', 'andw-contents-generator' ),
					'insertDraft'  => __( '下書きとして挿入', 'andw-contents-generator' ),
					'append'       => __( '現在の記事に追記', 'andw-contents-generator' ),
					'columnsToggle'=> __( '列化を自動検出する', 'andw-contents-generator' ),
					'loading'      => __( 'ブロックに変換しています…', 'andw-contents-generator' ),
					'successInsert'=> __( '変換結果を下書きに反映しました。', 'andw-contents-generator' ),
					'successAppend'=> __( '変換結果を追記しました。', 'andw-contents-generator' ),
					'failure'      => __( 'HTMLの変換に失敗しました。', 'andw-contents-generator' ),
				),
			)
		);

		wp_enqueue_script( $handle );
	}
}

