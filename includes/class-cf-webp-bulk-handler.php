<?php

class Cf_Webp_Bulk_Handler {

    private $processor;

    public function __construct() {
        $this->processor = new Cf_Webp_Processor();
    }

    public function run() {
        add_action( 'wp_ajax_cf_webp_bulk_convert', array( $this, 'handle_ajax' ) );
        add_action( 'wp_ajax_cf_webp_convert_post_urls', array( $this, 'handle_convert_post_urls_ajax' ) );
        add_action( 'wp_ajax_cf_webp_sync_r2', array( $this, 'handle_sync_r2_ajax' ) );
    }

    public function handle_ajax() {
        check_ajax_referer( 'cf_webp_bulk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $batch_size = 5; // Process 5 images at a time to avoid timeout

        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => array( 'image/jpeg', 'image/png' ),
            'post_status'    => 'inherit',
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        );

        $query = new WP_Query( $args );
        $posts = $query->posts;

        if ( empty( $posts ) ) {
            wp_send_json_success( array( 'complete' => true, 'message' => 'No more images to process.' ) );
        }


        $log = '';
        foreach ( $posts as $post_id ) {
            // Check if already processed
            global $wpdb;
            $table_name = $wpdb->prefix . 'cf_webp_status';
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE attachment_id = %d", $post_id ) );

            if ( $row ) {
                // Already converted, check if we need to upload to R2
                $options = get_option( 'cf_webp_settings' );
                $r2_enabled = isset( $options['enable_r2'] ) && $options['enable_r2'];
                
                // If R2 is enabled but this file hasn't been uploaded yet
                if ( $r2_enabled && $row->status === 'converted' ) {
                    $file_path = get_attached_file( $post_id );
                    $path_info = pathinfo( $file_path );
                    $webp_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';
                    
                    if ( file_exists( $webp_path ) ) {
                        // Upload to R2
                        $path_info = pathinfo( $webp_path );
                        $upload_dir = wp_upload_dir();
                        $relative_path = str_replace( $upload_dir['basedir'] . '/', '', $webp_path );
                        
                        $r2_client = new Cf_Webp_R2_Client();
                        $r2_url = $r2_client->upload_file( $webp_path, $relative_path, 'image/webp' );
                        
                        if ( ! is_wp_error( $r2_url ) ) {
                            // Update status to uploaded
                            $wpdb->update(
                                $table_name,
                                array(
                                    'status' => 'uploaded',
                                    'r2_url' => $r2_url,
                                    'updated_at' => current_time( 'mysql' )
                                ),
                                array( 'attachment_id' => $post_id ),
                                array( '%s', '%s', '%s' ),
                                array( '%d' )
                            );
                            $log .= "Uploaded existing WebP to R2 (ID $post_id).<br>";
                        } else {
                            $log .= "Failed to upload to R2 (ID $post_id): " . $r2_url->get_error_message() . "<br>";
                        }
                    } else {
                        $log .= "WebP file not found for ID $post_id.<br>";
                    }
                } else {
                    $log .= "Skipping ID $post_id (Already processed).<br>";
                }
            } else {
                // Trigger the processor manually
                // We need to fetch metadata first
                $metadata = wp_get_attachment_metadata( $post_id );
                $this->processor->process_attachment( $metadata, $post_id );
                $log .= "Processed ID $post_id.<br>";
            }
        }

        wp_send_json_success( array( 
            'complete' => false, 
            'offset' => $offset + $batch_size,
            'message' => $log 
        ) );
    }

    /**
     * Handle AJAX request to convert post content URLs to WebP
     */
    public function handle_convert_post_urls_ajax() {
        check_ajax_referer( 'cf_webp_bulk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $batch_size = 10; // Process 10 posts at a time
        $url_type = isset( $_POST['url_type'] ) ? sanitize_text_field( $_POST['url_type'] ) : 'local';

        $args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        );

        $query = new WP_Query( $args );
        $posts = $query->posts;

        if ( empty( $posts ) ) {
            wp_send_json_success( array( 
                'complete' => true, 
                'message' => 'All posts processed!' 
            ) );
        }

        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        $upload_path = $upload_dir['basedir'];
        $log = '';
        $updated_count = 0;
        
        // Get R2 settings if using R2 URLs
        $options = get_option( 'cf_webp_settings' );
        $r2_domain = isset( $options['r2_domain'] ) ? rtrim( $options['r2_domain'], '/' ) : '';

        foreach ( $posts as $post ) {
            $content = $post->post_content;
            $original_content = $content;
            
            // Find all image URLs in content
            preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );
            
            if ( empty( $matches[1] ) ) {
                $log .= "Post ID {$post->ID}: No images found.<br>";
                continue;
            }
            
            $replacements = 0;
            
            foreach ( $matches[1] as $img_url ) {
                // Skip external URLs
                if ( strpos( $img_url, $upload_url ) === false && strpos( $img_url, $r2_domain ) === false ) {
                    continue;
                }
                
                // Determine if this is already a WebP URL
                $is_webp = preg_match( '/\.webp$/i', $img_url );
                $is_r2_url = ! empty( $r2_domain ) && strpos( $img_url, $r2_domain ) !== false;
                $is_local_url = strpos( $img_url, $upload_url ) !== false;
                
                // Check if it's an image we can process (JPG, PNG, or WebP)
                if ( ! preg_match( '/\.(jpe?g|png|webp)$/i', $img_url ) ) {
                    continue;
                }
                
                // Determine the file path for verification
                $file_path = '';
                if ( $is_r2_url ) {
                    // Extract relative path from R2 URL
                    $relative_path = str_replace( $r2_domain . '/', '', $img_url );
                    $file_path = $upload_path . '/' . $relative_path;
                } else {
                    // Convert local URL to file path
                    $file_path = str_replace( $upload_url, $upload_path, $img_url );
                }
                
                // Get WebP file path
                $path_info = pathinfo( $file_path );
                if ( $is_webp ) {
                    $webp_path = $file_path; // Already WebP
                } else {
                    $webp_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';
                }
                
                // Check if WebP file exists
                if ( file_exists( $webp_path ) ) {
                    $webp_url = '';
                    
                    if ( $url_type === 'r2' && ! empty( $r2_domain ) ) {
                        // Convert to R2 CDN URL
                        $relative_path = str_replace( $upload_path . '/', '', $webp_path );
                        $webp_url = $r2_domain . '/' . $relative_path;
                        
                        // Skip if already R2 URL and same
                        if ( $img_url === $webp_url ) {
                            continue;
                        }
                    } else {
                        // Convert to local WebP URL
                        if ( $is_r2_url ) {
                            // Convert from R2 to local
                            $relative_path = str_replace( $upload_path . '/', '', $webp_path );
                            $webp_url = $upload_url . '/' . $relative_path;
                        } else {
                            // Convert from JPG/PNG to local WebP
                            $webp_url = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $img_url );
                        }
                        
                        // Skip if already local WebP URL and same
                        if ( $img_url === $webp_url ) {
                            continue;
                        }
                    }
                    
                    // Replace in content
                    $content = str_replace( $img_url, $webp_url, $content );
                    $replacements++;
                }
            }
            
            // Update post if content changed
            if ( $content !== $original_content ) {
                wp_update_post( array(
                    'ID'           => $post->ID,
                    'post_content' => $content,
                ) );
                
                $updated_count++;
                $url_type_label = $url_type === 'r2' ? 'R2 CDN' : 'Local';
                $log .= "Post ID {$post->ID}: Updated {$replacements} image(s) to {$url_type_label} WebP.<br>";
            } else {
                $log .= "Post ID {$post->ID}: No changes needed.<br>";
            }
        }

        wp_send_json_success( array( 
            'complete' => false, 
            'offset' => $offset + $batch_size,
            'message' => $log,
            'updated' => $updated_count
        ) );
    }

    /**
     * Create a WordPress attachment for an existing WebP file
     * Simplified version for bulk processing
     */
    private function create_webp_attachment_for_bulk( $webp_path, $parent_id ) {
        if ( ! file_exists( $webp_path ) ) {
            return false;
        }

        $parent_post = get_post( $parent_id );
        if ( ! $parent_post ) {
            return false;
        }

        $filename = basename( $webp_path );
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace( $upload_dir['basedir'] . '/', '', $webp_path );

        // Check if attachment already exists
        $existing = get_posts( array(
            'post_type' => 'attachment',
            'meta_query' => array(
                array(
                    'key' => '_wp_attached_file',
                    'value' => $relative_path,
                ),
            ),
            'posts_per_page' => 1,
            'fields' => 'ids',
        ) );

        if ( ! empty( $existing ) ) {
            return $existing[0];
        }

        // Create attachment post
        $attachment = array(
            'guid'           => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => 'image/webp',
            'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_parent'    => $parent_post->post_parent,
        );

        $attach_id = wp_insert_attachment( $attachment, $webp_path );

        if ( is_wp_error( $attach_id ) ) {
            return false;
        }

        $attach_data = wp_generate_attachment_metadata( $attach_id, $webp_path );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        update_post_meta( $attach_id, '_cf_webp_original_id', $parent_id );
        update_post_meta( $parent_id, '_cf_webp_version_id', $attach_id );

        return $attach_id;
    }

    /**
     * Handle AJAX request to sync WebP files to R2
     * Scans filesystem for WebP files and uploads any that are missing from R2
     */
    public function handle_sync_r2_ajax() {
        check_ajax_referer( 'cf_webp_bulk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $batch_size = 20; // Process 20 files at a time

        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'];
        
        // Get all WebP files from filesystem
        $webp_files = $this->find_webp_files( $upload_path, $offset, $batch_size );
        
        if ( empty( $webp_files['files'] ) ) {
            wp_send_json_success( array(
                'complete' => true,
                'message' => 'All WebP files synced to R2!'
            ) );
        }

        $r2_client = new Cf_Webp_R2_Client();
        $log = '';
        $uploaded_count = 0;
        $skipped_count = 0;

        foreach ( $webp_files['files'] as $webp_path ) {
            $relative_path = str_replace( $upload_path . '/', '', $webp_path );
            
            // Check if this file is already in R2 by checking the database
            global $wpdb;
            $table_name = $wpdb->prefix . 'cf_webp_status';
            
            // Try to find the attachment ID from the WebP path
            $attachment_id = $this->get_attachment_id_from_webp_path( $webp_path );
            
            if ( $attachment_id ) {
                // Check if already uploaded to R2
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT r2_url FROM $table_name WHERE attachment_id = %d AND status = 'uploaded' AND r2_url IS NOT NULL",
                    $attachment_id
                ) );
                
                if ( $row && ! empty( $row->r2_url ) ) {
                    // Check if this specific file (might be a size variant) is in R2
                    $expected_r2_url = $row->r2_url;
                    // For size variants, the r2_url in DB is for main file
                    // We need to check if the variant URL exists
                    $skipped_count++;
                    continue; // Skip if main file is uploaded (variants should be too)
                }
            }
            
            // Upload to R2
            $upload_result = $r2_client->upload_file( $webp_path, $relative_path, 'image/webp' );
            
            if ( ! is_wp_error( $upload_result ) ) {
                $options = get_option( 'cf_webp_settings' );
                $r2_domain = isset( $options['r2_domain'] ) ? rtrim( $options['r2_domain'], '/' ) : '';
                $r2_url = $r2_domain . '/' . $relative_path;
                
                // Update database if we have an attachment ID
                if ( $attachment_id ) {
                    $existing = $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM $table_name WHERE attachment_id = %d",
                        $attachment_id
                    ) );
                    
                    if ( $existing ) {
                        $wpdb->update(
                            $table_name,
                            array(
                                'status' => 'uploaded',
                                'r2_url' => $r2_url,
                            ),
                            array( 'id' => $existing ),
                            array( '%s', '%s' ),
                            array( '%d' )
                        );
                    } else {
                        $wpdb->insert(
                            $table_name,
                            array(
                                'attachment_id' => $attachment_id,
                                'status' => 'uploaded',
                                'r2_url' => $r2_url,
                            ),
                            array( '%d', '%s', '%s' )
                        );
                    }
                }
                
                $uploaded_count++;
                $log .= "Uploaded: {$relative_path}<br>";
            } else {
                $log .= "Failed: {$relative_path} - " . $upload_result->get_error_message() . "<br>";
            }
        }

        wp_send_json_success( array(
            'complete' => false,
            'offset' => $webp_files['next_offset'],
            'message' => $log,
            'uploaded' => $uploaded_count,
            'skipped' => $skipped_count,
            'total_found' => $webp_files['total']
        ) );
    }

    /**
     * Find WebP files in the uploads directory
     */
    private function find_webp_files( $directory, $offset, $limit ) {
        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $all_webp_files = array();
        foreach ( $iterator as $file ) {
            if ( $file->isFile() && strtolower( $file->getExtension() ) === 'webp' ) {
                $all_webp_files[] = $file->getPathname();
            }
        }

        $total = count( $all_webp_files );
        $files_batch = array_slice( $all_webp_files, $offset, $limit );

        return array(
            'files' => $files_batch,
            'total' => $total,
            'next_offset' => $offset + $limit
        );
    }

    /**
     * Get attachment ID from WebP file path
     */
    private function get_attachment_id_from_webp_path( $webp_path ) {
        // Remove .webp extension and try to find original
        $original_path = preg_replace( '/\.webp$/i', '.jpg', $webp_path );
        if ( ! file_exists( $original_path ) ) {
            $original_path = preg_replace( '/\.webp$/i', '.jpeg', $webp_path );
        }
        if ( ! file_exists( $original_path ) ) {
            $original_path = preg_replace( '/\.webp$/i', '.png', $webp_path );
        }

        if ( file_exists( $original_path ) ) {
            // Get attachment ID from original file path
            global $wpdb;
            $upload_dir = wp_upload_dir();
            $relative_path = str_replace( $upload_dir['basedir'] . '/', '', $original_path );
            
            $attachment_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
                $relative_path
            ) );
            
            return $attachment_id ? intval( $attachment_id ) : null;
        }

        return null;
    }
}
