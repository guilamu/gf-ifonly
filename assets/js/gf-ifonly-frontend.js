/**
 * Gravity Forms IfOnly — Frontend Logic Processor.
 *
 * Intercepts GF's conditional logic evaluation and replaces it with
 * grouped AND/OR logic for fields that have IfOnly configuration.
 *
 * @package GF_IfOnly
 * @license AGPL-3.0-or-later
 */
( function( $ ) {
	'use strict';

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
			// Intercept GF's value matching to control show/hide for IfOnly fields.
			gform.addFilter( 'gform_is_value_match', function( isMatch, formId, rule ) {
				if ( parseInt( formId, 10 ) !== parseInt( self.formId, 10 ) ) {
					return isMatch;
				}

				// Passthrough rules — always return true so GF binds change listeners.
				if ( rule.value === '__ifonly_passthrough' ) {
					return true;
				}

				// Trigger rule — this is the main logic entry point.
				if ( rule.value !== '__ifonly_trigger' ) {
					return isMatch;
				}

				// Prevent re-entrant evaluation.
				if ( self.evaluating ) {
					return isMatch;
				}

				var fieldId = String( rule.fieldId );
				var fieldLogic = self.logic[ fieldId ];

				if ( ! fieldLogic ) {
					return isMatch;
				}

				self.evaluating = true;
				var result = self.evaluateLogic( fieldLogic );
				self.evaluating = false;

				return result;
			} );
		};

		/**
		 * Evaluate grouped logic.
		 *
		 * @param {Object} ifonly - { actionType, groups: [ { rules: [...] } ] }
		 * @return {boolean} True if the field should be shown (when actionType=show)
		 *                   or hidden (when actionType=hide).
		 */
		self.evaluateLogic = function( ifonly ) {
			if ( ! ifonly.groups || ifonly.groups.length === 0 ) {
				return false;
			}

			// OR across groups.
			for ( var g = 0; g < ifonly.groups.length; g++ ) {
				if ( self.evaluateGroup( ifonly.groups[ g ] ) ) {
					return true;
				}
			}

			return false;
		};

		/**
		 * Evaluate a single group — all rules must match (AND).
		 */
		self.evaluateGroup = function( group ) {
			if ( ! group.rules || group.rules.length === 0 ) {
				return false;
			}

			for ( var r = 0; r < group.rules.length; r++ ) {
				if ( ! self.evaluateRule( group.rules[ r ] ) ) {
					return false;
				}
			}

			return true;
		};

		/**
		 * Evaluate a single rule against the current DOM values.
		 */
		self.evaluateRule = function( rule ) {
			var fieldValue = self.getFieldValue( rule.fieldId );
			return self.compare( fieldValue, rule.operator, rule.value );
		};

		/**
		 * Get the current value of a field from the DOM.
		 */
		self.getFieldValue = function( fieldId ) {
			var formId = self.formId;

			// Check for sub-input (e.g., "5.3").
			var parts    = String( fieldId ).split( '.' );
			var baseId   = parts[ 0 ];
			var inputId  = parts.length > 1 ? fieldId : null;

			if ( inputId ) {
				// Specific sub-input.
				var subInputName = 'input_' + baseId + '.' + parts[ 1 ];
				var el = document.getElementById( 'input_' + formId + '_' + baseId + '_' + parts[ 1 ] );
				if ( el ) {
					return el.value || '';
				}
				// Fallback to name-based lookup.
				var byName = document.querySelector( '#gform_wrapper_' + formId + ' [name="' + subInputName + '"]' );
				return byName ? ( byName.value || '' ) : '';
			}

			// Try standard input element.
			var input = document.getElementById( 'input_' + formId + '_' + baseId );
			if ( input ) {
				var tag = input.tagName.toLowerCase();
				// Only read .value from actual form elements (input, select, textarea).
				// Radio/checkbox fields use a <ul> container with this ID — skip it.
				if ( tag === 'input' || tag === 'select' || tag === 'textarea' ) {
					if ( input.type === 'checkbox' ) {
						return input.checked ? input.value : '';
					}
					return input.value || '';
				}
			}

			// Radio buttons.
			var radios = document.querySelectorAll( '#gform_wrapper_' + formId + ' input[name="input_' + baseId + '"]' );
			if ( radios.length > 0 ) {
				for ( var i = 0; i < radios.length; i++ ) {
					if ( radios[ i ].checked ) {
						return radios[ i ].value;
					}
				}
				return '';
			}

			// Checkboxes — collect all checked values.
			var checkboxes = document.querySelectorAll( '#gform_wrapper_' + formId + ' input[name^="input_' + baseId + '."]' );
			if ( checkboxes.length > 0 ) {
				var vals = [];
				for ( var j = 0; j < checkboxes.length; j++ ) {
					if ( checkboxes[ j ].checked ) {
						vals.push( checkboxes[ j ].value );
					}
				}
				return vals.join( ', ' );
			}

			// Select element.
			var select = document.querySelector( '#gform_wrapper_' + formId + ' select[name="input_' + baseId + '"]' );
			if ( select ) {
				return select.value || '';
			}

			// Textarea.
			var textarea = document.querySelector( '#gform_wrapper_' + formId + ' textarea[name="input_' + baseId + '"]' );
			if ( textarea ) {
				return textarea.value || '';
			}

			return '';
		};

		/**
		 * Compare a field value against a target with a given operator.
		 */
		self.compare = function( fieldValue, operator, target ) {
			var fLower = ( fieldValue || '' ).toLowerCase();
			var tLower = ( target || '' ).toLowerCase();

			switch ( operator ) {
				case 'is':
					return fLower === tLower;
				case 'isnot':
					return fLower !== tLower;
				case '>':
					return parseFloat( fieldValue ) > parseFloat( target );
				case '<':
					return parseFloat( fieldValue ) < parseFloat( target );
				case 'contains':
					return target !== '' && fLower.indexOf( tLower ) !== -1;
				case 'does_not_contain':
					return target === '' || fLower.indexOf( tLower ) === -1;
				case 'starts_with':
					return target !== '' && fLower.indexOf( tLower ) === 0;
				case 'ends_with':
					return target !== '' && fLower.indexOf( tLower, fLower.length - tLower.length ) !== -1;
				case 'in_csv':
					return self.inCSV( fLower, tLower );
				case 'not_in_csv':
					return ! self.inCSV( fLower, tLower );
				default:
					return false;
			}
		};

		/**
		 * Check if value matches any item in a CSV string.
		 */
		self.inCSV = function( value, csv ) {
			var items = csv.split( ',' );
			for ( var i = 0; i < items.length; i++ ) {
				if ( items[ i ].trim() === value ) {
					return true;
				}
			}
			return false;
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
