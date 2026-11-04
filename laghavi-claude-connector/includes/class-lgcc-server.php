<?php
/**
 * The MCP endpoint Claude connects to (Model Context Protocol, Streamable HTTP, JSON responses).
 *
 * Address: /wp-json/lgcc/v1/mcp/<key>, or /wp-json/lgcc/v1/mcp with "Authorization: Bearer <key>".
 * The key in the address works everywhere, also on hosts that drop the Authorization header and in
 * Claude's custom connectors.
 *
 * @package LaghaviClaudeConnector
 */

defined( 'ABSPATH' ) || exit;

/**
 * MCP server.
 */
class LGCC_Server {

	const NS       = 'lgcc/v1';
	const PROTOCOL = '2025-06-18';
	const MAX_TEXT = 300000;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function routes() {
		foreach ( array( '/mcp', '/mcp/(?P<key>lgcc_[a-f0-9]{48})' ) as $route ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					array(
						'methods'             => 'POST',
						'callback'            => array( __CLASS__, 'handle' ),
						'permission_callback' => '__return_true', // The key is checked in handle(), for every call.
					),
					array(
						'methods'             => 'GET, DELETE',
						'callback'            => array( __CLASS__, 'not_allowed' ),
						'permission_callback' => '__return_true',
					),
				)
			);
		}
	}

	/**
	 * Address of the endpoint with a key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function url( $key ) {
		return rest_url( self::NS . '/mcp/' . $key );
	}

	/**
	 * GET (event stream) and DELETE (end session) are not used: every answer comes as JSON.
	 *
	 * @return WP_REST_Response
	 */
	public static function not_allowed() {
		return new WP_REST_Response( array( 'error' => 'Use POST.' ), 405 );
	}

	/**
	 * Key from the address or the Authorization header.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function key( $request ) {
		if ( ! empty( $request['key'] ) ) {
			return (string) $request['key'];
		}
		$auth = (string) $request->get_header( 'authorization' );
		return 0 === stripos( $auth, 'Bearer ' ) ? trim( substr( $auth, 7 ) ) : '';
	}

	/**
	 * Handle a JSON-RPC message or batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$ok = LGCC_Access::check( self::key( $request ) );
		if ( is_wp_error( $ok ) ) {
			$data = $ok->get_error_data();
			return new WP_REST_Response( self::error( null, -32001, $ok->get_error_message() ), isset( $data['status'] ) ? $data['status'] : 401 );
		}
		// Tools run with the rights of the admin who created the key.
		wp_set_current_user( LGCC_Access::user() );
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response( self::error( null, -32001, 'The admin who created this key no longer exists or is not an administrator.' ), 403 );
		}

		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( self::error( null, -32700, 'Parse error.' ), 400 );
		}
		$batch = isset( $body[0] ) || array() === $body;
		$out   = array();
		foreach ( $batch ? $body : array( $body ) as $message ) {
			$reply = self::message( is_array( $message ) ? $message : array() );
			if ( null !== $reply ) {
				$out[] = $reply;
			}
		}
		if ( ! $out ) {
			return new WP_REST_Response( null, 202 ); // Only notifications.
		}
		return new WP_REST_Response( $batch ? $out : $out[0], 200 );
	}

	/**
	 * Answer one message.
	 *
	 * @param array $m Message.
	 * @return array|null Null for notifications.
	 */
	private static function message( $m ) {
		$id     = array_key_exists( 'id', $m ) ? $m['id'] : null;
		$method = isset( $m['method'] ) ? (string) $m['method'] : '';
		$params = isset( $m['params'] ) && is_array( $m['params'] ) ? $m['params'] : array();
		if ( null === $id && 0 === strpos( $method, 'notifications/' ) ) {
			return null;
		}
		switch ( $method ) {
			case 'initialize':
				return self::result(
					$id,
					array(
						'protocolVersion' => isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : self::PROTOCOL,
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array(
							'name'    => 'laghavi-claude-connector',
							'title'   => get_bloginfo( 'name' ) . ' (WordPress)',
							'version' => LGCC_VERSION,
						),
						'instructions'    => self::instructions(),
					)
				);
			case 'ping':
				return self::result( $id, new stdClass() );
			case 'tools/list':
				return self::result( $id, array( 'tools' => LGCC_Tools::definitions() ) );
			case 'tools/call':
				$name = isset( $params['name'] ) ? (string) $params['name'] : '';
				$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
				$res  = LGCC_Tools::call( $name, $args );
				$err  = is_wp_error( $res );
				LGCC_Access::log( $name, self::summary( $args ) . ( $err ? ' → ' . $res->get_error_message() : '' ), ! $err );
				$text = $err ? 'Error: ' . $res->get_error_message() : ( is_string( $res ) ? $res : wp_json_encode( $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) );
				if ( strlen( $text ) > self::MAX_TEXT ) {
					$text = substr( $text, 0, self::MAX_TEXT ) . "\n… (cut: ask for a smaller part)";
				}
				return self::result(
					$id,
					array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => $text,
							),
						),
						'isError' => $err,
					)
				);
			default:
				return self::error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * Short text of a call's arguments for the log.
	 *
	 * @param array $args Arguments.
	 * @return string
	 */
	private static function summary( $args ) {
		unset( $args['content'], $args['replace'], $args['value'] );
		$json = wp_json_encode( $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return '[]' === $json ? '' : (string) $json;
	}

	/**
	 * Guidance for Claude, sent when it connects.
	 *
	 * @return string
	 */
	private static function instructions() {
		$fix = LGCC_Access::can_fix();
		return 'You are connected to the WordPress site ' . home_url() . ' (WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . '). '
			. 'Start with site_overview and error_logs to find problems, then read the files involved before changing anything. '
			. ( $fix
				? 'You may fix problems: every file change is backed up first (list_backups, restore_backup) and PHP files are checked for syntax errors before they are saved. Prefer small, exact replace_in_file edits over rewriting whole files, never edit WordPress core files, and after each fix check the page again with fetch_page and error_logs. Tell the site owner what you changed and why.'
				: 'This key can only look: the tools that change the site are not available. Explain what you find and what should be changed.' );
	}

	/**
	 * A JSON-RPC result.
	 *
	 * @param mixed $id     ID.
	 * @param mixed $result Result.
	 * @return array
	 */
	private static function result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * A JSON-RPC error.
	 *
	 * @param mixed  $id      ID.
	 * @param int    $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	private static function error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
