<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Domain;

use OddsCompare\Domain\OddsFormat;
use OddsCompare\Tests\TestCase;

final class OddsFormatTest extends TestCase {

	public function test_unknown_value_falls_back_instead_of_throwing(): void {
		self::assertSame( OddsFormat::Decimal, OddsFormat::from_string( 'weird' ) );
		self::assertSame( OddsFormat::American, OddsFormat::from_string( 'weird', OddsFormat::American ) );
		self::assertSame( OddsFormat::Decimal, OddsFormat::from_string( null ) );
		self::assertSame( OddsFormat::Decimal, OddsFormat::from_string( '' ) );
	}

	public function test_known_values_are_parsed_case_insensitively(): void {
		self::assertSame( OddsFormat::Fractional, OddsFormat::from_string( ' Fractional ' ) );
		self::assertSame( OddsFormat::American, OddsFormat::from_string( 'american' ) );
	}

	public function test_labels_cover_every_case(): void {
		self::assertSame( array( 'decimal', 'fractional', 'american' ), array_keys( OddsFormat::labels() ) );
	}
}
