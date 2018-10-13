<?php

namespace mycryptocheckout\api\v2\wordpress;

/**
	@brief		Payment handling.
	@since		2017-12-21 23:28:34
**/
class Payments
	extends \mycryptocheckout\api\v2\Payments
{
	/**
		@brief		Add our data to the javascript checkout data.
		@details	This is a convenience function, shared by the e-commerce integrations, to add payment info into the javascript checkout data object.
		@since		2018-09-05 23:22:47
	**/
	public static function add_to_checkout_javascript_data( $action, $payment )
	{
		$action->data->set( 'amount', $payment->amount );
		$action->data->set( 'created_at', $payment->created_at );
		$action->data->set( 'currency_id', $payment->currency_id );

		$currencies = MyCryptoCheckout()->currencies();
		$currency = $currencies->get( $payment->currency_id );
		$action->data->set( 'currency', $currency );
		$action->data->set( 'supports', $currency->supports );

		if ( isset( $payment->paid ) )
			$action->data->set( 'paid', $payment->paid );

		$action->data->set( 'timeout_hours', $payment->timeout_hours );
		$action->data->set( 'to', $payment->to );
	}

	/**
		@brief		Cancel a local payment.
		@since		2018-10-13 11:53:18
	**/
	public function cancel_local( \mycryptocheckout\api\v2\Payment $payment )
	{
		$this->do_local( 'cancel_payment', $payment );
	}

	/**
		@brief		Complete a local payment.
		@since		2018-10-13 11:53:39
	**/
	public function complete_local( \mycryptocheckout\api\v2\Payment $payment )
	{
		$this->do_local( 'complete_payment', $payment );
	}

	/**
		@brief		Convenience method to create a new payment.
		@since		2018-09-20 20:58:53
	**/
	public static function create_new( $data = null )
	{
		$payment = parent::create_new( $data );

		// If we are on a network, then note down the site data.
		if ( MULTISITE )
		{
			$payment->data()->set( 'site_id', get_current_blog_id() );
			$payment->data()->set( 'site_url', get_option( 'siteurl' ) );
		}

		return $payment;
	}

	/**
		@brief		Do this local payment action.
		@since		2018-10-13 11:57:50
	**/
	public function do_local( $message_type, \mycryptocheckout\api\v2\Payment $payment )
	{
		$action = MyCryptoCheckout()->new_action( $message_type );
		$action->payment = $payment;
		$action->execute();
		if ( $action->applied < 1 )
			throw new Exception( sprintf( 'Unable to apply %s for payment ID %s.', $message_type, json_encode( $payment ) ) );
		$this->api()->debug( '%s action applied %s times.', $message_type, $action->applied );
	}

	/**
		@brief		Generate a Payment class from an order.
		@since		2017-12-21 23:47:17
	**/
	public static function generate_payment_from_order( $post_id )
	{
		$payment = static::create_new();

		$payment->amount = get_post_meta( $post_id,  '_mcc_amount', true );
		$payment->confirmations = get_post_meta( $post_id,  '_mcc_confirmations', true );
		$payment->created_at = get_post_meta( $post_id,  '_mcc_created_at', true );
		$payment->currency_id = get_post_meta( $post_id,  '_mcc_currency_id', true );
		$payment->timeout_hours = get_post_meta( $post_id,  '_mcc_payment_timeout_hours', true );
		$payment->to = get_post_meta( $post_id,  '_mcc_to', true );

		$payment->data = get_post_meta( $post_id,  '_mcc_payment_data', true );

		// If we are on a network, then note down the site data.
		if ( MULTISITE )
		{
			$payment->data()->set( 'site_id', get_current_blog_id() );
			$payment->data()->set( 'site_url', get_option( 'siteurl' ) );
		}

		return $payment;
	}

	/**
		@brief		Replace the shortcodes in this string with payment data.
		@since		2018-10-13 13:04:53
	**/
	public static function replace_shortcodes( $payment, $string )
	{
		$string = str_replace( '[AMOUNT]', $payment->amount, $string );
		$string = str_replace( '[CURRENCY]', $payment->currency_id, $string );
		$string = str_replace( '[TO]', $payment->to, $string );
		return $string;
	}

	/**
		@brief		Save this payment for this post.
		@since		2018-10-13 12:42:48
	**/
	public static function save( $post_id, $payment )
	{
		update_post_meta( $post_id, '_mcc_amount', $payment->amount );
		update_post_meta( $post_id, '_mcc_confirmations', $payment->confirmations );
		update_post_meta( $post_id, '_mcc_created_at', $payment->created_at );
		update_post_meta( $post_id, '_mcc_currency_id', $payment->currency_id );
		update_post_meta( $post_id, '_mcc_payment_timeout_hours', $payment->timeout_hours );
		update_post_meta( $post_id, '_mcc_to', $payment->to );
		update_post_meta( $post_id, '_mcc_payment_data', $payment->data );
	}

	/**
		@brief		Send a payment for a post ID.
		@since		2018-01-02 19:16:06
	**/
	public function send( $post_id )
	{
		$attempts = intval( get_post_meta( $post_id, '_mcc_attempts', true ) );

		MyCryptoCheckout()->api()->account()->lock()->save();

		try
		{
			$payment = static::generate_payment_from_order( $post_id );
			$payment_id = $this->add( $payment );
			update_post_meta( $post_id, '_mcc_payment_id', $payment_id );
			$this->api()->debug( 'Payment for order %d has been added as payment #%d.', $post_id, $payment_id );
		}
		catch ( Exception $e )
		{
			$attempts++;
			update_post_meta( $post_id, '_mcc_attempts', $attempts );
			$this->api()->debug( 'Failure #%d trying to send the payment for order %d. %s', $attempts, $post_id, $e->getMessage() );
			if ( $attempts > 48 )	// 48 hours, since this is usually run on the hourly cron.
			{
				// TODO: Give up and inform the admin of the failure.
				$this->api()->debug( 'We have now given up on trying to send the payment for order %d.', $post_id );
				update_post_meta( $post_id,  '_mcc_payment_id', -1 );
			}
		}
	}

	/**
		@brief		Send unsent payments.
		@since		2017-12-24 12:11:03
	**/
	public function send_unsent_payments()
	{
		// Find all posts in the database that do not have a payment ID.
		global $wpdb;
		$query = sprintf( "SELECT `post_id` FROM `%s` WHERE `meta_key` = '_mcc_payment_id' AND `meta_value` = '0'",
			$wpdb->postmeta
		);
		$results = $wpdb->get_col( $query );
		if ( count( $results ) < 1 )
			return;
		$this->api()->debug( 'Unsent payments: %s', implode( ', ', $results ) );
		foreach( $results as $post_id )
			$this->send( $post_id );
	}
}