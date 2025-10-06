<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles communication with OpenAI API and block generation.
 */
class Andw_Contents_Generator_AI_Service {
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
	 * Determine readiness.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return $this->settings->is_ai_configured();
	}

	/**
	 * Generate Gutenberg block markup from AI.
	 *
	 * @param array $args Generation parameters.
	 *
	 * @return array|WP_Error Associative array containing blocks/title/summary or WP_Error on failure.
	 */
	public function generate( $args ) {
		if ( ! $this->is_ready() ) {
			return new WP_Error( 'andw_ai_not_configured', __( 'AI設定が完了していません。', 'andw-contents-generator' ) );
		}

		$defaults = array(
			'keywords' => '',
			'mode'     => 'body',
			'post_id'  => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		if ( empty( $args['keywords'] ) ) {
			return new WP_Error( 'andw_ai_missing_keywords', __( 'キーワードを入力してください。', 'andw-contents-generator' ) );
		}

		$payload = $this->build_payload( $args );
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => $this->build_headers(),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->log( 'AI request failed', array( 'error' => $response->get_error_message() ) );
			return new WP_Error( 'andw_ai_http_error', __( 'AIリクエストに失敗しました。', 'andw-contents-generator' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			$this->logger->log( 'AI response non-200', array( 'code' => $code, 'body' => $body ) );
			return new WP_Error( 'andw_ai_bad_response', __( 'AIから有効な応答が得られませんでした。', 'andw-contents-generator' ) );
		}

		$data = json_decode( $body, true );

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			$this->logger->log( 'AI response missing content', array( 'body' => $body ) );
			return new WP_Error( 'andw_ai_empty', __( 'AI応答を解析できませんでした。', 'andw-contents-generator' ) );
		}

		$content_raw = trim( $data['choices'][0]['message']['content'] );
		$decoded     = json_decode( $content_raw, true );

		if ( null === $decoded || ! isset( $decoded['blocks'] ) ) {
			// Attempt to log truncated content for debugging.
			$this->logger->log( 'AI response not JSON', array( 'snippet' => mb_substr( $content_raw, 0, 200 ) ) );
			return new WP_Error( 'andw_ai_invalid_format', __( 'AI出力が想定形式ではありません。', 'andw-contents-generator' ) );
		}

		$post_id     = absint( $args['post_id'] );
		$blocks_html = $this->convert_blocks_to_markup( $decoded['blocks'], $post_id );

		if ( is_wp_error( $blocks_html ) ) {
			return $blocks_html;
		}

		$result = array(
			'blocks'  => $blocks_html,
			'title'   => isset( $decoded['title'] ) ? sanitize_text_field( $decoded['title'] ) : '',
			'summary' => isset( $decoded['summary'] ) ? sanitize_text_field( $decoded['summary'] ) : '',
		);

		return $result;
	}

	/**
	 * Build API request payload.
	 *
	 * @param array $args Generation arguments.
	 *
	 * @return array
	 */
	private function build_payload( $args ) {
		$ai_settings = $this->settings->get_ai_settings();
		$mode        = sanitize_key( $args['mode'] );
		$keywords    = sanitize_text_field( $args['keywords'] );
		$prompt_base = $ai_settings['prompt'];
		$prompt      = str_replace( '{{keyword}}', $keywords, $prompt_base );

		$system_message = __( 'あなたはWordPress Gutenberg向けの編集アシスタントです。出力は必ず有効なJSON文字列とし、UTF-8で次の形式に従います: {"title":"...","summary":"...","blocks":[{"type":"heading","level":2,"content":"..."},{"type":"paragraph","content":"..."},{"type":"image","url":"https://","alt":"..."}]}。typeはheading/pargraph/imageのいずれかで、見出しレベルは2-4に限定します。', 'andw-contents-generator' );

		if ( 'heading' === $mode ) {
			$mode_instruction = __( '見出し候補を3件生成し、paragraphは含めないでください。', 'andw-contents-generator' );
		} elseif ( 'summary' === $mode ) {
			$mode_instruction = __( '本文の要約 paragraph を1件のみ blocks に含め、titleとheadingは省略して構いません。', 'andw-contents-generator' );
		} else {
			$mode_instruction = __( 'タイトル、見出し、本文段落をバランスよく生成してください。', 'andw-contents-generator' );
		}

		$user_message = sprintf(
			"%s\n\nキーワード: %s\nモード: %s",
			$prompt,
			$keywords,
			$mode
		);

		return array(
			'model'       => $ai_settings['model'],
			'max_tokens'  => (int) $ai_settings['max_tokens'],
			'temperature' => 0.7,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $system_message . ' ' . $mode_instruction,
				),
				array(
					'role'    => 'user',
					'content' => $user_message,
				),
			),
		);
	}

	/**
	 * Prepare request headers.
	 *
	 * @return array
	 */
	private function build_headers() {
		$ai_settings = $this->settings->get_ai_settings();

		return array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $ai_settings['api_key'],
		);
	}

	/**
	 * Convert block definition array to serialized markup.
	 *
	 * @param array $blocks Raw block definitions.
	 * @param int   $post_id Target post ID for attachments.
	 *
	 * @return string|WP_Error
	 */
	private function convert_blocks_to_markup( $blocks, $post_id ) {
		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return new WP_Error( 'andw_ai_no_blocks', __( '生成されたブロックがありません。', 'andw-contents-generator' ) );
		}

		$markup = array();

		foreach ( $blocks as $block ) {
			$type = isset( $block['type'] ) ? sanitize_key( $block['type'] ) : 'paragraph';

			switch ( $type ) {
				case 'heading':
					$level   = isset( $block['level'] ) ? absint( $block['level'] ) : 2;
					$level   = $level < 2 || $level > 4 ? 2 : $level;
					$content = isset( $block['content'] ) ? wp_kses_post( $block['content'] ) : '';

					if ( empty( $content ) ) {
						continue 2;
					}

					$markup[] = sprintf(
						"<!-- wp:heading {\"level\":%1\$d} -->\n<h%1\$d>%2\$s</h%1\$d>\n<!-- /wp:heading -->",
						$level,
						esc_html( wp_strip_all_tags( $content ) )
					);
					break;

				case 'image':
					if ( empty( $block['url'] ) ) {
						continue 2;
					}

					$alt   = isset( $block['alt'] ) ? sanitize_text_field( $block['alt'] ) : '';
					$block_markup = $this->prepare_image_block( esc_url_raw( $block['url'] ), $alt, $post_id );

					if ( is_wp_error( $block_markup ) ) {
						$this->logger->log( 'Image block failed', array( 'error' => $block_markup->get_error_message() ) );
						continue 2;
					}

					$markup[] = $block_markup;
					break;

				default:
					$content = isset( $block['content'] ) ? wp_kses_post( $block['content'] ) : '';

					if ( empty( $content ) ) {
						continue 2;
					}

					$markup[] = sprintf(
						"<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
						esc_html( wp_strip_all_tags( $content ) )
					);
					break;
			}
		}

		return implode( "\n\n", $markup );
	}

	/**
	 * Prepare image block markup via sideloading.
	 *
	 * @param string $url     Image URL.
	 * @param string $alt     Alt text.
	 * @param int    $post_id Post ID for attachment association.
	 *
	 * @return string|WP_Error
	 */
	private function prepare_image_block( $url, $alt, $post_id ) {
		if ( empty( $url ) ) {
			return new WP_Error( 'andw_ai_image_missing_url', __( '画像URLが無効です。', 'andw-contents-generator' ) );
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $url, $post_id, $alt, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( ! empty( $alt ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		$image_html = wp_get_attachment_image( $attachment_id, 'large' );

		if ( ! $image_html ) {
			return new WP_Error( 'andw_ai_image_html', __( '画像HTMLを生成できませんでした。', 'andw-contents-generator' ) );
		}

		return sprintf(
			"<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n%s\n<!-- /wp:image -->",
			$attachment_id,
			$image_html
		);
	}
}
