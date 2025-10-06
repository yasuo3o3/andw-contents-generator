<?php
/**
 * Logger utility.
 */
defined( 'ABSPATH' ) || exit;

/**
 * Lightweight file-based logger.
 */
class Andw_Contents_Generator_Logger {
	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private $log_dir;

	/**
	 * Log file path.
	 *
	 * @var string
	 */
	private $log_file;

	/**
	 * Constructor.
	 *
	 * @param string $log_dir Absolute path to the logs directory.
	 */
	public function __construct( $log_dir ) {
		$this->log_dir  = trailingslashit( $log_dir );
		$this->log_file = $this->log_dir . 'andw-contents-generator.log';

		$this->ensure_directory();
	}

	/**
	 * Write entry to log file.
	 *
	 * @param string $message Human readable message.
	 * @param array  $context Additional context data.
	 * @param string $level   Log level.
	 */
	public function log( $message, $context = array(), $level = 'info' ) {
		if ( empty( $message ) ) {
			return;
		}

		$level   = strtoupper( sanitize_key( $level ) );
		$line    = sanitize_text_field( $message );
		$context = $this->sanitize_context( $context );

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		$entry = sprintf( "[%s] %s: %s\n", gmdate( 'c' ), $level, $line );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		file_put_contents( $this->log_file, $entry, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Ensure logs directory exists.
	 */
	private function ensure_directory() {
		if ( ! wp_mkdir_p( $this->log_dir ) ) {
			return;
		}

		if ( ! file_exists( $this->log_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
			file_put_contents( $this->log_file, '' );
		}
	}

	/**
	 * Sanitize context payloads.
	 *
	 * @param array $context Raw context.
	 *
	 * @return array
	 */
	private function sanitize_context( $context ) {
		$sanitized = array();

		foreach ( (array) $context as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( is_scalar( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
				continue;
			}

			$sanitized[ $key ] = wp_json_encode( $value );
		}

		return $sanitized;
	}
}
