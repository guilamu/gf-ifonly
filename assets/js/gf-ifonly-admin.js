/**
 * Gravity Forms IfOnly — Admin (Form Editor) UI.
 *
 * Follows the same patterns as GF's native GFConditionalLogic flyout:
 * - renderView() template engine with {{ token }} replacement
 * - data-js-* attributes for event delegation
 * - Reuses native GF helpers: IsConditionalLogicField(), GetLabel(), GetInputType()
 * - Reuses native GF CSS classes for the flyout, accordion, rules, buttons
 *
 * @package GF_IfOnly
 * @license AGPL-3.0-or-later
 */
( function( $ ) {
	'use strict';

	/**
	 * Boot function — called by the inline script in editor_js_init()
	 * after gfIfOnlyConfig has been defined.
	 */
	window.gfIfOnlyBoot = function() {
		if ( typeof gfIfOnlyConfig === 'undefined' ) {
			return;
		}

	var views   = gfIfOnlyConfig.views;
	var strings = gfIfOnlyConfig.strings;

	// Operators supported by IfOnly (superset of GF native).
	var OPERATORS = {
		'is':                strings.is || 'is',
		'isnot':             strings.isNot || 'is not',
		'>':                 strings.greaterThan || 'greater than',
		'<':                 strings.lessThan || 'less than',
		'contains':          strings.contains || 'contains',
		'does_not_contain':  strings.doesNotContain || 'does NOT contain',
		'starts_with':       strings.startsWith || 'starts with',
		'ends_with':         strings.endsWith || 'ends with',
	};

	// Operators that require a text input instead of a value dropdown.
	var TEXT_OPERATORS = [ 'contains', 'does_not_contain', 'starts_with', 'ends_with', '>', '<' ];

	// =====================================================================
	// renderView — mirrors GF's renderView() template engine
	// =====================================================================

	function renderView( html, container, config, echo ) {
		var parsed = html;
		for ( var key in config ) {
			if ( ! config.hasOwnProperty( key ) ) {
				continue;
			}
			var searchRgx = new RegExp( '\\{\\{\\s*' + key + '\\s*\\}\\}', 'g' );
			parsed = parsed.replace( searchRgx, config[ key ] );
		}

		if ( ! echo ) {
			return parsed;
		}

		if ( container ) {
			container.innerHTML = parsed;
		}

		return true;
	}

	/**
	 * Escape HTML entities for safe insertion.
	 */
	function esc( str ) {
		var el = document.createElement( 'span' );
		el.textContent = str;
		return el.innerHTML;
	}

	// =====================================================================
	// GFIfOnlyFlyout — main controller (prototype-based, like GFConditionalLogic)
	// =====================================================================

	function GFIfOnlyFlyout() {
		this.fieldId            = null;
		this.field              = null;
		this.form               = null;
		this.visible            = false;
		this.state              = null;
		this._nativeCLInstance  = null;

		this.els = {
			sidebar: document.getElementById( 'ifonly-sidebar-field' ),
			flyout:  document.getElementById( 'ifonly_flyout_container' ),
		};

		// Relocate flyout container to the correct DOM position.
		// It is output by gform_editor_js (inside gform_editor_js_action_output_wrapper)
		// but GF's native flyout containers live inside the sidebar__panel,
		// as siblings of the settings wrapper. The CSS positioning depends on this.
		this.relocateFlyout();

		this._handleSidebarClick = this.handleSidebarClick.bind( this );
		this._handleFlyoutClick  = this.handleFlyoutClick.bind( this );
		this._handleFlyoutChange = this.handleFlyoutChange.bind( this );
		this._handleFlyoutInput  = this.handleFlyoutInput.bind( this );
		this._handleBodyClick    = this.handleBodyClick.bind( this );
		this._handleKeydown      = this.handleKeydown.bind( this );

		this.addGlobalListeners();
	}

	// ------------------------------------------------------------------
	// Initialisation
	// ------------------------------------------------------------------

	/**
	 * Move the flyout container from gform_editor_js_action_output_wrapper
	 * to the sidebar panel, next to GF's native flyout containers.
	 * Also move the sidebar setting out of the native conditional_logic_wrapper
	 * so it sits as its own section (like Perks) — avoids double-border gap.
	 */
	GFIfOnlyFlyout.prototype.relocateFlyout = function() {
		// Relocate sidebar setting: move #ifonly_field_setting right after the native CL wrapper.
		var settingEl = document.getElementById( 'ifonly_field_setting' );
		var nativeWrapper = settingEl && settingEl.closest( '.conditional_logic_wrapper:not(#ifonly_cl_wrapper)' );
		if ( nativeWrapper && nativeWrapper.parentNode ) {
			nativeWrapper.parentNode.insertBefore( settingEl, nativeWrapper.nextSibling );
		}

		// Relocate flyout container.
		if ( ! this.els.flyout ) {
			return;
		}

		var nativeContainer = document.getElementById( 'conditional_logic_flyout_container' );
		if ( nativeContainer && nativeContainer.parentNode ) {
			nativeContainer.parentNode.insertBefore( this.els.flyout, nativeContainer );
		}
	};

	GFIfOnlyFlyout.prototype.addGlobalListeners = function() {
		if ( this.els.sidebar ) {
			this.els.sidebar.addEventListener( 'click', this._handleSidebarClick );
		}
		if ( this.els.flyout ) {
			this.els.flyout.addEventListener( 'click', this._handleFlyoutClick );
			this.els.flyout.addEventListener( 'change', this._handleFlyoutChange );
			this.els.flyout.addEventListener( 'input', this._handleFlyoutInput );
		}
		document.body.addEventListener( 'click', this._handleBodyClick );
		document.addEventListener( 'keydown', this._handleKeydown );

		// Mutual exclusivity: wrap GFConditionalLogic.prototype.updateState
		// to detect when native CL is enabled, so we can disable IfOnly.
		var self = this;
		if ( typeof GFConditionalLogic !== 'undefined' && GFConditionalLogic.prototype ) {
			var origUpdateState = GFConditionalLogic.prototype.updateState;
			GFConditionalLogic.prototype.updateState = function( stateKey, stateValue ) {
				origUpdateState.call( this, stateKey, stateValue );
				if ( stateKey === 'enabled' && stateValue && this.objectType === 'field' ) {
					self.disableIfOnly();
				}
			};

			// Wrap showFlyout to close IfOnly when native CL flyout opens,
			// and capture the native instance reference for later use.
			var origShowFlyout = GFConditionalLogic.prototype.showFlyout;
			GFConditionalLogic.prototype.showFlyout = function() {
				if ( this.objectType === 'field' ) {
					self._nativeCLInstance = this;
					self.hideFlyout();
				}
				return origShowFlyout.call( this );
			};
		}
	};

	/**
	 * Called when a field is selected in the form editor.
	 */
	GFIfOnlyFlyout.prototype.loadField = function( field, form ) {
		// If we're switching fields while flyout is open, close it.
		if ( this.visible && this.fieldId !== field.id ) {
			this.hideFlyout();
		}

		this.field   = field;
		this.fieldId = field.id;
		this.form    = form;
		this.state   = this.getStateForField( field );

		this.renderSidebar();
		this.renderFlyout();

		// If the flyout is currently open (e.g. after a save), re-render groups
		// since renderFlyout() replaces the entire flyout innerHTML.
		if ( this.visible ) {
			this.renderGroups();
		}
	};

	// ------------------------------------------------------------------
	// State management
	// ------------------------------------------------------------------

	GFIfOnlyFlyout.prototype.getDefaultRule = function() {
		var fieldId = typeof GetFirstRuleField === 'function' ? GetFirstRuleField() : '';
		return { fieldId: fieldId, operator: 'is', value: '' };
	};

	GFIfOnlyFlyout.prototype.getDefaultState = function() {
		return {
			enabled: false,
			actionType: 'show',
			groups: [
				{ rules: [ this.getDefaultRule() ] },
			],
		};
	};

	GFIfOnlyFlyout.prototype.getStateForField = function( field ) {
		var logic = field.ifonlyLogic;

		if ( ! logic || typeof logic !== 'object' || ! logic.groups ) {
			return this.getDefaultState();
		}

		// Ensure enabled key exists.
		if ( ! ( 'enabled' in logic ) ) {
			logic.enabled = true;
		}

		return logic;
	};

	GFIfOnlyFlyout.prototype.updateForm = function() {
		if ( ! this.field ) {
			return;
		}

		if ( ! this.state.enabled ) {
			this.field.ifonlyLogic = null;
		} else {
			this.field.ifonlyLogic = this.state;
		}

		if ( typeof SetFieldProperty === 'function' ) {
			SetFieldProperty( 'ifonlyLogic', this.field.ifonlyLogic );
		}
	};

	// ------------------------------------------------------------------
	// Mutual exclusivity: native CL vs IfOnly
	// ------------------------------------------------------------------

	/**
	 * Close the native CL flyout using the captured GF instance.
	 */
	GFIfOnlyFlyout.prototype.closeNativeFlyout = function() {
		if ( this._nativeCLInstance && this._nativeCLInstance.visible ) {
			this._nativeCLInstance.hideFlyout();
		}
	};

	/**
	 * Disable native Conditional Logic when IfOnly is enabled.
	 *
	 * Uses the captured GFConditionalLogic instance's own updateState()
	 * to let GF handle field data + sidebar re-rendering properly.
	 * Falls back to manual DOM manipulation if no instance is available.
	 */
	GFIfOnlyFlyout.prototype.disableNativeCL = function() {
		if ( ! this.field ) {
			return;
		}

		// Check if native CL is currently active on this field.
		var cl = this.field.conditionalLogic;
		if ( ! cl || ( typeof cl === 'object' && cl.enabled === false ) ) {
			return;
		}

		// Preferred path: call GF's own updateState via the captured instance.
		if ( this._nativeCLInstance ) {
			this._nativeCLInstance.updateState( 'enabled', false );
			this.closeNativeFlyout();
		} else {
			// Fallback: manually clear conditionalLogic on the field.
			this.field.conditionalLogic = '';

			if ( typeof form !== 'undefined' && form.fields ) {
				for ( var i = 0; i < form.fields.length; i++ ) {
					if ( form.fields[ i ].id == this.fieldId ) {
						form.fields[ i ].conditionalLogic = '';
						break;
					}
				}
			}

			this.closeNativeFlyout();
		}

		// Always update the native CL sidebar DOM to show "Inactive",
		// in case GF's updateState doesn't re-render it.
		var nativeSidebar = document.querySelector( '.conditional_logic_field_setting' );
		if ( nativeSidebar ) {
			var statusEl = nativeSidebar.querySelector( '.gform-status-indicator-status' );
			if ( statusEl ) {
				statusEl.textContent = 'Inactive';
			}
			var indicator = nativeSidebar.querySelector( '.gform-status-indicator' );
			if ( indicator ) {
				indicator.classList.remove( 'gform-status--active' );
			}
		}

		// Also uncheck the native toggle inside the flyout so it's in sync
		// if the user re-opens the native CL flyout.
		var nativeFlyoutContainer = document.getElementById( 'conditional_logic_flyout_container' );
		if ( nativeFlyoutContainer ) {
			var toggle = nativeFlyoutContainer.querySelector( '[data-js-conditonal-toggle]' );
			if ( toggle ) {
				toggle.checked = false;
			}
		}
	};

	/**
	 * Disable IfOnly when native Conditional Logic is enabled.
	 */
	GFIfOnlyFlyout.prototype.disableIfOnly = function() {
		if ( ! this.state || ! this.state.enabled ) {
			return;
		}

		this.state.enabled = false;
		this.updateForm();
		this.renderSidebar();

		if ( this.visible ) {
			this.hideFlyout();
		}
	};

	// ------------------------------------------------------------------
	// Render: sidebar accordion
	// ------------------------------------------------------------------

	GFIfOnlyFlyout.prototype.renderSidebar = function() {
		if ( ! this.els.sidebar ) {
			return;
		}

		var hasFields = typeof GetFirstRuleField === 'function' && GetFirstRuleField() > 0;

		var config = {
			title:        strings.advancedLogic,
			toggleText:   strings.configure + ' ' + strings.advancedLogic,
			active_class: this.state.enabled ? 'gform-status--active' : '',
			active_text:  this.state.enabled ? strings.active : strings.inactive,
			desc_class:   hasFields ? '' : 'active',
			toggle_class: hasFields ? 'active' : '',
			desc:         strings.helperText,
		};

		renderView( views.accordion, this.els.sidebar, config, true );
	};

	// ------------------------------------------------------------------
	// Render: flyout panel
	// ------------------------------------------------------------------

	GFIfOnlyFlyout.prototype.renderFlyout = function() {
		if ( ! this.els.flyout ) {
			return;
		}

		var config = {
			flyoutTitle:  strings.flyoutTitle,
			flyoutDesc:   strings.flyoutDesc,
			enableLabel:  strings.enable + ' ' + strings.advancedLogic,
			fieldId:      this.fieldId,
			checked:      this.state.enabled ? 'checked' : '',
			enabledText:  this.state.enabled ? strings.enabled : strings.disabled,
			main:         this.renderMainControls(),
		};

		renderView( views.flyout, this.els.flyout, config, true );
	};

	GFIfOnlyFlyout.prototype.renderMainControls = function() {
		var config = {
			enabledClass: this.state.enabled ? 'active' : '',
			showSelected: this.state.actionType === 'show' ? 'selected="selected"' : '',
			hideSelected: this.state.actionType === 'hide' ? 'selected="selected"' : '',
			showText:     strings.show,
			hideText:     strings.hide,
			thisFieldIf:  strings.thisFieldIf,
			allMatch:     strings.allMatch,
			addGroupText: strings.addGroup,
		};

		return renderView( views.main, null, config, false );
	};

	// ------------------------------------------------------------------
	// Render: groups & rules
	// ------------------------------------------------------------------

	GFIfOnlyFlyout.prototype.renderGroups = function() {
		var container = this.els.flyout.querySelector( '.ifonly_flyout__groups' );
		if ( ! container ) {
			return;
		}

		var html = '';
		for ( var g = 0; g < this.state.groups.length; g++ ) {
			// OR separator between groups.
			if ( g > 0 ) {
				html += '<div class="ifonly_or-separator"><span>' + esc( strings.or ) + '</span></div>';
			}
			html += this.renderGroup( g );
		}

		container.innerHTML = html;

		// Now render rules into each group's container.
		for ( var g = 0; g < this.state.groups.length; g++ ) {
			this.renderRulesForGroup( g );
		}

		this.syncValuesFromDOM();
	};

	/**
	 * After a re-render, the browser auto-selects the first <option> of any
	 * <select> whose current state value doesn't match an available choice.
	 * Read the actual DOM values back into state so they stay in sync.
	 */
	GFIfOnlyFlyout.prototype.syncValuesFromDOM = function() {
		var self = this;
		var groups = this.els.flyout.querySelectorAll( '[data-ifonly-group]' );
		groups.forEach( function( groupEl ) {
			var gi = parseInt( groupEl.dataset.ifonlyGroup, 10 );
			var rules = groupEl.querySelectorAll( '[data-ifonly-rule]' );
			rules.forEach( function( ruleEl ) {
				var ri  = parseInt( ruleEl.dataset.ifonlyRule, 10 );
				var val = ruleEl.querySelector( '[data-js-ifonly-rule="value"]' );
				if ( val && self.state.groups[ gi ] && self.state.groups[ gi ].rules[ ri ] ) {
					self.state.groups[ gi ].rules[ ri ].value = val.value;
				}
			} );
		} );
	};

	GFIfOnlyFlyout.prototype.renderGroup = function( groupIdx ) {
		var config = {
			group_idx: groupIdx,
			andLabel:  strings.and,
		};
		return renderView( views.group, null, config, false );
	};

	GFIfOnlyFlyout.prototype.renderRulesForGroup = function( groupIdx ) {
		var groupEl = this.els.flyout.querySelector( '[data-ifonly-group="' + groupIdx + '"] .ifonly_group__rules' );
		if ( ! groupEl ) {
			return;
		}

		var group = this.state.groups[ groupIdx ];
		var html  = '';

		for ( var r = 0; r < group.rules.length; r++ ) {
			html += this.renderRule( group.rules[ r ], r, groupIdx );
		}

		groupEl.innerHTML = html;
	};

	GFIfOnlyFlyout.prototype.renderRule = function( rule, ruleIdx, groupIdx ) {
		var config = {
			rule_idx:        ruleIdx,
			fieldOptions:    this.renderFieldOptions( rule ),
			operatorOptions: this.renderOperatorOptions( rule ),
			valueMarkup:     this.renderRuleValue( rule, ruleIdx ),
			deleteClass:     ( this.state.groups[ groupIdx ].rules.length > 1 || this.state.groups.length > 1 ) ? 'active' : '',
			addRuleText:     strings.addRule,
			removeRuleText:  strings.removeRule,
		};

		return renderView( views.rule, null, config, false );
	};

	// ------------------------------------------------------------------
	// Render: field options (reusing GF native helpers)
	// ------------------------------------------------------------------

	GFIfOnlyFlyout.prototype.renderFieldOptions = function( rule ) {
		var html     = '';
		var template = views.option;

		for ( var i = 0; i < form.fields.length; i++ ) {
			var field = form.fields[ i ];

			if ( typeof IsConditionalLogicField !== 'function' || ! IsConditionalLogicField( field ) ) {
				continue;
			}

			var inputType = typeof GetInputType === 'function' ? GetInputType( field ) : ( field.inputType || field.type );

			// Fields with sub-inputs (name, address, etc.) but NOT checkbox/email/consent/radio.
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
	};

	// ------------------------------------------------------------------
	// Render: operator options
	// ------------------------------------------------------------------

	GFIfOnlyFlyout.prototype.renderOperatorOptions = function( rule ) {
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
	};

	// ------------------------------------------------------------------
	// Render: value (select dropdown or text input)
	// ------------------------------------------------------------------

	GFIfOnlyFlyout.prototype.renderRuleValue = function( rule, ruleIdx ) {
		var isTextOp = TEXT_OPERATORS.indexOf( rule.operator ) !== -1;

		if ( isTextOp ) {
			return this.renderInput( rule );
		}

		var field = this.getFieldById( rule.fieldId );

		if ( field && field.choices && [ 'is', 'isnot' ].indexOf( rule.operator ) !== -1 ) {
			return this.renderSelect( rule );
		}

		// Fallback to text input.
		return this.renderInput( rule );
	};

	GFIfOnlyFlyout.prototype.renderInput = function( rule ) {
		var config = {
			value:       esc( rule.value || '' ),
			placeholder: esc( strings.enterValue ),
		};
		return renderView( views.input, null, config, false );
	};

	GFIfOnlyFlyout.prototype.renderSelect = function( rule ) {
		var field   = this.getFieldById( rule.fieldId );
		var options = [];

		if ( field && field.choices ) {
			// Add empty/placeholder choice.
			if ( field.placeholder ) {
				options.push( { text: field.placeholder, value: '' } );
			}

			for ( var i = 0; i < field.choices.length; i++ ) {
				options.push( field.choices[ i ] );
			}
		}

		var template = views.option;
		var optionsHtml = '';

		for ( var i = 0; i < options.length; i++ ) {
			var choice = options[ i ];
			var val    = typeof choice.value !== 'undefined' ? String( choice.value ) : String( choice.text );
			var config = {
				label:    esc( choice.text || val ),
				value:    esc( val ),
				selected: val == rule.value ? 'selected="selected"' : '',
			};
			optionsHtml += renderView( template, null, config, false );
		}

		var selectConfig = {
			fieldValueOptions: optionsHtml,
		};

		return renderView( views.select, null, selectConfig, false );
	};

	/**
	 * Get a field from the form by ID.
	 */
	GFIfOnlyFlyout.prototype.getFieldById = function( fieldId ) {
		if ( typeof GetFieldById === 'function' ) {
			return GetFieldById( fieldId );
		}
		var id = parseInt( fieldId, 10 );
		for ( var i = 0; i < form.fields.length; i++ ) {
			if ( form.fields[ i ].id == id ) {
				return form.fields[ i ];
			}
		}
		return null;
	};

	// ------------------------------------------------------------------
	// Flyout show / hide (matching GF's animation pattern)
	// ------------------------------------------------------------------

	GFIfOnlyFlyout.prototype.showFlyout = function() {
		// Close GF's own conditional logic flyout first.
		this.closeNativeFlyout();

		var flyout = this.els.flyout;
		flyout.classList.remove( 'anim-out-ready', 'anim-out-active' );
		flyout.classList.add( 'anim-in-ready' );

		window.setTimeout( function() {
			flyout.classList.add( 'anim-in-active' );
		}, 25 );

		this.visible = true;

		// Render groups now that the flyout is about to show.
		this.renderGroups();
	};

	GFIfOnlyFlyout.prototype.hideFlyout = function() {
		var flyout = this.els.flyout;
		if ( ! flyout.classList.contains( 'anim-in-active' ) ) {
			return;
		}

		flyout.classList.remove( 'anim-in-ready', 'anim-in-active' );
		flyout.classList.add( 'anim-out-ready' );

		window.setTimeout( function() {
			flyout.classList.add( 'anim-out-active' );
		}, 25 );

		window.setTimeout( function() {
			flyout.classList.remove( 'anim-out-ready', 'anim-out-active' );
		}, 215 );

		this.visible = false;
	};

	GFIfOnlyFlyout.prototype.toggleFlyout = function() {
		this.renderFlyout();

		if ( this.visible ) {
			this.hideFlyout();
		} else {
			this.showFlyout();
		}
	};

	// ------------------------------------------------------------------
	// Event handlers
	// ------------------------------------------------------------------

	/**
	 * Sidebar accordion click — toggle button opens/closes flyout.
	 */
	GFIfOnlyFlyout.prototype.handleSidebarClick = function( e ) {
		if (
			e.target.classList.contains( 'ifonly_accordion__toggle_button' ) ||
			e.target.classList.contains( 'conditional_logic_accordion__toggle_button_icon' )
		) {
			// Stop propagation so GF's body click handler doesn't see this
			// as a valid flyout click (our button shares the GF icon class).
			e.stopPropagation();
			this.toggleFlyout();
		}
	};

	/**
	 * Clicks inside the flyout: toggle, add rule, delete rule, add group, close.
	 */
	GFIfOnlyFlyout.prototype.handleFlyoutClick = function( e ) {
		// Prevent body click handler from closing the flyout.
		// This is critical because renderGroups() detaches e.target from the DOM,
		// which would make flyout.contains(e.target) return false in handleBodyClick.
		e.stopPropagation();

		var target = e.target;

		// Enable toggle.
		if ( 'jsIfonlyToggle' in target.dataset ) {
			this.state.enabled = target.checked;
			if ( this.state.enabled ) {
				this.disableNativeCL();
			}
			this.renderSidebar();
			this.renderFlyout();
			if ( this.visible ) {
				this.renderGroups();
			}
			this.updateForm();
			return;
		}

		// Close button.
		if ( 'jsIfonlyClose' in target.dataset ) {
			this.toggleFlyout();
			return;
		}

		// Add rule — find which group.
		if ( 'jsIfonlyAddRule' in target.dataset ) {
			var groupEl = target.closest( '[data-ifonly-group]' );
			if ( groupEl ) {
				var gi = parseInt( groupEl.dataset.ifonlyGroup, 10 );
				this.state.groups[ gi ].rules.push( this.getDefaultRule() );
				this.renderGroups();
				this.updateForm();
			}
			return;
		}

		// Delete rule.
		if ( 'jsIfonlyDeleteRule' in target.dataset ) {
			var ruleEl  = target.closest( '[data-ifonly-rule]' );
			var groupEl = target.closest( '[data-ifonly-group]' );
			if ( ruleEl && groupEl ) {
				var gi = parseInt( groupEl.dataset.ifonlyGroup, 10 );
				var ri = parseInt( ruleEl.dataset.ifonlyRule, 10 );

				this.state.groups[ gi ].rules.splice( ri, 1 );

				// If group is empty, remove it.
				if ( this.state.groups[ gi ].rules.length === 0 ) {
					this.state.groups.splice( gi, 1 );
				}

				// Ensure we always have at least one group with one rule.
				if ( this.state.groups.length === 0 ) {
					this.state.groups.push( { rules: [ this.getDefaultRule() ] } );
				}

				this.renderGroups();
				this.updateForm();
			}
			return;
		}

		// Add group (OR).
		if ( 'jsIfonlyAddGroup' in target.dataset ) {
			this.state.groups.push( { rules: [ this.getDefaultRule() ] } );
			this.renderGroups();
			this.updateForm();
			return;
		}
	};

	/**
	 * Change events inside the flyout — rule selects and state selects.
	 */
	GFIfOnlyFlyout.prototype.handleFlyoutChange = function( e ) {
		var target = e.target;

		// State update (actionType).
		if ( 'jsIfonlyState' in target.dataset ) {
			var key = target.dataset.jsIfonlyState;
			this.state[ key ] = target.value;
			this.updateForm();
			return;
		}

		// Rule property update (field, operator, value).
		if ( 'jsIfonlyRule' in target.dataset ) {
			var ruleEl  = target.closest( '[data-ifonly-rule]' );
			var groupEl = target.closest( '[data-ifonly-group]' );
			if ( ! ruleEl || ! groupEl ) {
				return;
			}

			var gi  = parseInt( groupEl.dataset.ifonlyGroup, 10 );
			var ri  = parseInt( ruleEl.dataset.ifonlyRule, 10 );
			var key = target.dataset.jsIfonlyRule;
			var val = target.value;

			this.state.groups[ gi ].rules[ ri ][ key ] = val;

			// If fieldId or operator changed, re-render to update value input type.
			if ( key === 'fieldId' || key === 'operator' ) {
				this.renderGroups();
			}

			this.updateForm();
			return;
		}
	};

	/**
	 * Text input events inside the flyout — live save for text value fields.
	 */
	GFIfOnlyFlyout.prototype.handleFlyoutInput = function( e ) {
		var target = e.target;

		if ( 'jsIfonlyRule' in target.dataset && target.dataset.jsIfonlyRule === 'value' ) {
			var ruleEl  = target.closest( '[data-ifonly-rule]' );
			var groupEl = target.closest( '[data-ifonly-group]' );
			if ( ! ruleEl || ! groupEl ) {
				return;
			}

			var gi = parseInt( groupEl.dataset.ifonlyGroup, 10 );
			var ri = parseInt( ruleEl.dataset.ifonlyRule, 10 );

			this.state.groups[ gi ].rules[ ri ].value = target.value;
			this.updateForm();
		}
	};

	/**
	 * Close flyout when clicking outside.
	 */
	GFIfOnlyFlyout.prototype.handleBodyClick = function( e ) {
		if ( ! this.visible ) {
			return;
		}

		// Don't close if click was inside flyout or sidebar.
		if ( this.els.flyout.contains( e.target ) || this.els.sidebar.contains( e.target ) ) {
			return;
		}

		// Don't close if clicking on toggle switches or dialog masks.
		if (
			e.target.classList.contains( 'gform-field__toggle-input' ) ||
			( e.target.closest && e.target.closest( '.gform-dialog__mask' ) !== null )
		) {
			return;
		}

		this.hideFlyout();
	};

	/**
	 * ESC closes flyout.
	 */
	GFIfOnlyFlyout.prototype.handleKeydown = function( e ) {
		if ( this.visible && e.which === 27 ) {
			e.preventDefault();
			this.hideFlyout();
		}
	};

	// =====================================================================
	// Boot — instantiate immediately; DOM elements are already present
	// because gform_editor_js outputs them before this script loads.
	// =====================================================================

	window.GFIfOnlyFlyout  = GFIfOnlyFlyout;
	window.gfIfOnlyFlyout = new GFIfOnlyFlyout();

	// Replay any pending field load that fired before this script was ready.
	if ( window._gfIfOnlyPending ) {
		window.gfIfOnlyFlyout.loadField( window._gfIfOnlyPending.field, window._gfIfOnlyPending.form );
		delete window._gfIfOnlyPending;
	}

	}; // end gfIfOnlyBoot

	// Auto-boot if the inline config script already ran (external JS loaded after it).
	if ( typeof gfIfOnlyConfig !== 'undefined' && typeof window.gfIfOnlyFlyout === 'undefined' ) {
		window.gfIfOnlyBoot();
	}

} )( jQuery );
