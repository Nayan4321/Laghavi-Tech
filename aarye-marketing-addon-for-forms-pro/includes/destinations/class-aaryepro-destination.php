<?php
/**
 * Base for a place entries are sent to (CRM, database, webhook, Laghavi Ads Hub).
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Destination.
 */
abstract class AARYEPRO_Destination {

	/**
	 * Key, e.g. "hubspot".
	 *
	 * @return string
	 */
	abstract public function key();

	/**
	 * Name shown to the user.
	 *
	 * @return string
	 */
	abstract public function name();

	/**
	 * One line about what it does.
	 *
	 * @return string
	 */
	abstract public function description();

	/**
	 * Settings fields: key => array( label, type (text|url|password|select|textarea|checkbox|number), help, options, placeholder ).
	 * Password fields are secrets: never printed back, kept when left empty.
	 *
	 * @return array
	 */
	abstract public function fields();

	/**
	 * Step-by-step setup instructions (HTML allowed: a, b, code, br).
	 *
	 * @return string[]
	 */
	abstract public function steps();

	/**
	 * Send one entry.
	 *
	 * @param object $entry Entry.
	 * @param object $job   Queue job (remote_id holds what the service returned last time).
	 * @return array Result, see result().
	 */
	abstract public function send( $entry, $job );

	/**
	 * Check the settings with the service.
	 *
	 * @return array Result, see result().
	 */
	abstract public function test();

	/**
	 * Adjust settings when they are saved (for example swap a one-time code for a token).
	 *
	 * @param array $values New values.
	 * @param array $old    Saved values.
	 * @return array|WP_Error Values to save, or an error to show.
	 */
	public function on_save( $values, $old ) {
		return $values;
	}

	/**
	 * Whether entries are sent again when their lead status changes.
	 *
	 * @return bool
	 */
	public function resend_on_status() {
		return false;
	}

	/**
	 * Send several entries at once. By default one by one.
	 *
	 * @param array<int, array{entry: object, job: object}> $items Entries with their jobs.
	 * @return array<int, array> Results by job ID.
	 */
	public function send_batch( $items ) {
		$out = array();
		foreach ( $items as $item ) {
			$out[ (int) $item['job']->id ] = $this->send( $item['entry'], $item['job'] );
			if ( ! empty( $out[ (int) $item['job']->id ]['stop'] ) ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Most entries sent in one batch.
	 *
	 * @return int
	 */
	public function batch_size() {
		return 20;
	}

	/**
	 * A saved setting.
	 *
	 * @param string $key Setting.
	 * @return string
	 */
	public function get( $key ) {
		return (string) AARYEPRO_Connections::get( $this->key(), $key );
	}

	/**
	 * Whether everything required is filled in.
	 *
	 * @return bool
	 */
	public function configured() {
		foreach ( $this->fields() as $key => $field ) {
			if ( ! empty( $field['required'] ) && '' === $this->get( $key ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A result.
	 *
	 * @param bool   $ok        Success.
	 * @param string $message   Message for the log.
	 * @param array  $more      retry (try again later), stop (stop all sending until the settings are saved
	 *                          again), code, remote_id.
	 * @return array
	 */
	protected function result( $ok, $message = '', $more = array() ) {
		return array_merge(
			array(
				'ok'        => (bool) $ok,
				'retry'     => false,
				'stop'      => false,
				'code'      => '',
				'message'   => (string) $message,
				'remote_id' => '',
			),
			$more
		);
	}

	/**
	 * HTTP request.
	 *
	 * @param string $method  GET, POST, PATCH, PUT.
	 * @param string $url     Address.
	 * @param mixed  $body    Array (sent as JSON) or string.
	 * @param array  $headers Headers.
	 * @param int    $timeout Seconds.
	 * @return array{status: int, body: string, json: mixed, error: string}
	 */
	protected function http( $method, $url, $body = null, $headers = array(), $timeout = 15 ) {
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array_merge( array( 'Accept' => 'application/json' ), $headers ),
		);
		if ( null !== $body ) {
			$args['body'] = is_string( $body ) ? $body : wp_json_encode( $body );
			if ( ! isset( $args['headers']['Content-Type'] ) ) {
				$args['headers']['Content-Type'] = 'application/json';
			}
		}
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'json'   => null,
				'error'  => $response->get_error_message(),
			);
		}
		$text = (string) wp_remote_retrieve_body( $response );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => $text,
			'json'   => json_decode( $text, true ),
			'error'  => '',
		);
	}

	/**
	 * Standard result for an HTTP reply: 2xx ok; no answer, 408, 429 and 5xx are tried again; other errors not.
	 *
	 * @param array  $r       From http().
	 * @param string $message Error text found in the reply, if any.
	 * @return array
	 */
	protected function http_result( $r, $message = '' ) {
		if ( $r['status'] >= 200 && $r['status'] < 300 ) {
			return $this->result( true, 'HTTP ' . $r['status'] );
		}
		if ( 0 === $r['status'] ) {
			return $this->result( false, $r['error'], array( 'retry' => true, 'code' => 'no_answer' ) );
		}
		$text = '' !== $message ? $message : wp_strip_all_tags( mb_substr( $r['body'], 0, 300 ) );
		return $this->result(
			false,
			'HTTP ' . $r['status'] . ( '' !== $text ? ': ' . $text : '' ),
			array(
				'retry' => in_array( $r['status'], array( 408, 425, 429 ), true ) || $r['status'] >= 500,
				'code'  => 'http_' . $r['status'],
			)
		);
	}
}
