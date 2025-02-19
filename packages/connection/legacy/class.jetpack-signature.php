<?php

use \Automattic\Jetpack\Connection\Manager as Connection_Manager;

class Jetpack_Signature {
	public $token;
	public $secret;

	function __construct( $access_token, $time_diff = 0 ) {
		$secret = explode( '.', $access_token );
		if ( 2 != count( $secret ) ) {
			return;
		}

		$this->token     = $secret[0];
		$this->secret    = $secret[1];
		$this->time_diff = $time_diff;
	}

	function sign_current_request( $override = array() ) {
		if ( isset( $override['scheme'] ) ) {
			$scheme = $override['scheme'];
			if ( ! in_array( $scheme, array( 'http', 'https' ) ) ) {
				return new WP_Error( 'invalid_scheme', 'Invalid URL scheme' );
			}
		} else {
			if ( is_ssl() ) {
				$scheme = 'https';
			} else {
				$scheme = 'http';
			}
		}

		$host_port = isset( $_SERVER['HTTP_X_FORWARDED_PORT'] ) ? $_SERVER['HTTP_X_FORWARDED_PORT'] : $_SERVER['SERVER_PORT'];

		$connection = new Connection_Manager();
		/**
		 * Note: This port logic is tested in the Jetpack_Cxn_Tests->test__server_port_value() test.
		 * Please update the test if any changes are made in this logic.
		 */
		if ( is_ssl() ) {
			// 443: Standard Port
			// 80: Assume we're behind a proxy without X-Forwarded-Port. Hardcoding "80" here means most sites
			// with SSL termination proxies (self-served, Cloudflare, etc.) don't need to fiddle with
			// the JETPACK_SIGNATURE__HTTPS_PORT constant. The code also implies we can't talk to a
			// site at https://example.com:80/ (which would be a strange configuration).
			// JETPACK_SIGNATURE__HTTPS_PORT: Set this constant in wp-config.php to the back end webserver's port
			// if the site is behind a proxy running on port 443 without
			// X-Forwarded-Port and the back end's port is *not* 80. It's better,
			// though, to configure the proxy to send X-Forwarded-Port.
			$https_port = defined( 'JETPACK_SIGNATURE__HTTPS_PORT' ) ? JETPACK_SIGNATURE__HTTPS_PORT : 443;
			$port       = in_array( $host_port, array( 443, 80, $https_port ) ) ? '' : $host_port;
		} else {
			// 80: Standard Port
			// JETPACK_SIGNATURE__HTTPS_PORT: Set this constant in wp-config.php to the back end webserver's port
			// if the site is behind a proxy running on port 80 without
			// X-Forwarded-Port. It's better, though, to configure the proxy to
			// send X-Forwarded-Port.
			$http_port = defined( 'JETPACK_SIGNATURE__HTTP_PORT' ) ? JETPACK_SIGNATURE__HTTP_PORT : 80;
			$port      = in_array( $host_port, array( 80, $http_port ) ) ? '' : $host_port;
		}

		$url = "{$scheme}://{$_SERVER['HTTP_HOST']}:{$port}" . stripslashes( $_SERVER['REQUEST_URI'] );

		if ( array_key_exists( 'body', $override ) && ! empty( $override['body'] ) ) {
			$body = $override['body'];
		} elseif ( 'POST' == strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			$body = isset( $GLOBALS['HTTP_RAW_POST_DATA'] ) ? $GLOBALS['HTTP_RAW_POST_DATA'] : null;

			// Convert the $_POST to the body, if the body was empty. This is how arrays are hashed
			// and encoded on the Jetpack side.
			if ( defined( 'IS_WPCOM' ) && IS_WPCOM ) {
				if ( empty( $body ) && is_array( $_POST ) && count( $_POST ) > 0 ) {
					$body = $_POST;
				}
			}
		} elseif ( 'PUT' == strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			// This is a little strange-looking, but there doesn't seem to be another way to get the PUT body
			$raw_put_data = file_get_contents( 'php://input' );
			parse_str( $raw_put_data, $body );

			if ( defined( 'IS_WPCOM' ) && IS_WPCOM ) {
				$put_data = json_decode( $raw_put_data, true );
				if ( is_array( $put_data ) && count( $put_data ) > 0 ) {
					$body = $put_data;
				}
			}
		} else {
			$body = null;
		}

		if ( empty( $body ) ) {
			$body = null;
		}

		$a = array();
		foreach ( array( 'token', 'timestamp', 'nonce', 'body-hash' ) as $parameter ) {
			if ( isset( $override[ $parameter ] ) ) {
				$a[ $parameter ] = $override[ $parameter ];
			} else {
				$a[ $parameter ] = isset( $_GET[ $parameter ] ) ? stripslashes( $_GET[ $parameter ] ) : '';
			}
		}

		$method = isset( $override['method'] ) ? $override['method'] : $_SERVER['REQUEST_METHOD'];
		return $this->sign_request( $a['token'], $a['timestamp'], $a['nonce'], $a['body-hash'], $method, $url, $body, true );
	}

	// body_hash v. body-hash is annoying.  Refactor to accept an array?
	function sign_request( $token = '', $timestamp = 0, $nonce = '', $body_hash = '', $method = '', $url = '', $body = null, $verify_body_hash = true ) {
		if ( ! $this->secret ) {
			return new WP_Error( 'invalid_secret', 'Invalid secret' );
		}

		if ( ! $this->token ) {
			return new WP_Error( 'invalid_token', 'Invalid token' );
		}

		list( $token ) = explode( '.', $token );

		if ( 0 !== strpos( $token, "$this->token:" ) ) {
			return new WP_Error( 'token_mismatch', 'Incorrect token' );
		}

		// If we got an array at this point, let's encode it, so we can see what it looks like as a string.
		if ( is_array( $body ) ) {
			if ( count( $body ) > 0 ) {
				$body = json_encode( $body );

			} else {
				$body = '';
			}
		}

		$required_parameters = array( 'token', 'timestamp', 'nonce', 'method', 'url' );
		if ( ! is_null( $body ) ) {
			$required_parameters[] = 'body_hash';
			if ( ! is_string( $body ) ) {
				return new WP_Error( 'invalid_body', 'Body is malformed.' );
			}
		}

		foreach ( $required_parameters as $required ) {
			if ( ! is_scalar( $$required ) ) {
				return new WP_Error( 'invalid_signature', sprintf( 'The required "%s" parameter is malformed.', str_replace( '_', '-', $required ) ) );
			}

			if ( ! strlen( $$required ) ) {
				return new WP_Error( 'invalid_signature', sprintf( 'The required "%s" parameter is missing.', str_replace( '_', '-', $required ) ) );
			}
		}

		if ( empty( $body ) ) {
			if ( $body_hash ) {
				return new WP_Error( 'invalid_body_hash', 'The body hash does not match.' );
			}
		} else {
			$connection = new Connection_Manager();
			if ( $verify_body_hash && $connection->sha1_base64( $body ) !== $body_hash ) {
				return new WP_Error( 'invalid_body_hash', 'The body hash does not match.' );
			}
		}

		$parsed = parse_url( $url );
		if ( ! isset( $parsed['host'] ) ) {
			return new WP_Error( 'invalid_signature', sprintf( 'The required "%s" parameter is malformed.', 'url' ) );
		}

		if ( ! empty( $parsed['port'] ) ) {
			$port = $parsed['port'];
		} else {
			if ( 'http' == $parsed['scheme'] ) {
				$port = 80;
			} elseif ( 'https' == $parsed['scheme'] ) {
				$port = 443;
			} else {
				return new WP_Error( 'unknown_scheme_port', "The scheme's port is unknown" );
			}
		}

		if ( ! ctype_digit( "$timestamp" ) || 10 < strlen( $timestamp ) ) { // If Jetpack is around in 275 years, you can blame mdawaffe for the bug.
			return new WP_Error( 'invalid_signature', sprintf( 'The required "%s" parameter is malformed.', 'timestamp' ) );
		}

		$local_time = $timestamp - $this->time_diff;
		if ( $local_time < time() - 600 || $local_time > time() + 300 ) {
			return new WP_Error( 'invalid_signature', 'The timestamp is too old.' );
		}

		if ( 12 < strlen( $nonce ) || preg_match( '/[^a-zA-Z0-9]/', $nonce ) ) {
			return new WP_Error( 'invalid_signature', sprintf( 'The required "%s" parameter is malformed.', 'nonce' ) );
		}

		$normalized_request_pieces = array(
			$token,
			$timestamp,
			$nonce,
			$body_hash,
			strtoupper( $method ),
			strtolower( $parsed['host'] ),
			$port,
			$parsed['path'],
			// Normalized Query String
		);

		$normalized_request_pieces      = array_merge( $normalized_request_pieces, $this->normalized_query_parameters( isset( $parsed['query'] ) ? $parsed['query'] : '' ) );
		$flat_normalized_request_pieces = array();
		foreach ( $normalized_request_pieces as $piece ) {
			if ( is_array( $piece ) ) {
				foreach ( $piece as $subpiece ) {
					$flat_normalized_request_pieces[] = $subpiece;
				}
			} else {
				$flat_normalized_request_pieces[] = $piece;
			}
		}
		$normalized_request_pieces = $flat_normalized_request_pieces;

		$normalized_request_string = join( "\n", $normalized_request_pieces ) . "\n";

		return base64_encode( hash_hmac( 'sha1', $normalized_request_string, $this->secret, true ) );
	}

	function normalized_query_parameters( $query_string ) {
		parse_str( $query_string, $array );
		if ( get_magic_quotes_gpc() ) {
			$array = stripslashes_deep( $array );
		}

		unset( $array['signature'] );

		$names  = array_keys( $array );
		$values = array_values( $array );

		$names  = array_map( array( $this, 'encode_3986' ), $names );
		$values = array_map( array( $this, 'encode_3986' ), $values );

		$pairs = array_map( array( $this, 'join_with_equal_sign' ), $names, $values );

		sort( $pairs );

		return $pairs;
	}

	function encode_3986( $string_or_array ) {
		if ( is_array( $string_or_array ) ) {
			return array_map( array( $this, 'encode_3986' ), $string_or_array );
		}

		$string_or_array = rawurlencode( $string_or_array );
		return str_replace( '%7E', '~', $string_or_array ); // prior to PHP 5.3, rawurlencode was RFC 1738
	}

	function join_with_equal_sign( $name, $value ) {
		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $array_key => $array_value ) {
				$result[] = $name . '[' . $array_key . ']' . '=' . $array_value;
			}
			return $result;
		}
		return "{$name}={$value}";
	}
}
