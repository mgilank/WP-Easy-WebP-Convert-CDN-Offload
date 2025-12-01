<?php

class Cf_Webp_Processor {

	private $worker_client;
	private $r2_client;
	private $options;

	public function __construct() {
		$this->options = get_option( 'cf_webp_settings' );
		$this->worker_client = new Cf_Webp_Worker_Client();
		$this->r2_client = new Cf_Webp_R2_Client();
	}

	    public function run() {
        // Hook into attachment metadata generation (after upload)
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'process_attachment' ), 10, 2 );
        add_filter( 'wp_get_attachment_url', array( $this, 'replace_attachment_url' ), 10, 2 );
        add_filter( 'the_content', array( $this, 'replace_content_urls' ), 999 );
        add_filter( 'post_thumbnail_html', array( $this, 'replace_content_urls' ), 999 );
    }

	public function process_attachment( $metadata, $attachment_id ) {
		error_log( 'CF WebP: Processing attachment ID ' . $attachment_id );
		
		$file_path = get_attached_file( $attachment_id );
		$mime_type = get_post_mime_type( $attachment_id );

		error_log( 'CF WebP: File path: ' . $file_path );
		error_log( 'CF WebP: Mime type: ' . $mime_type );

		// Only process JPG and PNG for conversion
		// If it's already WebP, we can skip conversion but still upload to R2 if enabled
		if ( $mime_type === 'image/webp' ) {
			error_log( 'CF WebP: File is already WebP, skipping conversion' );
			
			// Check if already processed
			if ( $this->is_processed( $attachment_id ) ) {
				error_log( 'CF WebP: Already processed, skipping' );
				return $metadata;
			}
			
			// If R2 is enabled, upload the WebP file directly
			if ( isset( $this->options['enable_r2'] ) && $this->options['enable_r2'] ) {
				error_log( 'CF WebP: R2 enabled, uploading existing WebP file' );
				$upload_dir = wp_upload_dir();
				$relative_path = str_replace( $upload_dir['basedir'] . '/', '', $file_path );
				
				$upload_result = $this->r2_client->upload_file( $file_path, $relative_path, 'image/webp' );
				
				if ( ! is_wp_error( $upload_result ) ) {
					$r2_domain = isset( $this->options['r2_domain'] ) ? rtrim( $this->options['r2_domain'], '/' ) : '';
					$r2_url = $r2_domain . '/' . $relative_path;
					error_log( 'CF WebP: Uploaded existing WebP to R2: ' . $r2_url );
					
					// Upload size variants if they exist
					$this->process_image_sizes( $attachment_id, $metadata, $file_path );
					
					$this->log_status( $attachment_id, 'uploaded', $r2_url, $file_path );
				} else {
					error_log( 'CF WebP: Failed to upload existing WebP to R2: ' . $upload_result->get_error_message() );
					$this->log_status( $attachment_id, 'error', '', $file_path, 'R2 Upload Failed: ' . $upload_result->get_error_message() );
				}
			} else {
				// R2 not enabled, just log it
				$this->log_status( $attachment_id, 'converted', '', $file_path );
			}
			
			return $metadata;
		}
		
		if ( ! in_array( $mime_type, array( 'image/jpeg', 'image/png' ) ) ) {
			error_log( 'CF WebP: Skipping - not JPG, PNG, or WebP' );
			return $metadata;
		}

        // Check if already processed to avoid infinite loops if we update metadata
        if ( $this->is_processed( $attachment_id ) ) {
			error_log( 'CF WebP: Already processed, skipping' );
            return $metadata;
        }

		error_log( 'CF WebP: Starting conversion' );

		// 1. Convert to WebP (external API if enabled, otherwise local PHP)
		$webp_content = $this->worker_client->convert_image_with_fallback( $file_path );

		if ( is_wp_error( $webp_content ) ) {
			error_log( 'CF WebP: Conversion error: ' . $webp_content->get_error_message() );
			$this->log_status( $attachment_id, 'error', '', '', $webp_content->get_error_message() );
			return $metadata;
		}

		error_log( 'CF WebP: Received ' . strlen( $webp_content ) . ' bytes from Worker' );

		// Save WebP locally
		$path_info = pathinfo( $file_path );
		$webp_filename = $path_info['filename'] . '.webp';
		$webp_path = $path_info['dirname'] . '/' . $webp_filename;

		if ( false === file_put_contents( $webp_path, $webp_content ) ) {
			error_log( 'CF WebP: Failed to save WebP file to ' . $webp_path );
			$this->log_status( $attachment_id, 'error', '', '', 'Failed to save WebP file locally.' );
			return $metadata;
		}

	
	error_log( 'CF WebP: Saved WebP to ' . $webp_path );

	// Create a WordPress attachment for the WebP file
	$webp_attachment_id = $this->create_webp_attachment( $webp_path, $attachment_id );
	if ( $webp_attachment_id ) {
		error_log( 'CF WebP: Created attachment ID ' . $webp_attachment_id . ' for WebP file' );
	}

	// Process image size variants (thumbnails, medium, large, etc.)
	$this->process_image_sizes( $attachment_id, $metadata, $file_path );

        $r2_url = '';
        $status = 'converted';

		// 2. Offload to R2 (if enabled)
		if ( isset( $this->options['enable_r2'] ) && $this->options['enable_r2'] ) {
			error_log( 'CF WebP: R2 offload enabled, uploading...' );
            $upload_dir = wp_upload_dir();
            $relative_path = str_replace( $upload_dir['basedir'] . '/', '', $webp_path );
            
            // Upload WebP
            $upload_result = $this->r2_client->upload_file( $webp_path, $relative_path, 'image/webp' );
            
            if ( ! is_wp_error( $upload_result ) ) {
                $status = 'uploaded';
                $r2_domain = isset( $this->options['r2_domain'] ) ? rtrim( $this->options['r2_domain'], '/' ) : '';
                $r2_url = $r2_domain . '/' . $relative_path;
				error_log( 'CF WebP: Uploaded to R2: ' . $r2_url );
                
                // Optionally upload original
                // $this->r2_client->upload_file( $file_path, str_replace( $upload_dir['basedir'] . '/', '', $file_path ), 'image/jpeg' );
                // Handle "Keep Original" logic
                // NOTE: Deleting original files can break WordPress Media Library
                // It's safer to keep the original files and let R2 serve the WebP versions
                // Users can manually delete originals if needed
                /*
                if ( ! isset( $this->options['keep_original'] ) || ! $this->options['keep_original'] ) {
                    // If we are NOT keeping original, delete the local original file
                    // We only do this if R2 upload was successful to avoid data loss
                    if ( file_exists( $file_path ) ) {
                        unlink( $file_path );
						error_log( 'CF WebP: Deleted original file' );
                    }
                    
                    // Also delete the local WebP if we are offloading to R2 and don't want to keep local files?
                    // The user didn't explicitly say to delete local WebP, but "offload" usually implies it.
                    // However, for safety, let's just delete the original as requested by "replace existing image".
                    // If R2 is NOT enabled, we obviously keep the local WebP.
                }
                */

            } else {
				error_log( 'CF WebP: R2 upload failed: ' . $upload_result->get_error_message() );
                $this->log_status( $attachment_id, 'error', '', $webp_path, 'R2 Upload Failed: ' . $upload_result->get_error_message() );
                return $metadata;
            }
		} else {
            // R2 is NOT enabled.
			error_log( 'CF WebP: R2 not enabled' );
            // NOTE: Deleting original files breaks WordPress Media Library
            // Keep original files - WordPress will serve them, and our filter will serve WebP when appropriate
            /*
            // If "Keep Original" is unchecked, we delete the original and keep only the WebP.
            if ( ! isset( $this->options['keep_original'] ) || ! $this->options['keep_original'] ) {
                if ( file_exists( $file_path ) ) {
                    unlink( $file_path );
					error_log( 'CF WebP: Deleted original file (no R2)' );
                }
                
                // We also need to update metadata to point to the new file? 
                // WordPress attachment metadata relies on the file path. 
                // If we delete the original JPG, WordPress might break for this attachment unless we update the 'file' metadata to the WebP version.
                // But changing 'file' metadata to .webp might require regenerating thumbnails.
                // For this MVP, let's just delete the file. The user can use a plugin like "Enable Media Replace" or similar if they want full WP integration for replacing.
                // Or we can just leave it as is: the file is gone, but WP thinks it's there. That's bad.
                // BETTER APPROACH: If "Replace" is selected, we should probably use `update_attached_file` to point to the new WebP.
                
                update_attached_file( $attachment_id, $webp_path );
                wp_update_attachment_metadata( $attachment_id, $metadata ); // This might be recursive if we are not careful, but we have the is_processed check.
				error_log( 'CF WebP: Updated attachment file path to WebP' );
            }
            */
        }

		$this->log_status( $attachment_id, $status, $r2_url, $webp_path );
		error_log( 'CF WebP: Conversion complete, status: ' . $status );

		return $metadata;
	}

    public function replace_attachment_url( $url, $post_id ) {
        // Don't replace URLs in admin area (Media Library needs local URLs)
        if ( is_admin() ) {
            return $url;
        }
        
        // Only replace with R2 URL if R2 offload is enabled
        if ( ! isset( $this->options['enable_r2'] ) || ! $this->options['enable_r2'] ) {
            return $url;
        }

        // Check if we have an R2 URL for this attachment
        global $wpdb;
        $table_name = $wpdb->prefix . 'cf_webp_status';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT r2_url FROM $table_name WHERE attachment_id = %d AND status = 'uploaded'", $post_id ) );

        if ( $row && ! empty( $row->r2_url ) ) {
            return $row->r2_url;
        }
        
        return $url;
    }

/**
 * Replace image URLs in post content with WebP/R2 versions
 */
public function replace_content_urls( $content ) {
    if ( empty( $content ) ) {
        return $content;
    }

    global $wpdb;
    $upload_dir = wp_upload_dir();
    $upload_url = $upload_dir['baseurl'];
    
    // Check if R2 offload is enabled
    $r2_enabled = isset( $this->options['enable_r2'] ) && $this->options['enable_r2'];
    
    // Get all images with R2 URLs or WebP versions
    $table_name = $wpdb->prefix . 'cf_webp_status';
    
    // Find all image URLs in content (including srcset)
    preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );
    
    if ( empty( $matches[1] ) ) {
        return $content;
    }
    
    foreach ( $matches[1] as $img_url ) {
        // Skip external URLs (but allow local WebP URLs to be replaced with R2)
        if ( strpos( $img_url, $upload_url ) === false ) {
            continue;
        }
        
        // Get attachment ID from URL
        // Handle both regular and size variant URLs (e.g., image-150x150.jpg)
        // Also handle WebP URLs by converting them back to original extension
        $lookup_url = $img_url;
        
        // If URL is WebP, try to find original by replacing extension
        if ( preg_match( '/\.webp$/i', $img_url ) ) {
            $lookup_url = preg_replace( '/\.webp$/i', '.jpg', $img_url );
        }
        
        $attachment_id = attachment_url_to_postid( $lookup_url );
        
        // If not found, try to get the base image URL (without size suffix)
        if ( ! $attachment_id ) {
            // Remove size suffix like -150x150, -300x200, -scaled
            $base_url = preg_replace( '/-\d+x\d+(?=\.(jpe?g|png|webp))/i', '', $lookup_url );
            $base_url = preg_replace( '/-scaled(?=\.(jpe?g|png|webp))/i', '', $base_url );
            
            // Try different extensions
            $attachment_id = attachment_url_to_postid( $base_url );
            if ( ! $attachment_id ) {
                $base_url_png = preg_replace( '/\.(jpg|jpeg|webp)$/i', '.png', $base_url );
                $attachment_id = attachment_url_to_postid( $base_url_png );
            }
            if ( ! $attachment_id ) {
                $base_url_jpeg = preg_replace( '/\.(jpg|png|webp)$/i', '.jpeg', $base_url );
                $attachment_id = attachment_url_to_postid( $base_url_jpeg );
            }
        }
        
        if ( ! $attachment_id ) {
            continue;
        }
        
        // If R2 is enabled, check for R2 URL
        if ( $r2_enabled ) {
            $row = $wpdb->get_row( $wpdb->prepare( 
                "SELECT r2_url FROM $table_name WHERE attachment_id = %d AND status = 'uploaded' AND r2_url IS NOT NULL", 
                $attachment_id 
            ) );
            
            if ( $row && ! empty( $row->r2_url ) ) {
                // For size variants, we need to construct the R2 URL with the size suffix
                $r2_url = $row->r2_url;
                
                // Check if the original URL has a size suffix
                if ( preg_match( '/-\d+x\d+\.(jpe?g|png)$/i', $img_url, $size_match ) ) {
                    // Extract the size suffix from the original URL
                    preg_match( '/(-\d+x\d+)\.(jpe?g|png)$/i', $img_url, $suffix_match );
                    if ( isset( $suffix_match[1] ) ) {
                        // Replace the extension in R2 URL and add the size suffix
                        $r2_url = preg_replace( '/\.webp$/i', $suffix_match[1] . '.webp', $r2_url );
                    }
                }
                
                // Create fallback URLs (must use local domain, not R2 domain)
                $r2_domain = isset( $this->options['r2_domain'] ) ? rtrim( $this->options['r2_domain'], '/' ) : '';
                
                // Extract the relative path from R2 URL
                $relative_path = str_replace( $r2_domain . '/', '', $r2_url );
                
                // Construct local URLs
                $local_webp_url = $upload_url . '/' . $relative_path;
                $original_url = preg_replace( '/\.webp$/i', '.jpg', $local_webp_url );
                
                // Try .jpeg if .jpg doesn't match
                if ( ! preg_match( '/\.jpg$/i', $original_url ) ) {
                    $original_url = preg_replace( '/\.webp$/i', '.png', $local_webp_url );
                }
                
                // Find the full img tag for this URL
            // Find the full img tag for this URL
                if ( preg_match( '/<img([^>]+)src=["\']' . preg_quote( $img_url, '/' ) . '["\']([^>]*)>/i', $content, $img_match ) ) {
                    $full_img_tag = $img_match[0];
                    $before_src = $img_match[1];
                    $after_src = $img_match[2];
                    
                    // Process srcset in before_src and after_src to use R2 URLs
                    if ( ! empty( $r2_domain ) ) {
                        $process_srcset = function( $str ) use ( $upload_url, $r2_domain ) {
                            return preg_replace_callback( '/srcset=["\']([^"\']+)["\']/i', function( $m ) use ( $upload_url, $r2_domain ) {
                                $srcset_urls = explode( ',', $m[1] );
                                $new_urls = array();
                                foreach ( $srcset_urls as $url_entry ) {
                                    $url_entry = trim( $url_entry );
                                    if ( empty( $url_entry ) ) continue;
                                    
                                    $parts = preg_split( '/\s+/', $url_entry );
                                    $url = $parts[0];
                                    $width = isset( $parts[1] ) ? ' ' . $parts[1] : '';
                                    
                                    if ( strpos( $url, $upload_url ) !== false ) {
                                        $url = str_replace( $upload_url, $r2_domain, $url );
                                        $url = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $url );
                                    }
                                    $new_urls[] = $url . $width;
                                }
                                return 'srcset="' . implode( ', ', $new_urls ) . '"';
                            }, $str );
                        };
                        
                        $before_src = $process_srcset( $before_src );
                        $after_src = $process_srcset( $after_src );
                    }
                    
                    // Check if onerror already exists
                    if ( strpos( $full_img_tag, 'onerror=' ) === false ) {
                        // Add onerror fallback: Remove srcset -> R2 -> Local WebP -> Original
                        $fallback_script = "this.onerror=null;this.removeAttribute('srcset');this.src='" . esc_js( $local_webp_url ) . "';this.onerror=function(){this.onerror=null;this.src='" . esc_js( $original_url ) . "';}";
                        $new_img_tag = '<img' . $before_src . 'src="' . esc_url( $r2_url ) . '" onerror="' . $fallback_script . '"' . $after_src . '>';
                        $content = str_replace( $full_img_tag, $new_img_tag, $content );
                    } else {
                        // Just replace the URL if onerror exists
                        $content = str_replace( $img_url, $r2_url, $content );
                    }
                }
                
                continue;
            }
        }
        
        // If R2 is not enabled or no R2 URL found, check for local WebP version
        $file_path = get_attached_file( $attachment_id );
        if ( $file_path ) {
            $path_info = pathinfo( $file_path );
            
            // For size variants, we need to check the specific size file
            $webp_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';
            
            // If the URL has a size suffix, adjust the webp_path accordingly
            if ( preg_match( '/-\d+x\d+\.(jpe?g|png)$/i', $img_url ) ) {
                // Extract filename from URL
                $url_filename = basename( $img_url );
                $url_path_info = pathinfo( $url_filename );
                $webp_path = $path_info['dirname'] . '/' . $url_path_info['filename'] . '.webp';
            }
            
            if ( file_exists( $webp_path ) ) {
                // Replace extension in URL
                $webp_url = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $img_url );
                $content = str_replace( $img_url, $webp_url, $content );
            }
        }
    }
    
    return $content;
}
	private function log_status( $attachment_id, $status, $r2_url = '', $local_path = '', $error = '' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'cf_webp_status';

        // Check if exists
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE attachment_id = %d", $attachment_id ) );

        $data = array(
            'attachment_id' => $attachment_id,
            'status' => $status,
            'r2_url' => $r2_url,
            'local_webp_path' => $local_path,
            'error_message' => $error,
        );

        if ( $exists ) {
            $wpdb->update( $table_name, $data, array( 'id' => $exists ) );
        } else {
            $wpdb->insert( $table_name, $data );
        }
	}

    private function is_processed( $attachment_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cf_webp_status';
        return $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE attachment_id = %d AND status IN ('converted', 'uploaded')", $attachment_id ) );
    }

    /**
     * Create a WordPress attachment for the WebP file
     * 
     * @param string $webp_path Path to the WebP file
     * @param int $parent_id ID of the original attachment
     * @return int|false Attachment ID on success, false on failure
     */
    private function create_webp_attachment( $webp_path, $parent_id ) {
        // Check if file exists
        if ( ! file_exists( $webp_path ) ) {
            return false;
        }

        // Get the original attachment
        $parent_post = get_post( $parent_id );
        if ( ! $parent_post ) {
            return false;
        }

        // Prepare attachment data
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
            error_log( 'CF WebP: Attachment already exists for ' . $webp_path );
            return $existing[0];
        }

        // Create attachment post
        $attachment = array(
            'guid'           => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => 'image/webp',
            'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_parent'    => $parent_post->post_parent, // Use same parent as original
        );

        // Insert the attachment
        $attach_id = wp_insert_attachment( $attachment, $webp_path );

        if ( is_wp_error( $attach_id ) ) {
            error_log( 'CF WebP: Failed to create attachment: ' . $attach_id->get_error_message() );
            return false;
        }

        // Generate attachment metadata
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        $attach_data = wp_generate_attachment_metadata( $attach_id, $webp_path );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        // Add a custom meta to link it to the original
        update_post_meta( $attach_id, '_cf_webp_original_id', $parent_id );
        update_post_meta( $parent_id, '_cf_webp_version_id', $attach_id );

        return $attach_id;
    }

    /**
     * Process image size variants (thumbnails, medium, large, etc.)
     * 
     * @param int $attachment_id Attachment ID
     * @param array $metadata Attachment metadata
     * @param string $main_file_path Path to main image file
     */
    private function process_image_sizes( $attachment_id, $metadata, $main_file_path ) {
        if ( empty( $metadata['sizes'] ) ) {
            error_log( 'CF WebP: No size variants found for attachment ' . $attachment_id );
            return;
        }
        
        $path_info = pathinfo( $main_file_path );
        $upload_dir = wp_upload_dir();
        $r2_enabled = isset( $this->options['enable_r2'] ) && $this->options['enable_r2'];
        
        error_log( 'CF WebP: Processing ' . count( $metadata['sizes'] ) . ' size variants for attachment ' . $attachment_id );
        
        foreach ( $metadata['sizes'] as $size_name => $size_data ) {
            $size_file = $size_data['file'];
            $size_path = $path_info['dirname'] . '/' . $size_file;
            
            // Skip if size file doesn't exist
            if ( ! file_exists( $size_path ) ) {
                error_log( "CF WebP: Size variant file not found: {$size_path}" );
                continue;
            }
            
            // Convert size variant to WebP
            $webp_content = $this->worker_client->convert_image_with_fallback( $size_path );
            
            if ( is_wp_error( $webp_content ) ) {
                error_log( "CF WebP: Failed to convert size variant {$size_name}: " . $webp_content->get_error_message() );
                continue;
            }
            
            // Save WebP version
            $size_path_info = pathinfo( $size_path );
            $size_webp_filename = $size_path_info['filename'] . '.webp';
            $size_webp_path = $size_path_info['dirname'] . '/' . $size_webp_filename;
            
            if ( false === file_put_contents( $size_webp_path, $webp_content ) ) {
                error_log( "CF WebP: Failed to save WebP for size {$size_name}" );
                continue;
            }
            
            error_log( "CF WebP: Converted size variant {$size_name} to WebP: {$size_webp_filename}" );
            
            // Upload to R2 if enabled
            if ( $r2_enabled ) {
                $relative_path = str_replace( $upload_dir['basedir'] . '/', '', $size_webp_path );
                $upload_result = $this->r2_client->upload_file( $size_webp_path, $relative_path, 'image/webp' );
                
                if ( ! is_wp_error( $upload_result ) ) {
                    $r2_domain = isset( $this->options['r2_domain'] ) ? rtrim( $this->options['r2_domain'], '/' ) : '';
                    $r2_url = $r2_domain . '/' . $relative_path;
                    error_log( "CF WebP: Uploaded size variant {$size_name} to R2: {$r2_url}" );
                } else {
                    error_log( "CF WebP: Failed to upload size variant {$size_name} to R2: " . $upload_result->get_error_message() );
                }
            }
        }
        
        error_log( 'CF WebP: Finished processing size variants for attachment ' . $attachment_id );
    }
}
