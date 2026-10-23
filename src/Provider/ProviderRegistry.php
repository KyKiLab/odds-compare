<?php
/**
 * Provider registry.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Provider;

use OddsCompare\Support\Logger;

/**
 * Holds every known provider by key. The plugin registers its own; other plugins add theirs
 * through the `odds_compare_providers` filter, which receives this registry.
 */
final class ProviderRegistry {

	public const FALLBACK = 'fixture';

	/**
	 * Providers by key.
	 *
	 * @var array<string, ProviderInterface>
	 */
	private array $providers = array();

	/**
	 * Builds a registry.
	 *
	 * @param Logger|null $logger Logger for misconfiguration warnings.
	 */
	public function __construct( private readonly ?Logger $logger = null ) {}

	/**
	 * Adds or replaces a provider.
	 *
	 * @param ProviderInterface $provider The provider.
	 */
	public function register( ProviderInterface $provider ): self {
		$this->providers[ $provider->key() ] = $provider;

		return $this;
	}

	/**
	 * Whether a key is registered.
	 *
	 * @param string $key Provider key.
	 */
	public function has( string $key ): bool {
		return isset( $this->providers[ $key ] );
	}

	/**
	 * Provider by key, or null.
	 *
	 * @param string $key Provider key.
	 */
	public function get( string $key ): ?ProviderInterface {
		return $this->providers[ $key ] ?? null;
	}

	/**
	 * Provider by key, falling back to the fixture provider when the configured one is gone
	 * (plugin deactivated, key renamed). Showing sample data beats a fatal error on the front end.
	 *
	 * @param string $key Provider key from settings.
	 *
	 * @throws \LogicException When not even the fallback is registered.
	 */
	public function resolve( string $key ): ProviderInterface {
		$provider = $this->get( $key );
		if ( $provider ) {
			return $provider;
		}

		$this->logger?->warning( 'Configured provider is not registered, using fallback', array( 'provider' => $key ) );

		$fallback = $this->get( self::FALLBACK );
		if ( ! $fallback ) {
			throw new \LogicException( 'No fallback provider registered.' );
		}

		return $fallback;
	}

	/**
	 * Every provider, keyed.
	 *
	 * @return array<string, ProviderInterface>
	 */
	public function all(): array {
		return $this->providers;
	}
}
