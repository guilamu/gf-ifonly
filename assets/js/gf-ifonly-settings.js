/**
 * Gravity Forms IfOnly — Settings Page UI (Notifications & Confirmations).
 *
 * Renders IfOnly grouped logic (AND within groups, OR between groups)
 * inline on notification/confirmation edit pages, matching the old-school
 * CL pattern used by form_admin.js (no flyout).
 *
 * Reuses the same view templates as the form editor.
 *
 * @package GF_IfOnly
 * @license AGPL-3.0-or-later
 */
( function( $ ) {
	'use strict';

	// Bail if config wasn't output (we're not on a notification/confirmation edit page).
	if ( typeof gfIfOnlySettingsConfig === 'undefined' ) {
		return;
	}

	var views   = gfIfOnlySettingsConfig.views;
	var strings = gfIfOnlySettingsConfig.strings;
	var objectType = gfIfOnlySettingsConfig.objectType;

	// Current IfOnly state for this object.
	var state = gfIfOnlySettingsConfig.ifonlyLogic || getDefaultState();

	// Operators.
	var OPERATORS = {
		'is':                strings.is || 'is',
		'isnot':             strings.isNot || 'is not',
		'>':                 strings.greaterThan || 'greater than',
		'<':                 strings.lessThan || 'less than',
		'contains':          strings.contains || 'contains',
		'does_not_contain':  strings.doesNotContain || 'does NOT contain',
		'starts_with':       strings.startsWith || 'starts with',
		'ends_with':         strings.endsWith || 'ends with',
		'in_csv':            strings.inCsv || 'in CSV list',
		'not_in_csv':        strings.notInCsv || 'not in CSV list',
	};

	var TEXT_OPERATORS = [ 'contains', 'does_not_contain', 'starts_with', 'ends_with', 'in_csv', 'not_in_csv', '>', '<' ];

	// =====================================================================
	// Template engine (same as form editor)
	// =====================================================================

	function renderView( html, container, config, echo ) {
		var parsed = html;
		for ( var key in config ) {
			if ( ! config.hasOwnProperty( key ) ) {
				continue;
			}
			parsed = parsed.replace( new RegExp( '\\{\\{\\s*' + key + '\\s*\\}\\}', 'g' ), config[ key ] );
		}
		if ( ! echo ) {
			return parsed;
		}
		if ( container ) {
			container.innerHTML = parsed;
		}
		return true;
	}

	function esc( str ) {
		var el = document.createElement( 'span' );
		el.textContent = str;
		return el.innerHTML;
	}

	// =====================================================================
	// Default state
	// =====================================================================

	function getDefaultRule() {
		var fieldId = typeof GetFirstRuleField === 'function' ? GetFirstRuleField() : '';
		return { fieldId: fieldId, operator: 'is', value: '' };
	}

	function getDefaultState() {
		return {
			enabled: false,
			actionType: 'show',
			groups: [ { rules: [ getDefaultRule() ] } ],
		};
	}

	// =====================================================================
	// Render
	// =====================================================================

	function renderAll() {
		var container = document.getElementById( 'ifonly_settings_container' );
		if ( ! container ) {
			return;
		}

		// Render main controls.
		var mainConfig = {
			enabledClass: state.enabled ? 'active' : '',
			showSelected: state.actionType === 'show' ? 'selected="selected"' : '',
			hideSelected: state.actionType === 'hide' ? 'selected="selected"' : '',
			showText:     strings.show,
			hideText:     strings.hide,
			thisFieldIf:  strings.thisFieldIf,
			allMatch:     strings.allMatch,
			addGroupText: strings.addGroup,
		};

		renderView( views.main, container, mainConfig, true );
		renderGroups();
	}

	function renderGroups() {
		var container = document.querySelector( '#ifonly_settings_container .ifonly_flyout__groups' );
		if ( ! container ) {
			return;
		}

		var html = '';
		for ( var g = 0; g < state.groups.length; g++ ) {
			if ( g > 0 ) {
				html += '<div class="ifonly_or-separator"><span>' + esc( strings.or ) + '</span></div>';
			}
			var groupConfig = { group_idx: g, andLabel: strings.and };
			html += renderView( views.group, null, groupConfig, false );
		}

		container.innerHTML = html;

		// Render rules into each group.
		for ( var g = 0; g < state.groups.length; g++ ) {
			renderRulesForGroup( g );
		}
	}

	function renderRulesForGroup( groupIdx ) {
		var groupEl = document.querySelector( '#ifonly_settings_container [data-ifonly-group="' + groupIdx + '"] .ifonly_group__rules' );
		if ( ! groupEl ) {
			return;
		}

		var group = state.groups[ groupIdx ];
		var html  = '';

		for ( var r = 0; r < group.rules.length; r++ ) {
			html += renderRule( group.rules[ r ], r, groupIdx );
		}

		groupEl.innerHTML = html;
	}

	function renderRule( rule, ruleIdx, groupIdx ) {
		var config = {
			rule_idx:        ruleIdx,
			fieldOptions:    renderFieldOptions( rule ),
			operatorOptions: renderOperatorOptions( rule ),
			valueMarkup:     renderRuleValue( rule, ruleIdx ),
			deleteClass:     state.groups[ groupIdx ].rules.length > 1 ? 'active' : '',
			addRuleText:     strings.addRule,
			removeRuleText:  strings.removeRule,
		};
		return renderView( views.rule, null, config, false );
	}

	function renderFieldOptions( rule ) {
		var html     = '';
		var template = views.option;

		if ( typeof form === 'undefined' || ! form.fields ) {
			return html;
		}

		for ( var i = 0; i < form.fields.length; i++ ) {
			var field = form.fields[ i ];

			if ( typeof IsConditionalLogicField !== 'function' || ! IsConditionalLogicField( field ) ) {
				continue;
			}

			var inputType = typeof GetInputType === 'function' ? GetInputType( field ) : ( field.inputType || field.type );

			if ( field.inputs && [ 'checkbox', 'email', 'consent', 'radio' ].indexOf( inputType ) === -1 ) {
				for ( var j = 0; j < field.inputs.length; j++ ) {
					var input = field.inputs[ j ];
					if ( input.isHidden ) {
						continue;
					}
					var config = {
						label:    esc( typeof GetLabel === 'function' ? GetLabel( field, input.id ) : ( field.label + ' (' + input.label + ')' ) ),
						value:    esc( String( input.id ) ),
						selected: input.id == rule.fieldId ? 'selected="selected"' : '',
					};
					html += renderView( template, null, config, false );
				}
			} else {
				var config = {
					label:    esc( typeof GetLabel === 'function' ? GetLabel( field ) : field.label ),
					value:    esc( String( field.id ) ),
					selected: ( parseInt( field.id, 10 ) === parseInt( rule.fieldId, 10 ) ) ? 'selected="selected"' : '',
				};
				html += renderView( template, null, config, false );
			}
		}
		return html;
	}

	function renderOperatorOptions( rule ) {
		var html     = '';
		var template = views.option;

		for ( var key in OPERATORS ) {
			if ( ! OPERATORS.hasOwnProperty( key ) ) {
				continue;
			}
			var config = {
				label:    esc( OPERATORS[ key ] ),
				value:    esc( key ),
				selected: key === rule.operator ? 'selected="selected"' : '',
			};
			html += renderView( template, null, config, false );
		}
		return html;
	}

	function renderRuleValue( rule ) {
		var isTextOp = TEXT_OPERATORS.indexOf( rule.operator ) !== -1;

		if ( isTextOp ) {
			return renderView( views.input, null, {
				value:       esc( rule.value || '' ),
				placeholder: esc( strings.enterValue ),
			}, false );
		}

		var field = getFieldById( rule.fieldId );
		if ( field && field.choices && [ 'is', 'isnot' ].indexOf( rule.operator ) !== -1 ) {
			return renderSelectValue( rule, field );
		}

		return renderView( views.input, null, {
			value:       esc( rule.value || '' ),
			placeholder: esc( strings.enterValue ),
		}, false );
	}

	function renderSelectValue( rule, field ) {
		var options = [];
		if ( field.placeholder ) {
			options.push( { text: field.placeholder, value: '' } );
		}
		for ( var i = 0; i < field.choices.length; i++ ) {
			options.push( field.choices[ i ] );
		}

		var optionsHtml = '';
		var template    = views.option;
		for ( var i = 0; i < options.length; i++ ) {
			var choice = options[ i ];
			var val    = typeof choice.value !== 'undefined' ? String( choice.value ) : String( choice.text );
			optionsHtml += renderView( template, null, {
				label:    esc( choice.text || val ),
				value:    esc( val ),
				selected: val == rule.value ? 'selected="selected"' : '',
			}, false );
		}

		return renderView( views.select, null, { fieldValueOptions: optionsHtml }, false );
	}

	function getFieldById( fieldId ) {
		if ( typeof GetFieldById === 'function' ) {
			return GetFieldById( fieldId );
		}
		if ( typeof form === 'undefined' || ! form.fields ) {
			return null;
		}
		var id = parseInt( fieldId, 10 );
		for ( var i = 0; i < form.fields.length; i++ ) {
			if ( form.fields[ i ].id == id ) {
				return form.fields[ i ];
			}
		}
		return null;
	}

	// =====================================================================
	// Serialize state to hidden field
	// =====================================================================

	function serializeState() {
		var hidden = document.getElementById( 'ifonly_logic_object' );
		if ( hidden ) {
			hidden.value = JSON.stringify( state );
		}
	}

	// =====================================================================
	// Event handling
	// =====================================================================

	$( document ).ready( function() {
		var $container = $( '#ifonly_settings_container' );
		var $checkbox  = $( '#ifonly_logic_enabled' );

		// Ensure state has proper defaults.
		if ( ! state.groups || ! state.groups.length ) {
			state = getDefaultState();
		}
		if ( ! ( 'enabled' in state ) ) {
			state.enabled = false;
		}

		// Toggle container visibility.
		$checkbox.on( 'change', function() {
			state.enabled = this.checked;
			if ( state.enabled ) {
				$container.show();
				renderAll();
			} else {
				$container.hide();
			}
			serializeState();
		} );

		// If already enabled, render immediately.
		if ( state.enabled ) {
			renderAll();
		}

		// Delegate events inside the container.
		$container.on( 'change', '[data-js-ifonly-state]', function() {
			var key = $( this ).data( 'js-ifonly-state' );
			state[ key ] = this.value;
			serializeState();
		} );

		$container.on( 'change', '[data-js-ifonly-rule]', function() {
			var $rule  = $( this ).closest( '[data-ifonly-rule]' );
			var $group = $( this ).closest( '[data-ifonly-group]' );
			if ( ! $rule.length || ! $group.length ) {
				return;
			}

			var gi  = parseInt( $group.data( 'ifonly-group' ), 10 );
			var ri  = parseInt( $rule.data( 'ifonly-rule' ), 10 );
			var key = $( this ).data( 'js-ifonly-rule' );

			state.groups[ gi ].rules[ ri ][ key ] = this.value;

			if ( key === 'fieldId' || key === 'operator' ) {
				renderGroups();
			}
			serializeState();
		} );

		$container.on( 'input', '[data-js-ifonly-rule="value"]', function() {
			var $rule  = $( this ).closest( '[data-ifonly-rule]' );
			var $group = $( this ).closest( '[data-ifonly-group]' );
			if ( ! $rule.length || ! $group.length ) {
				return;
			}
			var gi = parseInt( $group.data( 'ifonly-group' ), 10 );
			var ri = parseInt( $rule.data( 'ifonly-rule' ), 10 );
			state.groups[ gi ].rules[ ri ].value = this.value;
			serializeState();
		} );

		$container.on( 'click', '[data-js-ifonly-add-rule]', function() {
			var $group = $( this ).closest( '[data-ifonly-group]' );
			if ( $group.length ) {
				var gi = parseInt( $group.data( 'ifonly-group' ), 10 );
				state.groups[ gi ].rules.push( getDefaultRule() );
				renderGroups();
				serializeState();
			}
		} );

		$container.on( 'click', '[data-js-ifonly-delete-rule]', function() {
			var $rule  = $( this ).closest( '[data-ifonly-rule]' );
			var $group = $( this ).closest( '[data-ifonly-group]' );
			if ( ! $rule.length || ! $group.length ) {
				return;
			}
			var gi = parseInt( $group.data( 'ifonly-group' ), 10 );
			var ri = parseInt( $rule.data( 'ifonly-rule' ), 10 );

			state.groups[ gi ].rules.splice( ri, 1 );

			if ( state.groups[ gi ].rules.length === 0 ) {
				state.groups.splice( gi, 1 );
			}
			if ( state.groups.length === 0 ) {
				state.groups.push( { rules: [ getDefaultRule() ] } );
			}

			renderGroups();
			serializeState();
		} );

		$container.on( 'click', '[data-js-ifonly-add-group]', function() {
			state.groups.push( { rules: [ getDefaultRule() ] } );
			renderGroups();
			serializeState();
		} );

		// Serialize state on parent form submit.
		$container.closest( 'form' ).on( 'submit', function() {
			serializeState();
		} );

		// Initial serialize to ensure hidden field has correct data.
		serializeState();
	} );

} )( jQuery );
