<?php
/**
 * Odds comparison table model.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Domain;

/**
 * Turns an event's quotes for one market into rows (bookmakers) and columns (outcomes),
 * and knows which price is best in each column. Pure data: rendering lives in the block.
 */
final class Comparison {

	/**
	 * Column labels in display order.
	 *
	 * @var string[]
	 */
	private array $columns = array();

	/**
	 * Rows keyed by bookmaker; each row maps column => Quote.
	 *
	 * @var array<string, array<string, Quote>>
	 */
	private array $rows = array();

	/**
	 * Best odds per column.
	 *
	 * @var array<string, Odds>
	 */
	private array $best = array();

	/**
	 * Use build().
	 *
	 * @param Event  $event      Source event.
	 * @param string $market_key Market shown.
	 */
	private function __construct(
		public readonly Event $event,
		public readonly string $market_key,
	) {}

	/**
	 * Builds the table.
	 *
	 * @param Event    $event          Event with quotes.
	 * @param string   $market_key     Market to show.
	 * @param string[] $bookmaker_keys Restrict to these bookmakers; empty means all present.
	 */
	public static function build( Event $event, string $market_key, array $bookmaker_keys = array() ): self {
		$table = new self( $event, $market_key );
		$allow = array_fill_keys( $bookmaker_keys, true );

		foreach ( $event->quotes_for( $market_key ) as $quote ) {
			if ( $allow && ! isset( $allow[ $quote->bookmaker_key ] ) ) {
				continue;
			}
			// Totals keep one column per side ("Over", "Under") and show the line in the cell;
			// a separate column per line would make a sparse table once bookmakers disagree on it.
			$column = $quote->outcome;

			$table->rows[ $quote->bookmaker_key ][ $column ] = $quote;

			if ( ! isset( $table->best[ $column ] ) || $quote->odds->is_better_than( $table->best[ $column ] ) ) {
				$table->best[ $column ] = $quote->odds;
			}
		}

		$table->columns = $table->order_columns( array_keys( $table->best ) );
		$table->sort_rows();

		return $table;
	}

	/**
	 * No quotes matched.
	 */
	public function is_empty(): bool {
		return empty( $this->rows );
	}

	/**
	 * Column labels in display order.
	 *
	 * @return string[]
	 */
	public function columns(): array {
		return $this->columns;
	}

	/**
	 * Rows in display order, keyed by bookmaker key; each row maps column => Quote.
	 *
	 * @return array<string, array<string, Quote>>
	 */
	public function rows(): array {
		return $this->rows;
	}

	/**
	 * Bookmaker keys in display order.
	 *
	 * @return string[]
	 */
	public function bookmaker_keys(): array {
		return array_keys( $this->rows );
	}

	/**
	 * Best odds for a column, or null if the column is unknown.
	 *
	 * @param string $column Column label.
	 */
	public function best( string $column ): ?Odds {
		return $this->best[ $column ] ?? null;
	}

	/**
	 * Whether a quote matches the best price in its column. Ties all count as best.
	 *
	 * @param Quote $quote Quote to check.
	 */
	public function is_best( Quote $quote ): bool {
		$best = $this->best[ $quote->outcome ] ?? null;

		return null !== $best && 0 === $quote->odds->compare( $best );
	}

	/**
	 * Most recent update time across the shown quotes, or null.
	 */
	public function updated_at(): ?\DateTimeImmutable {
		$latest = null;
		foreach ( $this->rows as $cells ) {
			foreach ( $cells as $quote ) {
				if ( $quote->updated_at && ( null === $latest || $quote->updated_at > $latest ) ) {
					$latest = $quote->updated_at;
				}
			}
		}

		return $latest;
	}

	/**
	 * Home, Draw, Away first for match markets; Over before Under; anything else as found.
	 *
	 * @param string[] $columns Column labels as collected.
	 *
	 * @return string[]
	 */
	private function order_columns( array $columns ): array {
		$preferred = array( $this->event->home_team, 'Draw', $this->event->away_team, 'Over', 'Under' );
		$ordered   = array_values( array_intersect( $preferred, $columns ) );

		return array_merge( $ordered, array_values( array_diff( $columns, $ordered ) ) );
	}

	/**
	 * Rows with the best price in the first column come first; the first column is what the
	 * reader scans. Rows without a price there go last, keeping provider order among themselves.
	 */
	private function sort_rows(): void {
		$first = $this->columns[0] ?? null;
		if ( null === $first ) {
			return;
		}

		uasort(
			$this->rows,
			static function ( array $a, array $b ) use ( $first ): int {
				if ( ! isset( $a[ $first ] ) || ! isset( $b[ $first ] ) ) {
					return isset( $a[ $first ] ) ? -1 : ( isset( $b[ $first ] ) ? 1 : 0 );
				}

				return $b[ $first ]->odds->compare( $a[ $first ]->odds );
			}
		);
	}
}
