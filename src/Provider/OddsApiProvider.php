<?php
/**
 * The Odds API provider.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Provider;

use JsonException;
use OddsCompare\Domain\Event;
use OddsCompare\Domain\Sport;
use OddsCompare\Http\Client;
use OddsCompare\Http\HttpException;
use OddsCompare\Support\Logger;

/**
 * Live odds from the-odds-api.com. The free plan gives 500 credits a month and one
 * request costs (regions x markets), so the quota is checked before every call.
 */
final class OddsApiProvider implements ProviderInterface {

	public const KEY = 'odds_api';

	private const BASE_URL = 'https://api.the-odds-api.com/v4/';

	/**
	 * Builds the provider.
	 *
	 * @param Client        $client  HTTP client.
	 * @param OddsApiQuota  $quota   Quota bookkeeping.
	 * @param OddsApiMapper $mapper Response mapper.
	 * @param string        $api_key API key; empty means not configured.
	 * @param string        $region  Bookmaker region: uk, eu, us, au.
	 * @param int           $reserve Credits to keep unused.
	 * @param Logger|null   $logger  Logger.
	 */
	public function __construct(
		private readonly Client $client,
		private readonly OddsApiQuota $quota,
		private readonly OddsApiMapper $mapper,
		private readonly string $api_key,
		private readonly string $region = 'uk',
		private readonly int $reserve = 20,
		private readonly ?Logger $logger = null,
	) {}

	/**
	 * Stable identifier used in settings and cache keys.
	 */
	public function key(): string {
		return self::KEY;
	}

	/**
	 * Display name for the admin screen.
	 */
	public function label(): string {
		return 'The Odds API';
	}

	/**
	 * Whether the provider can make requests.
	 */
	public function is_configured(): bool {
		return '' !== trim( $this->api_key );
	}

	/**
	 * Competitions available for the event picker.
	 *
	 * @throws ProviderException When the source cannot be reached.
	 *
	 * @return Sport[]
	 */
	public function sports(): array {
		// The sports list is free: it does not count against the quota.
		return $this->mapper->sports( $this->request( 'sports/', array() ) );
	}

	/**
	 * Upcoming events for a competition with quotes for the requested markets.
	 *
	 * @param string   $sport_key   Provider's sport key.
	 * @param string[] $market_keys Market keys: h2h, totals, spreads.
	 *
	 * @throws ProviderException When the source is not configured, refuses the request or fails.
	 *
	 * @return Event[]
	 */
	public function events( string $sport_key, array $market_keys ): array {
		$market_keys = array_values( array_unique( array_map( 'strval', $market_keys ) ) );
		if ( ! $market_keys ) {
			$market_keys = array( 'h2h' );
		}

		if ( ! $this->quota->can_spend( count( $market_keys ), $this->reserve ) ) {
			throw new ProviderException(
				sprintf(
					'The Odds API quota is down to %d credits; refusing to go below the reserve of %d.',
					(int) $this->quota->remaining(),
					$this->reserve
				),
				false
			);
		}

		$data = $this->request(
			sprintf( 'sports/%s/odds/', rawurlencode( $sport_key ) ),
			array(
				'regions'    => $this->region,
				'markets'    => implode( ',', $market_keys ),
				'oddsFormat' => 'decimal',
				'dateFormat' => 'iso',
			)
		);

		return $this->mapper->events( $data );
	}

	/**
	 * Performs a request and decodes the JSON.
	 *
	 * @param string                $path  Path under /v4/.
	 * @param array<string, string> $query Query arguments; the key is added here.
	 *
	 * @throws ProviderException On configuration, transport, HTTP or parse failure.
	 *
	 * @return array<mixed>
	 */
	private function request( string $path, array $query ): array {
		if ( ! $this->is_configured() ) {
			throw ProviderException::not_configured( $this->label() );
		}

		$query['apiKey'] = $this->api_key;
		$url             = self::BASE_URL . $path . '?' . http_build_query( $query );

		try {
			$response = $this->client->get( $url, array( 'headers' => array( 'Accept' => 'application/json' ) ), self::KEY );
		} catch ( HttpException $e ) {
			// 401 means a bad key; the message must not echo the key or the URL back.
			if ( 401 === $e->status ) {
				throw new ProviderException( 'The Odds API rejected the API key.', false );
			}
			throw ProviderException::from_http( $e, $this->label() );
		}

		$this->quota->record( $response );

		try {
			return $response->json();
		} catch ( JsonException $e ) {
			$this->logger?->error( 'The Odds API returned invalid JSON', array( 'error' => $e->getMessage() ) );
			throw ProviderException::bad_response( $this->label(), 'invalid JSON' );
		}
	}
}
