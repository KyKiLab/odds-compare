<?php
/**
 * Provider health bookkeeping.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Cache;

/**
 * Consecutive failure count, circuit breaker and last error/success per provider, in one
 * option. After FAILURE_THRESHOLD failures in a row the breaker opens for OPEN_SECONDS and
 * nobody calls the provider; the admin screen shows why.
 */
final class ProviderState {

	public const OPTION            = 'odds_compare_provider_state';
	public const FAILURE_THRESHOLD = 3;
	public const OPEN_SECONDS      = 300;

	/**
	 * Whether the breaker is open for a provider.
	 *
	 * @param string   $provider_key Provider key.
	 * @param int|null $now          Reference time.
	 */
	public function is_open( string $provider_key, ?int $now = null ): bool {
		$state = $this->get( $provider_key );

		return ! empty( $state['opened_at'] ) && ( $now ?? time() ) < $state['opened_at'] + self::OPEN_SECONDS;
	}

	/**
	 * Records a failure; opens the breaker on the threshold.
	 *
	 * @param string $provider_key Provider key.
	 * @param string $message      Error message, shown to administrators.
	 */
	public function record_failure( string $provider_key, string $message ): void {
		$state                  = $this->get( $provider_key );
		$state['failures']      = (int) ( $state['failures'] ?? 0 ) + 1;
		$state['last_error']    = $message;
		$state['last_error_at'] = time();

		if ( $state['failures'] >= self::FAILURE_THRESHOLD && empty( $state['opened_at'] ) ) {
			$state['opened_at'] = time();
		}

		$this->put( $provider_key, $state );
	}

	/**
	 * Records a success and closes the breaker.
	 *
	 * @param string $provider_key Provider key.
	 */
	public function record_success( string $provider_key ): void {
		$state                    = $this->get( $provider_key );
		$state['failures']        = 0;
		$state['opened_at']       = null;
		$state['last_success_at'] = time();

		$this->put( $provider_key, $state );
	}

	/**
	 * Clears everything, e.g. when the admin resets the provider.
	 */
	public function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Stored state for a provider.
	 *
	 * @param string $provider_key Provider key.
	 *
	 * @return array<string, mixed>
	 */
	public function get( string $provider_key ): array {
		$all = get_option( self::OPTION, array() );

		return is_array( $all ) && isset( $all[ $provider_key ] ) && is_array( $all[ $provider_key ] ) ? $all[ $provider_key ] : array();
	}

	/**
	 * Writes the state for a provider.
	 *
	 * @param string               $provider_key Provider key.
	 * @param array<string, mixed> $state        New state.
	 */
	private function put( string $provider_key, array $state ): void {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ $provider_key ] = $state;
		update_option( self::OPTION, $all, false );
	}
}
