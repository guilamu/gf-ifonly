# Gravity Forms IfOnly

Advanced conditional logic for Gravity Forms — group rules with AND/OR logic for fields, buttons, confirmations, and notifications.

## Grouped Conditional Logic

- Create multiple rule groups per field, button, confirmation, or notification
- Rules within a group are evaluated with **AND** logic
- Groups are connected with **OR** logic
- Example: Show field if (Industry **is** Finance **AND** Country **in CSV** Greece,Italy) **OR** (Industry **is** Tech **AND** Email **does NOT contain** .gov)

## Extended Operators

- All standard Gravity Forms operators: is, is not, greater than, less than, contains, starts with, ends with
- **does NOT contain** — show/hide when a field value does not contain a substring
- **in CSV** — match against a comma-separated list of values
- **not in CSV** — exclude matches from a comma-separated list

## Key Features

- **Fields, Buttons & More:** Apply advanced logic to fields, Next button, Submit button, confirmations, and notifications
- **Server-Side Validation:** Logic is re-evaluated on the server during form submission for security and reliability
- **Multilingual:** Works with content in any language
- **Translation-Ready:** All strings are internationalized
- **Secure:** All input is sanitized and escaped — no raw user data rendered
- **GitHub Updates:** Automatic updates from GitHub releases

## Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher
- Gravity Forms 2.8 or higher

## Installation

1. Upload the `gf-ifonly` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Open any form in the Gravity Forms editor
4. Select a field and look for the **Advanced Conditional Logic** panel in the field settings

## FAQ

### How does grouped logic work?

Each group contains one or more rules joined by AND. Multiple groups are joined by OR. This lets you express: "Show this field if (A AND B) OR (C AND D)."

### Does this replace the standard Gravity Forms conditional logic?

No. Standard conditional logic continues to work as before. IfOnly adds a separate "Advanced Conditional Logic" panel. If a field has IfOnly logic enabled, it takes priority over standard conditional logic for that field.

### What happens on the server side?

When a form is submitted, IfOnly re-evaluates all advanced logic on the server. Fields hidden by IfOnly logic are skipped during validation — required fields that are hidden won't block submission.

### Does it work with multi-page forms?

Yes. You can use IfOnly logic to show/hide the Next button on multi-page forms.

### Can I customize the operators available?

The `gf_ifonly_operators` filter lets you add or remove operators:

```php
add_filter( 'gf_ifonly_operators', function( $operators ) {
    $operators[] = array( 'key' => 'my_custom_op', 'label' => 'My Operator' );
    return $operators;
} );
```

## Project Structure

```
.
├── gf-ifonly.php                      # Bootstrap & plugin header
├── uninstall.php                      # Cleanup on uninstall
├── LICENSE
├── README.md
├── includes
│   ├── class-gf-ifonly.php            # Main GFAddOn class
│   ├── class-gf-ifonly-logic.php      # Server-side logic evaluator
│   └── class-github-updater.php       # GitHub auto-updates
├── assets
│   ├── js
│   │   ├── gf-ifonly-admin.js         # Form editor UI (vanilla JS)
│   │   ├── gf-ifonly-settings.js      # Notification & confirmation settings UI
│   │   └── gf-ifonly-frontend.js      # Frontend logic processor
│   ├── css
│   │   └── gf-ifonly-admin.css        # Editor & settings panel styles
│   └── views
│       ├── accordion.html             # Sidebar accordion template
│       ├── flyout.html                # Flyout panel template
│       ├── main.html                  # Main logic container template
│       ├── group.html                 # Rule group template
│       ├── rule.html                  # Single rule template
│       ├── option.html                # Select option template
│       ├── input.html                 # Text input template
│       └── select.html                # Select dropdown template
└── languages
    ├── gf-ifonly.pot                  # Translation template
    └── gf-ifonly-fr_FR.po             # French translation
```

## Changelog

### 0.9.9
- **Fix:** Frontend field visibility was broken — fields with IfOnly logic remained hidden even when the user selected matching values. Root cause: GF renders radio button choices inside a `<ul id="input_FORMID_FIELDID">` container; `getFieldValue()` found this `<ul>` via `getElementById` and tried to read `.value` on it (which is `undefined`), so radio field values always evaluated as empty. Fixed by checking the element's tag name and only reading `.value` from actual form elements (`input`, `select`, `textarea`).
- **Fix:** On initial page load, IfOnly fields could have the wrong visibility state because GF's conditional logic init script evaluates rules before the IfOnly filter is registered. A re-evaluation is now triggered immediately after filter registration.

### 0.9.8
- **Fix:** Changing the field dropdown in an IfOnly rule to a choice-based field (radio, select, etc.) displayed the first choice in the value dropdown, but the underlying state kept an empty string — so the saved rule targeted `""` instead of the visible choice. Root cause: after re-rendering, the browser auto-selects the first `<option>` of the new `<select>`, but no `change` event fires, leaving the state stale. Both the settings-page and form-editor scripts now sync state from the actual DOM values after every re-render.

### 0.9.7
- **Fix:** IfOnly-enabled confirmations were always displayed regardless of whether conditions were met. Root cause: clearing native `conditionalLogic` (v0.9.6) caused GF's `update_confirmation()` to evaluate `null` logic as "always true," selecting the IfOnly confirmation before the `gform_confirmation` filter ran. The old `maybe_override_confirmation()` could only add a confirmation, never reject one GF already picked. Rewritten to (a) detect when GF wrongly selected an IfOnly confirmation whose conditions are not met and fall back to the default, (b) properly handle both message and redirect confirmation types via a new `format_confirmation()` helper.

### 0.9.6
- **Fix:** Native Conditional Logic and IfOnly could both be active simultaneously on the same notification or confirmation, causing unpredictable behavior (native CL is evaluated first by GF and can silently override IfOnly). When IfOnly is enabled, native CL is now automatically hidden and disabled on the settings page. On save, native `conditionalLogic` data is cleared to prevent server-side conflicts.

### 0.9.5
- **Fix:** Notifications were still sent even when IfOnly conditions were not met. Root cause: the `gform_notification` filter fires inside `GFCommon::send_notification()`, but Gravity Forms does not re-check `isActive` after the filter — the email proceeds regardless. Replaced the `isActive = false` approach with a two-hook strategy: `gform_notification` now flags the notification (`ifonly_suppress`), and a new `gform_pre_send_email` callback sets `abort_email = true` for flagged notifications, which is the documented GF mechanism to cancel delivery.

### 0.9.4
- **Fix:** On notification and confirmation settings pages, IfOnly rules appeared to be lost after clicking the save button (they were actually saved — a page refresh showed them correctly). Root cause: GF's settings framework builds the field HTML *before* running `process_postback()`, and for existing items it does not redirect after save, so the rendered page contained stale data. The POST fallback now always reads from `$_POST` on a save postback instead of only when `$ifonly` was empty.

### 0.9.3
- **Fix:** The delete (−) button was missing on the sole rule of a group when multiple groups existed, making it impossible to remove an entire group. The button is now shown whenever deletion is meaningful: when the group has more than one rule, or when there is more than one group.

### 0.9.2
- **Fix:** Translated operator labels containing apostrophes (e.g. French "n'est pas") were rendered as HTML entities (`&#039;`) in the rule editor dropdowns. Replaced `esc_html__()` with `__()` for all strings serialized into the JavaScript configuration object — HTML-escaping is inappropriate for values passed through `wp_json_encode()`.

### 0.9.1
- **Fix:** After saving the form with the Advanced Logic flyout open, the rule groups were visually cleared (rules disappeared). Groups are now correctly re-rendered when `loadField()` is called while the flyout is already open.

### 0.9.0
- Initial public release
- **New:** Grouped conditional logic (AND within groups, OR between groups)
- **New:** Support for fields, Next button, Submit button, confirmations, and notifications
- **New:** Extra operators: does NOT contain, in CSV, not in CSV
- **New:** Server-side logic re-evaluation during submission
- **New:** GitHub auto-update support
- **New:** French translation

## Acknowledgements

The idea and first draft of this plugin originate from [this gist](https://gist.github.com/spivurno/79f82d340942fd33fa05c263754f8663) by [David Smith](https://github.com/spivurno) (@spivurno), the boss of [Gravity Wiz](https://gravitywiz.com/). I have been a very happy Gravity Wiz user for more than a decade, and nobody in the WordPress community comes even close to their level of professionalism and their stellar support!

## License

This project is licensed under the GNU Affero General Public License v3.0 (AGPL-3.0) - see the [LICENSE](LICENSE) file for details.

---

<p align="center">
  Made with love for the WordPress community
</p>
