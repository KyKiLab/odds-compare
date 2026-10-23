# Changelog

## 1.0.0 (2026-10-24)

First release.

- Providers: The Odds API, Oddschecker page reader, bundled sample data. Registry open to other plugins through `odds_compare_providers`.
- Odds value object with decimal/fractional/American conversion and the traditional UK price ladder.
- Transient cache with stale-while-revalidate, request lock, circuit breaker and API quota accounting.
- Settings screen: provider, key, region, markets, default format, bookmakers with affiliate links, cache intervals, status, cache tools.
- Gutenberg block with event picker, per-block bookmaker selection and format, server-side rendering, client-side format switch.
- Editor REST endpoints behind `edit_posts`.
