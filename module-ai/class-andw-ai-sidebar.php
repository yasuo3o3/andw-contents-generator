<?php
defined( 'ABSPATH' ) || exit;

/**
 * Block editor sidebar integration.
 */
class Andw_Contents_Generator_AI_Sidebar {
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
	 * Enqueue sidebar script.
	 */
	public function enqueue_assets() {
		if ( ! Andw_Contents_Generator_Permissions::can_manage_ai() ) {
			return;
		}

		$handle   = 'andw-ai-sidebar';
		$asset_js = ANDW_CONTENTS_GENERATOR_PATH . 'assets/js/ai-sidebar.js';
		$version  = file_exists( $asset_js ) ? filemtime( $asset_js ) : ANDW_CONTENTS_GENERATOR_VERSION;

		wp_register_script(
			$handle,
			ANDW_CONTENTS_GENERATOR_URL . 'assets/js/ai-sidebar.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-notices', 'wp-blocks' ),
			$version,
			true
		);

		$ai_settings = $this->settings->get_ai_settings();

		wp_localize_script(
			$handle,
			'andwAiSidebar',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'andw/v1/ai/generate' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'isReady'   => $this->settings->is_ai_configured(),
				'strings'   => array(
					'panelTitle'      => __( 'AI生成', 'andw-contents-generator' ),
					'keywordsLabel'   => __( 'キーワード', 'andw-contents-generator' ),
					'generateHeading' => __( '見出し生成', 'andw-contents-generator' ),
					'generateBody'    => __( '本文生成', 'andw-contents-generator' ),
					'generateSummary' => __( '要約生成', 'andw-contents-generator' ),
					'placeholder'     => __( '例: テレワーク 時短制度', 'andw-contents-generator' ),
					'loading'         => __( 'AIが下書きを作成中です…', 'andw-contents-generator' ),
					'needConfig'      => __( 'AI設定が未完了のため利用できません。', 'andw-contents-generator' ),
					'success'         => __( 'AI生成結果を下書きとして反映しました。', 'andw-contents-generator' ),
					'failure'         => __( 'AI生成に失敗しました。', 'andw-contents-generator' ),
					'statusDraft'     => __( '投稿ステータスを下書きに変更しました。', 'andw-contents-generator' ),
				),
				'defaultPrompt' => (string) $ai_settings['prompt'],
			)
		);

		wp_enqueue_script( $handle );
	}
}
