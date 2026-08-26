<?php
/**
 * Main plugin class.
 *
 * @package FieldLock_Sync_Guard_For_ACF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects pending ACF Local JSON changes and locks field-group editing.
 */
final class FieldLock_Sync_Guard_For_ACF {

	/** Transient key. */
	private const TRANSIENT_KEY = 'fieldlock_sync_guard_for_acf_pending';

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

		$url            = $this->get_sync_url();
		$pending_groups = $this->get_pending_groups();
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'ACF Local JSON sync is pending.', 'fieldlock-sync-guard-for-acf' ); ?></strong>
				<?php
				printf(
					/* translators: %s: Number of pending ACF field groups. */
					esc_html( _n( '%s field group requires syncing. Editing is locked until it is synced.', '%s field groups require syncing. Editing is locked until they are synced.', count( $pending_groups ), 'fieldlock-sync-guard-for-acf' ) ),
					esc_html( number_format_i18n( count( $pending_groups ) ) )
				);
				?>
				<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Review field group sync', 'fieldlock-sync-guard-for-acf' ); ?></a>
			</p>
			<?php $this->render_pending_group_list( $pending_groups ); ?>
		</div>
		<?php
	}

	/**
	 * Renders the pending field-group names and reasons.
	 *
	 * @param array $pending_groups Pending field-group details.
	 */
	private function render_pending_group_list( $pending_groups ) {
		if ( empty( $pending_groups ) ) {
			return;
		}
		?>
		<ul>
			<?php foreach ( $pending_groups as $group ) : ?>
				<li>
					<strong><?php echo esc_html( $group['title'] ); ?></strong>
					&mdash; <?php echo esc_html( $this->get_pending_reason_label( $group['reason'] ) ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
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
			'fieldlock-sync-guard-for-acf',
			FIELDLOCK_SYNC_GUARD_FOR_ACF_URL . 'assets/js/admin-field-group-lock.js',
			array(),
			FIELDLOCK_SYNC_GUARD_FOR_ACF_VERSION,
			true
		);

		wp_localize_script(
			'fieldlock-sync-guard-for-acf',
			'fieldLockSyncGuardForAcf',
			array(
				'title'         => __( 'Field-group editing is locked', 'fieldlock-sync-guard-for-acf' ),
				'message'       => __( 'Sync the pending ACF Local JSON changes before editing field groups.', 'fieldlock-sync-guard-for-acf' ),
				'actionLabel'   => __( 'Review field group sync', 'fieldlock-sync-guard-for-acf' ),
				'syncUrl'       => $this->get_sync_url(),
				'pendingGroups' => array_map(
					function ( $group ) {
						return array(
							'title'  => $group['title'],
							'reason' => $this->get_pending_reason_label( $group['reason'] ),
						);
					},
					$this->get_pending_groups()
				),
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
			esc_html__( 'This field group was not saved because ACF Local JSON changes are waiting to be synced.', 'fieldlock-sync-guard-for-acf' ),
			esc_html__( 'ACF field-group editing locked', 'fieldlock-sync-guard-for-acf' ),
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
		return (bool) apply_filters( 'fieldlock_sync_guard_for_acf_should_lock', $pending, $pending );
	}

	/**
	 * Detects Local JSON field groups that are missing or newer in the database.
	 *
	 * @return bool
	 */
	private function has_pending_sync() {
		return ! empty( $this->get_pending_groups() );
	}

	/**
	 * Returns details of Local JSON field groups waiting to be synced.
	 *
	 * @return array<int,array{key:string,title:string,reason:string}>
	 */
	private function get_pending_groups() {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( is_string( $cached ) ) {
			$decoded = json_decode( $cached, true );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		$pending  = $this->scan_for_pending_sync();
		$lifetime = (int) apply_filters( 'fieldlock_sync_guard_for_acf_cache_lifetime', MINUTE_IN_SECONDS );

		set_transient( self::TRANSIENT_KEY, wp_json_encode( $pending ), max( 1, $lifetime ) );

		return $pending;
	}

	/**
	 * Performs the uncached Local JSON scan.
	 *
	 * @return array<int,array{key:string,title:string,reason:string}>
	 */
	private function scan_for_pending_sync() {
		if ( ! $this->acf_ready || ! function_exists( 'acf_get_local_json_files' ) ) {
			return array();
		}

		$files = acf_get_local_json_files();

		if ( ! is_array( $files ) || empty( $files ) ) {
			return array();
		}

		if ( function_exists( 'acf_get_internal_post_type_posts' ) ) {
			return $this->scan_acf_field_groups();
		}

		$database_groups = $this->get_database_field_groups();

		$pending = array();

		foreach ( $files as $file ) {
			$item = $this->read_json_item( $file );

			if ( ! $this->is_public_field_group( $item ) ) {
				continue;
			}

			$key      = $item['key'];
			$modified = isset( $item['modified'] ) ? (int) $item['modified'] : 0;

			if ( ! isset( $database_groups[ $key ] ) ) {
				$pending[] = $this->format_pending_group( $item, 'missing' );
			} elseif ( $modified > $database_groups[ $key ] ) {
				$pending[] = $this->format_pending_group( $item, 'newer' );
			}
		}

		return $pending;
	}

	/**
	 * Uses ACF's merged local and database field-group collection when available.
	 *
	 * This follows the same comparisons used by ACF's Sync Available screen.
	 *
	 * @return array<int,array{key:string,title:string,reason:string}>
	 */
	private function scan_acf_field_groups() {
		$groups = acf_get_internal_post_type_posts( 'acf-field-group' );

		if ( ! is_array( $groups ) ) {
			return array();
		}

		$pending = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || ! empty( $group['private'] ) || 'json' !== ( $group['local'] ?? '' ) ) {
				continue;
			}

			$post_id  = isset( $group['ID'] ) ? (int) $group['ID'] : 0;
			$modified = isset( $group['modified'] ) ? (int) $group['modified'] : 0;

			if ( ! $post_id ) {
				$pending[] = $this->format_pending_group( $group, 'missing' );
				continue;
			}

			$database_modified = get_post_modified_time( 'U', true, $post_id );

			if ( $modified && $modified > (int) $database_modified ) {
				$pending[] = $this->format_pending_group( $group, 'newer' );
			}
		}

		return $pending;
	}

	/**
	 * Normalizes pending field-group details for caching and display.
	 *
	 * @param array  $group  ACF field-group data.
	 * @param string $reason Pending reason.
	 * @return array{key:string,title:string,reason:string}
	 */
	private function format_pending_group( $group, $reason ) {
		$key   = isset( $group['key'] ) && is_string( $group['key'] ) ? $group['key'] : '';
		$title = isset( $group['title'] ) && is_string( $group['title'] ) ? $group['title'] : '';

		if ( '' === $title ) {
			$title = $key;
		}

		return array(
			'key'    => $key,
			'title'  => $title,
			'reason' => $reason,
		);
	}

	/**
	 * Returns a localized label for a pending reason.
	 *
	 * @param string $reason Pending reason.
	 * @return string
	 */
	private function get_pending_reason_label( $reason ) {
		if ( 'missing' === $reason ) {
			return __( 'not yet imported into the database', 'fieldlock-sync-guard-for-acf' );
		}

		return __( 'Local JSON is newer than the database version', 'fieldlock-sync-guard-for-acf' );
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

		$capability = apply_filters( 'fieldlock_sync_guard_for_acf_capability', $capability );

		return is_string( $capability ) && current_user_can( $capability );
	}

	/**
	 * Gets the ACF field group sync URL.
	 *
	 * @return string
	 */
	private function get_sync_url() {
		$url = admin_url( 'edit.php?post_type=acf-field-group&post_status=sync' );

		return (string) apply_filters( 'fieldlock_sync_guard_for_acf_sync_url', $url );
	}

	/**
	 * Clears the cached detection result.
	 */
	public function clear_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}
}
