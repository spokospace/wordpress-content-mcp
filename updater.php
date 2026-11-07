<?php
/**
 * Updates Polo Blocks MCP from the GitHub releases of spokospace/spoko-blocks-mcp.
 *
 * WordPress shows a new release on the Plugins screen like any other plugin update, and
 * installs it on its own when auto-updates are enabled for the plugin. A release is used only
 * when it has the asset polo-blocks-mcp.zip (built by bin/release.sh) and a higher version tag.
 *
 * Private repo: define POLO_BLOCKS_MCP_GITHUB_TOKEN in wp-config.php (a fine-grained token with
 * read-only Contents on this repo). A public repo needs no token.
 */

defined( 'ABSPATH' ) || exit;

final class Polo_Blocks_MCP_Updater {

	const REPO      = 'spokospace/spoko-blocks-mcp';
	const ASSET     = 'polo-blocks-mcp.zip';
	const CACHE_KEY = 'polo_blocks_mcp_release';
	const CACHE_TTL = 21600; // 6 hours.
	const FAIL_TTL  = 3600;  // After a failed check, try again in an hour.

	/** @var string Plugin file, e.g. polo-blocks-mcp/polo-blocks-mcp.php. */
	private static $basename = '';

	/** @var string Plugin folder, e.g. polo-blocks-mcp. */
	private static $slug = '';

	/** @var string Full path of the main plugin file. */
	private static $file = '';

	public static function init( string $file ): void {
		self::$file     = $file;
		self::$basename = plugin_basename( $file );
		self::$slug     = dirname( self::$basename );

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'source_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 2 );
	}

	/**
	 * Adds the latest release to WordPress's plugin update check.
	 *
	 * @param mixed $transient The update_plugins site transient.
	 * @return mixed
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
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
			'requires_php' => '7.4',
		);

		if ( version_compare( $release['version'], self::installed_version(), '>' ) ) {
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
		return (object) array(
			'name'          => 'Polo Blocks MCP',
			'slug'          => self::$slug,
			'version'       => $release['version'],
			'author'        => 'polo.blue',
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'sections'      => array(
				'changelog' => '<pre>' . esc_html( $release['notes'] ) . '</pre>',
			),
		);
	}

	/**
	 * Downloads the release asset of a private repo. GitHub answers the API asset URL with a
	 * redirect to a signed URL, which must be fetched without the token, so the redirect is
	 * followed here instead of by the HTTP client.
	 *
	 * @param mixed  $reply   false to let WordPress download, or a file path / WP_Error.
	 * @param string $package Package URL.
	 * @return mixed
	 */
	public static function download( $reply, $package ) {
		$token = self::token();
		if ( false !== $reply || '' === $token || 0 !== strpos( (string) $package, 'https://api.github.com/repos/' . self::REPO . '/releases/assets/' ) ) {
			return $reply;
		}
		$response = wp_remote_get(
			$package,
			array(
				'redirection' => 0,
				'timeout'     => 30,
				'headers'     => array(
					'Accept'        => 'application/octet-stream',
					'Authorization' => 'Bearer ' . $token,
					'User-Agent'    => 'polo-blocks-mcp',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( ! $location ) {
			return new WP_Error( 'polo_blocks_download', sprintf( 'GitHub did not return the release file (HTTP %s).', wp_remote_retrieve_response_code( $response ) ) );
		}
		return download_url( $location );
	}

	/**
	 * Keeps the plugin in its current folder even if the zip's top folder is named differently.
	 *
	 * @param mixed  $source        Unpacked source folder.
	 * @param string $remote_source Parent folder of the source.
	 * @param mixed  $upgrader      Upgrader instance.
	 * @param array  $hook_extra    Extra arguments, includes 'plugin' on updates.
	 * @return mixed
	 */
	public static function source_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( is_wp_error( $source ) || ! isset( $hook_extra['plugin'] ) || self::$basename !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . self::$slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'polo_blocks_folder', 'Could not rename the update folder.' );
	}

	/**
	 * Forgets the cached release after this plugin was updated.
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
	 * The latest release with its zip asset, cached for CACHE_TTL. "Check again" on the
	 * Updates screen (force-check) skips the cache.
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
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'polo-blocks-mcp',
		);
		$token = self::token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => $headers,
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
			if ( self::ASSET !== ( $asset['name'] ?? '' ) ) {
				continue;
			}
			$package = '' !== $token ? (string) ( $asset['url'] ?? '' ) : (string) ( $asset['browser_download_url'] ?? '' );
			if ( '' === $package ) {
				return null;
			}
			return array(
				'version'   => $version,
				'package'   => $package,
				'url'       => (string) ( $data['html_url'] ?? 'https://github.com/' . self::REPO ),
				'notes'     => (string) ( $data['body'] ?? '' ),
				'published' => (string) ( $data['published_at'] ?? '' ),
			);
		}
		return null;
	}

	private static function installed_version(): string {
		$data = get_file_data( self::$file, array( 'Version' => 'Version' ) );
		return (string) $data['Version'];
	}

	private static function token(): string {
		return defined( 'POLO_BLOCKS_MCP_GITHUB_TOKEN' ) ? (string) POLO_BLOCKS_MCP_GITHUB_TOKEN : '';
	}
}
