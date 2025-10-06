<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API integration for AI generation.
 */
class Andw_Contents_Generator_AI_Rest {
	/**
	 * Service layer.
	 *
	 * @var Andw_Contents_Generator_AI_Service
	 */
	private $service;

	/**
	 * Logger instance.
	 *
	 * @var Andw_Contents_Generator_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Andw_Contents_Generator_AI_Service $service Service instance.
	 * @param Andw_Contents_Generator_Logger     $logger  Logger instance.
	 */
	public function __construct( Andw_Contents_Generator_AI_Service $service, Andw_Contents_Generator_Logger $logger ) {
		$this->service = $service;
		$this->logger  = $logger;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register API routes.
	 */
	public function register_routes() {
		register_rest_route(
			'andw/v1',
			'/ai/generate',
			array(
				'args' => array(
					'keywords' => array(
						'required' => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'mode'     => array(
						'required' => true,
						'validate_callback' => array( $this, 'validate_mode' ),
					),
					'post_id'  => array(
						'required' => true,
						'validate_callback' => array( $this, 'validate_post_id' ),
					),
				),
				'permission_callback' => array( $this, 'check_permissions' ),
				'callback'            => array( $this, 'handle_generate' ),
				'methods'             => WP_REST_Server::CREATABLE,
			)
		);
	}

	/**
	 * Validate requested mode.
	 *
	 * @param string $value Value to validate.
	 *
	 * @return bool
	 */
	public function validate_mode( $value ) {
		$allowed = array( 'heading', 'body', 'summary' );

		return in_array( sanitize_key( $value ), $allowed, true );
	}

	/**
	 * Validate post id parameter.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return bool
	 */
	public function validate_post_id( $value ) {
		return absint( $value ) > 0;
	}

	/**
	 * Check permission for requests.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permissions( $request ) {
		if ( ! Andw_Contents_Generator_Permissions::can_manage_ai() ) {
			return new WP_Error( 'andw_ai_forbidden', __( 'AI生成を実行する権限がありません。', 'andw-contents-generator' ), array( 'status' => 403 ) );
		}

		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'andw_ai_cannot_edit', __( '対象の投稿を編集できません。', 'andw-contents-generator' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Handle content generation.
	 *
	 * @param WP_REST_Request $request Request payload.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_generate( WP_REST_Request $request ) {
		$params = array(
			'keywords' => $request->get_param( 'keywords' ),
			'mode'     => $request->get_param( 'mode' ),
			'post_id'  => absint( $request->get_param( 'post_id' ) ),
		);

		$result = $this->service->generate( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = $params['post_id'];

		if ( $post_id > 0 ) {
			$update = array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			);

			if ( ! empty( $result['title'] ) ) {
				$update['post_title'] = $result['title'];
			}

			wp_update_post( $update );
		}

		$this->logger->log( 'AI generation completed', array( 'post_id' => $post_id, 'mode' => $params['mode'] ) );

		$response = array(
			'blocks'  => $result['blocks'],
			'title'   => $result['title'],
			'summary' => $result['summary'],
			'locked'  => true,
		);

		return rest_ensure_response( $response );
	}
}
