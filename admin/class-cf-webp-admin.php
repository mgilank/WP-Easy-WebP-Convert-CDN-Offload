<?php

class Cf_Webp_Admin {

	private $option_name = 'cf_webp_settings';

	public function run() {
		add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'show_conversion_method_notice' ) );
	}

	public function add_plugin_admin_menu() {
		add_options_page(
			'Easy WebP Converter',
			'Easy WebP Converter',
			'manage_options',
			'cf-webp-converter',
			array( $this, 'display_plugin_setup_page' )
		);
	}

	public function register_settings() {
		register_setting( $this->option_name, $this->option_name, array( $this, 'sanitize_settings' ) );

		add_settings_section(
			'cf_webp_r2_section',
			'CDN Offload Settings (R2)',
			null,
			'cf-webp-converter'
		);

		add_settings_field(
			'enable_r2',
			'Enable CDN Offload',
			array( $this, 'render_checkbox_field' ),
			'cf-webp-converter',
			'cf_webp_r2_section',
			array( 'label_for' => 'enable_r2', 'description' => 'Upload WebP files to cloud storage (R2/S3).' )
		);
		
		add_settings_field(
			'storage_provider',
			'Storage Provider',
			array( $this, 'render_select_field' ),
			'cf-webp-converter',
			'cf_webp_r2_section',
			array( 
				'label_for' => 'storage_provider',
				'options' => array(
					'r2' => 'Cloudflare R2',
					's3' => 'AWS S3',
					'spaces' => 'DigitalOcean Spaces',
					'wasabi' => 'Wasabi',
					'backblaze' => 'Backblaze B2',
					'custom' => 'Custom S3-Compatible'
				),
				'description' => 'Choose your cloud storage provider.'
			)
		);
		
		add_settings_field(
			'storage_region',
			'Storage Region',
			array( $this, 'render_text_field' ),
			'cf-webp-converter',
			'cf_webp_r2_section',
			array( 
				'label_for' => 'storage_region',
				'placeholder' => 'e.g., us-east-1, nyc3, us-east-1',
				'description' => 'Required for AWS S3, DigitalOcean Spaces, Wasabi, and Backblaze. Use "auto" for Cloudflare R2.'
			)
		);
		
		add_settings_field(
			'storage_endpoint',
			'Custom Endpoint (Optional)',
			array( $this, 'render_text_field' ),
			'cf-webp-converter',
			'cf_webp_r2_section',
			array( 
				'label_for' => 'storage_endpoint',
				'placeholder' => 'https://s3.example.com',
				'description' => 'Only needed for custom S3-compatible storage. Leave empty for standard providers.'
			)
		);

		add_settings_field(
			'r2_account_id',
			'Account ID',
			array( $this, 'render_text_field' ),
			'cf-webp-converter',
			'cf_webp_r2_section',
			array( 'label_for' => 'r2_account_id' )
		);

		add_settings_field(
			'r2_access_key',
			'Access Key ID',
			array( $this, 'render_text_field' ),
			'cf-webp-converter',
			'cf_webp_r2_section',
			array( 'label_for' => 'r2_access_key' )
		);

		add_settings_field(
			'r2_secret_key',
			'Secret Access Key',
			array( $this, 'render_text_field' ),
			'cf-webp-converter',
			'cf_webp_r2_section',
			array( 'label_for' => 'r2_secret_key', 'type' => 'password' )
		);

		add_settings_field(
			'r2_bucket',
			'Bucket Name',
			array( $this, 'render_text_field' ),
			'cf-webp-converter',
			'cf_webp_r2_section',
			array( 'label_for' => 'r2_bucket' )
		);

		add_settings_field(
			'r2_domain',
			'Public Domain (e.g., https://cdn.example.com)',
			array( $this, 'render_text_field' ),
			'cf-webp-converter',
			'cf_webp_r2_section',
			array( 'label_for' => 'r2_domain' )
		);

        add_settings_section(
			'cf_webp_general_section',
			'General Settings',
			null,
			'cf-webp-converter'
		);

        add_settings_field(
			'keep_original',
			'Keep Original Images',
			array( $this, 'render_checkbox_field' ),
			'cf-webp-converter',
			'cf_webp_general_section',
			array( 'label_for' => 'keep_original', 'description' => 'If unchecked, original images will be deleted after conversion/upload.' )
		);

		add_settings_field(
			'use_external_api',
			'Use External API',
			array( $this, 'render_checkbox_field' ),
			'cf-webp-converter',
			'cf_webp_general_section',
			array( 'label_for' => 'use_external_api', 'description' => 'Enable external API for WebP conversion (disables local PHP conversion).' )
		);

		add_settings_field(
			'external_api_url',
			'External API URL',
			array( $this, 'render_text_field' ),
			'cf-webp-converter',
			'cf_webp_general_section',
			array( 'label_for' => 'external_api_url', 'placeholder' => 'http://your-vps-ip:3000/convert' )
		);

		add_settings_field(
			'external_api_key',
			'External API Key (Optional)',
			array( $this, 'render_text_field' ),
			'cf-webp-converter',
			'cf_webp_general_section',
			array( 'label_for' => 'external_api_key', 'type' => 'password', 'placeholder' => 'Leave empty if no API key required' )
		);
		
		add_settings_field(
			'preferred_url_type',
			'Preferred URL Type for Post Conversion',
			array( $this, 'render_radio_field' ),
			'cf-webp-converter',
			'cf_webp_general_section',
			array( 
				'label_for' => 'preferred_url_type',
				'options' => array(
					'local' => 'Local WebP',
					'r2' => 'R2 CDN'
				),
				'description' => 'Default URL type when converting post content URLs to WebP.'
			)
		);
	}

	public function sanitize_settings( $input ) {
		// Get existing options to preserve values not in the current input
		$existing_options = get_option( $this->option_name, array() );
		$new_input = array();
		
        $new_input['enable_r2'] = isset( $input['enable_r2'] ) ? 1 : 0;
        
        // Preserve R2 credentials even when R2 is disabled
        // Only update if new values are provided, otherwise keep existing
        $new_input['r2_account_id'] = isset( $input['r2_account_id'] ) && $input['r2_account_id'] !== '' 
            ? sanitize_text_field( $input['r2_account_id'] ) 
            : ( isset( $existing_options['r2_account_id'] ) ? $existing_options['r2_account_id'] : '' );
            
        $new_input['r2_access_key'] = isset( $input['r2_access_key'] ) && $input['r2_access_key'] !== '' 
            ? sanitize_text_field( $input['r2_access_key'] ) 
            : ( isset( $existing_options['r2_access_key'] ) ? $existing_options['r2_access_key'] : '' );
            
        $new_input['r2_secret_key'] = isset( $input['r2_secret_key'] ) && $input['r2_secret_key'] !== '' 
            ? sanitize_text_field( $input['r2_secret_key'] ) 
            : ( isset( $existing_options['r2_secret_key'] ) ? $existing_options['r2_secret_key'] : '' );
            
        $new_input['r2_bucket'] = isset( $input['r2_bucket'] ) && $input['r2_bucket'] !== '' 
            ? sanitize_text_field( $input['r2_bucket'] ) 
            : ( isset( $existing_options['r2_bucket'] ) ? $existing_options['r2_bucket'] : '' );
            
        $new_input['r2_domain'] = isset( $input['r2_domain'] ) && $input['r2_domain'] !== '' 
            ? esc_url_raw( $input['r2_domain'] ) 
            : ( isset( $existing_options['r2_domain'] ) ? $existing_options['r2_domain'] : '' );
        
        $new_input['keep_original'] = isset( $input['keep_original'] ) ? 1 : 0;
        $new_input['use_external_api'] = isset( $input['use_external_api'] ) ? 1 : 0;
        
        // Preserve external API settings similarly
        $new_input['external_api_url'] = isset( $input['external_api_url'] ) && $input['external_api_url'] !== '' 
            ? esc_url_raw( $input['external_api_url'] ) 
            : ( isset( $existing_options['external_api_url'] ) ? $existing_options['external_api_url'] : '' );
            
        $new_input['external_api_key'] = isset( $input['external_api_key'] ) && $input['external_api_key'] !== '' 
            ? sanitize_text_field( $input['external_api_key'] ) 
            : ( isset( $existing_options['external_api_key'] ) ? $existing_options['external_api_key'] : '' );
        
        $new_input['preferred_url_type'] = isset( $input['preferred_url_type'] ) && in_array( $input['preferred_url_type'], array( 'local', 'r2' ) )
            ? sanitize_text_field( $input['preferred_url_type'] )
            : 'local';
        
        // Storage provider settings
        $valid_providers = array( 'r2', 's3', 'spaces', 'wasabi', 'backblaze', 'custom' );
        $new_input['storage_provider'] = isset( $input['storage_provider'] ) && in_array( $input['storage_provider'], $valid_providers )
            ? sanitize_text_field( $input['storage_provider'] )
            : 'r2';
        
        $new_input['storage_region'] = isset( $input['storage_region'] )
            ? sanitize_text_field( $input['storage_region'] )
            : 'auto';
        
        $new_input['storage_endpoint'] = isset( $input['storage_endpoint'] ) && $input['storage_endpoint'] !== ''
            ? esc_url_raw( $input['storage_endpoint'] )
            : '';

		return $new_input;
	}

	public function render_text_field( $args ) {
		$options = get_option( $this->option_name );
		$id = $args['label_for'];
		$value = isset( $options[$id] ) ? $options[$id] : '';
		$type = isset( $args['type'] ) ? $args['type'] : 'text';
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		$description = isset( $args['description'] ) ? '<p class="description">' . $args['description'] . '</p>' : '';
		echo '<input type="' . $type . '" id="' . $id . '" name="' . $this->option_name . '[' . $id . ']" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" class="regular-text">';
		echo $description;
	}
	
	public function render_select_field( $args ) {
		$options = get_option( $this->option_name );
		$id = $args['label_for'];
		$current_value = isset( $options[$id] ) ? $options[$id] : '';
		$select_options = isset( $args['options'] ) ? $args['options'] : array();
		$description = isset( $args['description'] ) ? '<p class="description">' . $args['description'] . '</p>' : '';
		
		echo '<select id="' . $id . '" name="' . $this->option_name . '[' . $id . ']" class="regular-text">';
		foreach ( $select_options as $value => $label ) {
			$selected = ( $current_value === $value ) ? 'selected' : '';
			echo '<option value="' . esc_attr( $value ) . '" ' . $selected . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo $description;
	}

    public function render_checkbox_field( $args ) {
		$options = get_option( $this->option_name );
		$id = $args['label_for'];
		$checked = isset( $options[$id] ) && $options[$id] ? 'checked' : '';
        $description = isset( $args['description'] ) ? '<p class="description">' . $args['description'] . '</p>' : '';
		echo '<input type="checkbox" id="' . $id . '" name="' . $this->option_name . '[' . $id . ']" value="1" ' . $checked . '>';
        echo $description;
	}
	
	public function render_radio_field( $args ) {
		$options = get_option( $this->option_name );
		$id = $args['label_for'];
		$current_value = isset( $options[$id] ) ? $options[$id] : 'local';
		$radio_options = isset( $args['options'] ) ? $args['options'] : array();
		$description = isset( $args['description'] ) ? '<p class="description">' . $args['description'] . '</p>' : '';
		
		foreach ( $radio_options as $value => $label ) {
			$checked = ( $current_value === $value ) ? 'checked' : '';
			echo '<label style="display: block; margin: 5px 0;">';
			echo '<input type="radio" name="' . $this->option_name . '[' . $id . ']" value="' . esc_attr( $value ) . '" ' . $checked . '> ';
			echo esc_html( $label );
			echo '</label>';
		}
		echo $description;
	}

	public function display_plugin_setup_page() {
		$active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
		?>
		<div class="wrap">
			<h1>Easy WebP Converter & CDN Offload</h1>
			<h2 class="nav-tab-wrapper">
				<a href="?page=cf-webp-converter&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
				<a href="?page=cf-webp-converter&tab=bulk" class="nav-tab <?php echo $active_tab == 'bulk' ? 'nav-tab-active' : ''; ?>">Tools</a>
			</h2>

			<?php if ( $active_tab == 'settings' ) : ?>
				<div style="display: flex; gap: 20px;">
					<div style="flex: 1;">
						<form action="options.php" method="post">
							<?php
							settings_fields( $this->option_name );
							do_settings_sections( 'cf-webp-converter' );
							submit_button();
							?>
						</form>
					</div>
					<div style="width: 350px;">
						<div class="card">
							<h2 style="margin-top: 0;">System Status</h2>
							<?php
							$method = Cf_Webp_Worker_Client::get_conversion_method();
							$has_gd = function_exists( 'imagewebp' );
							$has_imagick = class_exists( 'Imagick' );
							?>
							<table class="widefat">
								<tbody>
									<tr>
										<td><strong>GD Library</strong></td>
										<td><?php echo $has_gd ? '<span style="color: #46b450;">✅ Available</span>' : '<span style="color: #dc3232;">❌ Not Available</span>'; ?></td>
									</tr>
									<tr>
										<td><strong>ImageMagick</strong></td>
										<td><?php echo $has_imagick ? '<span style="color: #46b450;">✅ Available</span>' : '<span style="color: #dc3232;">❌ Not Available</span>'; ?></td>
									</tr>
									<tr>
										<td><strong>Active Method</strong></td>
										<td><?php 
											if ( $method !== 'None' ) {
												echo '<span style="color: #46b450;"><strong>' . esc_html( $method ) . '</strong></span>';
											} else {
												echo '<span style="color: #dc3232;"><strong>None</strong></span>';
											}
										?></td>
									</tr>
									<tr>
										<td><strong>PHP Version</strong></td>
										<td><?php echo phpversion(); ?></td>
									</tr>
								</tbody>
							</table>
							<?php if ( $method === 'None' ) : ?>
								<div class="notice notice-error inline" style="margin: 15px 0 0 0; padding: 8px 12px;">
									<p style="margin: 0;"><strong>Action Required:</strong> Install GD or ImageMagick.</p>
								</div>
							<?php else : ?>
								<div class="notice notice-success inline" style="margin: 15px 0 0 0; padding: 8px 12px;">
									<p style="margin: 0;"><strong>Ready!</strong> WebP conversion is enabled.</p>
								</div>
							<?php endif; ?>
							<p style="margin-top: 15px;">
								<a href="?page=cf-webp-converter&tab=status" class="button">View Detailed Status →</a>
							</p>
						</div>
					</div>
				</div>
				<script>
				jQuery(document).ready(function($) {
					// Toggle R2 fields based on checkbox
					function toggleR2Fields() {
						var isChecked = $('#enable_r2').is(':checked');
						// Only change visual appearance, don't disable to preserve values on submit
						$('#r2_account_id, #r2_access_key, #r2_secret_key, #r2_bucket, #r2_domain')
							.css('opacity', isChecked ? '1' : '0.5')
							.attr('readonly', !isChecked);
					}
					
					// Toggle external API fields based on checkbox
					function toggleExternalAPIFields() {
						var isChecked = $('#use_external_api').is(':checked');
						// Only change visual appearance, don't disable to preserve values on submit
						$('#external_api_url, #external_api_key')
							.css('opacity', isChecked ? '1' : '0.5')
							.attr('readonly', !isChecked);
					}
					
					// Run on page load
					toggleR2Fields();
					toggleExternalAPIFields();
					
					// Run when checkboxes change
					$('#enable_r2').on('change', toggleR2Fields);
					$('#use_external_api').on('change', toggleExternalAPIFields);
				});
				</script>
			<?php else : ?>
					<div class="card">
						<h2>Bulk Convert Existing Images</h2>
						<p>This tool will scan your Media Library for images that haven't been converted yet and process them.</p>
						<p><strong>Note:</strong> Keep this tab open while the process runs.</p>
						<p>
							<label>
								<input type="checkbox" id="cf-webp-force-rerun" value="1">
								Force re-run (reconvert even if marked as processed)
							</label>
						</p>
						<button id="cf-webp-start-bulk" class="button button-primary">Start Bulk Conversion</button>
						<div id="cf-webp-progress" style="margin-top: 20px; display: none;">
							<div class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></div>
							<span id="cf-webp-status-text">Processing...</span>
							<div id="cf-webp-log" style="margin-top: 10px; max-height: 300px; overflow-y: auto; background: #f0f0f1; padding: 10px; border: 1px solid #ccc;"></div>
					</div>
				</div>
				<script>
				jQuery(document).ready(function($) {
					$('#cf-webp-start-bulk').on('click', function() {
						var $btn = $(this);
						var $progress = $('#cf-webp-progress');
						var $log = $('#cf-webp-log');
						var $status = $('#cf-webp-status-text');
						
						$btn.prop('disabled', true);
						$progress.show();
						
						var forceRerun = $('#cf-webp-force-rerun').is(':checked') ? 1 : 0;
						var hasErrors = false;

						function process_batch( offset ) {
							$.post(ajaxurl, {
								action: 'cf_webp_bulk_convert',
								offset: offset,
								force_rerun: forceRerun,
								nonce: '<?php echo wp_create_nonce( "cf_webp_bulk_nonce" ); ?>'
							}, function(response) {
								if ( response.success ) {
									if ( response.data.had_error ) {
										hasErrors = true;
									}
									if ( response.data.complete ) {
										$('.spinner').removeClass('is-active').hide();
										if ( hasErrors || response.data.had_error ) {
											$status.html('<span class="dashicons dashicons-warning" style="color: #d63638; font-size: 20px; width: 20px; height: 20px;"></span> Conversion failed. Check log for details.');
											$log.append('<p><strong>Conversion Failed. See log for errors.</strong></p>');
										} else {
											$status.html('<span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 20px; width: 20px; height: 20px;"></span> All done!');
											$log.append('<p><strong>Conversion Complete!</strong></p>');
										}
										$btn.prop('disabled', false);
									} else {
										$log.append('<p>' + response.data.message + '</p>');
										$log.scrollTop($log[0].scrollHeight);
										$status.text('Processed ' + response.data.offset + ' images...');
										process_batch( response.data.offset );
									}
								} else {
									$log.append('<p style="color: red;">Error: ' + response.data + '</p>');
									$btn.prop('disabled', false);
								}
							}).fail(function() {
								$log.append('<p style="color: red;">Server Error. Retrying...</p>');
								setTimeout(function() { process_batch(offset); }, 3000);
							});
						}
						
						process_batch(0);
					});
				});
				</script>
					
					<!-- Convert Post URLs Section -->
					<div class="card" style="margin-top: 20px;">
						<h2>Convert Post URLs to WebP</h2>
						<p>This tool will update all image URLs in your blog posts to use the .webp extension.</p>
						<p><strong>Note:</strong> This will permanently modify post content. Make sure you have a backup before proceeding.</p>
						<p><strong>Requirement:</strong> Run "Bulk Convert Existing Images" first to ensure WebP files exist.</p>
						
						<div style="margin: 15px 0;">
							<p><strong>Choose URL type:</strong> (You can change the default in Settings tab)</p>
							<?php 
							$options = get_option( 'cf_webp_settings' );
							$preferred_type = isset( $options['preferred_url_type'] ) ? $options['preferred_url_type'] : 'local';
							?>
							<label style="display: block; margin: 8px 0;">
								<input type="radio" name="url_type" value="local" <?php checked( $preferred_type, 'local' ); ?>>
								<strong>Local WebP</strong> - Use local WebP files (e.g., /uploads/image.webp)
							</label>
							<label style="display: block; margin: 8px 0;">
								<input type="radio" name="url_type" value="r2" <?php checked( $preferred_type, 'r2' ); ?>>
								<strong>R2 CDN</strong> - Use R2 CDN URLs (e.g., https://cdn.example.com/uploads/image.webp)
							</label>
						</div>
						
						<button id="cf-webp-convert-urls" class="button button-primary">Convert Post URLs to WebP</button>
						<div id="cf-webp-url-progress" style="margin-top: 20px; display: none;">
							<div class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></div>
							<span id="cf-webp-url-status-text">Processing posts...</span>
							<div id="cf-webp-url-log" style="margin-top: 10px; max-height: 300px; overflow-y: auto; background: #f0f0f1; padding: 10px; border: 1px solid #ccc;"></div>
						</div>
					</div>
					<script>
				jQuery(document).ready(function($) {
					$('#cf-webp-convert-urls').on('click', function() {
						var $btn = $(this);
						var $progress = $('#cf-webp-url-progress');
						var $log = $('#cf-webp-url-log');
						var $status = $('#cf-webp-url-status-text');
						
						if (!confirm('This will permanently modify your post content. Have you created a backup?')) {
							return;
						}
						
						$btn.prop('disabled', true);
						$progress.show();
						$log.html('');
						
						var totalUpdated = 0;
						
						// Get selected URL type
						var urlType = $('input[name="url_type"]:checked').val();
						
						function process_posts( offset ) {
							$.post(ajaxurl, {
								action: 'cf_webp_convert_post_urls',
								offset: offset,
								url_type: urlType,
								nonce: '<?php echo wp_create_nonce( "cf_webp_bulk_nonce" ); ?>'
							}, function(response) {
								if ( response.success ) {
									if ( response.data.complete ) {
										$('.spinner').removeClass('is-active').hide();
										$status.html('<span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 20px; width: 20px; height: 20px;"></span> All posts processed!');
										$btn.prop('disabled', false);
										$log.append('<p><strong>URL Conversion Complete! Total posts updated: ' + totalUpdated + '</strong></p>');
									} else {
										if (response.data.updated) {
											totalUpdated += response.data.updated;
										}
										$log.append(response.data.message);
										$log.scrollTop($log[0].scrollHeight);
										$status.text('Processed ' + response.data.offset + ' posts... (' + totalUpdated + ' updated)');
										process_posts( response.data.offset );
									}
								} else {
									$log.append('<p style="color: red;">Error: ' + response.data + '</p>');
									$btn.prop('disabled', false);
								}
							}).fail(function() {
								$log.append('<p style="color: red;">Server Error. Retrying...</p>');
								setTimeout(function() { process_posts(offset); }, 3000);
							});
						}
						
						process_posts(0);
					});
				});
				</script>
					
					<!-- Sync WebP to R2 Section -->
					<div class="card" style="margin-top: 20px;">
						<h2>Sync WebP Files to R2</h2>
						<p>This tool will scan your uploads directory for WebP files that haven't been uploaded to R2 yet and upload them.</p>
						<p><strong>Use this when:</strong> You enabled R2 after converting images, or if some uploads failed.</p>
						<button id="cf-webp-sync-r2" class="button button-primary">Sync WebP to R2</button>
						<div id="cf-webp-sync-progress" style="margin-top: 20px; display: none;">
							<div class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></div>
							<span id="cf-webp-sync-status-text">Scanning files...</span>
							<div id="cf-webp-sync-log" style="margin-top: 10px; max-height: 300px; overflow-y: auto; background: #f0f0f1; padding: 10px; border: 1px solid #ccc;"></div>
						</div>
					</div>
					<script>
				jQuery(document).ready(function($) {
					$('#cf-webp-sync-r2').on('click', function() {
						var $btn = $(this);
						var $progress = $('#cf-webp-sync-progress');
						var $log = $('#cf-webp-sync-log');
						var $status = $('#cf-webp-sync-status-text');
						
						$btn.prop('disabled', true);
						$progress.show();
						$log.html('');
						
						var totalUploaded = 0;
						var totalSkipped = 0;
						
						function sync_batch( offset ) {
							$.post(ajaxurl, {
								action: 'cf_webp_sync_r2',
								offset: offset,
								nonce: '<?php echo wp_create_nonce( "cf_webp_bulk_nonce" ); ?>'
							}, function(response) {
								if ( response.success ) {
									if ( response.data.complete ) {
										$('.spinner').removeClass('is-active').hide();
										$status.html('<span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 20px; width: 20px; height: 20px;"></span> Sync complete!');
										$btn.prop('disabled', false);
										$log.append('<p><strong>Sync Complete! Uploaded: ' + totalUploaded + ', Skipped: ' + totalSkipped + '</strong></p>');
									} else {
										if (response.data.uploaded) {
											totalUploaded += response.data.uploaded;
										}
										if (response.data.skipped) {
											totalSkipped += response.data.skipped;
										}
										$log.append(response.data.message);
										$log.scrollTop($log[0].scrollHeight);
										$status.text('Processing... (Uploaded: ' + totalUploaded + ', Skipped: ' + totalSkipped + ')');
										sync_batch( response.data.offset );
									}
								} else {
									$log.append('<p style="color: red;">Error: ' + response.data + '</p>');
									$btn.prop('disabled', false);
								}
							}).fail(function() {
								$log.append('<p style="color: red;">Server Error. Retrying...</p>');
								setTimeout(function() { sync_batch(offset); }, 3000);
							});
						}
						
						sync_batch(0);
					});
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	public function show_conversion_method_notice() {
		$screen = get_current_screen();
		if ( $screen && $screen->id !== 'settings_page_cf-webp-converter' ) {
			return;
		}

		$method = Cf_Webp_Worker_Client::get_conversion_method();
		$options = get_option( 'cf_webp_settings' );
		
		if ( $method === 'None' ) {
			?>
			<div class="notice notice-error">
				<p><strong>Easy WebP Converter:</strong> Neither GD (with WebP support) nor ImageMagick is available. Please install one of these PHP extensions to enable WebP conversion.</p>
			</div>
			<?php
		} else {
			// Determine the message based on method
			if ( $method === 'External API' ) {
				$message = 'Using <strong>External API</strong> for WebP conversion.';
			} else {
				$message = 'Using <strong>' . esc_html( $method ) . '</strong> for local WebP conversion.';
			}
			?>
			<div class="notice notice-info">
				<p><strong>Easy WebP Converter:</strong> <?php echo $message; ?></p>
			</div>
			<?php
		}
	}
}
