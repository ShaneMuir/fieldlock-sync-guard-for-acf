<?php
/**
 * Main plugin class.
 *
 * @package JSON_Sync_Guard_For_ACF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects pending ACF Local JSON changes and locks field-group editing.
 */
final class JSON_Sync_Guard_For_ACF {

	/** Transient key. */
	private const TRANSIENT_KEY = 'json_sync_guard_for_acf_pending';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Whether the ACF integration has been registered.
	 *
	 * @var bool
	 */
	private $acf_ready = false;

	/**
	 * Returns the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks that do not execute on the front end.
	 */
	private function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'acf/init', array( $this, 'register_admin_hooks' ) );
	}

	/**
	 * Registers integration hooks after ACF is ready.
	 */
	public function register_admin_hooks() {
		$this->acf_ready = true;

		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'block_field_group_save' ), 10, 4 );

		add_action( 'acf/update_field_group', array( $this, 'clear_cache' ) );
		add_action( 'acf/trash_field_group', array( $this, 'clear_cache' ) );
		add_action( 'acf/untrash_field_group', array( $this, 'clear_cache' ) );
		add_action( 'acf/delete_field_group', array( $this, 'clear_cache' ) );
		add_action( 'save_post_acf-field-group', array( $this, 'clear_cache' ) );
	}

	/**
	 * Shows a warning when Local JSON needs to be synced.
	 */
	public function render_admin_notice() {
		if ( ! $this->current_user_can_manage_field_groups() || ! $this->should_lock() ) {
			return;
		}

		$url = $this->get_sync_url();
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'ACF Local JSON sync is pending.', 'json-sync-guard-for-acf' ); ?></strong>
				<?php esc_html_e( 'Field-group editing is locked until the pending JSON changes are synced.', 'json-sync-guard-for-acf' ); ?>
				<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Review field group sync', 'json-sync-guard-for-acf' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Enqueues the lock script on ACF field-group edit screens only.
	 */
	public function enqueue_admin_assets() {
		$screen = get_current_screen();

		if ( ! $screen || 'acf-field-group' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		if ( ! $this->current_user_can_manage_field_groups() || ! $this->should_lock() ) {
			return;
		}

		wp_enqueue_script(
			'json-sync-guard-for-acf',
			JSON_SYNC_GUARD_FOR_ACF_URL . 'assets/js/admin-field-group-lock.js',
			array(),
			JSON_SYNC_GUARD_FOR_ACF_VERSION,
			true
		);

		wp_localize_script(
			'json-sync-guard-for-acf',
			'jsonSyncGuardForAcf',
			array(
				'message' => __( 'Sync the pending ACF Local JSON changes before editing field groups.', 'json-sync-guard-for-acf' ),
			)
		);
	}

	/**
	 * Stops a normal field-group edit request before WordPress writes to the DB.
	 *
	 * Restricting this to the post.php edit action leaves ACF's Local JSON sync
	 * request free to create or update the field groups that clear the lock.
	 *
	 * @param array $data                Slashed post data.
	 * @param array $postarr             Sanitized post data.
	 * @param array $unsanitized_postarr Unsanitized post data.
	 * @param bool  $update              Whether this is an existing post update.
	 * @return array
	 */
	public function block_field_group_save( $data, $postarr, $unsanitized_postarr, $update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		global $pagenow;

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'post.php' !== $pagenow || 'editpost' !== $action || 'acf-field-group' !== $data['post_type'] ) {
			return $data;
		}

		if ( ! $this->current_user_can_manage_field_groups() || ! $this->should_lock() ) {
			return $data;
		}

		wp_die(
			esc_html__( 'This field group was not saved because ACF Local JSON changes are waiting to be synced.', 'json-sync-guard-for-acf' ),
			esc_html__( 'ACF field-group editing locked', 'json-sync-guard-for-acf' ),
			array(
				'back_link' => true,
				'response'  => 409,
			)
		);

		return $data;
	}

	/**
	 * Determines whether field-group changes should be locked.
	 *
	 * @return bool
	 */
	private function should_lock() {
		$pending = $this->has_pending_sync();

		/**
		 * Filters whether ACF field-group editing should be locked.
		 *
		 * @param bool $lock    Whether editing should be locked.
		 * @param bool $pending Whether a pending Local JSON sync was detected.
		 */
		return (bool) apply_filters( 'json_sync_guard_for_acf_should_lock', $pending, $pending );
	}

	/**
	 * Detects Local JSON field groups that are missing or newer in the database.
	 *
	 * @return bool
	 */
	private function has_pending_sync() {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$pending  = $this->scan_for_pending_sync();
		$lifetime = (int) apply_filters( 'json_sync_guard_for_acf_cache_lifetime', MINUTE_IN_SECONDS );

		set_transient( self::TRANSIENT_KEY, $pending ? '1' : '0', max( 1, $lifetime ) );

		return $pending;
	}

	/**
	 * Performs the uncached Local JSON scan.
	 *
	 * @return bool
	 */
	private function scan_for_pending_sync() {
		if ( ! $this->acf_ready || ! function_exists( 'acf_get_local_json_files' ) ) {
			return false;
		}

		$files = acf_get_local_json_files();

		if ( ! is_array( $files ) || empty( $files ) ) {
			return false;
		}

		if ( function_exists( 'acf_get_internal_post_type_posts' ) ) {
			return $this->scan_acf_field_groups();
		}

		$database_groups = $this->get_database_field_groups();

		foreach ( $files as $file ) {
			$item = $this->read_json_item( $file );

			if ( ! $this->is_public_field_group( $item ) ) {
				continue;
			}

			$key      = $item['key'];
			$modified = isset( $item['modified'] ) ? (int) $item['modified'] : 0;

			if ( ! isset( $database_groups[ $key ] ) || $modified > $database_groups[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Uses ACF's merged local and database field-group collection when available.
	 *
	 * This follows the same comparisons used by ACF's Sync Available screen.
	 *
	 * @return bool
	 */
	private function scan_acf_field_groups() {
		$groups = acf_get_internal_post_type_posts( 'acf-field-group' );

		if ( ! is_array( $groups ) ) {
			return false;
		}

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || ! empty( $group['private'] ) || 'json' !== ( $group['local'] ?? '' ) ) {
				continue;
			}

			$post_id  = isset( $group['ID'] ) ? (int) $group['ID'] : 0;
			$modified = isset( $group['modified'] ) ? (int) $group['modified'] : 0;

			if ( ! $post_id ) {
				return true;
			}

			$database_modified = get_post_modified_time( 'U', true, $post_id );

			if ( $modified && $modified > (int) $database_modified ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns DB field-group modification times keyed by the ACF group key.
	 *
	 * @return array<string,int>
	 */
	private function get_database_field_groups() {
		$groups = get_posts(
			array(
				'post_type'              => 'acf-field-group',
				'post_status'            => array( 'publish', 'acf-disabled', 'draft', 'private' ),
				'posts_per_page'         => -1,
				'orderby'                => 'none',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$indexed = array();

		foreach ( $groups as $group ) {
			$modified                     = get_post_modified_time( 'U', true, $group );
			$indexed[ $group->post_name ] = false === $modified ? 0 : (int) $modified;
		}

		return $indexed;
	}

	/**
	 * Reads one file result returned by acf_get_local_json_files().
	 *
	 * @param mixed $file File path or an ACF file descriptor.
	 * @return array
	 */
	private function read_json_item( $file ) {
		if ( is_array( $file ) && isset( $file['data'] ) && is_array( $file['data'] ) ) {
			return $file['data'];
		}

		if ( is_array( $file ) && isset( $file['path'] ) ) {
			$file = $file['path'];
		}

		if ( ! is_string( $file ) || ! is_readable( $file ) ) {
			return array();
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents ) {
			return array();
		}

		$item = json_decode( $contents, true );

		return is_array( $item ) ? $item : array();
	}

	/**
	 * Checks that a decoded JSON item is a non-private field group.
	 *
	 * @param array $item Decoded JSON item.
	 * @return bool
	 */
	private function is_public_field_group( $item ) {
		return isset( $item['key'] )
			&& is_string( $item['key'] )
			&& 0 === strpos( $item['key'], 'group_' )
			&& empty( $item['private'] );
	}

	/**
	 * Gets the capability required to manage ACF field groups.
	 *
	 * @return bool
	 */
	private function current_user_can_manage_field_groups() {
		$capability = function_exists( 'acf_get_setting' ) ? acf_get_setting( 'capability' ) : 'manage_options';

		if ( ! is_string( $capability ) || '' === $capability ) {
			$capability = 'manage_options';
		}

		$capability = apply_filters( 'json_sync_guard_for_acf_capability', $capability );

		return is_string( $capability ) && current_user_can( $capability );
	}

	/**
	 * Gets the ACF field group sync URL.
	 *
	 * @return string
	 */
	private function get_sync_url() {
		$url = admin_url( 'edit.php?post_type=acf-field-group&post_status=sync' );

		return (string) apply_filters( 'json_sync_guard_for_acf_sync_url', $url );
	}

	/**
	 * Clears the cached detection result.
	 */
	public function clear_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}
}
