<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings manager.
 */
class Andw_Contents_Generator_Settings {
	const OPTION_AI   = 'andw_contents_generator_ai';
	const OPTION_HTML = 'andw_contents_generator_html';

	/**
	 * Logger instance.
	 *
	 * @var Andw_Contents_Generator_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Andw_Contents_Generator_Logger $logger Logger instance.
	 */
	public function __construct( Andw_Contents_Generator_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Register settings with WordPress.
	 */
	public function register() {
		register_setting(
			'andw_contents_generator_ai',
			self::OPTION_AI,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_ai_settings' ),
				'default'           => $this->get_default_ai_settings(),
			)
		);

		register_setting(
			'andw_contents_generator_html',
			self::OPTION_HTML,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_html_settings' ),
				'default'           => $this->get_default_html_settings(),
			)
		);
	}

	/**
	 * Fetch AI settings.
	 *
	 * @return array
	 */
	public function get_ai_settings() {
		return wp_parse_args( get_option( self::OPTION_AI, array() ), $this->get_default_ai_settings() );
	}

	/**
	 * Fetch HTML settings.
	 *
	 * @return array
	 */
	public function get_html_settings() {
		return wp_parse_args( get_option( self::OPTION_HTML, array() ), $this->get_default_html_settings() );
	}

	/**
	 * Determine if AI is configured.
	 *
	 * @return bool
	 */
	public function is_ai_configured() {
		$settings = $this->get_ai_settings();

		return ! empty( $settings['api_key'] );
	}

	/**
	 * Sanitize AI settings.
	 *
	 * @param array $input Raw settings.
	 *
	 * @return array
	 */
	public function sanitize_ai_settings( $input ) {
		$input = (array) $input;

		$settings = array(
			'api_key'   => isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '',
			'model'     => isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : 'gpt-4.1-mini',
			'max_tokens'=> isset( $input['max_tokens'] ) ? absint( $input['max_tokens'] ) : 1024,
			'prompt'    => isset( $input['prompt'] ) ? wp_kses_post( $input['prompt'] ) : '',
		);

		if ( $settings['max_tokens'] < 1 ) {
			$settings['max_tokens'] = 256;
		}

		return $settings;
	}

	/**
	 * Sanitize HTML settings.
	 *
	 * @param array $input Raw settings.
	 *
	 * @return array
	 */
	public function sanitize_html_settings( $input ) {
		$input = (array) $input;

		$settings = array(
			'column_detection' => isset( $input['column_detection'] ) ? (bool) $input['column_detection'] : true,
			'score_threshold'  => isset( $input['score_threshold'] ) ? floatval( $input['score_threshold'] ) : 0.7,
			'strip_attributes' => isset( $input['strip_attributes'] ) ? (bool) $input['strip_attributes'] : true,
			'allowlist_domains'=> isset( $input['allowlist_domains'] ) ? $this->sanitize_domains( $input['allowlist_domains'] ) : array(),
		);

		if ( $settings['score_threshold'] < 0 ) {
			$settings['score_threshold'] = 0.0;
		}

		if ( $settings['score_threshold'] > 1 ) {
			$settings['score_threshold'] = 1.0;
		}

		return $settings;
	}

	/**
	 * Default AI settings.
	 *
	 * @return array
	 */
	private function get_default_ai_settings() {
		return array(
			'api_key'    => '',
			'model'      => 'gpt-4.1-mini',
			'max_tokens' => 1024,
			'prompt'     => __( '以下のキーワードから、日本語のタイトルと見出し、本文を提案してください。', 'andw-contents-generator' ),
		);
	}

	/**
	 * Default HTML settings.
	 *
	 * @return array
	 */
	private function get_default_html_settings() {
		return array(
			'column_detection' => true,
			'score_threshold'  => 0.7,
			'strip_attributes' => true,
			'allowlist_domains'=> array(),
		);
	}

	/**
	 * Sanitize newline separated domains.
	 *
	 * @param mixed $domains Raw value.
	 *
	 * @return array
	 */
	private function sanitize_domains( $domains ) {
		$domains = is_array( $domains ) ? $domains : explode( "\n", (string) $domains );
		$allowed = array();

		foreach ( $domains as $domain ) {
			$domain = trim( sanitize_text_field( $domain ) );

			if ( empty( $domain ) ) {
				continue;
			}

			$allowed[] = strtolower( $domain );
		}

		return $allowed;
	}
}
