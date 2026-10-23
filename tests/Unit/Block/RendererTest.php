<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Block;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use Mockery;
use OddsCompare\Block\Renderer;
use OddsCompare\Bookmakers\Catalogue;
use OddsCompare\Cache\OddsCache;
use OddsCompare\Cache\OddsRepository;
use OddsCompare\Cache\ProviderState;
use OddsCompare\Domain\Event;
use OddsCompare\Domain\Odds;
use OddsCompare\Domain\Quote;
use OddsCompare\Provider\ProviderException;
use OddsCompare\Provider\ProviderInterface;
use OddsCompare\Settings\Settings;
use OddsCompare\Tests\TestCase;

final class RendererTest extends TestCase {

	/** @var array<string, mixed> */
	private array $store = array();

	protected function setUp(): void {
		parent::setUp();
		$store = &$this->store;
		foreach ( array( 'get_transient', 'get_option' ) as $fn ) {
			Functions\when( $fn )->alias(
				static function ( string $key, $fallback = false ) use ( &$store ) {
					return $store[ $key ] ?? $fallback;
				}
			);
		}
		foreach ( array( 'set_transient', 'update_option' ) as $fn ) {
			Functions\when( $fn )->alias(
				static function ( string $key, $value ) use ( &$store ): bool {
					$store[ $key ] = $value;
					return true;
				}
			);
		}
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$store ): bool {
				unset( $store[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'get_block_wrapper_attributes' )->alias(
			static function ( array $extra = array() ): string {
				$extra['class'] = 'wp-block-odds-compare-comparison ' . ( $extra['class'] ?? '' );
				$out            = array();
				foreach ( $extra as $k => $v ) {
					$out[] = $k . '="' . $v . '"';
				}
				return implode( ' ', $out );
			}
		);
		Functions\when( 'wp_date' )->justReturn( '23 October 2026, 20:00' );
		Functions\when( 'human_time_diff' )->justReturn( '5 mins' );
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ) => trim( strip_tags( $s ) ) );
	}

	private function event(): Event {
		$q = static fn( string $bk, string $outcome, float $decimal, ?float $point = null, string $market = 'h2h' ) => new Quote( $bk, $market, $outcome, Odds::from_decimal( $decimal ), $point, new DateTimeImmutable( '2026-10-22T10:00:00Z' ) );

		return new Event(
			'ev1',
			'soccer_epl',
			'Premier League',
			'Ipswich',
			'Forest',
			new DateTimeImmutable( '2026-10-23T19:00:00Z' ),
			array(
				$q( 'williamhill', 'Ipswich', 3.2 ),
				$q( 'williamhill', 'Draw', 3.5 ),
				$q( 'williamhill', 'Forest', 2.2 ),
				$q( 'coral', 'Ipswich', 3.3 ),
				$q( 'coral', 'Draw', 3.6 ),
				$q( 'coral', 'Forest', 2.1 ),
				$q( 'coral', 'Over', 1.9, 2.5, 'totals' ),
				$q( 'oddschecker:xyz', 'Ipswich', 9.0 ),
			)
		);
	}

	private function renderer( ?callable $provider_setup = null ): Renderer {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'key' )->andReturn( 'test' );
		if ( $provider_setup ) {
			$provider_setup( $provider );
		} else {
			$provider->shouldReceive( 'events' )->andReturn( array( $this->event() ) );
		}
		$repository = new OddsRepository( $provider, new OddsCache( 3600 ), new ProviderState(), 3600 );

		return new Renderer( static fn() => $repository, new Settings(), new Catalogue() );
	}

	public function test_renders_table_with_best_price_and_sponsored_links(): void {
		$html = $this->renderer()->render(
			array(
				'sportKey'   => 'soccer_epl',
				'eventId'    => 'ev1',
				'market'     => 'h2h',
				'title'      => 'Who wins?',
				'bookmakers' => array( 'coral', 'williamhill', '<img onerror=x>' ),
			)
		);

		self::assertStringContainsString( '<h3 class="odds-compare__title">Who wins?</h3>', $html );
		self::assertStringContainsString( 'Ipswich v Forest', $html );
		self::assertStringContainsString( 'data-format="decimal"', $html );
		self::assertStringContainsString( 'rel="nofollow sponsored noopener"', $html );
		self::assertStringContainsString( 'https://sports.coral.co.uk/', $html );
		self::assertStringContainsString( 'Odds updated 5 mins ago', $html );
		self::assertStringNotContainsString( 'onerror', $html );
		self::assertStringNotContainsString( 'oddschecker:xyz', $html, 'discovered bookmakers are off by default' );

		// Coral has the best Ipswich price; William Hill does not.
		self::assertMatchesRegularExpression( '~Coral.*?is-best"\s+data-decimal="3.30"~s', $html );
		self::assertMatchesRegularExpression( '~William Hill.*?class="odds-compare__cell"\s+data-decimal="3.20"~s', $html );
		self::assertStringContainsString( 'data-fractional="23/10"', $html );
		self::assertStringContainsString( 'data-american="+230"', $html );
	}

	public function test_empty_bookmaker_list_means_every_enabled_one_and_order_is_best_first(): void {
		$html = $this->renderer()->render(
			array(
				'sportKey' => 'soccer_epl',
				'eventId'  => 'ev1',
			)
		);

		self::assertLessThan( strpos( $html, 'William Hill' ), strpos( $html, 'Coral' ) );
	}

	public function test_garbage_attributes_fall_back_safely(): void {
		$html = $this->renderer()->render(
			array(
				'sportKey' => 'soccer_epl',
				'eventId'  => 'ev1',
				'market'   => 'x',
				'format'   => 'weird',
				'title'    => '<script>alert(1)</script>Heading',
			)
		);

		self::assertStringContainsString( 'Heading', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( 'data-format="decimal"', $html );
	}

	public function test_unconfigured_block_renders_nothing_for_visitors_and_a_hint_in_the_editor(): void {
		$renderer = $this->renderer();

		self::assertSame( '', $renderer->render( array() ) );
		self::assertStringContainsString( 'Choose a competition', $renderer->render( array(), true ) );
	}

	public function test_missing_event_and_provider_errors(): void {
		$renderer = $this->renderer();
		self::assertStringContainsString(
			'Odds are not available right now.',
			$renderer->render(
				array(
					'sportKey' => 'soccer_epl',
					'eventId'  => 'gone',
				)
			)
		);
		self::assertStringContainsString(
			'no longer available',
			$renderer->render(
				array(
					'sportKey' => 'soccer_epl',
					'eventId'  => 'gone',
				),
				true
			)
		);

		// Fresh repository state: the calls above cached the event under the same key.
		$this->store = array();
		Filters\expectApplied( 'odds_compare_empty_message' )->once()->andReturn( 'Nope.' );
		$failing = $this->renderer(
			static function ( $provider ): void {
				$provider->shouldReceive( 'events' )->andThrow( new ProviderException( 'quota gone', false ) );
			}
		);
		self::assertStringContainsString(
			'Nope.',
			$failing->render(
				array(
					'sportKey' => 'soccer_epl',
					'eventId'  => 'ev1',
				)
			)
		);
		self::assertStringContainsString(
			'quota gone',
			$failing->render(
				array(
					'sportKey' => 'soccer_epl',
					'eventId'  => 'ev1',
				),
				true
			)
		);
	}

	public function test_affiliate_url_filter_and_totals_line(): void {
		Filters\expectApplied( 'odds_compare_affiliate_url' )->atLeast()->once()->andReturnUsing(
			static fn( $url, $bookmaker ) => 'coral' === $bookmaker->key ? 'https://aff.example/coral' : $url
		);
		$this->store[ Settings::OPTION ] = array( 'markets' => array( 'h2h', 'totals' ) );

		$html = $this->renderer()->render(
			array(
				'sportKey' => 'soccer_epl',
				'eventId'  => 'ev1',
				'market'   => 'totals',
			)
		);

		self::assertStringContainsString( 'https://aff.example/coral', $html );
		self::assertStringContainsString( '<small class="odds-compare__line">Over 2.5</small>', $html );
		self::assertStringNotContainsString( 'William Hill', $html, 'no totals from William Hill' );
	}
}
