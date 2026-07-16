<?php
/**
 * WooCommerce Factory for unit tests.
 *
 * Creates mock WooCommerce objects (WC_Order, WC_Product, WC_Cart, etc.)
 * for testing WooCommerce integration handlers without actual WC dependency.
 *
 * @package NetterTechEvents\Tests\Factories
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Factories;

/**
 * Factory for creating mock WooCommerce objects.
 */
class WooCommerceFactory {

	/**
	 * Counter for generating unique IDs.
	 *
	 * @var int
	 */
	private static int $counter = 0;

	/**
	 * Default order data.
	 *
	 * @var array<string, mixed>
	 */
	private static array $order_defaults = [
		'id'                 => null,
		'status'             => 'completed',
		'billing_first_name' => 'John',
		'billing_last_name'  => 'Doe',
		'billing_email'      => 'john.doe@example.com',
		'billing_phone'      => '555-1234',
		'meta_data'          => [],
		'items'              => [],
	];

	/**
	 * Default product data.
	 *
	 * @var array<string, mixed>
	 */
	private static array $product_defaults = [
		'id'    => null,
		'name'  => 'Test Product',
		'price' => '10.00',
		'type'  => 'simple',
		'meta'  => [],
	];

	/**
	 * Default order item data.
	 *
	 * @var array<string, mixed>
	 */
	private static array $item_defaults = [
		'id'         => null,
		'product_id' => 1,
		'quantity'   => 1,
		'total'      => '10.00',
		'meta_data'  => [],
	];

	/**
	 * Reset the counter (useful between tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$counter = 0;
	}

	/**
	 * Create a mock WC_Order object.
	 *
	 * @param array<string, mixed> $attributes Order attributes to override defaults.
	 * @return MockWCOrder
	 */
	public static function create_order( array $attributes = [] ): MockWCOrder {
		++self::$counter;
		$data = array_merge( self::$order_defaults, $attributes );

		$order     = new MockWCOrder();
		$order->id = $data['id'] ?? self::$counter;
		$order->set_status( $data['status'] );
		$order->set_billing_first_name( $data['billing_first_name'] );
		$order->set_billing_last_name( $data['billing_last_name'] );
		$order->set_billing_email( $data['billing_email'] );
		$order->set_billing_phone( $data['billing_phone'] );

		foreach ( $data['meta_data'] as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		foreach ( $data['items'] as $item_data ) {
			$item = $item_data instanceof MockWCOrderItemProduct
				? $item_data
				: self::create_order_item( $item_data );
			$order->add_item( $item );
		}

		return $order;
	}

	/**
	 * Create a mock WC_Order with completed status.
	 *
	 * @param array<string, mixed> $attributes Additional attributes.
	 * @return MockWCOrder
	 */
	public static function completed_order( array $attributes = [] ): MockWCOrder {
		return self::create_order( array_merge( [ 'status' => 'completed' ], $attributes ) );
	}

	/**
	 * Create a mock WC_Order with pending status.
	 *
	 * @param array<string, mixed> $attributes Additional attributes.
	 * @return MockWCOrder
	 */
	public static function pending_order( array $attributes = [] ): MockWCOrder {
		return self::create_order( array_merge( [ 'status' => 'pending' ], $attributes ) );
	}

	/**
	 * Create a mock WC_Order_Refund object.
	 *
	 * @param int                  $parent_order_id Parent order ID.
	 * @param array<string, mixed> $attributes      Refund attributes.
	 * @return MockWCOrderRefund
	 */
	public static function create_refund( int $parent_order_id, array $attributes = [] ): MockWCOrderRefund {
		++self::$counter;
		$data = array_merge( self::$order_defaults, $attributes );

		$refund                  = new MockWCOrderRefund();
		$refund->id              = $data['id'] ?? self::$counter;
		$refund->parent_order_id = $parent_order_id;
		$refund->set_status( 'refunded' );

		return $refund;
	}

	/**
	 * Create a mock WC_Order_Item_Product object.
	 *
	 * @param array<string, mixed> $attributes Item attributes to override defaults.
	 * @return MockWCOrderItemProduct
	 */
	public static function create_order_item( array $attributes = [] ): MockWCOrderItemProduct {
		++self::$counter;
		$data = array_merge( self::$item_defaults, $attributes );

		$item             = new MockWCOrderItemProduct();
		$item->id         = $data['id'] ?? self::$counter;
		$item->product_id = $data['product_id'];
		$item->quantity   = $data['quantity'];
		$item->total      = $data['total'];

		foreach ( $data['meta_data'] as $key => $value ) {
			$item->add_meta_data( $key, $value );
		}

		return $item;
	}

	/**
	 * Create a mock WC_Product object.
	 *
	 * @param array<string, mixed> $attributes Product attributes to override defaults.
	 * @return MockWCProduct
	 */
	public static function create_product( array $attributes = [] ): MockWCProduct {
		++self::$counter;
		$data = array_merge( self::$product_defaults, $attributes );

		$product        = new MockWCProduct();
		$product->id    = $data['id'] ?? self::$counter;
		$product->name  = $data['name'];
		$product->price = $data['price'];
		$product->type  = $data['type'];

		foreach ( $data['meta'] as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}

		return $product;
	}

	/**
	 * Create a mock event ticket product.
	 *
	 * @param int                  $ticket_type_id Ticket type ID.
	 * @param int                  $occurrence_id  Occurrence ID.
	 * @param array<string, mixed> $attributes     Additional attributes.
	 * @return MockWCProduct
	 */
	public static function create_ticket_product( int $ticket_type_id, int $occurrence_id, array $attributes = [] ): MockWCProduct {
		$meta = [
			'_nettertech_events_ticket_type_id' => $ticket_type_id,
			'_nettertech_events_occurrence_id'  => $occurrence_id,
			'_nettertech_events_is_event_ticket'      => true,
		];

		return self::create_product(
			array_merge(
				[
					'name' => 'Event Ticket',
					'type' => 'venue_ticket',
					'meta' => $meta,
				],
				$attributes
			)
		);
	}

	/**
	 * Create a mock cart item array.
	 *
	 * @param MockWCProduct        $product    Product mock.
	 * @param int                  $quantity   Quantity.
	 * @param array<string, mixed> $extra_data Additional cart item data.
	 * @return array<string, mixed>
	 */
	public static function create_cart_item( MockWCProduct $product, int $quantity = 1, array $extra_data = [] ): array {
		$cart_item_key = md5( (string) $product->get_id() . microtime() );

		return array_merge(
			[
				'key'          => $cart_item_key,
				'product_id'   => $product->get_id(),
				'variation_id' => 0,
				'quantity'     => $quantity,
				'data'         => $product,
				'line_total'   => (float) $product->get_price() * $quantity,
			],
			$extra_data
		);
	}

	/**
	 * Create a mock WC_Cart object.
	 *
	 * @param array<array<string, mixed>> $items Cart items to add.
	 * @return MockWCCart
	 */
	public static function create_cart( array $items = [] ): MockWCCart {
		$cart = new MockWCCart();

		foreach ( $items as $item ) {
			$cart->add_item( $item );
		}

		return $cart;
	}

	/**
	 * Create and register the WC() singleton mock.
	 *
	 * @param MockWCCart|null $cart Optional cart to use.
	 * @return MockWCSingleton
	 */
	public static function create_wc_singleton( ?MockWCCart $cart = null ): MockWCSingleton {
		$wc          = new MockWCSingleton();
		$wc->cart    = $cart ?? new MockWCCart();
		$wc->session = new MockWCSession();

		return $wc;
	}
}

/**
 * Mock WC_Order class.
 */
class MockWCOrder {

	/**
	 * Order ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Order status.
	 *
	 * @var string
	 */
	private string $status = 'pending';

	/**
	 * Billing first name.
	 *
	 * @var string
	 */
	private string $billing_first_name = '';

	/**
	 * Billing last name.
	 *
	 * @var string
	 */
	private string $billing_last_name = '';

	/**
	 * Billing email.
	 *
	 * @var string
	 */
	private string $billing_email = '';

	/**
	 * Billing phone.
	 *
	 * @var string
	 */
	private string $billing_phone = '';

	/**
	 * Order meta data.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta_data = [];

	/**
	 * Order items.
	 *
	 * @var array<MockWCOrderItemProduct>
	 */
	private array $items = [];

	/**
	 * Whether the order was saved.
	 *
	 * @var bool
	 */
	public bool $saved = false;

	/**
	 * Get order ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Get order status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Set order status.
	 *
	 * @param string $status Order status.
	 * @return void
	 */
	public function set_status( string $status ): void {
		$this->status = $status;
	}

	/**
	 * Get billing first name.
	 *
	 * @return string
	 */
	public function get_billing_first_name(): string {
		return $this->billing_first_name;
	}

	/**
	 * Set billing first name.
	 *
	 * @param string $name First name.
	 * @return void
	 */
	public function set_billing_first_name( string $name ): void {
		$this->billing_first_name = $name;
	}

	/**
	 * Get billing last name.
	 *
	 * @return string
	 */
	public function get_billing_last_name(): string {
		return $this->billing_last_name;
	}

	/**
	 * Set billing last name.
	 *
	 * @param string $name Last name.
	 * @return void
	 */
	public function set_billing_last_name( string $name ): void {
		$this->billing_last_name = $name;
	}

	/**
	 * Get billing email.
	 *
	 * @return string
	 */
	public function get_billing_email(): string {
		return $this->billing_email;
	}

	/**
	 * Set billing email.
	 *
	 * @param string $email Email address.
	 * @return void
	 */
	public function set_billing_email( string $email ): void {
		$this->billing_email = $email;
	}

	/**
	 * Get billing phone.
	 *
	 * @return string
	 */
	public function get_billing_phone(): string {
		return $this->billing_phone;
	}

	/**
	 * Set billing phone.
	 *
	 * @param string $phone Phone number.
	 * @return void
	 */
	public function set_billing_phone( string $phone ): void {
		$this->billing_phone = $phone;
	}

	/**
	 * Get meta data value.
	 *
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return single value.
	 * @param string $context Context (view or edit).
	 * @return mixed
	 */
	public function get_meta( string $key, bool $single = true, string $context = 'view' ): mixed {
		if ( ! isset( $this->meta_data[ $key ] ) ) {
			return $single ? '' : [];
		}
		return $this->meta_data[ $key ];
	}

	/**
	 * Update meta data.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @return void
	 */
	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta_data[ $key ] = $value;
	}

	/**
	 * Get order items.
	 *
	 * @param string|array<string> $types Item types to get.
	 * @return array<MockWCOrderItemProduct>
	 */
	public function get_items( string|array $types = 'line_item' ): array {
		return $this->items;
	}

	/**
	 * Add an order item.
	 *
	 * @param MockWCOrderItemProduct $item Order item.
	 * @return void
	 */
	public function add_item( MockWCOrderItemProduct $item ): void {
		$this->items[ $item->get_id() ] = $item;
	}

	/**
	 * Get a specific order item.
	 *
	 * @param int $item_id Item ID.
	 * @return MockWCOrderItemProduct|null
	 */
	public function get_item( int $item_id ): ?MockWCOrderItemProduct {
		return $this->items[ $item_id ] ?? null;
	}

	/**
	 * Save the order.
	 *
	 * @return int Order ID.
	 */
	public function save(): int {
		$this->saved = true;
		return $this->id;
	}
}

/**
 * Mock WC_Order_Refund class.
 */
class MockWCOrderRefund extends MockWCOrder {

	/**
	 * Parent order ID.
	 *
	 * @var int
	 */
	public int $parent_order_id = 0;

	/**
	 * Get parent order ID.
	 *
	 * @return int
	 */
	public function get_parent_id(): int {
		return $this->parent_order_id;
	}
}

/**
 * Mock WC_Order_Item_Product class.
 */
class MockWCOrderItemProduct {

	/**
	 * Item ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	public int $product_id = 0;

	/**
	 * Quantity.
	 *
	 * @var int
	 */
	public int $quantity = 1;

	/**
	 * Item total.
	 *
	 * @var string
	 */
	public string $total = '0.00';

	/**
	 * Item meta data.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta_data = [];

	/**
	 * Get item ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Get product ID.
	 *
	 * @return int
	 */
	public function get_product_id(): int {
		return $this->product_id;
	}

	/**
	 * Get quantity.
	 *
	 * @return int
	 */
	public function get_quantity(): int {
		return $this->quantity;
	}

	/**
	 * Get item total.
	 *
	 * @return string
	 */
	public function get_total(): string {
		return $this->total;
	}

	/**
	 * Get meta data.
	 *
	 * @param string $key    Meta key.
	 * @param bool   $single Whether to return single value.
	 * @return mixed
	 */
	public function get_meta( string $key, bool $single = true ): mixed {
		if ( ! isset( $this->meta_data[ $key ] ) ) {
			return $single ? '' : [];
		}
		return $this->meta_data[ $key ];
	}

	/**
	 * Add meta data.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @param bool   $unique Whether the meta key should be unique.
	 * @return void
	 */
	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void {
		$this->meta_data[ $key ] = $value;
	}
}

/**
 * Mock WC_Product class.
 */
class MockWCProduct {

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Product name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Product price.
	 *
	 * @var string
	 */
	public string $price = '0.00';

	/**
	 * Product type.
	 *
	 * @var string
	 */
	public string $type = 'simple';

	/**
	 * Product meta data.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta_data = [];

	/**
	 * Get product ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Get product name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Get product price.
	 *
	 * @return string
	 */
	public function get_price(): string {
		return $this->price;
	}

	/**
	 * Get product type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Get meta data.
	 *
	 * @param string $key    Meta key.
	 * @param bool   $single Whether to return single value.
	 * @return mixed
	 */
	public function get_meta( string $key, bool $single = true ): mixed {
		if ( ! isset( $this->meta_data[ $key ] ) ) {
			return $single ? '' : [];
		}
		return $this->meta_data[ $key ];
	}

	/**
	 * Update meta data.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @return void
	 */
	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta_data[ $key ] = $value;
	}

	/**
	 * Check if product is an event ticket.
	 *
	 * @return bool
	 */
	public function is_event_ticket(): bool {
		return $this->type === 'venue_ticket' || (bool) $this->get_meta( '_nettertech_events_is_event_ticket' );
	}
}

/**
 * Mock WC_Cart class.
 */
class MockWCCart {

	/**
	 * Cart contents.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cart_contents = [];

	/**
	 * Removed cart contents.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $removed_cart_contents = [];

	/**
	 * Get cart contents.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_cart(): array {
		return $this->cart_contents;
	}

	/**
	 * Get a cart item by key.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @return array<string, mixed>|null
	 */
	public function get_cart_item( string $cart_item_key ): ?array {
		return $this->cart_contents[ $cart_item_key ] ?? null;
	}

	/**
	 * Add a cart item.
	 *
	 * @param array<string, mixed> $item Cart item data.
	 * @return void
	 */
	public function add_item( array $item ): void {
		$key                          = $item['key'] ?? md5( (string) ( $item['product_id'] ?? 0 ) . microtime() );
		$item['key']                  = $key;
		$this->cart_contents[ $key ] = $item;
	}

	/**
	 * Add to cart (simulates WC add_to_cart).
	 *
	 * @param int   $product_id   Product ID.
	 * @param int   $quantity     Quantity.
	 * @param int   $variation_id Variation ID.
	 * @param array<string, mixed> $cart_item_data Additional cart item data.
	 * @return string Cart item key.
	 */
	public function add_to_cart( int $product_id, int $quantity = 1, int $variation_id = 0, array $cart_item_data = [] ): string {
		$key  = md5( $product_id . $variation_id . microtime() );
		$item = array_merge(
			[
				'key'          => $key,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'quantity'     => $quantity,
			],
			$cart_item_data
		);

		$this->cart_contents[ $key ] = $item;
		return $key;
	}

	/**
	 * Remove a cart item.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @return bool
	 */
	public function remove_cart_item( string $cart_item_key ): bool {
		if ( isset( $this->cart_contents[ $cart_item_key ] ) ) {
			$this->removed_cart_contents[ $cart_item_key ] = $this->cart_contents[ $cart_item_key ];
			unset( $this->cart_contents[ $cart_item_key ] );
			return true;
		}
		return false;
	}

	/**
	 * Empty the cart.
	 *
	 * @return void
	 */
	public function empty_cart(): void {
		$this->cart_contents = [];
	}
}

/**
 * Mock WC_Session class.
 */
class MockWCSession {

	/**
	 * Session data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data = [];

	/**
	 * Get session data.
	 *
	 * @param string $key     Session key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}

	/**
	 * Set session data.
	 *
	 * @param string $key   Session key.
	 * @param mixed  $value Session value.
	 * @return void
	 */
	public function set( string $key, mixed $value ): void {
		$this->data[ $key ] = $value;
	}
}

/**
 * Mock WC() singleton.
 */
class MockWCSingleton {

	/**
	 * Cart instance.
	 *
	 * @var MockWCCart|null
	 */
	public ?MockWCCart $cart = null;

	/**
	 * Session instance.
	 *
	 * @var MockWCSession|null
	 */
	public ?MockWCSession $session = null;
}
