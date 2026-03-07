<?php
/**
 * GitHub Auto-Updater for Gravity Forms IfOnly.
 *
 * Enables automatic updates from GitHub releases.
 *
 * @package GF_IfOnly
 * @license AGPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GF_IfOnly_GitHub_Updater {

	private const GITHUB_USER         = 'guilamu';
	private const GITHUB_REPO         = 'gf-ifonly';
	private const PLUGIN_FILE         = 'gf-ifonly/gf-ifonly.php';
	private const PLUGIN_SLUG         = 'gf-ifonly';
	private const PLUGIN_NAME         = 'Gravity Forms IfOnly';
	private const PLUGIN_DESCRIPTION  = 'Advanced conditional logic for Gravity Forms — group rules with AND/OR logic for fields, buttons, confirmations, and notifications.';
	private const REQUIRES_WP         = '6.0';
	private const TESTED_WP           = '6.7';
	private const REQUIRES_PHP        = '8.0';
	private const TEXT_DOMAIN         = 'gf-ifonly';
	private const CACHE_KEY           = 'gf_ifonly_github_release';
	private const CACHE_EXPIRATION    = 43200; // 12 hours.
	private const GITHUB_TOKEN        = '';

	public static function init(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'check_for_update' ), 10, 4 );
		add_filter( 'plugins_api', array( self::class, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( self::class, 'fix_folder_name' ), 10, 4 );
	}

	private static function get_release_data(): ?array {
		$release_data = get_transient( self::CACHE_KEY );

		if ( false !== $release_data && is_array( $release_data ) ) {
			return $release_data;
		}

		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', self::GITHUB_USER, self::GITHUB_REPO ),
			array(
				'user-agent' => 'WordPress/' . self::PLUGIN_SLUG,
				'timeout'    => 15,
				'headers'    => ! empty( self::GITHUB_TOKEN )
					? array( 'Authorization' => 'token ' . self::GITHUB_TOKEN )
					: array(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$release_data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $release_data['tag_name'] ) ) {
			return null;
		}

		set_transient( self::CACHE_KEY, $release_data, self::CACHE_EXPIRATION );

		return $release_data;
	}

	private static function get_package_url( array $release_data ): string {
		if ( ! empty( $release_data['assets'] ) && is_array( $release_data['assets'] ) ) {
			foreach ( $release_data['assets'] as $asset ) {
				if (
					isset( $asset['browser_download_url'], $asset['name'] ) &&
					str_ends_with( $asset['name'], '.zip' )
				) {
					return $asset['browser_download_url'];
				}
			}
		}

		return $release_data['zipball_url'] ?? '';
	}

	public static function check_for_update( $update, array $plugin_data, string $plugin_file, $locales ) {
		if ( self::PLUGIN_FILE !== $plugin_file ) {
			return $update;
		}

		$release_data = self::get_release_data();
		if ( null === $release_data ) {
			return $update;
		}

		$new_version = ltrim( $release_data['tag_name'], 'v' );

		if ( version_compare( $plugin_data['Version'], $new_version, '>=' ) ) {
			return $update;
		}

		return array(
			'version'      => $new_version,
			'package'      => self::get_package_url( $release_data ),
			'url'          => $release_data['html_url'],
			'tested'       => self::TESTED_WP,
			'requires_php' => self::REQUIRES_PHP,
			'compatibility' => new stdClass(),
			'icons'         => array(),
			'banners'       => array(),
		);
	}

	public static function plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $res;
		}

		if ( ! isset( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
			return $res;
		}

		$release_data = self::get_release_data();

		if ( null === $release_data ) {
			$plugin_file = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
			$plugin_data = get_plugin_data( $plugin_file, false, false );

			$res               = new stdClass();
			$res->name         = self::PLUGIN_NAME;
			$res->slug         = self::PLUGIN_SLUG;
			$res->plugin       = self::PLUGIN_FILE;
			$res->version      = $plugin_data['Version'] ?? '0.9.0';
			$res->author       = sprintf( '<a href="https://github.com/%s">%s</a>', self::GITHUB_USER, self::GITHUB_USER );
			$res->homepage     = sprintf( 'https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO );
			$res->requires     = self::REQUIRES_WP;
			$res->tested       = self::TESTED_WP;
			$res->requires_php = self::REQUIRES_PHP;
			$res->sections     = array(
				'description' => self::PLUGIN_DESCRIPTION,
				'changelog'   => sprintf(
					'<p>Unable to fetch changelog from GitHub. Visit <a href="https://github.com/%s/%s/releases" target="_blank">GitHub releases</a>.</p>',
					self::GITHUB_USER,
					self::GITHUB_REPO
				),
			);
			return $res;
		}

		$new_version = ltrim( $release_data['tag_name'], 'v' );

		$res                = new stdClass();
		$res->name          = self::PLUGIN_NAME;
		$res->slug          = self::PLUGIN_SLUG;
		$res->plugin        = self::PLUGIN_FILE;
		$res->version       = $new_version;
		$res->author        = sprintf( '<a href="https://github.com/%s">%s</a>', self::GITHUB_USER, self::GITHUB_USER );
		$res->homepage      = sprintf( 'https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO );
		$res->download_link = self::get_package_url( $release_data );
		$res->requires      = self::REQUIRES_WP;
		$res->tested        = self::TESTED_WP;
		$res->requires_php  = self::REQUIRES_PHP;
		$res->last_updated  = $release_data['published_at'] ?? '';
		$res->sections      = array(
			'description' => self::PLUGIN_DESCRIPTION,
			'changelog'   => ! empty( $release_data['body'] )
				? nl2br( esc_html( $release_data['body'] ) )
				: sprintf(
					'See <a href="https://github.com/%s/%s/releases" target="_blank">GitHub releases</a> for changelog.',
					self::GITHUB_USER,
					self::GITHUB_REPO
				),
		);

		return $res;
	}

	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) ) {
			return $source;
		}

		if ( self::PLUGIN_FILE !== $hook_extra['plugin'] ) {
			return $source;
		}

		$correct_folder = dirname( self::PLUGIN_FILE );
		$source_folder  = basename( untrailingslashit( $source ) );

		if ( $source_folder === $correct_folder ) {
			return $source;
		}

		$new_source = trailingslashit( $remote_source ) . $correct_folder . '/';

		if ( $wp_filesystem && $wp_filesystem->move( $source, $new_source ) ) {
			return $new_source;
		}

		if ( $wp_filesystem && $wp_filesystem->copy( $source, $new_source, true ) && $wp_filesystem->delete( $source, true ) ) {
			return $new_source;
		}

		return new WP_Error(
			'rename_failed',
			__( 'Unable to rename the update folder. Please retry or update manually.', 'gf-ifonly' )
		);
	}
}

GF_IfOnly_GitHub_Updater::init();
