<?php
/**
 * Sample-data provider.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Provider;

use DateInterval;
use DateTimeImmutable;
use OddsCompare\Domain\Event;
use OddsCompare\Domain\Sport;

/**
 * Serves JSON files bundled with the plugin, in The Odds API shape. No network, no key:
 * this is what the block shows before an API key is configured, what unit tests use, and
 * what runs inside WordPress Playground. Kick-off times are shifted so the sample fixtures
 * always look upcoming.
 */
final class FixtureProvider implements ProviderInterface {

	public const KEY = 'fixture';

	/**
	 * Builds the provider.
	 *
	 * @param OddsApiMapper $mapper Mapper shared with the live provider.
	 * @param string        $dir    Directory holding sports.json and <sport_key>.json files.
	 */
	public function __construct(
		private readonly OddsApiMapper $mapper,
		private readonly string $dir,
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
		return 'Sample data';
	}

	/**
	 * Whether the provider can make requests.
	 */
	public function is_configured(): bool {
		return true;
	}

	/**
	 * Competitions available for the event picker.
	 *
	 * @throws ProviderException When the source cannot be reached.
	 *
	 * @return Sport[]
	 */
	public function sports(): array {
		$sports = array();
		$files  = glob( rtrim( $this->dir, '/' ) . '/*.json' );
		foreach ( $files ? $files : array() as $file ) {
			$key = basename( $file, '.json' );
			if ( 'sports' === $key ) {
				continue;
			}
			$sports[] = new Sport( $key, ucwords( str_replace( '_', ' ', $key ) ), 'Sample' );
		}

		return $sports;
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
		$file = rtrim( $this->dir, '/' ) . '/' . basename( $sport_key ) . '.json';
		if ( ! is_file( $file ) ) {
			throw new ProviderException( sprintf( 'No sample data for "%s".', $sport_key ), false );
		}

		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file bundled with the plugin.
		if ( ! is_array( $data ) ) {
			throw ProviderException::bad_response( $this->label(), 'invalid JSON in ' . basename( $file ) );
		}

		$events = $this->mapper->events( $data );
		$wanted = array_fill_keys( $market_keys, true );

		return array_map(
			function ( Event $event ) use ( $wanted ): Event {
				$quotes = $wanted
					? array_values( array_filter( $event->quotes, static fn( $quote ) => isset( $wanted[ $quote->market_key ] ) ) )
					: $event->quotes;

				return $this->shifted( $event )->with_quotes( $quotes );
			},
			$events
		);
	}

	/**
	 * Moves kick-off into the future, keeping the weekday/time of the original fixture.
	 *
	 * @param Event $event Event with the recorded kick-off.
	 */
	private function shifted( Event $event ): Event {
		$now = new DateTimeImmutable();
		if ( $event->commence_time > $now ) {
			return $event;
		}

		$weeks = (int) ceil( ( $now->getTimestamp() - $event->commence_time->getTimestamp() ) / WEEK_IN_SECONDS );
		$time  = $event->commence_time->add( new DateInterval( 'P' . $weeks . 'W' ) );

		return new Event( $event->id, $event->sport_key, $event->sport_title, $event->home_team, $event->away_team, $time, $event->quotes, $event->bookmaker_titles );
	}
}
