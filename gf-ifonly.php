<?php
/**
 * Plugin Name: Gravity Forms IfOnly
 * Plugin URI: https://github.com/guilamu/gf-ifonly
 * Description: Advanced conditional logic for Gravity Forms — group rules with AND/OR logic for fields, buttons, confirmations, and notifications.
 * Version: 0.9.1
 * Author: Guilamu
 * Author URI: https://github.com/guilamu
 * Text Domain: gf-ifonly
 * Domain Path: /languages
 * Update URI: https://github.com/guilamu/gf-ifonly/
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GF_IFONLY_VERSION', '0.9.1' );
define( 'GF_IFONLY_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_IFONLY_URL', plugin_dir_url( __FILE__ ) );
define( 'GF_IFONLY_FILE', __FILE__ );

// GitHub auto-updater.
require_once GF_IFONLY_PATH . 'includes/class-github-updater.php';

// Bootstrap via GFAddOn framework.
add_action( 'gform_loaded', array( 'GF_IfOnly_Bootstrap', 'load' ), 5 );

class GF_IfOnly_Bootstrap {

	public static function load(): void {
		if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
			return;
		}

		require_once GF_IFONLY_PATH . 'includes/class-gf-ifonly-logic.php';
		require_once GF_IFONLY_PATH . 'includes/class-gf-ifonly.php';

		GFAddOn::register( 'GF_IfOnly' );
	}
}

/**
 * Helper to access the singleton instance.
 */
function gf_ifonly(): GF_IfOnly {
	return GF_IfOnly::get_instance();
}

// Register with Guilamu Bug Reporter.
add_action( 'plugins_loaded', function () {
	if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
		Guilamu_Bug_Reporter::register( array(
			'slug'        => 'gf-ifonly',
			'name'        => 'Gravity Forms IfOnly',
			'version'     => GF_IFONLY_VERSION,
			'github_repo' => 'guilamu/gf-ifonly',
		) );
	}
}, 20 );

// Bug Reporter link in plugins list.
add_filter( 'plugin_row_meta', 'gf_ifonly_plugin_row_meta', 10, 2 );

function gf_ifonly_plugin_row_meta( array $links, string $file ): array {
	if ( plugin_basename( GF_IFONLY_FILE ) !== $file ) {
		return $links;
	}

	if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
		$links[] = sprintf(
			'<a href="#" class="guilamu-bug-report-btn" data-plugin-slug="gf-ifonly" data-plugin-name="%s">%s</a>',
			esc_attr__( 'Gravity Forms IfOnly', 'gf-ifonly' ),
			esc_html__( '🐛 Report a Bug', 'gf-ifonly' )
		);
	} else {
		$links[] = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			'https://github.com/guilamu/guilamu-bug-reporter/releases',
			esc_html__( '🐛 Report a Bug (install Bug Reporter)', 'gf-ifonly' )
		);
	}

	return $links;
}
