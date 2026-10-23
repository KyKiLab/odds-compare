<?php
/**
 * Oddschecker provider.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Provider;

use OddsCompare\Domain\Event;
use OddsCompare\Domain\Sport;
use OddsCompare\Http\Client;
use OddsCompare\Http\HttpException;
use OddsCompare\Provider\Oddschecker\HypernovaExtractor;
use OddsCompare\Provider\Oddschecker\Parser;
use OddsCompare\Support\Logger;

/**
 * Reads odds from oddschecker.com pages: one request for the league page to list fixtures,
 * then one per fixture (nearest kick-offs first, up to a cap) for the bookmaker prices.
 *
 * Oddschecker sits behind Cloudflare and answers 403 to clients that are not browsers, so
 * on most hosting this provider reports exactly that and the admin sees it. The plugin
 * does not try to get around the block; the parser is verified against saved pages.
 */
final class OddscheckerProvider implements ProviderInterface {

	public const KEY = 'oddschecker';

	private const BASE_URL = 'https://www.oddschecker.com/';

	/**
	 * Plugin sport key => [ path on oddschecker.com, title ].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const LEAGUES = array(
		'football-english-premier-league' => array( 'football/english/premier-league', 'Premier League' ),
		'football-english-championship'   => array( 'football/english/championship', 'Championship' ),
		'football-spain-la-liga'          => array( 'football/spain/la-liga-primera', 'La Liga' ),
		'football-germany-bundesliga'     => array( 'football/germany/bundesliga', 'Bundesliga' ),
		'football-italy-serie-a'          => array( 'football/italy/serie-a', 'Serie A' ),
		'football-champions-league'       => array( 'football/champions-league', 'Champions League' ),
	);

	/**
	 * Builds the provider.
	 *
	 * @param Client             $client     HTTP client; requests use the "oddschecker" rate-limit bucket.
	 * @param HypernovaExtractor $extractor  Page state extractor.
	 * @param Parser             $parser     Parser.
	 * @param int                $max_events Fixtures to fetch prices for per league.
	 * @param Logger|null        $logger     Logger.
	 */
	public function __construct(
		private readonly Client $client,
		private readonly HypernovaExtractor $extractor,
		private readonly Parser $parser,
		private readonly int $max_events = 10,
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
		return 'Oddschecker (scraper)';
	}

	/**
	 * Nothing to configure.
	 */
	public function is_configured(): bool {
		return true;
	}

	/**
	 * Supported leagues.
	 *
	 * @return Sport[]
	 */
	public function sports(): array {
		$sports = array();
		foreach ( $this->leagues() as $key => $league ) {
			$sports[] = new Sport( (string) $key, (string) $league[1], 'Football' );
		}

		return $sports;
	}

	/**
	 * Fixtures for a league with prices for the nearest ones.
	 *
	 * @param string   $sport_key   Plugin sport key.
	 * @param string[] $market_keys Market keys; only h2h is available from the match page.
	 *
	 * @throws ProviderException When the league is unknown or the league page cannot be read.
	 *
	 * @return Event[]
	 */
	public function events( string $sport_key, array $market_keys ): array {
		$league = $this->leagues()[ $sport_key ] ?? null;
		if ( ! $league ) {
			throw new ProviderException( sprintf( 'Oddschecker provider does not know the league "%s".', $sport_key ), false );
		}

		$blob = $this->extractor->extract( $this->fetch( self::BASE_URL . $league[0] ), 'competitionsaccumulatormatches' );
		if ( null === $blob ) {
			throw ProviderException::bad_response( $this->label(), 'fixture list not found in the league page' );
		}

		$events = $this->parser->league_events( $blob, $sport_key, (string) $league[1] );

		/**
		 * Filters how many fixtures get their prices fetched per refresh. Each costs one request.
		 *
		 * @param int    $max_events Default from settings.
		 * @param string $sport_key  League being refreshed.
		 */
		$max = max( 0, (int) apply_filters( 'odds_compare_oddschecker_max_events', $this->max_events, $sport_key ) );

		$fetched = 0;
		foreach ( $events as $slug => $event ) {
			if ( $fetched >= $max ) {
				break;
			}
			++$fetched;

			try {
				$html = $this->fetch( self::BASE_URL . $league[0] . '/' . $slug . '/winner' );
			} catch ( ProviderException $e ) {
				if ( $e->is_retryable() ) {
					// Rate limit or a transient failure: keep what we have, the next refresh continues.
					$this->logger?->warning(
						'Oddschecker match page skipped',
						array(
							'slug'  => $slug,
							'error' => $e->getMessage(),
						)
					);
					break;
				}
				throw $e;
			}

			$match = $this->extractor->extract( $html, 'subeventmarkets' );
			if ( null === $match ) {
				$this->logger?->warning( 'Oddschecker match page without market data', array( 'slug' => $slug ) );
				continue;
			}

			$parsed          = $this->parser->match_quotes( $match, $market_keys );
			$events[ $slug ] = new Event(
				$event->id,
				$event->sport_key,
				$event->sport_title,
				$event->home_team,
				$event->away_team,
				$event->commence_time,
				$parsed['quotes'],
				$parsed['titles']
			);
		}//end foreach

		return array_values( $events );
	}

	/**
	 * Leagues after the filter.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private function leagues(): array {
		/**
		 * Filters the leagues the Oddschecker provider offers.
		 *
		 * @param array<string, array{0: string, 1: string}> $leagues Sport key => [ path, title ].
		 */
		$leagues = apply_filters( 'odds_compare_oddschecker_leagues', self::LEAGUES );

		return is_array( $leagues ) ? $leagues : self::LEAGUES;
	}

	/**
	 * GET a page as text.
	 *
	 * @param string $url Page URL.
	 *
	 * @throws ProviderException On any HTTP failure; a 403 gets the explanation it deserves.
	 */
	private function fetch( string $url ): string {
		try {
			return $this->client->get( $url, array( 'headers' => array( 'Accept' => 'text/html' ) ), self::KEY )->body;
		} catch ( HttpException $e ) {
			if ( 403 === $e->status ) {
				throw new ProviderException(
					'Oddschecker refused the request (HTTP 403). The site is behind Cloudflare and blocks server-side clients, so this provider only works from networks it allows. Switch to The Odds API for live data.',
					false
				);
			}
			throw ProviderException::from_http( $e, $this->label() );
		}
	}
}
