<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Support;

use Brain\Monkey\Actions;
use OddsCompare\Support\Logger;
use OddsCompare\Tests\TestCase;

final class LoggerTest extends TestCase {

	public function test_every_entry_fires_the_log_action(): void {
		Actions\expectDone( 'odds_compare_log' )
			->once()
			->with( 'warning', 'Request failed', array( 'url' => 'https://x' ), 'odds-compare' );

		( new Logger() )->warning( 'Request failed', array( 'url' => 'https://x' ) );
	}
}
