# Odds Compare: developer documentation

This is the long version: how the plugin is put together, why it is put together that way, and where to plug in. For installation and the settings screen see [README.md](README.md).

## Contents

1. [Design in one page](#design-in-one-page)
2. [Directory layout](#directory-layout)
3. [What happens on a page view](#what-happens-on-a-page-view)
4. [Domain model](#domain-model)
5. [Providers](#providers)
6. [HTTP layer](#http-layer)
7. [Cache](#cache)
8. [Settings and bookmakers](#settings-and-bookmakers)
9. [REST endpoints](#rest-endpoints)
10. [Block](#block)
11. [Admin screen](#admin-screen)
12. [Hooks reference](#hooks-reference)
13. [Extending the plugin](#extending-the-plugin)
14. [Security notes](#security-notes)
15. [Testing](#testing)
16. [Limitations and ideas](#limitations-and-ideas)

## Design in one page

The plugin is a pipeline: a **provider** fetches odds from somewhere and normalises them into `Event` and `Quote` objects; a **repository** keeps those in a **cache** and decides when to go back to the provider; the **block** builds a `Comparison` table from a cached event and renders it. Settings and the bookmaker catalogue sit beside the pipeline and are read, never written, by it.

Three decisions shape everything else:

- **Decimal odds are the canonical form.** Every provider converts to decimal on the way in; fractional and American are computed on the way out. Comparison is numeric and there is exactly one implementation of each conversion.
- **Visitors never wait on the network.** Fresh cache is served as is. Stale cache is served immediately and a background refresh is queued. Only a cold cache fetches synchronously, under a lock, and that normally happens in the editor when the event is picked, not on the front end. If the provider keeps failing, a circuit breaker stops the plugin from calling it for a few minutes.
- **The source is replaceable.** The assignment asked for odds scraped from Oddschecker. Oddschecker sits behind Cloudflare and answers 403 to anything that is not a browser, so a plugin built around it alone would show nothing on a normal host. The provider contract makes the source a detail: The Odds API gives live data today, the Oddschecker reader is there for networks where it works, sample data covers tests and demos, and a fourth source is a filter away.

Patterns, since the brief asks: `Plugin` is a singleton used only as a composition root; `ProviderRegistry` is a factory keyed by provider id; providers are strategies behind `ProviderInterface`; WordPress actions and filters are the observer mechanism; `Odds`, `Fraction`, `Quote`, `Event`, `Bookmaker` are value objects; `OddsRepository` is a repository hiding cache and provider behind one method.

## Directory layout

```
odds-compare-kate.php      Plugin header, constants, autoloader, boot on plugins_loaded.
uninstall.php              Deletes options and the cache entries listed in the index.
src/
  Plugin.php               Composition root: builds services lazily, registers hooks.
  Autoloader.php           PSR-4 loader for OddsCompare\ -> src/.
  Domain/                  Value objects, no WordPress dependencies.
    Odds.php               Price with decimal/fractional/American conversion.
    Fraction.php           Reduced fraction, UK price ladder, float -> fraction.
    OddsFormat.php         Enum of display formats.
    Quote.php              One bookmaker's price for one outcome.
    Event.php              A fixture with its quotes.
    Comparison.php         Rows/columns/best-price table for one event and market.
    Bookmaker.php, Sport.php
  Provider/
    ProviderInterface.php  The contract.
    ProviderRegistry.php   Known providers, fallback to sample data.
    ProviderException.php  The one exception type the cache layer understands.
    OddsApiProvider.php, OddsApiMapper.php, OddsApiQuota.php
    OddscheckerProvider.php, Oddschecker/HypernovaExtractor.php, Oddschecker/Parser.php
    FixtureProvider.php    Bundled JSON, used by default and in tests.
  Http/                    Client over wp_remote_get, Response, HttpException, RateLimiter.
  Cache/                   OddsCache (transients + index), CacheEntry, Result,
                           OddsRepository (stale-while-revalidate), ProviderState (breaker),
                           RefreshScheduler (WP-Cron handler).
  Settings/                Settings reader, Sanitizer, Markets catalogue.
  Bookmakers/Catalogue.php Canonical bookmaker keys, titles, homepages, per-provider aliases.
  Rest/EditorController.php
  Block/Renderer.php, Block/Registrar.php
  Admin/SettingsPage.php, Admin/Tools.php, Admin/Notices.php
  Support/Logger.php
blocks/comparison/         Block source: block.json, edit.js, view.js, render.php, styles.
build/                     Compiled block (not committed; npm run build).
templates/comparison.php   Table markup, included by Block\Renderer.
data/fixtures/             Sample odds in The Odds API format.
assets/                    Settings screen CSS/JS.
tests/                     PHPUnit + Brain Monkey; fixtures/ holds saved provider responses.
```

## What happens on a page view

```
visitor requests a page with the block
  └─ render.php -> Block\Renderer::render( attributes )
       ├─ attributes re-validated (sport key, event id, market, bookmaker keys, format)
       ├─ OddsRepository::events( sport, configured markets )
       │    ├─ cache fresh?   -> return it                              (0 HTTP requests)
       │    ├─ cache stale?   -> return it, queue odds_compare_refresh  (0 HTTP requests)
       │    ├─ breaker open?  -> return empty/stale                     (0 HTTP requests)
       │    └─ cache cold     -> lock, fetch, store, unlock             (1 request, once)
       ├─ Comparison::build( event, market, selected bookmakers )
       └─ templates/comparison.php
```

Measured on the demo page with two blocks: 0 external requests and 2 database queries during rendering (the transient and the cache index), for both fresh and stale cache.

The editor path is the same with one difference: `Renderer::render()` receives `$in_editor = true` when the request is the editor's `ServerSideRender` preview (detected with `wp_is_serving_rest_request()`), and then provider error messages are shown instead of the neutral visitor message.

The cron path: `odds_compare_refresh( provider, sport, markets )` is a single event; `RefreshScheduler::handle()` builds a repository for that provider and calls `refresh()`. On failure the old entry stays and the error is recorded in `ProviderState`.

## Domain model

`Odds` is immutable and created through `from_decimal()`, `from_fractional()` or `from_american()`; each validates (decimal must be above 1.0, American outside (-100, 100), fractions with a positive denominator) and throws `InvalidArgumentException` otherwise. Output: `to_decimal()`, `to_fractional()`, `to_american()`, `format( OddsFormat )`, `implied_probability()`, `is_better_than()`, `compare()`.

Fractional output is the interesting part. Feeds deliver decimals rounded to two places, so 10/11 arrives as 1.91 and 1/100 as 1.01, and the arithmetic alone cannot recover the intended price (1/67 and 1/99 both round to 1.01). `Fraction::from_float()` therefore tries the traditional UK price ladder first (1/100 … 10/11, evens, 11/10 … 1000/1) and takes the closest ladder price within half a unit of the last decimal place. Off-ladder values, typically exchange prices, fall back to the simplest fraction within the same tolerance, trying "round" denominators first (halves, quarters, fifths, tenths, twentieths) and capped at 20 so that 2.35 reads 47/20 rather than 40/17. One display alias exists: 2/3 is shown as 4/6, as bookmakers do.

`Event` holds a fixture and all its `Quote`s across markets and bookmakers, plus `bookmaker_titles` (key => name as the provider calls it) so newly seen bookmakers can be listed in the admin screen. Both have `to_array()`/`from_array()`; the cache stores arrays, not objects, so renaming a class in a later version cannot leave unserializable transients behind.

`Comparison::build( $event, $market, $bookmaker_keys )` produces the table: columns are outcomes in home/draw/away order (over before under for totals), rows are bookmakers sorted by their price in the first column, and `best( $column )` / `is_best( $quote )` mark the top price per column, ties included. Totals keep one column per side and show the line inside the cell; a column per line would go sparse as soon as bookmakers disagree on it.

## Providers

```php
interface ProviderInterface {
    public function key(): string;          // 'odds_api', used in settings and cache keys
    public function label(): string;
    public function is_configured(): bool;
    /** @return Sport[] */
    public function sports(): array;
    /** @return Event[] */
    public function events( string $sport_key, array $market_keys ): array;
}
```

Failures are reported with `ProviderException`, whose `is_retryable()` is the only thing the cache layer looks at: timeouts, 5xx and 429 are retryable; a bad key, a 403, an unknown league or an exhausted quota are not. The repository records every failure in `ProviderState`; three in a row open the circuit breaker for five minutes.

Bookmaker identity is not the provider's job. Providers emit canonical keys (`williamhill`, `betfair_ex_uk`) that come from the `Catalogue`; for Oddschecker that means mapping its codes (`WH`, `BF`) through the catalogue's aliases, and unknown codes become `oddschecker:<code>`.

### The Odds API

`OddsApiProvider` calls `/v4/sports` (free) and `/v4/sports/{sport}/odds` with `regions=<setting>` and `markets=<selected>`. `OddsApiMapper` turns the JSON into events; it drops exchange *lay* markets (`h2h_lay`), which are offers to lay rather than prices to take, and skips records with missing fields or invalid prices with a log entry rather than failing the whole response. `OddsApiQuota` reads `x-requests-remaining` / `x-requests-used` from every response into the `odds_compare_quota` option, and the provider refuses to make a request that would take the quota below the configured reserve. A 401 is reported as "rejected the API key" without echoing the key or the URL.

### Oddschecker

Oddschecker pages are rendered with Hypernova, which leaves each component's props in the HTML as `<script type="application/json" data-hypernova-key="…"><!-- {json} --></script>`. The visible markup uses hashed class names that change with every deployment; the JSON is typed and stable. `HypernovaExtractor` pulls the blob out by key with a string scan (DOMDocument was tried first and dropped: libxml's HTML parser stops script content at the first `</`, which the JSON contains inside escaped offer descriptions, so the blob came back truncated).

`Parser::league_events()` reads `competitionsaccumulatormatches` from a league page: fixtures with teams, kick-off and URL slug, deduplicated because every fixture appears twice (pre-match and in-play siblings). `Parser::match_quotes()` reads `subeventmarkets` from a match page: the bookmaker code => name map, markets by `marketTypeName` ("Win Market" maps to `h2h`), bets with their line, and `odds[betId][bookmakerCode]` with `oddsDecimal`, status and feed timestamp; only `ACTIVE`, non-expired prices are kept.

`OddscheckerProvider::events()` fetches the league page, then the `/winner` page for the nearest fixtures up to a cap (`odds_compare_oddschecker_max_events`, default 10, so a refresh is at most 11 requests under the 10-per-minute politeness limit). Fixtures beyond the cap are returned without prices. If the limiter says no part-way through, what has been fetched is returned and the next refresh continues. A 403 becomes a non-retryable error whose message explains the Cloudflare situation.

Both parsers are tested against pages saved from a browser on 2026-10-22, in `tests/fixtures/oddschecker/`.

### Sample data

`FixtureProvider` serves `data/fixtures/<sport>.json` (same shape as The Odds API, trimmed to eight fixtures) and shifts kick-off times forward week by week so the sample always looks upcoming. It is the fallback the registry returns when the configured provider does not exist any more, so a removed provider degrades to sample odds instead of a fatal error.

## HTTP layer

`Http\Client::get( $url, $args, $bucket )` wraps `wp_remote_get()` with an 8-second timeout, `sslverify` on, a 2 MB `limit_response_size`, a User-Agent naming the plugin and the site, one retry after 200 ms for retryable failures, and the `odds_compare_http_args` filter for anything else (proxy, headers). `wp_remote_get()` was kept over a library because it already honours `WP_PROXY_*`, picks the available transport and participates in the core `http_*` filters.

`RateLimiter` is a sliding window on a transient per bucket. Limits come from settings (`scraper_rate_limit`, applied to the `oddschecker` bucket) and the `odds_compare_rate_limits` filter; unlisted buckets are unlimited. Its get/set on the transient is not atomic, which is acceptable for a politeness limit: the worst case is one extra request from two overlapping cron runs.

## Cache

`OddsCache` stores `CacheEntry` arrays in transients. Transients were chosen because they are the cache API that works on every host: in `wp_options` by default and in Redis/Memcached automatically when a persistent object cache is present. Each entry's transient lives for the *stale* TTL; *freshness* is judged by `fetched_at` against the fresh TTL, so one entry serves both states.

Because there is no portable way to list transients by prefix (a `LIKE` on `wp_options` finds nothing once an object cache is in play), the cache keeps an index option, `odds_compare_cache_index`, mapping each key to what it holds (provider, sport, markets). *Clear cache* and `uninstall.php` iterate that; *Refresh now* uses the metadata to know what to refresh.

Locks use `wp_cache_add()` when a persistent object cache is available (atomic) and a short transient otherwise (not atomic, but the cost of the race is a duplicate fetch, not corruption). Background refreshes are deduplicated with `wp_next_scheduled()` before scheduling.

Why no periodic cron job: with 500 credits a month, polling a league every five minutes would exhaust the quota in a day. Odds are refreshed only when a page shows them and the cache has gone stale, so cost follows traffic. The quota reserve keeps a few credits for the administrator to test with.

### Quota arithmetic

One refresh costs `markets × regions` credits (regions is always one here). 500 credits a month is roughly 16 a day. One league, one market, a two-hour fresh TTL on a site with traffic around the clock is 12 credits a day; two markets double that. The settings screen shows the cost of the current market selection next to the checkboxes.

## Settings and bookmakers

All settings live in one option, `odds_compare_settings`, read through `Settings` and written only by `Sanitizer` (on save) and `Plugin::activate()` (defaults). `Sanitizer::sanitize()` drops unknown keys, clamps numbers, intersects markets with the known list, validates the provider against the registry, keeps the stored API key when the constant is set, and validates bookmaker links as absolute `http(s)` URLs through `wp_http_validate_url()`, keeping the previous value and recording an error otherwise. Errors surface through `add_settings_error()`.

`Bookmakers\Catalogue` is the shipped list of around thirty UK bookmakers: canonical key, title, homepage and per-provider aliases, generated from a real The Odds API response plus the codes seen on Oddschecker. The `odds_compare_bookmakers` filter can add or alter entries. Bookmakers encountered in provider data but absent from the catalogue are remembered in `odds_compare_seen_bookmakers` (via the `odds_compare_odds_refreshed` action) and listed in the admin screen switched off. Built-in bookmakers are on by default, discovered ones off; the setting stores explicit overrides only.

## REST endpoints

Namespace `odds-compare/v1`, every route behind `current_user_can( 'edit_posts' )`:

| Route | Purpose |
|---|---|
| `GET /sports` | Competitions of the active provider, cached for 12 hours. |
| `GET /events?sport=<key>&markets=<a,b>` | Upcoming events without quotes (ids, names, kick-off, markets, bookmakers present). `sport` must match `[a-z0-9_-]{1,64}` and is validated before sanitization, so `../x` is a 400. |
| `GET /bookmakers` | Catalogue plus discovered bookmakers with their enabled state. |

Provider failures become `WP_Error( 'odds_compare_provider_error', …, [ 'status' => 503 ] )` with a message that points at the settings page. There is no public endpoint: the block is server-rendered, so visitors do not need one, and an open one would let anyone spend the API quota.

## Block

`odds-compare/comparison` is a dynamic block: the post stores attributes only (`sportKey`, `eventId`, `market`, `bookmakers`, `format`, `title`, `highlightBest`, `showUpdated`) and `render.php` produces the HTML on every view from the cache. The editor preview uses `@wordpress/server-side-render`, so there is one implementation of the markup.

An empty `bookmakers` attribute means "every enabled bookmaker", which is why a block keeps working when the administrator enables a new one. The sidebar checklist shows that state as all ticked and stores an explicit list only when something is unticked.

`Block\Renderer` re-validates every attribute (post content is editable and survives imports), asks the repository for the configured market set so that all blocks for a league share one cache entry, builds the `Comparison`, resolves each bookmaker's link (settings override, else catalogue homepage, then the `odds_compare_affiliate_url` filter) and includes `templates/comparison.php`. Links carry `rel="nofollow sponsored noopener"`.

Every cell carries `data-decimal`, `data-fractional` and `data-american`; `view.js` (plain JavaScript, under 1 KB) switches the visible text and remembers the visitor's choice in `localStorage`. There is no odds arithmetic in JavaScript.

Layout: the block defaults to wide alignment (a price table wants the room; the editor can change it) and renders as a card with the price table inside. The card is a CSS container (`container-type: inline-size`); below 640px of *block* width, not viewport width, each row turns into a stacked card with the outcome labels above the prices. That means the stacked layout also kicks in inside a narrow sidebar or a theme with a tight content column, where a media query would not. Colours, radius and shadow are CSS custom properties on the block (`--oc-accent`, `--oc-surface`, …) for themes to override.

The block is registered with `wp_register_block_types_from_metadata_collection()` from the `build/` directory. When `build/` is missing (a git clone before `npm run build`) the block is not registered and administrators see a notice with the command to run.

## Admin screen

*Settings > Odds Compare* is built on the Settings API, which supplies the nonce, the capability check on save and error display. The API key field is a password input, or a locked masked field when the constant is defined. *Clear cache* and *Refresh now* post to `admin-post.php` with their own nonces and `manage_options` checks, then redirect back with a one-time notice. Site-wide notices warn when the quota drops below the reserve or the circuit breaker is open.

## Hooks reference

### Actions

| Hook | Arguments | When |
|---|---|---|
| `odds_compare_loaded` | `Plugin $plugin` | After the plugin registered its hooks. Use this (not `plugins_loaded`) to reach its services. |
| `odds_compare_odds_refreshed` | `string $provider_key, string $sport_key, string[] $market_keys, Event[] $events` | Fresh odds were stored. |
| `odds_compare_provider_failed` | `string $provider_key, string $sport_key, ProviderException $e` | A fetch failed. |
| `odds_compare_cache_purged` | `string[] $keys` | The cache was cleared. |
| `odds_compare_log` | `string $level, string $message, array $context, string $channel` | Every log entry; forward to your own logger. |
| `odds_compare_refresh` | `string $provider_key, string $sport_key, string[] $market_keys` | WP-Cron single event; the plugin handles it. |

### Filters

| Hook | Arguments | Purpose |
|---|---|---|
| `odds_compare_providers` | `ProviderRegistry $registry, Plugin $plugin` | Register additional providers. |
| `odds_compare_bookmakers` | `array $entries` | Add or change catalogue entries (`key => [ title, url, aliases ]`). |
| `odds_compare_markets` | `array $markets` | Market key => label list. |
| `odds_compare_http_args` | `array $args, string $url, string $bucket` | Arguments for `wp_remote_get()`. |
| `odds_compare_rate_limits` | `array $limits` | Requests per minute per provider bucket. |
| `odds_compare_affiliate_url` | `?string $url, Bookmaker $bookmaker, Event $event, array $attrs` | Link for a bookmaker row. |
| `odds_compare_comparison_rows` | `array $rows, Comparison $table, array $attrs` | Reorder or cap the rows. |
| `odds_compare_empty_message` | `string $message` | Visitor-facing text when there are no odds. |
| `odds_compare_oddschecker_leagues` | `array $leagues` | Leagues the Oddschecker provider offers. |
| `odds_compare_oddschecker_max_events` | `int $max, string $sport_key` | Match pages fetched per refresh. |
| `odds_compare_oddschecker_market_types` | `array $types` | Oddschecker market type name => market key. |

## Extending the plugin

### Adding a provider

Implement `ProviderInterface` and register it. The plugin passes its HTTP client and logger for the asking.

```php
use OddsCompare\Domain\{Event, Odds, Quote, Sport};
use OddsCompare\Http\HttpException;
use OddsCompare\Provider\{ProviderException, ProviderInterface};

final class Acme_Odds_Provider implements ProviderInterface {

	public function __construct( private readonly \OddsCompare\Http\Client $client ) {}

	public function key(): string { return 'acme'; }
	public function label(): string { return 'Acme Odds'; }
	public function is_configured(): bool { return defined( 'ACME_ODDS_TOKEN' ); }

	public function sports(): array {
		return array( new Sport( 'acme_epl', 'Premier League', 'Soccer' ) );
	}

	public function events( string $sport_key, array $market_keys ): array {
		try {
			$data = $this->client->get( 'https://api.acme.example/epl?token=' . ACME_ODDS_TOKEN, array(), 'acme' )->json();
		} catch ( HttpException $e ) {
			throw ProviderException::from_http( $e, $this->label() );
		}

		$events = array();
		foreach ( $data['matches'] as $match ) {
			$quotes = array();
			foreach ( $match['prices'] as $price ) {
				$quotes[] = new Quote( $price['bookie'], 'h2h', $price['outcome'], Odds::from_decimal( (float) $price['decimal'] ) );
			}
			$events[] = new Event( (string) $match['id'], $sport_key, 'Premier League', $match['home'], $match['away'], new \DateTimeImmutable( $match['kickoff'] ), $quotes );
		}

		return $events;
	}
}

add_filter( 'odds_compare_providers', function ( $registry, $plugin ) {
	return $registry->register( new Acme_Odds_Provider( $plugin->client() ) );
}, 10, 2 );
```

The new provider appears in the settings screen; its results go through the same cache, breaker and block. Use canonical bookmaker keys from the catalogue (or add aliases, below) so the admin's bookmaker settings apply.

### Adding a bookmaker or an alias

```php
add_filter( 'odds_compare_bookmakers', function ( array $entries ) {
	$entries['acmebet'] = array( 'AcmeBet', 'https://acmebet.example/', array( 'oddschecker' => 'ACM', 'acme' => 'acme-bet' ) );
	return $entries;
} );
```

### Affiliate links with event context

```php
add_filter( 'odds_compare_affiliate_url', function ( $url, $bookmaker, $event ) {
	if ( 'williamhill' === $bookmaker->key && $url ) {
		$url = add_query_arg( 'utm_content', sanitize_title( $event->title() ), $url );
	}
	return $url;
}, 10, 3 );
```

### Fewer rows

```php
add_filter( 'odds_compare_comparison_rows', fn( array $rows ) => array_slice( $rows, 0, 5, true ) );
```

### Forwarding logs

```php
add_action( 'odds_compare_log', function ( $level, $message, $context ) {
	if ( 'error' === $level ) {
		wp_mail( get_option( 'admin_email' ), 'Odds Compare: ' . $message, wp_json_encode( $context ) );
	}
}, 10, 3 );
```

## Security notes

- Settings are saved through the Settings API (nonce, `manage_options`), sanitized by one callback that whitelists keys, clamps numbers and validates URLs. Tools use `check_admin_referer()` and `manage_options`.
- REST routes require `edit_posts`; arguments are validated before sanitization; error payloads never contain the API key or raw upstream responses.
- Block attributes are re-validated at render time; everything in the template goes through `esc_html`/`esc_attr`/`esc_url`; bookmaker links are limited to `http(s)` and carry `rel="nofollow sponsored noopener"`.
- Outbound requests have timeouts, TLS verification, a response size cap and a rate limiter; the plugin never follows user-supplied URLs.
- The API key can live in `wp-config.php`; the settings screen shows at most its last four characters, and the HTTP client redacts `apiKey`, `token` and similar query arguments before logging a failed request.
- No SQL is written by the plugin; it uses options and transients only.

## Testing

```bash
composer test
```

123 unit tests run in well under a second without a WordPress install; Brain Monkey stands in for WordPress functions. What is covered: odds conversion against a table of real bookmaker prices; the comparison table (ordering, best price, filters); the HTTP client (retries, 403 vs 5xx, size cap, rate limiting); The Odds API mapper and provider against a saved response (quota, key rejection, invalid JSON); the Oddschecker extractor, parser and provider against saved pages (deduplication, canonical keys, rate-limit stop, Cloudflare 403); the cache repository in every state (fresh, stale, cold, locked, failing, breaker open, purge, corrupt transient); settings sanitization; the bookmaker catalogue; the REST controller; the block renderer (markup, best price, affiliate filter, attribute garbage, editor vs visitor messages).

Not covered by automation: the editor UI itself and the admin screen's forms. Those were checked by hand on the test site.

## Limitations and ideas

- Oddschecker: match-result market only, and only from networks Cloudflare lets through. A headless browser would get further and was deliberately not done.
- The Odds API free tier is the real constraint on how "live" the odds are. A paid plan or a second provider for a different sport would change the defaults, not the code.
- Possible next steps: logos in the bookmaker catalogue; an "auto-refresh on the front end" option (REST endpoint plus polling, behind a filter, off by default because of quota); a WP-CLI command to warm the cache; a per-block "show N best bookmakers only" attribute; integration tests on the WordPress test suite.
