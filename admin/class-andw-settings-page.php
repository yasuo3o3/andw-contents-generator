<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings page renderer.
 */
class Andw_Contents_Generator_Settings_Page {
	/**
	 * Settings manager.
	 *
	 * @var Andw_Contents_Generator_Settings
	 */
	private $settings;

	/**
	 * Logger instance.
	 *
	 * @var Andw_Contents_Generator_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Andw_Contents_Generator_Settings $settings Settings manager.
	 * @param Andw_Contents_Generator_Logger   $logger   Logger instance.
	 */
	public function __construct( Andw_Contents_Generator_Settings $settings, Andw_Contents_Generator_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Register options page.
	 */
	public function register() {
		add_options_page(
			esc_html__( 'andW Contents Generator', 'andw-contents-generator' ),
			esc_html__( 'andW生成', 'andw-contents-generator' ),
			'manage_options',
			'andw-contents-generator',
			array( $this, 'render' )
		);
	}

	/**
	 * Convert README.md content to HTML.
	 *
	 * @return string
	 */
	private function convert_readme_to_html() {
		$readme_path = ANDW_CONTENTS_GENERATOR_PATH . 'README.md';

		if ( ! file_exists( $readme_path ) || ! is_readable( $readme_path ) ) {
			return '<p>' . esc_html__( 'README.mdファイルが見つかりません。', 'andw-contents-generator' ) . '</p>';
		}

		$content = file_get_contents( $readme_path );

		if ( false === $content ) {
			return '<p>' . esc_html__( 'README.mdファイルを読み込めませんでした。', 'andw-contents-generator' ) . '</p>';
		}

		// Simple Markdown to HTML conversion
		$html = esc_html( $content );

		// Headers
		$html = preg_replace( '/^### (.+)$/m', '<h3>$1</h3>', $html );
		$html = preg_replace( '/^## (.+)$/m', '<h2>$1</h2>', $html );
		$html = preg_replace( '/^# (.+)$/m', '<h1>$1</h1>', $html );

		// Code blocks
		$html = preg_replace( '/```([^`]*)```/s', '<pre><code>$1</code></pre>', $html );
		$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );

		// Bold and italic
		$html = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html );
		$html = preg_replace( '/\*([^*]+)\*/', '<em>$1</em>', $html );

		// Lists
		$html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
		$html = preg_replace( '/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html );

		// Paragraphs
		$html = preg_replace( '/\n\n/', '</p><p>', $html );
		$html = '<p>' . $html . '</p>';

		// Clean up
		$html = str_replace( '<p></p>', '', $html );
		$html = str_replace( '<p><h', '<h', $html );
		$html = str_replace( '</h1></p>', '</h1>', $html );
		$html = str_replace( '</h2></p>', '</h2>', $html );
		$html = str_replace( '</h3></p>', '</h3>', $html );
		$html = str_replace( '<p><ul>', '<ul>', $html );
		$html = str_replace( '</ul></p>', '</ul>', $html );
		$html = str_replace( '<p><pre>', '<pre>', $html );
		$html = str_replace( '</pre></p>', '</pre>', $html );

		// Convert newlines to <br>
		$html = nl2br( $html );

		return $html;
	}

	/**
	 * Render settings page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'このページにアクセスする権限がありません。', 'andw-contents-generator' ) );
		}

		$tab = 'ai';
		if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'andw_settings_tab' ) && isset( $_GET['tab'] ) ) {
			$requested_tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
			$tab = in_array( $requested_tab, array( 'ai', 'html', 'docs' ), true ) ? $requested_tab : 'ai';
		}
		$ai_settings   = $this->settings->get_ai_settings();
		$html_settings = $this->settings->get_html_settings();
		$domain_text   = implode( "\n", $html_settings['allowlist_domains'] );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'andW Contents Generator 設定', 'andw-contents-generator' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'tab', 'ai' ), 'andw_settings_tab' ) ); ?>" class="nav-tab <?php echo 'ai' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'AI生成', 'andw-contents-generator' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'tab', 'html' ), 'andw_settings_tab' ) ); ?>" class="nav-tab <?php echo 'html' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'HTMLインポート', 'andw-contents-generator' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'tab', 'docs' ), 'andw_settings_tab' ) ); ?>" class="nav-tab <?php echo 'docs' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'ドキュメント', 'andw-contents-generator' ); ?>
				</a>
			</h2>

			<?php if ( 'ai' === $tab ) : ?>
				<?php if ( ! Andw_Contents_Generator_Permissions::can_manage_ai() ) : ?>
					<p><?php esc_html_e( 'AI生成設定を変更する権限がありません。', 'andw-contents-generator' ); ?></p>
				<?php else : ?>
					<form method="post" action="options.php">
						<?php settings_fields( 'andw_contents_generator_ai' ); ?>
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row"><label for="andw-ai-api-key"><?php esc_html_e( 'OpenAI APIキー', 'andw-contents-generator' ); ?></label></th>
									<td>
										<input name="<?php echo esc_attr( Andw_Contents_Generator_Settings::OPTION_AI ); ?>[api_key]" id="andw-ai-api-key" type="password" class="regular-text" value="<?php echo esc_attr( $ai_settings['api_key'] ); ?>" autocomplete="off" />
										<p class="description"><?php esc_html_e( 'APIキーは安全に保管され、画面には伏せられます。', 'andw-contents-generator' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="andw-ai-model"><?php esc_html_e( 'モデル名', 'andw-contents-generator' ); ?></label></th>
									<td>
										<input name="<?php echo esc_attr( Andw_Contents_Generator_Settings::OPTION_AI ); ?>[model]" id="andw-ai-model" type="text" class="regular-text" value="<?php echo esc_attr( $ai_settings['model'] ); ?>" />
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="andw-ai-max-tokens"><?php esc_html_e( '最大トークン数', 'andw-contents-generator' ); ?></label></th>
									<td>
										<input name="<?php echo esc_attr( Andw_Contents_Generator_Settings::OPTION_AI ); ?>[max_tokens]" id="andw-ai-max-tokens" type="number" class="small-text" value="<?php echo esc_attr( $ai_settings['max_tokens'] ); ?>" min="1" max="4096" />
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="andw-ai-prompt"><?php esc_html_e( '既定プロンプト', 'andw-contents-generator' ); ?></label></th>
									<td>
										<textarea name="<?php echo esc_attr( Andw_Contents_Generator_Settings::OPTION_AI ); ?>[prompt]" id="andw-ai-prompt" class="large-text code" rows="5"><?php echo esc_textarea( $ai_settings['prompt'] ); ?></textarea>
										<p class="description"><?php esc_html_e( 'キーワードに差し替えられる {{keyword}} プレースホルダーが利用できます。', 'andw-contents-generator' ); ?></p>
									</td>
								</tr>
							</tbody>
						</table>
						<?php submit_button(); ?>
					</form>
				<?php endif; ?>
			<?php elseif ( 'html' === $tab ) : ?>
				<?php if ( ! Andw_Contents_Generator_Permissions::can_manage_html() ) : ?>
					<p><?php esc_html_e( 'HTMLインポート設定を変更する権限がありません。', 'andw-contents-generator' ); ?></p>
				<?php else : ?>
					<form method="post" action="options.php">
						<?php settings_fields( 'andw_contents_generator_html' ); ?>
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row"><?php esc_html_e( '列化の自動判定', 'andw-contents-generator' ); ?></th>
									<td>
										<label for="andw-html-column-detection">
											<input type="checkbox" name="<?php echo esc_attr( Andw_Contents_Generator_Settings::OPTION_HTML ); ?>[column_detection]" id="andw-html-column-detection" value="1" <?php checked( $html_settings['column_detection'] ); ?> />
											<?php esc_html_e( '類似コンテンツを自動的に列化する', 'andw-contents-generator' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="andw-html-score-threshold"><?php esc_html_e( '列化閾値', 'andw-contents-generator' ); ?></label></th>
									<td>
										<input type="number" step="0.1" min="0" max="1" class="small-text" name="<?php echo esc_attr( Andw_Contents_Generator_Settings::OPTION_HTML ); ?>[score_threshold]" id="andw-html-score-threshold" value="<?php echo esc_attr( $html_settings['score_threshold'] ); ?>" />
										<p class="description"><?php esc_html_e( '列化判定のスコア。高いほど厳密になります。', 'andw-contents-generator' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="andw-html-strip-attributes"><?php esc_html_e( '不要属性の除去', 'andw-contents-generator' ); ?></label></th>
									<td>
										<label for="andw-html-strip-attributes">
											<input type="checkbox" name="<?php echo esc_attr( Andw_Contents_Generator_Settings::OPTION_HTML ); ?>[strip_attributes]" id="andw-html-strip-attributes" value="1" <?php checked( $html_settings['strip_attributes'] ); ?> />
											<?php esc_html_e( 'style/class/on* 属性などを除去する', 'andw-contents-generator' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="andw-html-allowlist"><?php esc_html_e( 'iframe許可ドメイン', 'andw-contents-generator' ); ?></label></th>
									<td>
										<textarea name="<?php echo esc_attr( Andw_Contents_Generator_Settings::OPTION_HTML ); ?>[allowlist_domains]" id="andw-html-allowlist" class="large-text code" rows="4"><?php echo esc_textarea( $domain_text ); ?></textarea>
										<p class="description"><?php esc_html_e( '1行に1ドメインを入力。許可されていないiframeは除去されます。', 'andw-contents-generator' ); ?></p>
									</td>
								</tr>
							</tbody>
						</table>
						<?php submit_button(); ?>
					</form>
				<?php endif; ?>
			<?php elseif ( 'docs' === $tab ) : ?>
				<div class="andw-docs-content" style="max-width: 800px;">
					<?php echo wp_kses_post( $this->convert_readme_to_html() ); ?>
				</div>
				<style>
					.andw-docs-content {
						background: #fff;
						padding: 20px;
						border: 1px solid #ccd0d4;
						border-radius: 4px;
						margin-top: 20px;
					}
					.andw-docs-content h1 {
						color: #1d2327;
						border-bottom: 1px solid #ddd;
						padding-bottom: 10px;
						margin-bottom: 20px;
					}
					.andw-docs-content h2 {
						color: #1d2327;
						margin-top: 30px;
						margin-bottom: 15px;
					}
					.andw-docs-content h3 {
						color: #1d2327;
						margin-top: 25px;
						margin-bottom: 10px;
					}
					.andw-docs-content code {
						background: #f1f1f1;
						padding: 2px 4px;
						border-radius: 3px;
						font-family: Monaco, Consolas, "Lucida Console", monospace;
					}
					.andw-docs-content pre {
						background: #f6f7f7;
						border: 1px solid #e1e1e1;
						padding: 15px;
						border-radius: 4px;
						overflow-x: auto;
						margin: 15px 0;
					}
					.andw-docs-content pre code {
						background: none;
						padding: 0;
						border-radius: 0;
					}
					.andw-docs-content ul {
						margin: 15px 0;
						padding-left: 30px;
					}
					.andw-docs-content li {
						margin-bottom: 5px;
					}
				</style>
			<?php endif; ?>
		</div>
		<?php
	}
}
