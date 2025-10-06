<?php
defined( 'ABSPATH' ) || exit;

/**
 * Capability management.
 */
class Andw_Contents_Generator_Permissions {
	const CAP_MANAGE_AI   = 'manage_ai_generate';
	const CAP_MANAGE_HTML = 'manage_html_import';

	/**
	 * Register custom capabilities for administrator role.
	 */
	public static function register_caps() {
		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		$role->add_cap( self::CAP_MANAGE_AI );
		$role->add_cap( self::CAP_MANAGE_HTML );
	}

	/**
	 * Ensure capabilities exist for administrator.
	 */
	public static function ensure_caps() {
		self::register_caps();
	}

	/**
	 * Check AI capability.
	 *
	 * @return bool
	 */
	public static function can_manage_ai() {
		return current_user_can( self::CAP_MANAGE_AI );
	}

	/**
	 * Check HTML capability.
	 *
	 * @return bool
	 */
	public static function can_manage_html() {
		return current_user_can( self::CAP_MANAGE_HTML );
	}
}
