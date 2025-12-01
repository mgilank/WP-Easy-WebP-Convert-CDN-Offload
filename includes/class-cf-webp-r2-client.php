<?php

class Cf_Webp_R2_Client {

	private $account_id;
	private $access_key;
	private $secret_key;
	private $bucket;
	private $endpoint;

	public function __construct() {
		$options = get_option( 'cf_webp_settings' );
		$this->account_id = isset( $options['r2_account_id'] ) ? $options['r2_account_id'] : '';
		$this->access_key = isset( $options['r2_access_key'] ) ? $options['r2_access_key'] : '';
		$this->secret_key = isset( $options['r2_secret_key'] ) ? $options['r2_secret_key'] : '';
		$this->bucket     = isset( $options['r2_bucket'] ) ? $options['r2_bucket'] : '';
		
		// R2 Endpoint: https://<accountid>.r2.cloudflarestorage.com
		$this->endpoint = "https://{$this->account_id}.r2.cloudflarestorage.com";
	}

	public function is_configured() {
		return ! empty( $this->account_id ) && ! empty( $this->access_key ) && ! empty( $this->secret_key ) && ! empty( $this->bucket );
	}

	/**
	 * Uploads a file to R2.
	 *
	 * @param string $file_path Absolute path to the file.
	 * @param string $key Object key (path in bucket).
	 * @param string $mime_type MIME type of the file.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function upload_file( $file_path, $key, $mime_type ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'missing_config', 'R2 is not configured.' );
		}

		$content = file_get_contents( $file_path );
		if ( false === $content ) {
			return new WP_Error( 'read_error', 'Failed to read file.' );
		}

		return $this->put_object( $key, $content, $mime_type );
	}

	/**
	 * Uploads raw content to R2.
	 *
	 * @param string $key Object key.
	 * @param string $content File content.
	 * @param string $mime_type MIME type.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function put_object( $key, $content, $mime_type ) {
		$uri = "/{$this->bucket}/{$key}";
		$url = $this->endpoint . $uri;
		
		$headers = array(
			'Content-Type' => $mime_type,
			'Content-Length' => strlen( $content ),
		);

		$signed_headers = $this->sign_request( 'PUT', $uri, $headers, $content );

		$args = array(
			'method'  => 'PUT',
			'body'    => $content,
			'headers' => $signed_headers,
			'timeout' => 60,
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code && 201 !== $response_code ) {
			return new WP_Error( 'r2_error', 'R2 returned error: ' . $response_code . ' ' . wp_remote_retrieve_body( $response ) );
		}

		return true;
	}

    /**
     * Deletes a file from R2.
     * 
     * @param string $key Object key.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_object( $key ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'missing_config', 'R2 is not configured.' );
        }

        $uri = "/{$this->bucket}/{$key}";
        $url = $this->endpoint . $uri;

        $signed_headers = $this->sign_request( 'DELETE', $uri, array(), '' );

        $args = array(
            'method'  => 'DELETE',
            'headers' => $signed_headers,
            'timeout' => 30,
        );

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( 204 !== $response_code && 200 !== $response_code ) {
             return new WP_Error( 'r2_error', 'R2 returned error: ' . $response_code . ' ' . wp_remote_retrieve_body( $response ) );
        }

        return true;
    }

	private function sign_request( $method, $uri, $headers, $payload ) {
		$service = 's3';
		$region = 'auto';
		$algorithm = 'AWS4-HMAC-SHA256';
		$now = time();
		$amz_date = gmdate( 'Ymd\THis\Z', $now );
		$date_stamp = gmdate( 'Ymd', $now );

		// Canonical Headers
		$canonical_headers = '';
		$signed_headers_list = array();
		$headers['host'] = parse_url( $this->endpoint, PHP_URL_HOST );
		$headers['x-amz-date'] = $amz_date;
        $headers['x-amz-content-sha256'] = hash( 'sha256', $payload );

		ksort( $headers );
		foreach ( $headers as $k => $v ) {
			$k = strtolower( $k );
			$canonical_headers .= $k . ':' . trim( $v ) . "\n";
			$signed_headers_list[] = $k;
		}
		$signed_headers_str = implode( ';', $signed_headers_list );

		// Canonical Request
		$canonical_request = $method . "\n" . $uri . "\n" . '' . "\n" . $canonical_headers . "\n" . $signed_headers_str . "\n" . $headers['x-amz-content-sha256'];

		// String to Sign
		$credential_scope = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';
		$string_to_sign = $algorithm . "\n" . $amz_date . "\n" . $credential_scope . "\n" . hash( 'sha256', $canonical_request );

		// Signing Key
		$k_secret = 'AWS4' . $this->secret_key;
		$k_date = hash_hmac( 'sha256', $date_stamp, $k_secret, true );
		$k_region = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

		// Signature
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		// Authorization Header
		$authorization = $algorithm . ' Credential=' . $this->access_key . '/' . $credential_scope . ', SignedHeaders=' . $signed_headers_str . ', Signature=' . $signature;

		$headers['Authorization'] = $authorization;

		return $headers;
	}
}
