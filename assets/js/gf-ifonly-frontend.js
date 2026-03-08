/**
 * Gravity Forms IfOnly — Frontend Logic Processor.
 *
 * Delegates per-rule evaluation to GF's native gf_get_field_action()
 * and adds the OR-of-groups layer on top. Only the "does_not_contain"
 * operator is handled directly, as GF has no native equivalent.
 *
 * Based on the pattern from GravityWiz's Advanced Conditional Logic snippet
 * by David Smith (@spivurno).
 *
 * @package GF_IfOnly
 * @license AGPL-3.0-or-later
 */
( function( $ ) {
	'use strict';

	// TODO: migration — existing saved rules with in_csv / not_in_csv operators
	// will silently fail after this version. A future update may add a migration
	// path to convert them into multiple OR groups.

	/**
	 * Frontend logic controller.
	 *
	 * @param {Object} args - { formId: number, logic: { fieldId: { enabled, actionType, groups } } }
	 */
	window.GFIfOnlyFrontend = function( args ) {
		var self = this;

		self.formId     = args.formId;
		self.logic      = args.logic || {};
		self.evaluating = false;

		self.init = function() {
			gform.addFilter( 'gform_is_value_match', function( isMatch, formId, rule ) {
				if ( parseInt( formId, 10 ) !== parseInt( self.formId, 10 ) ) {
					return isMatch;
				}

				// 1. Handle "does_not_contain" operator — GF has no native equivalent.
				//    This fires naturally when gf_get_field_action evaluates group rules.
				if ( rule.operator === 'does_not_contain' ) {
					var fieldValue = '';
					var $field     = $( '#input_' + formId + '_' + rule.fieldId );
					var $inputs    = $field.find( 'input, select, textarea' );

					if ( $inputs.is( ':checkbox' ) || $inputs.is( ':radio' ) ) {
						fieldValue = $inputs.filter( ':checked' ).map( function() {
							return this.value;
						} ).get().join( ',' );
					} else if ( $inputs.is( 'select[multiple]' ) ) {
						fieldValue = $inputs.val() ? $inputs.val().join( ',' ) : '';
					} else {
						fieldValue = $field.val() || '';
					}

					return typeof fieldValue === 'string' && fieldValue.indexOf( rule.value ) === -1;
				}

				// 2. Passthrough rules — always true so GF binds change listeners.
				if ( rule.value === '__ifonly_passthrough' ) {
					return true;
				}

				// 3. Trigger rule — OR-of-groups evaluation entry point.
				if ( rule.value !== '__ifonly_trigger' ) {
					return isMatch;
				}

				// Prevent re-entrant evaluation.
				if ( self.evaluating ) {
					return isMatch;
				}

				var fieldId    = String( rule.fieldId );
				var fieldLogic = self.logic[ fieldId ];

				if ( ! fieldLogic || ! fieldLogic.groups || fieldLogic.groups.length === 0 ) {
					return isMatch;
				}

				self.evaluating = true;

				// OR across groups — delegate AND evaluation to GF's native engine.
				var result = false;
				for ( var g = 0; g < fieldLogic.groups.length; g++ ) {
					var gfGroupLogic = {
						actionType: 'show',
						logicType:  'all',
						rules:      fieldLogic.groups[ g ].rules
					};
					if ( gf_get_field_action( formId, gfGroupLogic ) === 'show' ) {
						result = true;
						break;
					}
				}

				self.evaluating = false;

				return result;
			} );
		};

		// Initialize.
		self.init();

		// Re-evaluate all IfOnly fields now that our filter is active.
		// GF's init script runs gf_apply_rules() before our filter is registered,
		// so fields may have the wrong visibility state.
		var fieldIds = [];
		for ( var fid in self.logic ) {
			if ( self.logic.hasOwnProperty( fid ) ) {
				fieldIds.push( parseInt( fid, 10 ) );
			}
		}
		if ( fieldIds.length > 0 && typeof gf_apply_rules === 'function' ) {
			gf_apply_rules( self.formId, fieldIds );
		}
	};

} )( jQuery );
