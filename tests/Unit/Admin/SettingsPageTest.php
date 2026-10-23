<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Admin;

use OddsCompare\Admin\SettingsPage;
use OddsCompare\Tests\TestCase;

final class SettingsPageTest extends TestCase {

	public function test_mask_keeps_only_the_tail(): void {
		self::assertSame( '••••••••cd12', SettingsPage::mask( '0123456789abcdef0123456789abcd12' ) );
		self::assertSame( '•••', SettingsPage::mask( 'abc' ) );
		self::assertSame( '', SettingsPage::mask( '' ) );
	}
}
