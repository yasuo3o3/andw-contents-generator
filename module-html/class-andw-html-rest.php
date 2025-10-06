<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST controller for HTML import conversions.
 */
class Andw_Contents_Generator_HTML_Rest {
	/**
	 * Importer service.
	 *
	 * @var Andw_Contents_Generator_HTML_Importer
	 */
	private $importer;

	/**
	 * Logger instance.
	 *
	 * @var Andw_Contents_Generator_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Andw_Contents_Generator_HTML_Importer $importer Importer service.
	 * @param Andw_Contents_Generator_Logger        $logger   Logger instance.
	 */
	public function __construct( Andw_Contents_Generator_HTML_Importer $importer, Andw_Contents_Generator_Logger $logger ) {
		$this->importer = $importer;
		$this->logger   = $logger;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			'andw/v1',
			'/html/convert',
			array(
				'args' => array(
					'html'             => array(
						'required' => true,
						'sanitize_callback' => array( $this, 'sanitize_html_input' ),
					),
					'post_id'          => array(
						'required' => true,
						'validate_callback' => array( $this, 'validate_post_id' ),
					),
					'column_detection' => array(
						'required' => false,
						'sanitize_callback' => array( $this, 'sanitize_bool' ),
					),
					'persist_media'    => array(
						'required' => false,
						'sanitize_callback' => array( $this, 'sanitize_bool' ),
					),
					'score_threshold'  => array(
						'required' => false,
						'sanitize_callback' => 'floatval',
					),
				),
				'permission_callback' => array( $this, 'check_permissions' ),
				'callback'            => array( $this, 'handle_convert' ),
				'methods'             => WP_REST_Server::CREATABLE,
			)
		);
	}

	/**
	 * Sanitize HTML input for transport.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	public function sanitize_html_input( $value ) {
		return (string) wp_unslash( $value );
	}

	/**
	 * Validate post id.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return bool
	 */
	public function validate_post_id( $value ) {
		return absint( $value ) >= 0;
	}

	/**
	 * Sanitize boolean-ish values.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return bool
	 */
	public function sanitize_bool( $value ) {
		return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Check user capability.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permissions( $request ) {
		if ( ! Andw_Contents_Generator_Permissions::can_manage_html() ) {
			return new WP_Error( 'andw_html_forbidden', __( 'HTMLインポートを実行する権限がありません。', 'andw-contents-generator' ), array( 'status' => 403 ) );
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		$persist = $this->sanitize_bool( $request->get_param( 'persist_media' ) );

		if ( $persist && $post_id <= 0 ) {
			return new WP_Error( 'andw_html_post_required', __( 'この操作には投稿IDが必要です。', 'andw-contents-generator' ), array( 'status' => 400 ) );
		}

		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'andw_html_cannot_edit', __( '対象の投稿を編集できません。', 'andw-contents-generator' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Handle conversion.
	 *
	 * @param WP_REST_Request $request Request payload.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_convert( WP_REST_Request $request ) {
		$params = array(
			'html'             => $request->get_param( 'html' ),
			'post_id'          => absint( $request->get_param( 'post_id' ) ),
			'column_detection' => $this->sanitize_bool( $request->get_param( 'column_detection' ) ),
			'persist_media'    => $this->sanitize_bool( $request->get_param( 'persist_media' ) ),
			'score_threshold'  => null !== $request->get_param( 'score_threshold' ) ? floatval( $request->get_param( 'score_threshold' ) ) : null,
		);

		$options = array(
			'post_id'          => $params['post_id'],
			'column_detection' => $params['column_detection'],
			'persist_media'    => $params['persist_media'],
		);

		if ( null !== $params['score_threshold'] ) {
			$options['score_threshold'] = $params['score_threshold'];
		}

		$result = $this->importer->convert( $params['html'], $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $params['persist_media'] && $params['post_id'] > 0 ) {
			wp_update_post(
				array(
					'ID'          => $params['post_id'],
					'post_status' => 'draft',
				)
			);
		}

		$this->logger->log(
			'HTML converted',
			array(
				'post_id' => $params['post_id'],
				'persist' => $params['persist_media'],
			)
		);

		return rest_ensure_response( $result );
	}
}

