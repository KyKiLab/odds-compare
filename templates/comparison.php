<?php
/**
 * Comparison table markup. Included by Block\Renderer with $view in scope.
 *
 * @var array<string, mixed> $view
 *
 * @package OddsCompare
 */

use OddsCompare\Domain\OddsFormat;

$attrs      = $view['attrs'];
$table      = $view['table'];
$format     = $view['format'];
$wrapper    = get_block_wrapper_attributes(
	array(
		'class'       => 'odds-compare',
		'data-format' => $format->value,
	)
);
$bet_label  = __( 'Bet now', 'odds-compare-kate' );
$best_label = __( 'Best price', 'odds-compare-kate' );
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>
	<div class="odds-compare__header">
		<div>
			<?php if ( '' !== $attrs['title'] ) : ?>
				<h3 class="odds-compare__title"><?php echo esc_html( $attrs['title'] ); ?></h3>
			<?php endif; ?>
			<p class="odds-compare__event">
				<strong><?php echo esc_html( $view['event']->title() ); ?></strong>
				<span class="odds-compare__meta"><?php echo esc_html( $view['market_label'] ); ?> &middot; <?php echo esc_html( $view['kickoff'] ); ?></span>
			</p>
		</div>
		<div class="odds-compare__format" role="group" aria-label="<?php esc_attr_e( 'Odds format', 'odds-compare-kate' ); ?>">
			<?php foreach ( $view['formats'] as $value => $label ) : ?>
				<button type="button" data-format="<?php echo esc_attr( $value ); ?>" aria-pressed="<?php echo $value === $format->value ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="odds-compare__scroll">
		<table class="odds-compare__table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Bookmaker', 'odds-compare-kate' ); ?></th>
					<?php foreach ( $view['columns'] as $column ) : ?>
						<th scope="col"><?php echo esc_html( $column ); ?></th>
					<?php endforeach; ?>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Link', 'odds-compare-kate' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $view['rows'] as $key => $cells ) : ?>
				<?php $bookmaker = $view['bookmakers'][ $key ]; ?>
				<tr>
					<th scope="row" class="odds-compare__bookmaker">
						<span class="odds-compare__avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $bookmaker->title, 0, 1 ) ) ); ?></span>
						<span class="odds-compare__name"><?php echo esc_html( $bookmaker->title ); ?></span>
					</th>
					<?php foreach ( $view['columns'] as $column ) : ?>
						<?php if ( ! isset( $cells[ $column ] ) ) : ?>
							<td class="odds-compare__cell odds-compare__cell--empty" data-label="<?php echo esc_attr( $column ); ?>"><span class="odds-compare__odds odds-compare__odds--empty">&ndash;</span></td>
							<?php continue; ?>
						<?php endif; ?>
						<?php
						$quote   = $cells[ $column ];
						$is_best = $attrs['highlightBest'] && $table->is_best( $quote );
						?>
						<td class="odds-compare__cell<?php echo $is_best ? ' is-best' : ''; ?>"
							data-decimal="<?php echo esc_attr( $quote->odds->format( OddsFormat::Decimal ) ); ?>"
							data-fractional="<?php echo esc_attr( $quote->odds->format( OddsFormat::Fractional ) ); ?>"
							data-american="<?php echo esc_attr( $quote->odds->format( OddsFormat::American ) ); ?>"
							data-label="<?php echo esc_attr( $column ); ?>">
							<span class="odds-compare__odds"><?php echo esc_html( $quote->odds->format( $format ) ); ?></span>
							<?php if ( null !== $quote->point ) : ?>
								<small class="odds-compare__line"><?php echo esc_html( $quote->label() ); ?></small>
							<?php endif; ?>
							<?php if ( $is_best ) : ?>
								<span class="screen-reader-text"><?php echo esc_html( $best_label ); ?></span>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
					<td class="odds-compare__cta">
						<?php if ( $bookmaker->url ) : ?>
							<a class="odds-compare__button" href="<?php echo esc_url( $bookmaker->url ); ?>" rel="nofollow sponsored noopener" target="_blank"><?php echo esc_html( $bet_label ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php if ( ( $attrs['showUpdated'] && $view['updated'] ) || $view['stale'] ) : ?>
	<div class="odds-compare__footer">
		<?php if ( $attrs['showUpdated'] && $view['updated'] ) : ?>
			<span class="odds-compare__updated">
				<?php
				/* translators: %s: human readable time difference */
				echo esc_html( sprintf( __( 'Odds updated %s ago', 'odds-compare-kate' ), $view['updated'] ) );
				?>
			</span>
		<?php endif; ?>
		<?php if ( $view['stale'] ) : ?>
			<span class="odds-compare__stale"><?php esc_html_e( 'Refreshing in the background; prices may have moved.', 'odds-compare-kate' ); ?></span>
		<?php endif; ?>
	</div>
	<?php endif; ?>
</div>
