<?php
/**
 * Updates Spoko Content MCP from the GitHub releases of spokospace/wordpress-content-mcp.
 *
 * WordPress shows a new release on the Plugins screen like any other plugin update, and
 * installs it on its own when auto-updates are enabled for the plugin. A release is used only
 * when it has the asset spoko-content-mcp.zip (built by bin/release.sh) and a higher version tag.
 */

defined( 'ABSPATH' ) || exit;

final class Spoko_Content_MCP_Updater {

	const REPO      = 'spokospace/wordpress-content-mcp';
	const ASSET     = 'spoko-content-mcp.zip';
	const CACHE_KEY = 'spoko_content_mcp_release';
	const CACHE_TTL = 21600; // 6 hours.
	const FAIL_TTL  = 3600;  // After a failed check, try again in an hour.

	/** @var string Plugin file, e.g. spoko-content-mcp/spoko-content-mcp.php. */
	private static $basename = '';

	/** @var string Plugin folder, e.g. spoko-content-mcp. */
	private static $slug = '';

	/** @var string Full path of the main plugin file. */
	private static $file = '';

	public static function init( string $file ): void {
		self::$file     = $file;
		self::$basename = plugin_basename( $file );
		self::$slug     = dirname( self::$basename );

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 2 );
	}

	/**
	 * Adds the latest release to WordPress's plugin update check.
	 *
	 * @param mixed $transient The update_plugins site transient.
	 * @return mixed
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked[ self::$basename ] ) ) {
			return $transient;
		}
		$release = self::release();
		if ( ! $release ) {
			return $transient;
		}

		$item = (object) array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::$slug,
			'plugin'       => self::$basename,
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires_php' => self::header()['RequiresPHP'],
		);

		// WordPress puts the installed version of every plugin in "checked".
		if ( version_compare( $release['version'], $transient->checked[ self::$basename ], '>' ) ) {
			$transient->response[ self::$basename ] = $item;
			unset( $transient->no_update[ self::$basename ] );
		} else {
			$transient->no_update[ self::$basename ] = $item;
			unset( $transient->response[ self::$basename ] );
		}
		return $transient;
	}

	/**
	 * The "View details" popup on the Plugins screen.
	 *
	 * @param mixed  $result The result so far.
	 * @param string $action plugins_api action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::$slug !== $args->slug ) {
			return $result;
		}
		$release = self::release();
		if ( ! $release ) {
			return $result;
		}
		$header = self::header();
		return (object) array(
			'name'          => $header['Name'],
			'slug'          => self::$slug,
			'version'       => $release['version'],
			'author'        => $header['Author'],
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'requires_php'  => $header['RequiresPHP'],
			'last_updated'  => $release['published'],
			'sections'      => array(
				'changelog' => '<pre>' . esc_html( $release['notes'] ) . '</pre>',
			),
		);
	}

	/**
	 * Forgets the cached release after a plugin update.
	 *
	 * @param mixed $upgrader   Upgrader instance.
	 * @param array $hook_extra Extra arguments.
	 */
	public static function flush( $upgrader, $hook_extra ): void {
		if ( isset( $hook_extra['type'] ) && 'plugin' === $hook_extra['type'] ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * The latest release with its zip asset, cached for CACHE_TTL (a failed check for FAIL_TTL).
	 * "Check again" on the Updates screen (force-check) skips the cache, as it does for
	 * wordpress.org plugins.
	 *
	 * @return array|null { version, package, url, notes, published } or null.
	 */
	public static function release(): ?array {
		$force  = isset( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$cached = $force ? false : get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}

		$release = self::fetch_release();
		set_transient( self::CACHE_KEY, $release ? $release : array(), $release ? self::CACHE_TTL : self::FAIL_TTL );
		return $release;
	}

	private static function fetch_release(): ?array {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => self::$slug,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) $data['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+(\.\d+)*$/', $version ) ) {
			return null;
		}
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( self::ASSET === ( $asset['name'] ?? '' ) && ! empty( $asset['browser_download_url'] ) ) {
				return array(
					'version'   => $version,
					'package'   => (string) $asset['browser_download_url'],
					'url'       => (string) ( $data['html_url'] ?? 'https://github.com/' . self::REPO ),
					'notes'     => (string) ( $data['body'] ?? '' ),
					'published' => (string) ( $data['published_at'] ?? '' ),
				);
			}
		}
		return null;
	}

	/**
	 * Name, author and PHP requirement from the plugin header, the one place they are written.
	 */
	private static function header(): array {
		return get_file_data(
			self::$file,
			array(
				'Name'        => 'Plugin Name',
				'Author'      => 'Author',
				'RequiresPHP' => 'Requires PHP',
			)
		);
	}
}
