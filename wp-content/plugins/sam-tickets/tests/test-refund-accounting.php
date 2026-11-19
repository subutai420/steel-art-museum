<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'OBJECT_K', 'OBJECT_K' );

final class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code, $message, $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

function current_time( $type, $gmt = false ) {
	return '2026-08-20 10:00:00';
}

final class SAM_Refund_Test_DB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $insert_id = 0;
	public $tickets = array();
	public $items = array();
	public $events = array();
	public $redemptions = array();
	private $event_sequence = 0;

	public function prepare( $query, ...$args ) {
		$index = 0;
		return preg_replace_callback(
			'/%[dsf]/',
			static function( $match ) use ( &$index, $args ) {
				$value = $args[ $index++ ];
				return '%s' === $match[0] ? "'" . str_replace( "'", "''", (string) $value ) . "'" : (string) (int) $value;
			},
			$query
		);
	}

	public function get_row( $sql ) {
		if ( preg_match( "/FROM wp_sam_tickets WHERE token = '([^']+)'/", $sql, $match ) ) {
			foreach ( $this->tickets as $ticket ) {
				if ( (string) ( $ticket->token ?? '' ) === $match[1] ) {
					return clone $ticket;
				}
			}
			return null;
		}
		if ( preg_match( '/FROM wp_sam_tickets WHERE order_id = (\d+)/', $sql, $match ) ) {
			foreach ( $this->tickets as $ticket ) {
				if ( (int) $ticket->order_id === (int) $match[1] ) {
					return clone $ticket;
				}
			}
			return null;
		}
		if ( preg_match( '/FROM wp_sam_tickets WHERE id = (\d+)/', $sql, $match ) ) {
			return isset( $this->tickets[ (int) $match[1] ] ) ? clone $this->tickets[ (int) $match[1] ] : null;
		}
		if ( preg_match( "/FROM wp_sam_ticket_events WHERE event_key = '([^']+)'/", $sql, $match ) ) {
			foreach ( $this->events as $event ) {
				if ( (string) ( $event->event_key ?? '' ) === $match[1] ) {
					return clone $event;
				}
			}
			return null;
		}
		if ( false !== strpos( $sql, 'SUM(purchased_quantity)' ) && preg_match( '/ticket_id = (\d+)/', $sql, $match ) ) {
			$totals = (object) array( 'purchased' => 0, 'redeemed' => 0, 'refunded' => 0, 'refund_pending' => 0 );
			foreach ( $this->items as $item ) {
				if ( (int) $item->ticket_id !== (int) $match[1] ) {
					continue;
				}
				$totals->purchased      += (int) $item->purchased_quantity;
				$totals->redeemed       += (int) $item->redeemed_quantity;
				$totals->refunded       += (int) $item->refunded_quantity;
				$totals->refund_pending += (int) $item->refund_pending_quantity;
			}
			return $totals;
		}
		throw new RuntimeException( 'Unexpected get_row query: ' . $sql );
	}

	public function get_results( $sql, $output = null ) {
		if ( preg_match( '/FROM wp_sam_ticket_items WHERE ticket_id = (\d+)/', $sql, $match ) ) {
			$result = array();
			foreach ( $this->items as $item ) {
				if ( (int) $item->ticket_id === (int) $match[1] ) {
					if ( OBJECT_K === $output ) {
						$result[ (int) $item->id ] = clone $item;
					} else {
						$result[] = clone $item;
					}
				}
			}
			return $result;
		}
		throw new RuntimeException( 'Unexpected get_results query: ' . $sql );
	}

	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'SUM(purchased_quantity - redeemed_quantity' ) && preg_match( '/ticket_id = (\d+)/', $sql, $match ) ) {
			$remaining = 0;
			foreach ( $this->items as $item ) {
				if ( (int) $item->ticket_id === (int) $match[1] ) {
					$remaining += $item->purchased_quantity - $item->redeemed_quantity - $item->refunded_quantity - $item->refund_pending_quantity;
				}
			}
			return $remaining;
		}
		throw new RuntimeException( 'Unexpected get_var query: ' . $sql );
	}

	public function query( $sql ) {
		$sql = trim( $sql );
		if ( in_array( $sql, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
			return true;
		}

		if ( preg_match( '/SET redeemed_quantity = redeemed_quantity \+ (\d+).*WHERE id = (\d+) AND ticket_id = (\d+).*refund_pending_quantity \+ (\d+) <= purchased_quantity/s', $sql, $match ) ) {
			$item = $this->items[ (int) $match[2] ] ?? null;
			if ( ! $item || (int) $item->ticket_id !== (int) $match[3] ) {
				return 0;
			}
			if ( $item->redeemed_quantity + $item->refunded_quantity + $item->refund_pending_quantity + (int) $match[4] > $item->purchased_quantity ) {
				return 0;
			}
			$item->redeemed_quantity += (int) $match[1];
			return 1;
		}

		if ( preg_match( '/SET refund_pending_quantity = refund_pending_quantity \+ (\d+).*WHERE id = (\d+) AND ticket_id = (\d+).*refund_pending_quantity \+ (\d+) <= purchased_quantity/s', $sql, $match ) ) {
			$item = $this->items[ (int) $match[2] ] ?? null;
			if ( ! $item || (int) $item->ticket_id !== (int) $match[3] ) {
				return 0;
			}
			$quantity = (int) $match[1];
			if ( $item->redeemed_quantity + $item->refunded_quantity + $item->refund_pending_quantity + (int) $match[4] > $item->purchased_quantity ) {
				return 0;
			}
			$item->refund_pending_quantity += $quantity;
			return 1;
		}

		if ( preg_match( '/SET refund_pending_quantity = refund_pending_quantity - (\d+),\s*refunded_quantity = refunded_quantity \+ (\d+).*WHERE id = (\d+) AND ticket_id = (\d+) AND refund_pending_quantity >= (\d+)/s', $sql, $match ) ) {
			$item = $this->items[ (int) $match[3] ] ?? null;
			if ( ! $item || (int) $item->ticket_id !== (int) $match[4] || $item->refund_pending_quantity < (int) $match[5] ) {
				return 0;
			}
			$item->refund_pending_quantity -= (int) $match[1];
			$item->refunded_quantity       += (int) $match[2];
			return 1;
		}

		if ( preg_match( '/SET refund_pending_quantity = refund_pending_quantity - (\d+).*WHERE id = (\d+) AND ticket_id = (\d+) AND refund_pending_quantity >= (\d+)/s', $sql, $match ) ) {
			$item = $this->items[ (int) $match[2] ] ?? null;
			if ( ! $item || (int) $item->ticket_id !== (int) $match[3] || $item->refund_pending_quantity < (int) $match[4] ) {
				return 0;
			}
			$item->refund_pending_quantity -= (int) $match[1];
			return 1;
		}

		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}

	public function insert( $table, $data, $formats = array() ) {
		if ( 'wp_sam_ticket_redemptions' === $table ) {
			$this->redemptions[] = (object) $data;
			return 1;
		}
		if ( 'wp_sam_ticket_events' !== $table ) {
			throw new RuntimeException( 'Unexpected insert table: ' . $table );
		}
		foreach ( $this->events as $event ) {
			if ( ! empty( $data['event_key'] ) && ( $event->event_key ?? '' ) === $data['event_key'] ) {
				return false;
			}
		}
		$data['id'] = ++$this->event_sequence;
		$this->events[ $data['id'] ] = (object) $data;
		$this->insert_id = $data['id'];
		return 1;
	}

	public function update( $table, $data, $where, $formats = array(), $where_formats = array() ) {
		if ( 'wp_sam_tickets' !== $table || empty( $this->tickets[ (int) $where['id'] ] ) ) {
			return false;
		}
		foreach ( $data as $key => $value ) {
			$this->tickets[ (int) $where['id'] ]->{$key} = $value;
		}
		return 1;
	}
}

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function assert_error( $code, $actual, $message ) {
	if ( ! is_wp_error( $actual ) || $code !== $actual->get_error_code() ) {
		fwrite( STDERR, $message . ': expected WP_Error ' . $code . "\n" );
		exit( 1 );
	}
}

$wpdb = new SAM_Refund_Test_DB();
$wpdb->tickets[1] = (object) array( 'id' => 1, 'order_id' => 100, 'status' => 'partially_redeemed' );
$wpdb->items[10]  = (object) array(
	'id' => 10, 'ticket_id' => 1, 'order_item_id' => 501, 'label' => 'Dospělý', 'unit_type' => 'single',
	'purchased_quantity' => 3, 'redeemed_quantity' => 1, 'refunded_quantity' => 0, 'refund_pending_quantity' => 0,
);
$wpdb->tickets[2] = (object) array( 'id' => 2, 'order_id' => 200, 'status' => 'valid' );
$wpdb->items[20]  = (object) array(
	'id' => 20, 'ticket_id' => 2, 'order_item_id' => 601, 'label' => 'Rodinná vstupenka', 'unit_type' => 'family',
	'purchased_quantity' => 2, 'redeemed_quantity' => 0, 'refunded_quantity' => 0, 'refund_pending_quantity' => 0,
);
$wpdb->tickets[3] = (object) array( 'id' => 3, 'order_id' => 300, 'status' => 'void' );
$wpdb->items[30]  = (object) array(
	'id' => 30, 'ticket_id' => 3, 'order_item_id' => 701, 'label' => 'Dítě', 'unit_type' => 'single',
	'purchased_quantity' => 2, 'redeemed_quantity' => 0, 'refunded_quantity' => 0, 'refund_pending_quantity' => 0,
);

require_once dirname( __DIR__ ) . '/includes/class-sam-tickets-repository.php';
$repository = new SAM_Tickets_Repository();

$plan = $repository->validate_refund_plan( 100, array( 501 => 2 ) );
assert_same( 2, $plan['items'][0]['quantity'], 'Validated quantity' );
assert_same( 2, $plan['items'][0]['refundable'], 'Only unused entries are refundable' );

$reserved = $repository->reserve_refund( 100, 'operation-1', $plan, 7 );
assert_same( 2, $wpdb->items[10]->refund_pending_quantity, 'Reservation blocks the requested quantity' );
assert_error( 'sam_ticket_refund_quantity_exceeded', $repository->validate_refund_plan( 100, array( 501 => 1 ) ), 'Pending entries cannot be refunded or redeemed again' );

$repository->reserve_refund( 100, 'operation-1', $plan, 7 );
assert_same( 2, $wpdb->items[10]->refund_pending_quantity, 'Repeated reservation is idempotent' );
assert_error( 'sam_ticket_refund_idempotency_conflict', $repository->reserve_refund( 100, 'operation-1', array( 501 => 1 ), 7 ), 'Same operation key rejects a changed payload' );

$committed = $repository->commit_refund( 100, 'operation-1', '9001', 7 );
assert_same( 0, $wpdb->items[10]->refund_pending_quantity, 'Commit clears pending quantity' );
assert_same( 2, $wpdb->items[10]->refunded_quantity, 'Commit records refunded quantity' );
assert_same( 'redeemed', $committed->status, 'Used plus refunded ticket is fully settled' );

$repository->commit_refund( 100, 'operation-1', '9001', 7 );
assert_same( 2, $wpdb->items[10]->refunded_quantity, 'Repeated commit is idempotent' );
assert_same( 1, count( array_filter( $wpdb->events, static function( $event ) { return 'refunded' === $event->event_type; } ) ), 'Refund history has one final event' );
$repository->apply_refund( 100, 'operation-1', array( 501 => 2 ), 7 );
assert_same( 2, $wpdb->items[10]->refunded_quantity, 'Repeated direct apply is idempotent' );

assert_error( 'sam_ticket_invalid_refund_quantity', $repository->validate_refund_plan( 200, array( 601 => 0.5 ) ), 'Family bundle cannot be split into people/fractions' );
$family_plan = $repository->validate_refund_plan( 200, array( 601 => 1 ) );
$repository->reserve_refund( 200, 'operation-2', $family_plan, 7 );
$repository->release_refund( 200, 'operation-2', 'gateway_failed', 7 );
assert_same( 0, $wpdb->items[20]->refund_pending_quantity, 'Rejected gateway refund releases quantity' );
assert_same( 0, $wpdb->items[20]->refunded_quantity, 'Release does not mark quantity refunded' );
$repository->release_refund( 200, 'operation-2', 'gateway_failed', 7 );
assert_same( 0, $wpdb->items[20]->refund_pending_quantity, 'Repeated release is idempotent' );
assert_error( 'sam_ticket_refund_released', $repository->reserve_refund( 200, 'operation-2', $family_plan, 7 ), 'Released operation cannot be sent to the gateway again' );

$void_plan = $repository->validate_refund_plan( 300, array( 701 => 1 ) );
$repository->reserve_refund( 300, 'operation-3', $void_plan, 7 );
$void_ticket = $repository->commit_refund( 300, 'operation-3', '9003', 7 );
assert_same( 'void', $void_ticket->status, 'Refund accounting never resurrects an already void ticket' );

// A released refund must give the reserved entry back to the scanner even
// when a redemption in the meantime marked the ticket as fully used.
$wpdb->tickets[4] = (object) array( 'id' => 4, 'order_id' => 400, 'status' => 'valid', 'token' => str_repeat( 'a', 48 ), 'valid_until' => '2027-01-01 00:00:00' );
$wpdb->items[40]  = (object) array(
	'id' => 40, 'ticket_id' => 4, 'order_item_id' => 801, 'label' => 'Dospělý', 'unit_type' => 'single',
	'purchased_quantity' => 2, 'redeemed_quantity' => 0, 'refunded_quantity' => 0, 'refund_pending_quantity' => 0,
);
$repository->reserve_refund( 400, 'operation-4', array( 801 => 1 ), 7 );
$redeemed = $repository->redeem( str_repeat( 'a', 48 ), array( 40 => 1 ), 7, 'scan-operation-1' );
assert_same( 'redeemed', $redeemed->status, 'Pending refund counts as consumed while it is in flight' );
$released = $repository->release_refund( 400, 'operation-4', 'gateway_failed', 7 );
assert_same( 'partially_redeemed', $released->status, 'Released refund makes the remaining entry redeemable again' );

// Repeating the same scanner operation must not deduct twice.
$replayed = $repository->redeem( str_repeat( 'a', 48 ), array( 40 => 1 ), 7, 'scan-operation-1' );
assert_same( 1, $wpdb->items[40]->redeemed_quantity, 'Replayed scanner operation does not deduct again' );
assert_same( true, ! empty( $replayed->sam_replayed ), 'Replay is reported to the caller' );
assert_error( 'sam_ticket_redeem_idempotency_conflict', $repository->redeem( str_repeat( 'a', 48 ), array( 40 => 2 ), 7, 'scan-operation-1' ), 'Same scanner operation rejects changed items' );
$second = $repository->redeem( str_repeat( 'a', 48 ), array( 40 => 1 ), 7, 'scan-operation-2' );
assert_same( 2, $wpdb->items[40]->redeemed_quantity, 'A new scanner operation deducts normally' );
assert_same( 'redeemed', $second->status, 'Last entry marks the ticket as used' );
assert_same( 2, count( $wpdb->redemptions ), 'Exactly two redemption rows were written' );

echo "Refund accounting tests passed.\n";
