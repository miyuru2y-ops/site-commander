<?php
/**
 * WooCommerce tools (only listed when WooCommerce is active).
 *
 * @package SiteCommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SC_Tools_Commerce {

	public static function register() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$t = 'SC_Tools';

		$product_props = array(
			'name'              => $t::p( 'string', 'Product name.' ),
			'description'       => $t::p( 'string', 'Long description (HTML).' ),
			'short_description' => $t::p( 'string', 'Short description (HTML).' ),
			'regular_price'     => $t::p( 'string', 'Regular price, e.g. "49.90".' ),
			'sale_price'        => $t::p( 'string', 'Sale price. Empty string removes the sale.' ),
			'sku'               => $t::p( 'string', 'SKU.' ),
			'stock_quantity'    => $t::p( 'integer', 'Stock (turns on stock management).' ),
			'stock_status'      => $t::p_enum( array( 'instock', 'outofstock', 'onbackorder' ), 'Stock status.' ),
			'status'            => $t::p_enum( array( 'publish', 'draft', 'pending', 'private' ), 'Status.' ),
			'categories'        => $t::p_list( 'Product category names (created if missing).' ),
			'tags'              => $t::p_list( 'Product tag names.' ),
			'image'             => array( 'type' => array( 'integer', 'string' ), 'description' => 'Main image: attachment ID or URL.' ),
			'gallery'           => array( 'type' => 'array', 'items' => array( 'type' => array( 'integer', 'string' ) ), 'description' => 'Gallery images: IDs or URLs.' ),
			'weight'            => $t::p( 'string', 'Weight.' ),
			'featured'          => $t::p( 'boolean', 'Featured product.' ),
			'seo'               => array( 'type' => 'object', 'description' => 'SEO fields: title, description, focus_keyword.' ),
		);

		$t::add( 'list_products', array(
			'group'       => 'commerce',
			'description' => 'List or search WooCommerce products with price, stock and status.',
			'properties'  => array(
				'search'       => $t::p( 'string', 'Search text.' ),
				'category'     => $t::p( 'string', 'Category slug.' ),
				'stock_status' => $t::p_enum( array( 'instock', 'outofstock', 'onbackorder' ), 'Filter by stock.' ),
				'per_page'     => $t::p( 'integer', 'Default 30.' ),
				'page'         => $t::p( 'integer', 'Default 1.' ),
			),
			'callback'    => array( __CLASS__, 'list_products' ),
		) );

		$t::add( 'save_product', array(
			'group'       => 'commerce',
			'description' => 'Create a simple product (no id) or update any product: name, descriptions, prices/sale, SKU, stock, categories, images, SEO.',
			'properties'  => array_merge( array( 'id' => $t::p( 'integer', 'Product ID to update.' ) ), $product_props ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'save_product' ),
		) );

		$t::add( 'list_orders', array(
			'group'       => 'commerce',
			'description' => 'List recent WooCommerce orders (number, status, total, date, items).',
			'properties'  => array(
				'status'   => $t::p( 'string', 'e.g. processing, completed, on-hold. Default any.' ),
				'per_page' => $t::p( 'integer', 'Default 20.' ),
			),
			'callback'    => array( __CLASS__, 'list_orders' ),
		) );

		$t::add( 'update_order_status', array(
			'group'       => 'commerce',
			'description' => 'Change an order\'s status (e.g. processing → completed) with an optional order note. Customers may be emailed by WooCommerce.',
			'properties'  => array(
				'id'     => $t::p( 'integer', 'Order ID.' ),
				'status' => $t::p( 'string', 'New status: pending, processing, on-hold, completed, cancelled, refunded.' ),
				'note'   => $t::p( 'string', 'Order note.' ),
			),
			'required'    => array( 'id', 'status' ),
			'write'       => true,
			'callback'    => array( __CLASS__, 'update_order_status' ),
		) );
	}

	private static function format( $p ) {
		return array(
			'id'            => $p->get_id(),
			'name'          => $p->get_name(),
			'type'          => $p->get_type(),
			'status'        => $p->get_status(),
			'sku'           => $p->get_sku(),
			'price'         => $p->get_price(),
			'regular_price' => $p->get_regular_price(),
			'sale_price'    => $p->get_sale_price(),
			'stock_status'  => $p->get_stock_status(),
			'stock'         => $p->get_stock_quantity(),
			'link'          => $p->get_permalink(),
		);
	}

	public static function list_products( $a ) {
		$args = array(
			'limit'    => min( 100, SC_Tools::int( $a, 'per_page', 30 ) ),
			'page'     => max( 1, SC_Tools::int( $a, 'page', 1 ) ),
			'status'   => array( 'publish', 'draft', 'pending', 'private' ),
			'paginate' => true,
		);
		if ( $s = SC_Tools::str( $a, 'search' ) ) {
			$args['s'] = $s;
		}
		if ( $c = SC_Tools::str( $a, 'category' ) ) {
			$args['category'] = array( $c );
		}
		if ( $st = SC_Tools::str( $a, 'stock_status' ) ) {
			$args['stock_status'] = $st;
		}
		$res = wc_get_products( $args );
		return array( 'total' => $res->total, 'items' => array_map( array( __CLASS__, 'format' ), $res->products ) );
	}

	private static function image_id( $v, $pid ) {
		return is_numeric( $v ) ? (int) $v : SC_Tools_Media::sideload_url( (string) $v, $pid );
	}

	public static function save_product( $a ) {
		$id = SC_Tools::int( $a, 'id' );
		if ( $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				SC_Tools::fail( 'Product not found.' );
			}
		} else {
			if ( empty( $a['name'] ) ) {
				SC_Tools::fail( 'name is required for a new product.' );
			}
			$p = new WC_Product_Simple();
			$p->set_status( 'draft' );
		}
		$setters = array(
			'name'              => 'set_name',
			'description'       => 'set_description',
			'short_description' => 'set_short_description',
			'regular_price'     => 'set_regular_price',
			'sale_price'        => 'set_sale_price',
			'sku'               => 'set_sku',
			'stock_status'      => 'set_stock_status',
			'status'            => 'set_status',
			'weight'            => 'set_weight',
		);
		try {
			foreach ( $setters as $k => $m ) {
				if ( isset( $a[ $k ] ) ) {
					$p->$m( (string) $a[ $k ] );
				}
			}
			if ( isset( $a['stock_quantity'] ) ) {
				$p->set_manage_stock( true );
				$p->set_stock_quantity( (int) $a['stock_quantity'] );
			}
			if ( isset( $a['featured'] ) ) {
				$p->set_featured( SC_Tools::bool( $a, 'featured' ) );
			}
		} catch ( WC_Data_Exception $e ) {
			SC_Tools::fail( $e->getMessage() );
		}
		$pid = $p->save();
		if ( isset( $a['categories'] ) ) {
			$ids = array();
			foreach ( (array) SC_Tools::arr( $a, 'categories' ) as $c ) {
				$ids[] = SC_Tools_Content::term_id( $c, 'product_cat' );
			}
			$p->set_category_ids( array_filter( $ids ) );
		}
		if ( isset( $a['tags'] ) ) {
			$ids = array();
			foreach ( (array) SC_Tools::arr( $a, 'tags' ) as $c ) {
				$ids[] = SC_Tools_Content::term_id( $c, 'product_tag' );
			}
			$p->set_tag_ids( array_filter( $ids ) );
		}
		if ( ! empty( $a['image'] ) ) {
			$p->set_image_id( self::image_id( $a['image'], $pid ) );
		}
		if ( isset( $a['gallery'] ) ) {
			$g = array();
			foreach ( (array) $a['gallery'] as $img ) {
				$g[] = self::image_id( $img, $pid );
			}
			$p->set_gallery_image_ids( $g );
		}
		$p->save();
		if ( ! empty( $a['seo'] ) && is_array( $a['seo'] ) ) {
			SC_Tools_SEO::apply_seo( $pid, $a['seo'] );
		}
		$out            = self::format( $p );
		$out['message'] = ( $id ? 'Updated' : 'Created' ) . " product #$pid.";
		return $out;
	}

	public static function list_orders( $a ) {
		$args = array( 'limit' => min( 100, SC_Tools::int( $a, 'per_page', 20 ) ), 'orderby' => 'date', 'order' => 'DESC' );
		if ( $s = SC_Tools::str( $a, 'status' ) ) {
			$args['status'] = $s;
		}
		return array_map(
			function ( $o ) {
				$items = array();
				foreach ( $o->get_items() as $it ) {
					$items[] = $it->get_quantity() . '× ' . $it->get_name();
				}
				return array(
					'id'       => $o->get_id(),
					'number'   => $o->get_order_number(),
					'status'   => $o->get_status(),
					'total'    => $o->get_total() . ' ' . $o->get_currency(),
					'date'     => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : null,
					'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
					'items'    => $items,
				);
			},
			wc_get_orders( $args )
		);
	}

	public static function update_order_status( $a ) {
		$o = wc_get_order( (int) $a['id'] );
		if ( ! $o ) {
			SC_Tools::fail( 'Order not found.' );
		}
		$status = preg_replace( '/^wc-/', '', (string) $a['status'] );
		if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
			SC_Tools::fail( "Unknown order status '$status'." );
		}
		$o->update_status( $status, SC_Tools::str( $a, 'note', '' ), true );
		return array( 'message' => "Order #{$o->get_order_number()} is now $status." );
	}
}
