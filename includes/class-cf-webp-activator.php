<?php

class Cf_Webp_Activator {

	public static function activate() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'cf_webp_status';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) NOT NULL,
			status varchar(20) DEFAULT 'pending' NOT NULL,
			r2_url text DEFAULT '' NOT NULL,
			local_webp_path text DEFAULT '' NOT NULL,
			error_message text DEFAULT '' NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}
}
