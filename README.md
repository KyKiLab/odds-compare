# Odds Compare

A WordPress plugin that shows live odds from a set of bookmakers for a chosen match, with the best price highlighted, in decimal, fractional or American format. Odds come from pluggable providers, go through a cache that never makes visitors wait on the network, and are placed in content with a Gutenberg block.

## Requirements

- WordPress 6.8 or newer
- PHP 8.1 or newer
- Node 20+ only if you want to rebuild the block from source

No PHP dependencies are needed at runtime. Composer is used for the test and lint tooling only.

## Installation

**From a release zip:** upload it through *Plugins > Add New > Upload* and activate. The zip contains the compiled block.

**From the repository:**

```bash
cd wp-content/plugins
git clone https://github.com/KyKiLab/odds-compare.git odds-compare-kate
cd odds-compare-kate
npm install && npm run build
```

Then activate the plugin. Without `npm run build` the plugin still activates, but the block is not registered and the admin sees a notice saying so.

## Configuration

Settings live under *Settings > Odds Compare*.

**Provider.** Three come built in:

- *The Odds API*: live odds from [the-odds-api.com](https://the-odds-api.com/). The free plan gives 500 credits a month; one refresh costs one credit per market. This is the provider the demo runs on.
- *Oddschecker (scraper)*: reads oddschecker.com pages. The site is behind Cloudflare and refuses non-browser clients from most hosting, in which case the provider reports exactly that. It is included because the parser is useful where the site is reachable and because the architecture should not depend on one source. See [DOCUMENTATION.md](DOCUMENTATION.md#oddschecker) for what it reads.
- *Sample data*: bundled JSON in The Odds API format, no network. Active by default so the block works out of the box; also what unit tests and WordPress Playground use.

**API key.** Either paste it into the settings screen or, better, define it in `wp-config.php` so it stays out of the database and its backups:

```php
define( 'ODDS_COMPARE_ODDS_API_KEY', 'your-key' );
```

When the constant is set, the field in the settings screen is locked and shows only the last four characters. If the constant exists at activation time, the plugin selects The Odds API as the provider automatically.

**Markets.** Match result (h2h), total goals (over/under) and handicap. Each selected market costs one credit per refresh, so start with match result only.

**Bookmakers.** Tick the ones to show and give each a link. Leave the link empty to use the bookmaker's homepage, or paste an affiliate URL. Links must be `http(s)`; anything else is rejected and the previous value kept. Bookmakers that appear in provider data without being in the plugin's list are shown switched off, so a new name in the feed never silently appears on the site.

**Caching.** "Refresh odds after" is how long odds are considered fresh (default two hours); "keep stale odds for" is how long old odds are still shown while a refresh is pending or the provider is down (default a day). Odds are only fetched when a page actually shows them, so a site with no blocks spends nothing.

The screen also shows the provider's last success and error, the remaining API quota, and has *Clear cache* and *Refresh now* buttons.

## The block

Insert *Odds Comparison* in any post or page. In the sidebar pick a competition, then an event, then the market. The bookmaker panel lists every enabled bookmaker; untick the ones this particular block should not show. Heading, odds format and the best-price highlight are per block.

The block is wide by default and adapts to the space it gets: a table on desktop, stacked cards per bookmaker when the block is narrower than about 640px. The block is rendered on the server from the cache. Visitors can switch the odds format with the buttons above the table; the switch is a text swap on the client (every cell carries all three formats), and the visitor's choice is remembered in their browser.

The test site's front page carries two blocks: every enabled bookmaker in decimal, and five high-street bookmakers in fractional.

## Development

```bash
composer install          # PHPCS with WordPress standards, PHPUnit, Brain Monkey
composer lint             # phpcs
composer test             # phpunit, no WordPress install needed
npm install
npm run start             # rebuild the block on change
npm run build             # production build
npm run lint:js
npm run lint:css
npm run plugin-zip        # distributable zip without dev files
```

Coding standard is WordPress-Extra plus WordPress-Docs, with classes autoloaded PSR-4 from `src/`. The unit tests mock WordPress functions with Brain Monkey; provider parsers are tested against saved responses in `tests/fixtures/`.

## Known limitations

- The Oddschecker provider only reads the match-result market, because that is what the `/winner` page contains; totals and handicaps are on other pages and are not fetched.
- The free The Odds API plan is small. With one league and one market the default two-hour refresh fits; more leagues or markets need a longer interval or a paid plan. The settings screen shows the cost of the current selection.
- Background refreshes run on WP-Cron. On a quiet site they execute on the next visit, which is fine because visitors are served the cached odds either way; a real cron job hitting `wp-cron.php` makes it tidier.
- Exchange prices (Betfair Exchange, Smarkets, Matchbook) are shown as they come. In fractional format they can look unusual (47/20) because exchanges do not quote on the traditional ladder.

## License

GPL-2.0-or-later.
