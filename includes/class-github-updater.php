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
	private const REQUIRES_GF         = '2.8';
	private const TEXT_DOMAIN         = 'gf-ifonly';
	private const CACHE_KEY           = 'gf_ifonly_github_release';
	private const CACHE_EXPIRATION    = 43200; // 12 hours.
	private const GITHUB_TOKEN        = '';

	public static function init(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'check_for_update' ), 10, 4 );
		add_filter( 'plugins_api', array( self::class, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( self::class, 'fix_folder_name' ), 10, 4 );
		add_action( 'admin_head', array( self::class, 'plugin_info_css' ) );
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
			'tested'       => get_bloginfo( 'version' ),
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

		$plugin_file = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
		$plugin_data = get_plugin_data( $plugin_file, false, false );
		$release_data = self::get_release_data();

		$version = $release_data
			? ltrim( $release_data['tag_name'], 'v' )
			: ( $plugin_data['Version'] ?? GF_IFONLY_VERSION );

		$res               = new stdClass();
		$res->name         = self::PLUGIN_NAME;
		$res->slug         = self::PLUGIN_SLUG;
		$res->plugin       = self::PLUGIN_FILE;
		$res->version      = $version;
		$res->author       = sprintf( '<a href="https://github.com/%s">%s</a>', self::GITHUB_USER, self::GITHUB_USER );
		$res->homepage     = sprintf( 'https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO );
		$res->requires     = self::REQUIRES_WP;
		$res->tested       = get_bloginfo( 'version' );
		$res->requires_php = self::REQUIRES_PHP;

		if ( $release_data ) {
			$res->download_link = self::get_package_url( $release_data );
			$res->last_updated  = $release_data['published_at'] ?? '';
		}

		// Build sections from local README.md.
		$readme = self::parse_readme();

		$res->sections = array(
			'description'  => ! empty( $readme['description'] )
				? $readme['description']
				: '<p>' . esc_html( self::PLUGIN_DESCRIPTION ) . '</p>',
			'installation' => ! empty( $readme['installation'] )
				? $readme['installation']
				: '',
			'faq'          => ! empty( $readme['faq'] )
				? $readme['faq']
				: '',
			'changelog'    => ! empty( $readme['changelog'] )
				? $readme['changelog']
				: sprintf(
					'<p>See <a href="https://github.com/%s/%s/releases" target="_blank">GitHub releases</a> for changelog.</p>',
					esc_attr( self::GITHUB_USER ),
					esc_attr( self::GITHUB_REPO )
				),
		);

		return $res;
	}

	/**
	 * Inject CSS overrides and extra sidebar info in the plugin-information iframe.
	 */
	public static function plugin_info_css(): void {
		if ( ! isset( $_GET['plugin'], $_GET['tab'] ) ) {
			return;
		}
		if ( 'plugin-information' !== sanitize_text_field( wp_unslash( $_GET['tab'] ) )
			|| self::PLUGIN_SLUG !== sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) ) {
			return;
		}
		echo '<style>'
			. '#section-holder .section h2 { margin: 1.5em 0 0.5em; clear: none; }'
			. '#section-holder .section h3 { margin: 1.5em 0 0.5em; }'
			. '#section-holder .section > :first-child { margin-top: 0; }'
			. '</style>';

		// Add "Requires Gravity Forms" line to the sidebar.
		$gf_version = esc_html( self::REQUIRES_GF );
		echo '<script>'
			. 'document.addEventListener("DOMContentLoaded",function(){'
			. 'var items=document.querySelectorAll(".fyi ul li");'
			. 'var php=null;'
			. 'for(var i=0;i<items.length;i++){if(items[i].textContent.indexOf("Requires PHP")!==-1){php=items[i];break;}}'
			. 'if(!php)return;'
			. 'var li=document.createElement("li");'
			. 'li.innerHTML="<strong>Requires Gravity Forms:<\/strong> ' . $gf_version . ' or higher";'
			. 'php.parentNode.insertBefore(li,php.nextSibling);'
			. '});'
			. '</script>';
	}

	// ------------------------------------------------------------------
	// README.md parsing
	// ------------------------------------------------------------------

	/**
	 * Parse the local README.md into description, installation and changelog HTML.
	 */
	private static function parse_readme(): array {
		$readme_path = WP_PLUGIN_DIR . '/' . dirname( self::PLUGIN_FILE ) . '/README.md';

		if ( ! file_exists( $readme_path ) ) {
			return array();
		}

		$content = file_get_contents( $readme_path );
		if ( false === $content ) {
			return array();
		}

		// Remove the main title line (# Title).
		$content = preg_replace( '/^#\s+[^\n]+\n*/m', '', $content, 1 );

		// Sections that are NOT part of the description tab.
		$utility_sections = array(
			'changelog', 'requirements', 'installation', 'faq',
			'project structure', 'acknowledgements', 'license',
		);

		// Split content by ## headers.
		$parts = preg_split( '/^##\s+/m', $content );

		$description  = trim( $parts[0] ?? '' );
		$installation = '';
		$faq          = '';
		$changelog    = '';

		for ( $i = 1, $count = count( $parts ); $i < $count; $i++ ) {
			$lines = explode( "\n", $parts[ $i ], 2 );
			$title = strtolower( trim( $lines[0] ) );
			$body  = trim( $lines[1] ?? '' );

			if ( 'installation' === $title ) {
				$installation .= $body . "\n\n";
			} elseif ( 'faq' === $title ) {
				$faq .= $body . "\n\n";
			} elseif ( 'changelog' === $title ) {
				$changelog .= $body . "\n\n";
			} elseif ( ! in_array( $title, $utility_sections, true ) ) {
				// Include in description (e.g. "Grouped Conditional Logic", "Key Features").
				$description .= "\n\n## " . trim( $lines[0] ) . "\n" . $body;
			}
		}

		return array(
			'description'  => self::markdown_to_html( trim( $description ) ),
			'installation' => self::markdown_to_html( trim( $installation ) ),
			'faq'          => self::markdown_to_html( trim( $faq ) ),
			'changelog'    => self::markdown_to_html( trim( $changelog ) ),
		);
	}

	/**
	 * Minimal Markdown-to-HTML converter for README sections.
	 */
	private static function markdown_to_html( string $markdown ): string {
		if ( '' === $markdown ) {
			return '';
		}

		$html = $markdown;

		// Remove images and collapse leftover blank lines.
		$html = preg_replace( '/!\[[^\]]*\]\([^\)]+\)/', '', $html );
		$html = preg_replace( '/\n{3,}/', "\n\n", $html );

		// Fenced code blocks.
		$html = preg_replace_callback( '/```[\w]*\n([\s\S]*?)```/', function ( $m ) {
			return '<pre><code>' . esc_html( trim( $m[1] ) ) . '</code></pre>';
		}, $html );

		// Headers.
		$html = preg_replace( '/^####\s+(.+)$/m', '<h4>$1</h4>', $html );
		$html = preg_replace( '/^###\s+(.+)$/m', '<h3>$1</h3>', $html );
		$html = preg_replace( '/^##\s+(.+)$/m', '<h2>$1</h2>', $html );

		// Bold.
		$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );

		// Inline code.
		$html = preg_replace_callback( '/`([^`]+)`/', function ( $m ) {
			return '<code>' . esc_html( $m[1] ) . '</code>';
		}, $html );

		// Links [text](url).
		$html = preg_replace_callback( '/\[([^\]]+)\]\(([^\)]+)\)/', function ( $m ) {
			return '<a href="' . esc_url( $m[2] ) . '">' . $m[1] . '</a>';
		}, $html );

		// Horizontal rules.
		$html = preg_replace( '/^---+$/m', '<hr>', $html );

		// Ordered list blocks.
		$html = preg_replace_callback( '/((?:^[ \t]*\d+\.\s+.+$\n?)+)/m', function ( $m ) {
			$items = preg_replace( '/^[ \t]*\d+\.\s+(.+)$/m', '<li>$1</li>', trim( $m[0] ) );
			return '<ol>' . $items . '</ol>';
		}, $html );

		// Unordered list blocks.
		$html = preg_replace_callback( '/((?:^[ \t]*[-*]\s+.+$\n?)+)/m', function ( $m ) {
			$items = preg_replace( '/^[ \t]*[-*]\s+(.+)$/m', '<li>$1</li>', trim( $m[0] ) );
			return '<ul>' . $items . '</ul>';
		}, $html );

		// Paragraphs: split by double newlines, wrap non-block content in <p>.
		$html   = preg_replace( '/\n{2,}/', "\n\n", $html );
		$blocks = preg_split( '/\n{2,}/', trim( $html ) );
		$output = '';

		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}
			if ( preg_match( '/^<(h[1-6]|ul|ol|pre|blockquote|div|table|hr|p)[\s>]/', $block ) ) {
				$output .= $block . "\n";
			} else {
				$output .= '<p>' . $block . "</p>\n";
			}
		}

		return $output;
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
