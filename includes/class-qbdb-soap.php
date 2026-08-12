<?php
/**
 * The QuickBooks Web Connector SOAP endpoint, at /wc-api/qbdb/.
 *
 * The Web Connector (QBWC) on the QuickBooks PC polls this endpoint on a
 * schedule and relays qbXML between us and QuickBooks Desktop. One run is:
 *
 *   authenticate → [ sendRequestXML → receiveResponseXML ]* → closeConnection
 *
 * QBWC is a .NET ASMX client, so responses are doc/literal wrapped envelopes
 * ({method}Response containing {method}Result) in the http://developer.intuit.com/
 * namespace. Requests are parsed by local element name only, so namespace
 * prefixes on QBWC's side don't matter.
 *
 * The endpoint deliberately lives on the wc-api path: WooCommerce endpoints
 * are excluded from the site's Varnish page cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QBDB_SOAP {

	const ENDPOINT = 'qbdb';
	const NS       = 'http://developer.intuit.com/';

	/** Session lifetime between two QBWC calls. */
	const SESSION_TTL = 600;

	public static function init() {
		add_action( 'woocommerce_api_' . self::ENDPOINT, array( __CLASS__, 'handle' ) );
	}

	/** Public URL the Web Connector should be pointed at (goes in the .qwc). */
	public static function endpoint_url() {
		return home_url( '/wc-api/' . self::ENDPOINT . '/', 'https' );
	}

	/* ------------------------------------------------------------------ */

	public static function handle() {
		$body = file_get_contents( 'php://input' );

		if ( '' === trim( (string) $body ) ) {
			// A browser (or QBWC's initial reachability check) doing a GET.
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'QuickBooks Desktop Bridge endpoint. POST SOAP requests only.';
			exit;
		}

		$call = self::parse_call( $body );
		if ( ! $call ) {
			self::fault( 'Client', 'Unrecognised SOAP request.' );
		}

		$method = $call['method'];
		$p      = $call['params'];

		switch ( $method ) {
			case 'serverVersion':
				$inner = self::result_string( $method, QBDB_VERSION );
				break;

			case 'clientVersion':
				// Empty string = any QBWC version is fine.
				$inner = self::result_string( $method, '' );
				break;

			case 'authenticate':
				$inner = self::do_authenticate( $p );
				break;

			case 'sendRequestXML':
				$inner = self::result_string( $method, self::do_send( $p ), true );
				break;

			case 'receiveResponseXML':
				$inner = self::result_int( $method, self::do_receive( $p ) );
				break;

			case 'getLastError':
				$inner = self::result_string( $method, self::do_last_error( $p ) );
				break;

			case 'connectionError':
				$inner = self::result_string( $method, self::do_connection_error( $p ) );
				break;

			case 'closeConnection':
				$inner = self::result_string( $method, self::do_close( $p ) );
				break;

			default:
				self::fault( 'Client', 'Unsupported method: ' . $method );
				return; // unreachable
		}

		self::respond( $method, $inner );
	}

	/* --------------------------- QBWC methods -------------------------- */

	private static function do_authenticate( $p ) {
		$settings = QBDB_Settings::get();
		$user     = (string) ( $p['strUserName'] ?? '' );
		$pass     = (string) ( $p['strPassword'] ?? '' );

		$valid = '' !== $settings['username']
			&& '' !== $settings['password']
			&& hash_equals( $settings['username'], $user )
			&& hash_equals( $settings['password'], $pass );

		if ( ! $valid ) {
			update_option( 'qbdb_last_run', array( 'time' => time(), 'result' => 'auth failed' ), false );
			return self::string_array( 'authenticate', array( '', 'nvu' ) );
		}

		QBDB_Queue::release_stale();
		$ids    = QBDB_Queue::pending_ids();
		$ticket = wp_generate_uuid4();

		if ( empty( $ids ) ) {
			update_option( 'qbdb_last_run', array( 'time' => time(), 'result' => 'nothing to do' ), false );
			return self::string_array( 'authenticate', array( $ticket, 'none' ) );
		}

		self::save_session( $ticket, array(
			'ids'        => $ids,
			'total'      => count( $ids ),
			'done'       => 0,
			'current'    => 0,
			'last_error' => '',
		) );

		update_option( 'qbdb_last_run', array( 'time' => time(), 'result' => count( $ids ) . ' queued' ), false );

		// Second string '' = work with whatever company file is open in QB.
		return self::string_array( 'authenticate', array( $ticket, '' ) );
	}

	private static function do_send( $p ) {
		$session = self::load_session( $p['ticket'] ?? '' );
		if ( ! $session ) {
			return '';
		}

		// Walk the queue until an order builds cleanly (orders deleted since
		// queueing are marked errored and skipped).
		while ( ! empty( $session['ids'] ) ) {
			$qid   = array_shift( $session['ids'] );
			$row   = QBDB_Queue::get( $qid );
			$order = $row ? wc_get_order( (int) $row->order_id ) : false;

			if ( ! $row || 'pending' !== $row->state ) {
				continue;
			}
			if ( ! $order ) {
				QBDB_Queue::mark( $qid, 'error', 'Order no longer exists.' );
				$session['done']++;
				continue;
			}

			$xml = QBDB_QBXML::build_order_request( $order, $qid );

			QBDB_Queue::mark( $qid, 'sent' );
			$session['current'] = $qid;
			self::save_session( $p['ticket'], $session );

			return $xml;
		}

		// Queue exhausted.
		self::save_session( $p['ticket'], $session );
		return '';
	}

	private static function do_receive( $p ) {
		$ticket  = $p['ticket'] ?? '';
		$session = self::load_session( $ticket );
		if ( ! $session ) {
			return -1;
		}

		$qid = (int) $session['current'];
		if ( $qid ) {
			$hresult = trim( (string) ( $p['hresult'] ?? '' ) );

			if ( '' !== $hresult ) {
				// Transport/QB-side failure before qbXML processing.
				$msg = trim( $hresult . ' ' . (string) ( $p['message'] ?? '' ) );
				QBDB_Queue::mark( $qid, 'error', $msg );
				$session['last_error'] = $msg;
			} else {
				$result = QBDB_QBXML::parse_response( (string) ( $p['response'] ?? '' ), $qid );
				if ( $result['ok'] ) {
					QBDB_Queue::mark( $qid, 'done', '', $result['txn_id'] );
					self::note_order( $qid, $result['txn_id'] );
				} else {
					QBDB_Queue::mark( $qid, 'error', $result['error'] );
					$session['last_error'] = $result['error'];
				}
			}

			$session['done']++;
			$session['current'] = 0;
		}

		self::save_session( $ticket, $session );

		if ( empty( $session['ids'] ) ) {
			return 100; // all handed over — QBWC stops asking
		}
		$pct = (int) floor( $session['done'] / max( 1, $session['total'] ) * 100 );
		return max( 1, min( 99, $pct ) );
	}

	private static function do_last_error( $p ) {
		$session = self::load_session( $p['ticket'] ?? '' );
		if ( $session && '' !== $session['last_error'] ) {
			return $session['last_error'];
		}
		return 'NoOp';
	}

	private static function do_connection_error( $p ) {
		$session = self::load_session( $p['ticket'] ?? '' );
		if ( $session && ! empty( $session['current'] ) ) {
			$msg = trim( (string) ( $p['hresult'] ?? '' ) . ' ' . (string) ( $p['message'] ?? '' ) );
			QBDB_Queue::mark( (int) $session['current'], 'error', 'Web Connector connection error: ' . $msg );
			$session['current'] = 0;
			self::save_session( $p['ticket'] ?? '', $session );
		}
		return 'done'; // tell QBWC to give up for this run
	}

	private static function do_close( $p ) {
		$ticket = $p['ticket'] ?? '';
		if ( $ticket ) {
			delete_transient( self::session_key( $ticket ) );
		}
		return 'OK';
	}

	/** Pin the QB transaction ID to the order for the paper trail. */
	private static function note_order( $qid, $txn_id ) {
		$row   = QBDB_Queue::get( $qid );
		$order = $row ? wc_get_order( (int) $row->order_id ) : false;
		if ( $order ) {
			if ( '' !== $txn_id ) {
				$order->update_meta_data( '_qbdb_txn_id', $txn_id );
				$order->save();
			}
			$order->add_order_note(
				sprintf( 'Exported to QuickBooks Desktop%s.', '' !== $txn_id ? ' (TxnID ' . $txn_id . ')' : '' )
			);
		}
	}

	/* --------------------------- session store ------------------------- */

	private static function session_key( $ticket ) {
		return 'qbdb_sess_' . md5( (string) $ticket );
	}

	private static function load_session( $ticket ) {
		if ( '' === (string) $ticket ) {
			return false;
		}
		$session = get_transient( self::session_key( $ticket ) );
		return is_array( $session ) ? $session : false;
	}

	private static function save_session( $ticket, $session ) {
		set_transient( self::session_key( $ticket ), $session, self::SESSION_TTL );
	}

	/* --------------------------- SOAP plumbing ------------------------- */

	/**
	 * Pull the method name and its parameters out of a QBWC SOAP envelope.
	 * Matching is by local name so it works with any prefix style.
	 *
	 * @return array{method:string,params:array<string,string>}|false
	 */
	public static function parse_call( $body ) {
		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$ok   = $doc->loadXML( $body, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok ) {
			return false;
		}

		$bodies = $doc->getElementsByTagNameNS( 'http://schemas.xmlsoap.org/soap/envelope/', 'Body' );
		if ( ! $bodies->length ) {
			return false;
		}

		$call = null;
		foreach ( $bodies->item( 0 )->childNodes as $node ) {
			if ( XML_ELEMENT_NODE === $node->nodeType ) {
				$call = $node;
				break;
			}
		}
		if ( ! $call ) {
			return false;
		}

		$params = array();
		foreach ( $call->childNodes as $node ) {
			if ( XML_ELEMENT_NODE === $node->nodeType ) {
				$params[ $node->localName ] = $node->textContent;
			}
		}

		return array(
			'method' => $call->localName,
			'params' => $params,
		);
	}

	/** &lt;methodResult&gt;value&lt;/methodResult&gt; */
	private static function result_string( $method, $value, $allow_empty_element = false ) {
		if ( '' === (string) $value && ! $allow_empty_element ) {
			return '<' . $method . 'Result></' . $method . 'Result>';
		}
		return '<' . $method . 'Result>' . self::esc( $value ) . '</' . $method . 'Result>';
	}

	private static function result_int( $method, $value ) {
		return '<' . $method . 'Result>' . (int) $value . '</' . $method . 'Result>';
	}

	/** ASMX ArrayOfString shape used by authenticate. */
	private static function string_array( $method, $strings ) {
		$xml = '<' . $method . 'Result>';
		foreach ( $strings as $s ) {
			$xml .= '<string>' . self::esc( $s ) . '</string>';
		}
		return $xml . '</' . $method . 'Result>';
	}

	private static function respond( $method, $inner ) {
		$envelope = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Body>'
			. '<' . $method . 'Response xmlns="' . self::NS . '">' . $inner . '</' . $method . 'Response>'
			. '</soap:Body>'
			. '</soap:Envelope>';

		status_header( 200 );
		header( 'Content-Type: text/xml; charset=utf-8' );
		header( 'Content-Length: ' . strlen( $envelope ) );
		echo $envelope; // phpcs:ignore WordPress.Security.EscapeOutput -- XML document, not HTML.
		exit;
	}

	private static function fault( $code, $message ) {
		$envelope = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Body><soap:Fault>'
			. '<faultcode>soap:' . self::esc( $code ) . '</faultcode>'
			. '<faultstring>' . self::esc( $message ) . '</faultstring>'
			. '</soap:Fault></soap:Body>'
			. '</soap:Envelope>';

		status_header( 500 );
		header( 'Content-Type: text/xml; charset=utf-8' );
		echo $envelope; // phpcs:ignore WordPress.Security.EscapeOutput -- XML document, not HTML.
		exit;
	}

	private static function esc( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
