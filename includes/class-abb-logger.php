<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight request log stored in its own table.
 */
class ABB_Logger {

	public static function init() {
		// Table is created on activation; nothing to hook at runtime.
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'abb_log';
	}

	public static function install_table() {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			external_id VARCHAR(191) NOT NULL DEFAULT '',
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(20) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			message TEXT NULL,
			PRIMARY KEY  (id),
			KEY external_id (external_id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * @param array $row external_id, post_id, action, status, message
	 */
	public static function log( array $row ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'created_at'  => current_time( 'mysql' ),
				'external_id' => substr( (string) ( $row['external_id'] ?? '' ), 0, 191 ),
				'post_id'     => absint( $row['post_id'] ?? 0 ),
				'action'      => substr( (string) ( $row['action'] ?? '' ), 0, 20 ),
				'status'      => substr( (string) ( $row['status'] ?? '' ), 0, 20 ),
				'user_id'     => get_current_user_id(),
				'message'     => (string) ( $row['message'] ?? '' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);

		self::prune();
	}

	private static function prune() {
		global $wpdb;

		$keep  = absint( ABB_Settings::get( 'log_retention', 200 ) );
		$table = self::table();

		// Delete everything older than the newest $keep rows.
		$cutoff = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $keep ) );
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) );
		}
	}

	public static function render_table( $limit = 25 ) {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", absint( $limit ) ) );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No requests logged yet.', 'ai-blog-bridge' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'ai-blog-bridge' ); ?></th>
				<th><?php esc_html_e( 'External ID', 'ai-blog-bridge' ); ?></th>
				<th><?php esc_html_e( 'Post', 'ai-blog-bridge' ); ?></th>
				<th><?php esc_html_e( 'Action', 'ai-blog-bridge' ); ?></th>
				<th><?php esc_html_e( 'Status', 'ai-blog-bridge' ); ?></th>
				<th><?php esc_html_e( 'Message', 'ai-blog-bridge' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->created_at ); ?></td>
					<td><code><?php echo esc_html( $row->external_id ); ?></code></td>
					<td>
						<?php if ( $row->post_id ) : ?>
							<a href="<?php echo esc_url( get_edit_post_link( $row->post_id ) ); ?>">#<?php echo (int) $row->post_id; ?></a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $row->action ); ?></td>
					<td><?php echo esc_html( $row->status ); ?></td>
					<td><?php echo esc_html( $row->message ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
