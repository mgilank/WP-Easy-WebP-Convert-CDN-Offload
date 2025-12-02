<?php

class Cf_Webp_Worker_Client {

	private $use_external_api;
	private $external_api_url;
	private $external_api_key;

	public function __construct() {
		$options = get_option( 'cf_webp_settings' );
		$this->use_external_api = isset( $options['use_external_api'] ) && $options['use_external_api'];
		$this->external_api_url = isset( $options['external_api_url'] ) ? $options['external_api_url'] : '';
		$this->external_api_key = isset( $options['external_api_key'] ) ? $options['external_api_key'] : '';
	}

	/**
	 * Sends an image to the Cloudflare Worker for conversion.
	 *
	 * @param string $image_path Absolute path to the image file.
	 * @return string|WP_Error WebP binary data on success, WP_Error on failure.
	 */
	public function convert_image( $image_path ) {
		if ( empty( $this->worker_url ) ) {
			return new WP_Error( 'missing_config', 'Worker URL is not configured.' );
		}

		if ( ! file_exists( $image_path ) ) {
			return new WP_Error( 'file_not_found', 'Image file not found: ' . $image_path );
		}

		$file_contents = file_get_contents( $image_path );
		if ( false === $file_contents ) {
			return new WP_Error( 'read_error', 'Failed to read image file.' );
		}

		$args = array(
			'body'    => $file_contents,
			'headers' => array(
				'Content-Type' => mime_content_type( $image_path ),
			),
			'timeout' => 60, // Increase timeout for large images
		);

		if ( ! empty( $this->auth_token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->auth_token;
		}

		$response = wp_remote_post( $this->worker_url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error( 'worker_error', 'Worker returned error: ' . $response_code . ' ' . wp_remote_retrieve_body( $response ) );
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Convert image locally using PHP GD or ImageMagick
	 * 
	 * @param string $image_path Absolute path to the image file
	 * @return string|WP_Error WebP binary data on success, WP_Error on failure
	 */
	public function convert_image_local( $image_path ) {
		if ( ! file_exists( $image_path ) ) {
			return new WP_Error( 'file_not_found', 'Image file not found: ' . $image_path );
		}

		// Try GD first
		if ( function_exists( 'imagewebp' ) ) {
			return $this->convert_with_gd( $image_path );
		}

		// Try ImageMagick
		if ( class_exists( 'Imagick' ) ) {
			return $this->convert_with_imagick( $image_path );
		}

		return new WP_Error( 'no_converter', 'Neither GD (with WebP support) nor ImageMagick is available.' );
	}

	/**
	 * Convert image: use external API if enabled, otherwise use local PHP
	 * 
	 * @param string $image_path Absolute path to the image file
	 * @return string|WP_Error WebP binary data on success, WP_Error on failure
	 */
	public function convert_image_with_fallback( $image_path ) {
		// If external API is enabled, use it exclusively
		if ( $this->use_external_api && ! empty( $this->external_api_url ) ) {
			error_log( 'CF WebP: Using external API (local conversion disabled)' );
			$result = $this->convert_via_external_api( $image_path );
			
			if ( ! is_wp_error( $result ) ) {
				error_log( 'CF WebP: Converted using external API' );
				return $result;
			}
			
			// External API failed
			error_log( 'CF WebP: External API failed: ' . $result->get_error_message() );
			return $result;
		}
		
		// External API not enabled, use local conversion
		error_log( 'CF WebP: Using local PHP conversion' );
		$result = $this->convert_image_local( $image_path );
		
		if ( ! is_wp_error( $result ) ) {
			error_log( 'CF WebP: Converted using local PHP (' . self::get_conversion_method() . ')' );
		}
		
		return $result;
	}

	/**
	 * Convert image using external API
	 */
	private function convert_via_external_api( $image_path ) {
		if ( ! file_exists( $image_path ) ) {
			return new WP_Error( 'file_not_found', 'Image file not found: ' . $image_path );
		}

		$file_contents = file_get_contents( $image_path );
		if ( false === $file_contents ) {
			return new WP_Error( 'read_error', 'Failed to read image file.' );
		}

		$args = array(
			'body'    => $file_contents,
			'headers' => array(
				'Content-Type' => mime_content_type( $image_path ),
			),
			'timeout' => 60,
		);

		// Add API key if provided
		if ( ! empty( $this->external_api_key ) ) {
			$args['headers']['X-API-Key'] = $this->external_api_key;
		}

		$response = wp_remote_post( $this->external_api_url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $response_code ) {
            if ( $response_code === 401 || $response_code === 403 ) {
                return new WP_Error( 'api_auth', 'External API authentication failed: invalid API key or permissions.' );
            }
            return new WP_Error( 'api_error', 'External API returned error: ' . $response_code . ' ' . wp_remote_retrieve_body( $response ) );
        }

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Convert image using GD
	 */
	private function convert_with_gd( $image_path ) {
		$mime = mime_content_type( $image_path );
		$image = false;

		if ( $mime === 'image/jpeg' ) {
			$image = @imagecreatefromjpeg( $image_path );
		} elseif ( $mime === 'image/png' ) {
			$image = @imagecreatefrompng( $image_path );
			// Preserve transparency
			if ( $image ) {
				imagepalettetotruecolor( $image );
				imagealphablending( $image, true );
				imagesavealpha( $image, true );
			}
		} else {
			return new WP_Error( 'unsupported', 'Unsupported image type: ' . $mime );
		}

		if ( ! $image ) {
			return new WP_Error( 'gd_error', 'Failed to create image resource with GD.' );
		}

		// Convert to WebP
		ob_start();
		$result = @imagewebp( $image, null, 85 );
		$webp_content = ob_get_clean();
		imagedestroy( $image );

		if ( ! $result || empty( $webp_content ) ) {
			return new WP_Error( 'conversion_failed', 'Failed to convert image to WebP with GD.' );
		}

		return $webp_content;
	}

	/**
	 * Convert image using ImageMagick
	 */
	private function convert_with_imagick( $image_path ) {
		try {
			$imagick = new Imagick( $image_path );
			$imagick->setImageFormat( 'webp' );
			$imagick->setImageCompressionQuality( 85 );
			$imagick->setOption( 'webp:method', '6' ); // Best quality/compression balance
			$webp_content = $imagick->getImageBlob();
			$imagick->clear();
			$imagick->destroy();

			if ( empty( $webp_content ) ) {
				return new WP_Error( 'conversion_failed', 'Failed to convert image to WebP with ImageMagick.' );
			}

			return $webp_content;
		} catch ( Exception $e ) {
			return new WP_Error( 'imagick_error', 'ImageMagick error: ' . $e->getMessage() );
		}
	}

	/**
	 * Check if local conversion is available
	 */
	public static function is_local_conversion_available() {
		return function_exists( 'imagewebp' ) || class_exists( 'Imagick' );
	}

	/**
	 * Get the conversion method being used
	 */
	public static function get_conversion_method() {
		$options = get_option( 'cf_webp_settings' );
		$use_external_api = isset( $options['use_external_api'] ) && $options['use_external_api'];
		
		// If external API is enabled, return that
		if ( $use_external_api && ! empty( $options['external_api_url'] ) ) {
			return 'External API';
		}
		
		// Otherwise check for local methods
		if ( function_exists( 'imagewebp' ) ) {
			return 'GD';
		}
		if ( class_exists( 'Imagick' ) ) {
			return 'ImageMagick';
		}
		return 'None';
	}
}
