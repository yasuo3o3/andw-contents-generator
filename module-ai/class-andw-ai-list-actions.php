<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adds admin list actions for AI draft creation.
 */
class Andw_Contents_Generator_AI_List_Actions {
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

		add_action( 'restrict_manage_posts', array( $this, 'render_quick_form' ), 20, 2 );
		add_action( 'admin_post_andw_ai_generate_draft', array( $this, 'handle_quick_generate' ) );
		add_action( 'load-post.php', array( $this, 'maybe_show_success_notice' ) );
		add_action( 'load-edit.php', array( $this, 'maybe_show_error_notice' ) );
	}

	/**
	 * Display keyword input on posts list.
	 *
	 * @param string $post_type Current post type.
	 * @param string $which     Location.
	 */
	public function render_quick_form( $post_type, $which ) {
		if ( 'post' !== $post_type || 'top' !== $which ) {
			return;
		}

		if ( ! Andw_Contents_Generator_Permissions::can_manage_ai() || ! $this->service->is_ready() ) {
			return;
		}
		?>
		<div class="andw-ai-quick-generate" style="margin-right:12px;">
			<label for="andw-ai-quick-keywords" class="screen-reader-text"><?php esc_html_e( 'AIで下書き', 'andw-contents-generator' ); ?></label>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="andw-ai-quick-form" style="display:flex;gap:6px;align-items:center;">
				<input type="hidden" name="action" value="andw_ai_generate_draft" />
				<?php wp_nonce_field( 'andw_ai_generate_draft', 'andw_ai_nonce' ); ?>
				<input type="text" id="andw-ai-quick-keywords" name="andw_ai_keywords" class="regular-text" placeholder="<?php esc_attr_e( 'AI下書き用キーワード', 'andw-contents-generator' ); ?>" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'AIで下書き作成', 'andw-contents-generator' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle draft creation from list screen.
	 */
	public function handle_quick_generate() {
		if ( ! current_user_can( Andw_Contents_Generator_Permissions::CAP_MANAGE_AI ) ) {
			wp_die( esc_html__( 'AI生成を実行する権限がありません。', 'andw-contents-generator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'andw_ai_generate_draft', 'andw_ai_nonce' );

		$keywords = isset( $_POST['andw_ai_keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['andw_ai_keywords'] ) ) : '';

		if ( empty( $keywords ) ) {
			$redirect_url = add_query_arg( array( 'andw_ai_error' => 'keywords', '_wpnonce' => wp_create_nonce( 'andw_ai_notice' ) ), admin_url( 'edit.php?post_type=post' ) );
		wp_safe_redirect( $redirect_url );
			exit;
		}

		if ( ! $this->service->is_ready() ) {
			$redirect_url = add_query_arg( array( 'andw_ai_error' => 'config', '_wpnonce' => wp_create_nonce( 'andw_ai_notice' ) ), admin_url( 'edit.php?post_type=post' ) );
		wp_safe_redirect( $redirect_url );
			exit;
		}

		$post_id = wp_insert_post(
			array(
				'post_title'  => $keywords,
				'post_status' => 'draft',
				'post_type'   => 'post',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$this->logger->log( 'Failed to create draft before AI generation', array( 'error' => $post_id->get_error_message() ) );
			$redirect_url = add_query_arg( array( 'andw_ai_error' => 'insert', '_wpnonce' => wp_create_nonce( 'andw_ai_notice' ) ), admin_url( 'edit.php?post_type=post' ) );
		wp_safe_redirect( $redirect_url );
			exit;
		}

		$result = $this->service->generate(
			array(
				'keywords' => $keywords,
				'mode'     => 'body',
				'post_id'  => $post_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_delete_post( $post_id, true );
			$this->logger->log( 'AI draft generation failed', array( 'error' => $result->get_error_message(), 'keywords' => $keywords ) );
			$redirect_url = add_query_arg( array( 'andw_ai_error' => 'api', '_wpnonce' => wp_create_nonce( 'andw_ai_notice' ) ), admin_url( 'edit.php?post_type=post' ) );
		wp_safe_redirect( $redirect_url );
			exit;
		}

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_status'  => 'draft',
				'post_title'   => ! empty( $result['title'] ) ? $result['title'] : $keywords,
				'post_content' => $result['blocks'],
				'post_excerpt' => $result['summary'],
			)
		);

		$redirect_url = add_query_arg( array( 'andw_ai_success' => '1', '_wpnonce' => wp_create_nonce( 'andw_ai_notice' ) ), admin_url( sprintf( 'post.php?post=%d&action=edit', $post_id ) ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Maybe render success notice on post edit screen.
	 */
	public function maybe_show_success_notice() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'andw_ai_notice' ) ) {
			return;
		}

		if ( ! isset( $_GET['andw_ai_success'] ) ) {
			return;
		}

		add_action(
			'admin_notices',
			static function () {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html__( 'AIで生成した下書きが読み込まれました。内容を確認して公開してください。', 'andw-contents-generator' )
				);
			}
		);
	}

	/**
	 * Maybe render error notice on list screen.
	 */
	public function maybe_show_error_notice() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'andw_ai_notice' ) ) {
			return;
		}

		if ( ! isset( $_GET['andw_ai_error'] ) ) {
			return;
		}

		$error_code = sanitize_key( wp_unslash( $_GET['andw_ai_error'] ) );
		$message    = __( 'AI下書きの作成に失敗しました。', 'andw-contents-generator' );

		switch ( $error_code ) {
			case 'keywords':
				$message = __( 'キーワードを入力してください。', 'andw-contents-generator' );
				break;
			case 'config':
				$message = __( 'AI設定を完了してください。', 'andw-contents-generator' );
				break;
			case 'api':
				$message = __( 'AIリクエストが失敗しました。ログを確認してください。', 'andw-contents-generator' );
				break;
			case 'insert':
				$message = __( '下書きの作成に失敗しました。', 'andw-contents-generator' );
				break;
		}

		add_action(
			'admin_notices',
			static function () use ( $message ) {
				printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
			}
		);
	}
}
