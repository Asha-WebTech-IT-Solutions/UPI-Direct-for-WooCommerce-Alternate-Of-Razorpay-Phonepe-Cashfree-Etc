<?php
/**
 * Tests for UPIDW_UPI.
 *
 * @package UPI_Direct_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers UPIDW_UPI
 */
final class UpiTest extends TestCase {

	/**
	 * @dataProvider valid_vpas
	 */
	public function test_valid_vpa( $vpa ) {
		$this->assertTrue( UPIDW_UPI::is_valid_vpa( $vpa ) );
	}

	public function valid_vpas() {
		return array(
			array( 'shop@okaxis' ),
			array( 'my.shop-1_x@ybl' ),
			array( '9876543210@paytm' ),
		);
	}

	/**
	 * @dataProvider invalid_vpas
	 */
	public function test_invalid_vpa( $vpa ) {
		$this->assertFalse( UPIDW_UPI::is_valid_vpa( $vpa ) );
	}

	public function invalid_vpas() {
		return array(
			array( '' ),
			array( 'noatsign' ),
			array( '@okaxis' ),
			array( 'a@b' ),
			array( 'shop@ok axis' ),
			array( "shop@okaxis\n" ),
			array( 'shop@okaxis&pa=evil@bank' ),
			array( 'shop@@okaxis' ),
			array( null ),
		);
	}

	public function test_reference_normalisation_and_validation() {
		$this->assertSame( '401234567890', UPIDW_UPI::normalize_reference( ' 4012 3456-7890 ' ) );
		$this->assertTrue( UPIDW_UPI::is_valid_reference( '401234567890', 'utr12' ) );
		$this->assertFalse( UPIDW_UPI::is_valid_reference( '40123456789', 'utr12' ) );
		$this->assertFalse( UPIDW_UPI::is_valid_reference( '4012345678901', 'utr12' ) );
		$this->assertFalse( UPIDW_UPI::is_valid_reference( 'ABCDEFGHIJKL', 'utr12' ) );
		$this->assertTrue( UPIDW_UPI::is_valid_reference( 'T2511201234ABCD', 'alnum' ) );
		$this->assertFalse( UPIDW_UPI::is_valid_reference( 'SHORT', 'alnum' ) );
	}

	public function test_amount_formatting() {
		$this->assertSame( '1499.00', UPIDW_UPI::format_amount( '1499' ) );
		$this->assertSame( '1499.50', UPIDW_UPI::format_amount( 1499.5 ) );
		$this->assertSame( '1234567.89', UPIDW_UPI::format_amount( '1234567.894' ) );
		$this->assertSame( '0.10', UPIDW_UPI::format_amount( '0.1' ) );
	}

	public function test_amounts_match_uses_minor_units() {
		$this->assertTrue( UPIDW_UPI::amounts_match( '10.10', 10.1 ) );
		$this->assertTrue( UPIDW_UPI::amounts_match( '0.1', '0.10' ) );
		$this->assertFalse( UPIDW_UPI::amounts_match( '100.00', '100.01' ) );
	}

	public function test_txn_ref_is_alphanumeric_and_bounded() {
		$ref = UPIDW_UPI::make_txn_ref( 1234, 'ab-c_12 xyz' );
		$this->assertSame( 'UPI1234ABC12XYZ', $ref );
		$this->assertLessThanOrEqual( 35, strlen( UPIDW_UPI::make_txn_ref( PHP_INT_MAX, str_repeat( 'a', 50 ) ) ) );
	}

	public function test_params_are_built_and_cleaned() {
		$params = UPIDW_UPI::build_params(
			array(
				'vpa'           => 'shop@okaxis',
				'merchant_name' => "My Shop\n& Sons",
				'amount'        => '1499',
				'note'          => 'Order 12',
				'txn_ref'       => 'UPI12-AB',
				'merchant_code' => '5411',
			)
		);
		$this->assertSame( 'shop@okaxis', $params['pa'] );
		$this->assertSame( 'My Shop & Sons', $params['pn'] );
		$this->assertSame( '1499.00', $params['am'] );
		$this->assertSame( 'INR', $params['cu'] );
		$this->assertSame( 'UPI12AB', $params['tr'] );
		$this->assertSame( '5411', $params['mc'] );
	}

	public function test_invalid_merchant_code_and_empty_note_are_omitted() {
		$params = UPIDW_UPI::build_params(
			array(
				'vpa'           => 'shop@okaxis',
				'merchant_name' => 'Shop',
				'amount'        => '1',
				'note'          => '',
				'txn_ref'       => 'X1',
				'merchant_code' => '54x1',
			)
		);
		$this->assertArrayNotHasKey( 'mc', $params );
		$this->assertArrayNotHasKey( 'tn', $params );
	}

	public function test_note_is_truncated_to_80_chars() {
		$params = UPIDW_UPI::build_params(
			array(
				'vpa'           => 'shop@okaxis',
				'merchant_name' => 'Shop',
				'amount'        => '1',
				'note'          => str_repeat( 'é', 200 ),
				'txn_ref'       => 'X1',
			)
		);
		$this->assertSame( 80, mb_strlen( $params['tn'], 'UTF-8' ) );
	}

	public function test_query_encoding_cannot_inject_parameters() {
		$uri = UPIDW_UPI::build_uri(
			array(
				'pa' => 'shop@okaxis',
				'pn' => 'A&pa=evil@bank#x',
				'am' => '10.00',
			)
		);
		$this->assertSame( 'upi://pay?pa=shop%40okaxis&pn=A%26pa%3Devil%40bank%23x&am=10.00', $uri );
		parse_str( substr( $uri, strlen( 'upi://pay?' ) ), $parsed );
		$this->assertSame( 'shop@okaxis', $parsed['pa'] );
		$this->assertSame( 'A&pa=evil@bank#x', $parsed['pn'] );
	}

	public function test_spaces_are_percent_20_not_plus() {
		$this->assertStringContainsString( 'tn=Order%2012', UPIDW_UPI::build_uri( array( 'tn' => 'Order 12' ) ) );
	}

	public function test_app_links_respect_enabled_list() {
		$params = array( 'pa' => 'shop@okaxis', 'am' => '1.00' );
		$links  = UPIDW_UPI::app_links( $params, array( 'generic', 'phonepe', 'bhim' ) );
		$this->assertSame( array( 'generic', 'phonepe', 'bhim' ), array_keys( $links ) );
		$this->assertStringStartsWith( 'upi://pay?pa=', $links['generic']['uri'] );
		$this->assertStringStartsWith( 'phonepe://pay?pa=', $links['phonepe']['uri'] );
		$this->assertTrue( $links['bhim']['android_only'] );
		$this->assertStringContainsString( 'package=in.org.npci.upiapp', $links['bhim']['uri'] );
		$this->assertSame( array(), UPIDW_UPI::app_links( $params, array() ) );
	}
}
