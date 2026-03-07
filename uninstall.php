<?php
/**
 * Clean up on uninstall.
 *
 * @package GF_IfOnly
 * @license AGPL-3.0-or-later
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove cached GitHub release data.
delete_transient( 'gf_ifonly_github_release' );
