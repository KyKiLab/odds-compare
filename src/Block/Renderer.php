<?php
/**
 * Server-side rendering of the comparison block.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Block;

use Closure;
use OddsCompare\Bookmakers\Catalogue;
use OddsCompare\Cache\Result;
use OddsCompare\Domain\Bookmaker;
use OddsCompare\Domain\Comparison;
use OddsCompare\Domain\OddsFormat;
use OddsCompare\Rest\EditorController;
use OddsCompare\Settings\Markets;
use OddsCompare\Settings\Sanitizer;
use OddsCompare\Settings\Settings;

/**
 * Turns block attributes into HTML. Attributes are re-validated here because post content
 * is editable by anyone with the right role and survives imports, so block.json's schema
 * is not a guarantee. The markup itself lives in templates/comparison.php.
 */
final class Renderer {

	public const BLOCK_NAME = 'odds-compare/comparison';

	/**
	 * Builds the renderer.
	 *
	 * @param Closure   $repository fn(): ?OddsRepository for the active provider.
	 * @param Settings  $settings   Settings.
	 * @param Catalogue $catalogue  Bookmakers.
	 */
	public function __construct(
		private readonly Closure $repository,
		private readonly Settings $settings,
		private readonly Catalogue $catalogue,
	) {}

	/**
	 * Renders the block.
	 *
	 * @param array<string, mixed> $attributes Raw block attributes.
	 * @param bool                 $in_editor  True when rendering a preview for the editor, which may see error details.
	 */
	public function render( array $attributes, bool $in_editor = false ): string {
		$attrs = $this->attributes( $attributes );

		if ( '' === $attrs['sportKey'] || '' === $attrs['eventId'] ) {
			return $in_editor ? $this->message( __( 'Choose a competition and an event in the block settings.', 'odds-compare-kate' ) ) : '';
		}

		$repository = ( $this->repository )();
		if ( ! $repository ) {
			return $this->message( $in_editor ? __( 'No odds provider is configured.', 'odds-compare-kate' ) : $this->empty_message() );
		}

		// Every block asks for the configured market set, so all blocks for a league share one cache entry.
		$result = $repository->events( $attrs['sportKey'], $this->settings->markets() );
		$event  = $result->find( $attrs['eventId'] );

		if ( ! $event ) {
			if ( Result::LOADING === $result->status ) {
				return $this->message( __( 'Odds are being fetched, check back in a moment.', 'odds-compare-kate' ) );
			}
			if ( null !== $result->error ) {
				return $this->message( $in_editor ? $result->error : $this->empty_message() );
			}

			return $this->message( $in_editor ? __( 'This event is no longer available from the provider. Pick another one.', 'odds-compare-kate' ) : $this->empty_message() );
		}

		$bookmakers = $this->enabled_bookmakers();
		$selected   = $attrs['bookmakers']
			? array_values( array_intersect( $attrs['bookmakers'], array_keys( $bookmakers ) ) )
			: array_keys( $bookmakers );

		$table = Comparison::build( $event, $attrs['market'], $selected );
		if ( $table->is_empty() ) {
			return $this->message( $in_editor ? __( 'No odds for this market from the selected bookmakers yet.', 'odds-compare-kate' ) : $this->empty_message() );
		}

		/**
		 * Filters the rows of a comparison table before rendering, e.g. to reorder or cap them.
		 *
		 * @param array<string, array<string, Quote>> $rows  Bookmaker key => column => Quote.
		 * @param Comparison                           $table The table.
		 * @param array<string, mixed>                 $attrs Sanitized block attributes.
		 */
		$rows = (array) apply_filters( 'odds_compare_comparison_rows', $table->rows(), $table, $attrs );

		$links = array();
		foreach ( array_keys( $rows ) as $key ) {
			$bookmaker = $bookmakers[ $key ] ?? new Bookmaker( $key, $this->catalogue->title( $key ) );

			/**
			 * Filters the link a bookmaker row points to, e.g. to append tracking or deep-link the event.
			 *
			 * @param string|null          $url       Affiliate URL from settings, or the catalogue homepage, or null.
			 * @param Bookmaker            $bookmaker The bookmaker.
			 * @param Event                $event     The event being shown.
			 * @param array<string, mixed> $attrs     Sanitized block attributes.
			 */
			$links[ $key ] = $bookmaker->with_url( apply_filters( 'odds_compare_affiliate_url', $bookmaker->url, $bookmaker, $event, $attrs ) );
		}

		$updated = $table->updated_at();
		$view    = array(
			'attrs'        => $attrs,
			'event'        => $event,
			'kickoff'      => wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $event->commence_time->getTimestamp() ),
			'market_label' => Markets::label( $attrs['market'] ),
			'table'        => $table,
			'rows'         => $rows,
			'columns'      => $table->columns(),
			'bookmakers'   => $links,
			'format'       => '' === $attrs['format'] ? $this->settings->default_format() : OddsFormat::from_string( $attrs['format'], $this->settings->default_format() ),
			'formats'      => OddsFormat::labels(),
			'updated'      => $updated ? human_time_diff( $updated->getTimestamp() ) : null,
			'stale'        => $result->is_stale(),
		);

		ob_start();
		include ODDS_COMPARE_DIR . 'templates/comparison.php';

		return (string) ob_get_clean();
	}

	/**
	 * Validated attributes with defaults.
	 *
	 * @param array<string, mixed> $raw Raw attributes.
	 *
	 * @return array<string, mixed>
	 */
	private function attributes( array $raw ): array {
		$sport = isset( $raw['sportKey'] ) && EditorController::is_sport_key( $raw['sportKey'] ) ? $raw['sportKey'] : '';
		$event = isset( $raw['eventId'] ) && is_string( $raw['eventId'] ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $raw['eventId'] ) ? $raw['eventId'] : '';

		$configured = $this->settings->markets();
		$market     = isset( $raw['market'] ) && is_string( $raw['market'] ) && in_array( $raw['market'], Markets::keys(), true ) ? $raw['market'] : '';
		if ( '' === $market || ! in_array( $market, $configured, true ) ) {
			$market = $configured[0] ?? 'h2h';
		}

		$bookmakers = array();
		foreach ( (array) ( $raw['bookmakers'] ?? array() ) as $key ) {
			$key = is_string( $key ) ? Sanitizer::bookmaker_key( $key ) : '';
			if ( '' !== $key ) {
				$bookmakers[] = $key;
			}
		}

		$format = isset( $raw['format'] ) && is_string( $raw['format'] ) && null !== OddsFormat::tryFrom( $raw['format'] ) ? $raw['format'] : '';

		return array(
			'sportKey'      => $sport,
			'eventId'       => $event,
			'market'        => $market,
			'bookmakers'    => array_values( array_unique( $bookmakers ) ),
			'format'        => $format,
			'title'         => isset( $raw['title'] ) && is_string( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '',
			'highlightBest' => ! isset( $raw['highlightBest'] ) || (bool) $raw['highlightBest'],
			'showUpdated'   => ! isset( $raw['showUpdated'] ) || (bool) $raw['showUpdated'],
		);
	}

	/**
	 * Enabled bookmakers with the link resolved: affiliate override, else catalogue homepage.
	 *
	 * @return array<string, Bookmaker>
	 */
	private function enabled_bookmakers(): array {
		$enabled = array();
		foreach ( $this->catalogue->all_with_seen() as $key => $bookmaker ) {
			if ( ! $this->settings->is_bookmaker_enabled( $key, $this->catalogue->is_built_in( $key ) ) ) {
				continue;
			}
			$enabled[ $key ] = $bookmaker->with_url( $this->settings->bookmaker_url( $key ) ?? $bookmaker->url );
		}

		return $enabled;
	}

	/**
	 * What visitors see when there is nothing to show.
	 */
	private function empty_message(): string {
		/**
		 * Filters the message shown to visitors when no odds are available.
		 *
		 * @param string $message Default message.
		 */
		return (string) apply_filters( 'odds_compare_empty_message', __( 'Odds are not available right now.', 'odds-compare-kate' ) );
	}

	/**
	 * Wrapped one-line message.
	 *
	 * @param string $text Plain text.
	 */
	private function message( string $text ): string {
		return sprintf(
			'<div %s><p class="odds-compare__message">%s</p></div>',
			get_block_wrapper_attributes( array( 'class' => 'odds-compare' ) ),
			esc_html( $text )
		);
	}
}
