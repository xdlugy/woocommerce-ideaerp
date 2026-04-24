<?php

namespace WooIdeaERP\Api;

use WooIdeaERP\Helpers\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Thin HTTP client for the IdeaERP REST API.
 * All requests are authenticated with a Bearer token.
 */
class Client {

	private string $base_url;
	private string $token;

	public function __construct( string $base_url, string $token ) {
		$this->base_url = rtrim( $base_url, '/' );
		$this->token    = $token;
	}

	/**
	 * @param  array<string,mixed> $params  Query parameters.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On HTTP or API error.
	 */
	public function get( string $path, array $params = [] ): array {
		$url = $this->build_url( $path, $params );

		Logger::debug( sprintf( 'API GET %s', $url ) );

		$response = wp_remote_get( $url, $this->default_args() );

		return $this->parse( 'GET', $url, $response );
	}

	/**
	 * @param  array<string,mixed> $body  JSON-serialisable payload.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On HTTP or API error.
	 */
	public function post( string $path, array $body = [] ): array {
		$url  = $this->build_url( $path );
		$args = array_merge( $this->default_args(), [
			'method' => 'POST',
			'body'   => wp_json_encode( $body ),
		] );

		Logger::debug( sprintf( 'API POST %s | body: %s', $url, wp_json_encode( $this->redact_body( $body ) ) ) );

		$response = wp_remote_post( $url, $args );

		return $this->parse( 'POST', $url, $response );
	}

	/**
	 * @param  array<string,mixed> $body
	 * @return array<string,mixed>
	 * @throws \RuntimeException On HTTP or API error.
	 */
	public function patch( string $path, array $body = [] ): array {
		$url  = $this->build_url( $path );
		$args = array_merge( $this->default_args(), [
			'method' => 'PATCH',
			'body'   => wp_json_encode( $body ),
		] );

		Logger::debug( sprintf( 'API PATCH %s | body: %s', $url, wp_json_encode( $this->redact_body( $body ) ) ) );

		$response = wp_remote_request( $url, $args );

		return $this->parse( 'PATCH', $url, $response );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Mask PII (customer name, email, phone, address, VAT, note) in request bodies
	 * before they reach debug.log. Only structural keys used by this plugin's payloads
	 * are touched; unknown keys are logged as-is.
	 *
	 * @param  array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	private function redact_body( array $body ): array {
		$partner_keys = [ 'partner', 'partner_invoice', 'partner_shipping' ];
		$sensitive    = [ 'name', 'company_name', 'email', 'phone', 'street', 'city', 'zip', 'vat' ];

		foreach ( $partner_keys as $pk ) {
			if ( isset( $body[ $pk ] ) && is_array( $body[ $pk ] ) ) {
				foreach ( $sensitive as $sk ) {
					if ( array_key_exists( $sk, $body[ $pk ] ) && $body[ $pk ][ $sk ] !== null ) {
						$body[ $pk ][ $sk ] = '[redacted]';
					}
				}
			}
		}

		if ( array_key_exists( 'integration_email', $body ) && $body['integration_email'] !== null ) {
			$body['integration_email'] = '[redacted]';
		}

		if ( array_key_exists( 'note', $body ) && $body['note'] !== null ) {
			$body['note'] = '[redacted]';
		}

		return $body;
	}

	/** @param array<string,mixed> $params */
	private function build_url( string $path, array $params = [] ): string {
		$url = $this->base_url . '/' . ltrim( $path, '/' );
		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}
		return $url;
	}

	/** @return array<string,mixed> */
	private function default_args(): array {
		return [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'timeout' => 30,
		];
	}

	/**
	 * @param  array|\WP_Error $response
	 * @return array<string,mixed>
	 * @throws \RuntimeException
	 */
	private function parse( string $method, string $url, $response ): array {
		if ( is_wp_error( $response ) ) {
			$error = 'IdeaERP API request failed: ' . $response->get_error_message();
			Logger::error( sprintf( 'API %s %s | WP_Error: %s', $method, $url, $response->get_error_message() ) );
			throw new \RuntimeException( $error );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Log the raw response (truncate very large bodies to keep logs readable).
		$log_body = strlen( $body ) > 2000 ? substr( $body, 0, 2000 ) . '...[truncated]' : $body;
		Logger::debug( sprintf( 'API %s %s | HTTP %d | response: %s', $method, $url, $code, $log_body ) );

		if ( $code < 200 || $code >= 300 ) {
			$message = $data['message'] ?? $data['detail'] ?? $body;
			throw new \RuntimeException(
				sprintf( 'IdeaERP API error %d: %s', $code, $message )
			);
		}

		return is_array( $data ) ? $data : [];
	}
}
