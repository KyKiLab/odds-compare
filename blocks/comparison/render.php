<?php
/**
 * Server render for odds-compare/comparison. WordPress provides $attributes, $content and $block.
 *
 * A REST request here means the editor's ServerSideRender preview, which may see provider
 * error details that visitors should not.
 *
 * @var array<string, mixed> $attributes
 *
 * @package OddsCompare
 */

echo OddsCompare\Plugin::instance()->block_renderer()->render( (array) $attributes, wp_is_serving_rest_request() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The renderer escapes everything as it builds the markup.
