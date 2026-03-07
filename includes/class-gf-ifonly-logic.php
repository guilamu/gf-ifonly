<?php
/**
 * Server-side conditional logic evaluator for IfOnly grouped rules.
 *
 * @package GF_IfOnly
 * @license AGPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GF_IfOnly_Logic {

	/**
	 * Evaluate IfOnly logic against an entry (or submitted values).
	 *
	 * Groups are OR'd. Rules within a group are AND'd.
	 *
	 * @param array $ifonly Logic configuration with 'actionType' and 'groups'.
	 * @param array $entry  Entry or current lead values.
	 * @param array $form   The form object.
	 * @return bool True if logic conditions are met.
	 */
	public static function evaluate( array $ifonly, array $entry, array $form ): bool {
		if ( empty( $ifonly['groups'] ) ) {
			return false;
		}

		// OR across groups.
		foreach ( $ifonly['groups'] as $group ) {
			if ( self::evaluate_group( $group, $entry, $form ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Evaluate a single group — all rules must pass (AND).
	 */
	private static function evaluate_group( array $group, array $entry, array $form ): bool {
		if ( empty( $group['rules'] ) ) {
			return false;
		}

		foreach ( $group['rules'] as $rule ) {
			if ( ! self::evaluate_rule( $rule, $entry, $form ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Evaluate a single rule against the entry.
	 */
	private static function evaluate_rule( array $rule, array $entry, array $form ): bool {
		$field_id = $rule['fieldId'] ?? '';
		$operator = $rule['operator'] ?? 'is';
		$target   = $rule['value'] ?? '';

		$field_value = self::get_field_value( $field_id, $entry, $form );

		return self::compare( $field_value, $operator, $target );
	}

	/**
	 * Get the value of a field from entry data.
	 *
	 * Handles multi-input fields (e.g., name, address sub-fields like 1.3, 1.6)
	 * and checkbox fields where values are stored across multiple inputs.
	 */
	private static function get_field_value( string $field_id, array $entry, array $form ): string {
		// Direct match (simple field or specific sub-input like "5.3").
		if ( isset( $entry[ $field_id ] ) ) {
			return (string) $entry[ $field_id ];
		}

		// For checkbox fields, try to get the combined value.
		$base_id = (int) $field_id;
		$field   = GFAPI::get_field( $form, $base_id );

		if ( $field && 'checkbox' === $field->type && is_array( $field->inputs ) ) {
			$values = array();
			foreach ( $field->inputs as $input ) {
				$val = rgar( $entry, (string) $input['id'] );
				if ( ! rgblank( $val ) ) {
					$values[] = $val;
				}
			}
			return implode( ', ', $values );
		}

		// Try posted value via rgpost.
		$input_name = 'input_' . str_replace( '.', '_', $field_id );
		$posted     = rgpost( $input_name );
		if ( is_array( $posted ) ) {
			return implode( ', ', array_filter( $posted ) );
		}

		return (string) $posted;
	}

	/**
	 * Compare a field value against a target using the given operator.
	 */
	private static function compare( string $field_value, string $operator, string $target ): bool {
		$field_lower  = mb_strtolower( $field_value, 'UTF-8' );
		$target_lower = mb_strtolower( $target, 'UTF-8' );

		return match ( $operator ) {
			'is'                => $field_lower === $target_lower,
			'isnot'             => $field_lower !== $target_lower,
			'>'                 => (float) $field_value > (float) $target,
			'<'                 => (float) $field_value < (float) $target,
			'contains'          => '' !== $target && str_contains( $field_lower, $target_lower ),
			'does_not_contain'  => '' === $target || ! str_contains( $field_lower, $target_lower ),
			'starts_with'       => '' !== $target && str_starts_with( $field_lower, $target_lower ),
			'ends_with'         => '' !== $target && str_ends_with( $field_lower, $target_lower ),
			'in_csv'            => self::in_csv( $field_lower, $target_lower ),
			'not_in_csv'        => ! self::in_csv( $field_lower, $target_lower ),
			default             => false,
		};
	}

	/**
	 * Check if a value matches any item in a comma-separated list.
	 */
	private static function in_csv( string $value, string $csv ): bool {
		$items = array_map( 'trim', explode( ',', $csv ) );
		foreach ( $items as $item ) {
			if ( $item === $value ) {
				return true;
			}
		}
		return false;
	}
}
