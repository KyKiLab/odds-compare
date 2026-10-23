<?php
/**
 * Plugin logger.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Support;

/**
 * Writes to the PHP error log under WP_DEBUG and always fires an action, so a site can
 * forward entries to its own logging stack without the plugin knowing about it.
 */
final class Logger {

	/**
	 * Builds a logger.
	 *
	 * @param string $channel Prefix for log lines.
	 */
	public function __construct( private readonly string $channel = 'odds-compare' ) {}

	/**
	 * Something failed and needs attention.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data, JSON-encoded in the log line.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Degraded but handled, e.g. a retry or a stale cache hit.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Routine events, only useful while debugging.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Dispatches one entry.
	 *
	 * @param string               $level   error|warning|info.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data.
	 */
	private function log( string $level, string $message, array $context ): void {
		/**
		 * Fires for every log entry the plugin produces.
		 *
		 * @param string $level   error, warning or info.
		 * @param string $message Message.
		 * @param array  $context Extra data.
		 * @param string $channel Logger channel.
		 */
		do_action( 'odds_compare_log', $level, $message, $context, $this->channel );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$line = sprintf( '[%s] %s: %s', $this->channel, $level, $message );
			if ( $context ) {
				$line .= ' ' . wp_json_encode( $context );
			}
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only under WP_DEBUG, which is what debug.log is for.
		}
	}
}
