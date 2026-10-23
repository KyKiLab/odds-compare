<?php
/**
 * Supported odds display formats.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Domain;

/**
 * Decimal (2.50), fractional (3/2) or American (+150).
 *
 * The string values double as the option/attribute values stored by WordPress,
 * so from_string() is the only place that needs to validate user input.
 */
enum OddsFormat: string {

	case Decimal    = 'decimal';
	case Fractional = 'fractional';
	case American   = 'american';

	/**
	 * Parses a stored or submitted value, falling back instead of throwing.
	 *
	 * Block attributes and options can hold anything after a bad import or a
	 * manual edit; a wrong format must never break the render.
	 *
	 * @param string|null $value    Raw value.
	 * @param OddsFormat  $fallback Format to use when the value is unknown.
	 */
	public static function from_string( ?string $value, OddsFormat $fallback = self::Decimal ): self {
		return self::tryFrom( strtolower( trim( (string) $value ) ) ) ?? $fallback;
	}

	/**
	 * Human readable name for selects and toggles.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Decimal    => __( 'Decimal', 'odds-compare-kate' ),
			self::Fractional => __( 'Fractional', 'odds-compare-kate' ),
			self::American   => __( 'American', 'odds-compare-kate' ),
		};
	}

	/**
	 * Value => label map for option fields.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		$labels = array();
		foreach ( self::cases() as $format ) {
			$labels[ $format->value ] = $format->label();
		}

		return $labels;
	}
}
