<?php
/**
 * qbXML generation and response parsing.
 *
 * Each queued order becomes ONE self-contained qbXML message:
 *   CustomerAdd + ItemNonInventoryAdd (per line item) + SalesReceiptAdd,
 * sent with onError="continueOnError" so the adds failing with "already
 * exists" (statusCode 3100) never blocks the receipt. A sales receipt (not an
 * invoice) is correct here because every order is paid in full at checkout.
 *
 * qbXML is order-sensitive: elements must appear in the exact sequence the
 * schema defines, or QuickBooks rejects the whole message with a parse error.
 * Don't reorder or insert fields without checking the OSR (Onscreen Reference).
 *
 * QuickBooks Desktop is not Unicode-safe, so every string is transliterated
 * down to printable ASCII before it goes in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QBDB_QBXML {

	const QBXML_VERSION = '13.0';

	/** QuickBooks name-field limits (chars). */
	const MAX_CUSTOMER_NAME = 41;
	const MAX_ITEM_NAME     = 31;

	/**
	 * Build the full qbXML message for one queue row / order.
	 *
	 * @param WC_Order $order Order to export.
	 * @param int      $qid   Queue row ID — embedded in each requestID so the
	 *                        response can be matched back.
	 * @return string qbXML document.
	 */
	public static function build_order_request( $order, $qid ) {
		$settings = QBDB_Settings::get();
		$requests = '';

		// 1. Ensure the customer exists.
		$customer = self::customer_name( $order, $settings );
		$requests .= self::customer_add_xml( $order, $customer, $qid );

		// 2. Ensure every item referenced by the receipt exists.
		$lines = self::receipt_lines( $order, $settings );
		$seen  = array();
		$n     = 0;
		foreach ( $lines as $line ) {
			if ( isset( $seen[ $line['item'] ] ) ) {
				continue;
			}
			$seen[ $line['item'] ] = true;
			$requests             .= self::item_add_xml( $line, $settings, $qid, ++$n );
		}

		// 3. The sales receipt itself.
		$requests .= self::sales_receipt_xml( $order, $customer, $lines, $qid );

		return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
			. '<?qbxml version="' . self::QBXML_VERSION . '"?>' . "\n"
			. '<QBXML><QBXMLMsgsRq onError="continueOnError">'
			. $requests
			. '</QBXMLMsgsRq></QBXML>';
	}

	/* ------------------------------------------------------------------ */

	private static function customer_add_xml( $order, $customer, $qid ) {
		$first = self::txt( $order->get_billing_first_name(), 25 );
		$last  = self::txt( $order->get_billing_last_name(), 25 );
		$phone = self::txt( $order->get_billing_phone(), 21 );
		$email = self::txt( $order->get_billing_email(), 1023 );

		// Element order per CustomerAdd schema: Name, FirstName, LastName,
		// BillAddress, Phone, Email.
		$xml = '<CustomerAddRq requestID="' . self::attr( 'cust-' . $qid ) . '"><CustomerAdd>';
		$xml .= '<Name>' . self::esc( $customer ) . '</Name>';
		if ( '' !== $first ) {
			$xml .= '<FirstName>' . self::esc( $first ) . '</FirstName>';
		}
		if ( '' !== $last ) {
			$xml .= '<LastName>' . self::esc( $last ) . '</LastName>';
		}
		$addr = self::bill_address_xml( $order );
		if ( '' !== $addr ) {
			$xml .= '<BillAddress>' . $addr . '</BillAddress>';
		}
		if ( '' !== $phone ) {
			$xml .= '<Phone>' . self::esc( $phone ) . '</Phone>';
		}
		if ( '' !== $email ) {
			$xml .= '<Email>' . self::esc( $email ) . '</Email>';
		}
		$xml .= '</CustomerAdd></CustomerAddRq>';

		return $xml;
	}

	private static function bill_address_xml( $order ) {
		$xml = '';
		foreach ( array(
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
		) as $i => $line ) {
			$line = self::txt( $line, 41 );
			if ( '' !== $line ) {
				$xml .= '<Addr' . ( $i + 1 ) . '>' . self::esc( $line ) . '</Addr' . ( $i + 1 ) . '>';
			}
		}
		$city = self::txt( $order->get_billing_city(), 31 );
		if ( '' !== $city ) {
			$xml .= '<City>' . self::esc( $city ) . '</City>';
		}
		$country = self::txt( $order->get_billing_country(), 31 );
		if ( '' !== $country ) {
			$xml .= '<Country>' . self::esc( $country ) . '</Country>';
		}
		return $xml;
	}

	private static function item_add_xml( $line, $settings, $qid, $n ) {
		// Element order per ItemNonInventoryAdd schema: Name, then
		// SalesOrPurchase { Desc, Price, AccountRef }.
		return '<ItemNonInventoryAddRq requestID="' . self::attr( 'item-' . $qid . '-' . $n ) . '"><ItemNonInventoryAdd>'
			. '<Name>' . self::esc( $line['item'] ) . '</Name>'
			. '<SalesOrPurchase>'
			. '<Desc>' . self::esc( $line['desc'] ) . '</Desc>'
			. '<Price>' . self::money( $line['unit'] ) . '</Price>'
			. '<AccountRef><FullName>' . self::esc( self::txt( $settings['income_account'], 159 ) ) . '</FullName></AccountRef>'
			. '</SalesOrPurchase>'
			. '</ItemNonInventoryAdd></ItemNonInventoryAddRq>';
	}

	private static function sales_receipt_xml( $order, $customer, $lines, $qid ) {
		// Element order per SalesReceiptAdd schema: CustomerRef, TxnDate,
		// RefNumber, Memo, then the lines. RefNumber is capped at 11 chars.
		$date = $order->get_date_paid() ?: $order->get_date_created();
		$memo = 'WooCommerce order #' . $order->get_order_number() . ' (' . $order->get_currency() . ')';

		$xml = '<SalesReceiptAddRq requestID="' . self::attr( 'receipt-' . $qid ) . '"><SalesReceiptAdd>';
		$xml .= '<CustomerRef><FullName>' . self::esc( $customer ) . '</FullName></CustomerRef>';
		if ( $date ) {
			$xml .= '<TxnDate>' . $date->date( 'Y-m-d' ) . '</TxnDate>';
		}
		$xml .= '<RefNumber>' . self::esc( self::txt( $order->get_order_number(), 11 ) ) . '</RefNumber>';
		$xml .= '<Memo>' . self::esc( self::txt( $memo, 4095 ) ) . '</Memo>';

		// Line order per SalesReceiptLineAdd schema: ItemRef, Desc, Quantity,
		// Amount. Rate is omitted on purpose — QB derives it, which avoids
		// rounding fights between Quantity × Rate and the discounted total.
		foreach ( $lines as $line ) {
			$xml .= '<SalesReceiptLineAdd>';
			$xml .= '<ItemRef><FullName>' . self::esc( $line['item'] ) . '</FullName></ItemRef>';
			$xml .= '<Desc>' . self::esc( $line['desc'] ) . '</Desc>';
			if ( null !== $line['qty'] ) {
				$xml .= '<Quantity>' . self::qty( $line['qty'] ) . '</Quantity>';
			}
			$xml .= '<Amount>' . self::money( $line['amount'] ) . '</Amount>';
			$xml .= '</SalesReceiptLineAdd>';
		}

		$xml .= '</SalesReceiptAdd></SalesReceiptAddRq>';
		return $xml;
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Flatten an order into receipt lines.
	 *
	 * Product line amounts come from get_total(), which is already net of
	 * coupon discounts — so no separate discount line is needed and the
	 * receipt total matches the order total exactly.
	 *
	 * @return array[] Each: item (QB item name), desc, qty (null = omit),
	 *                 unit (for ItemAdd's default price), amount.
	 */
	public static function receipt_lines( $order, $settings ) {
		$lines = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$qty     = max( 1, (float) $item->get_quantity() );
			$total   = (float) $item->get_total();

			if ( 'single' === $settings['item_mode'] ) {
				$name = $settings['generic_item'];
			} else {
				$sku  = $product ? $product->get_sku() : '';
				$name = '' !== $sku ? $sku : $item->get_name();
			}

			$lines[] = array(
				'item'   => self::item_name( $name ),
				'desc'   => self::txt( $item->get_name(), 4095 ),
				'qty'    => $qty,
				'unit'   => $qty > 0 ? $total / $qty : $total,
				'amount' => $total,
			);
		}

		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$lines[] = array(
				'item'   => self::item_name( $settings['misc_item'] ),
				'desc'   => self::txt( $fee->get_name(), 4095 ),
				'qty'    => null,
				'unit'   => (float) $fee->get_total(),
				'amount' => (float) $fee->get_total(),
			);
		}

		$shipping = (float) $order->get_shipping_total();
		if ( abs( $shipping ) >= 0.005 ) {
			$lines[] = array(
				'item'   => self::item_name( $settings['misc_item'] ),
				'desc'   => 'Shipping',
				'qty'    => null,
				'unit'   => $shipping,
				'amount' => $shipping,
			);
		}

		// Taxes are off on a plain MVR shop, but stay correct if that changes:
		// GST goes on as its own line so the receipt still sums to the order total.
		$tax = (float) $order->get_total_tax();
		if ( abs( $tax ) >= 0.005 ) {
			$lines[] = array(
				'item'   => self::item_name( $settings['tax_item'] ),
				'desc'   => 'GST',
				'qty'    => null,
				'unit'   => $tax,
				'amount' => $tax,
			);
		}

		return $lines;
	}

	/** QB customer list name for the order (unique, ≤41 chars, no colons). */
	public static function customer_name( $order, $settings ) {
		if ( 'generic' === $settings['customer_mode'] ) {
			return self::list_name( $settings['generic_customer'], self::MAX_CUSTOMER_NAME );
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $name ) {
			$name = $settings['generic_customer'];
		}
		return self::list_name( $name, self::MAX_CUSTOMER_NAME );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Parse the qbXML response QuickBooks sent back for one order's message.
	 *
	 * @param string $xml qbXML response document.
	 * @param int    $qid Queue row ID the message belonged to.
	 * @return array { ok: bool, txn_id: string, error: string }
	 */
	public static function parse_response( $xml, $qid ) {
		$out = array( 'ok' => false, 'txn_id' => '', 'error' => '' );

		if ( '' === trim( (string) $xml ) ) {
			$out['error'] = 'QuickBooks returned an empty response.';
			return $out;
		}

		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( false === $doc ) {
			$out['error'] = 'QuickBooks returned unparseable XML: ' . substr( $xml, 0, 300 );
			return $out;
		}

		$receipt_rs = null;
		foreach ( $doc->xpath( '//SalesReceiptAddRs' ) ?: array() as $rs ) {
			if ( (string) $rs['requestID'] === 'receipt-' . $qid ) {
				$receipt_rs = $rs;
				break;
			}
		}

		if ( null === $receipt_rs ) {
			// The receipt request never made it into the response — surface the
			// first hard error in the batch instead (often a qbXML parse error).
			foreach ( $doc->xpath( '//*[@statusSeverity="Error"]' ) ?: array() as $rs ) {
				$out['error'] = 'QuickBooks error ' . (string) $rs['statusCode'] . ': ' . (string) $rs['statusMessage'];
				return $out;
			}
			$out['error'] = 'No SalesReceiptAddRs in QuickBooks response.';
			return $out;
		}

		$code = (string) $receipt_rs['statusCode'];
		if ( '0' === $code ) {
			$out['ok'] = true;
			if ( isset( $receipt_rs->SalesReceiptRet->TxnID ) ) {
				$out['txn_id'] = (string) $receipt_rs->SalesReceiptRet->TxnID;
			}
			return $out;
		}

		$out['error'] = 'QuickBooks error ' . $code . ': ' . (string) $receipt_rs['statusMessage'];
		return $out;
	}

	/* ---------------------------- helpers ----------------------------- */

	/** ASCII-only text, trimmed to $max chars. */
	private static function txt( $value, $max ) {
		$value = remove_accents( (string) $value );
		// QuickBooks Desktop chokes on anything outside printable ASCII.
		$value = preg_replace( '/[^\x20-\x7E]/', '', $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		return substr( $value, 0, $max );
	}

	/** A QB list name: no colons (QB's hierarchy separator), length-capped. */
	private static function list_name( $value, $max ) {
		$value = str_replace( ':', '-', (string) $value );
		$value = self::txt( $value, $max );
		return '' !== $value ? $value : 'Web Customer';
	}

	private static function item_name( $value ) {
		$value = self::list_name( $value, self::MAX_ITEM_NAME );
		return '' !== $value ? $value : 'Online Sale';
	}

	private static function money( $value ) {
		return number_format( (float) $value, 2, '.', '' );
	}

	private static function qty( $value ) {
		$value = (float) $value;
		return ( floor( $value ) == $value ) ? (string) (int) $value : number_format( $value, 2, '.', '' );
	}

	private static function esc( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	private static function attr( $value ) {
		return self::esc( $value );
	}
}
