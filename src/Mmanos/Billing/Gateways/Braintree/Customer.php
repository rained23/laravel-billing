<?php namespace Mmanos\Billing\Gateways\Braintree;

use Mmanos\Billing\Gateways\CustomerInterface;
use Illuminate\Support\Arr;
use Braintree_Customer;
use Braintree_Transaction;
use Braintree_TransactionSearch;
use Braintree_ClientToken;
use Exception;
class Customer implements CustomerInterface
{
	/**
	 * The gateway instance.
	 *
	 * @var Gateway
	 */
	protected $gateway;
	
	/**
	 * Primary identifier.
	 *
	 * @var mixed
	 */
	protected $id;
	
	/**
	 * Braintree customer object.
	 *
	 * @var Braintree_Customer
	 */
	protected $braintree_customer;
	
	/**
	 * Create a new Braintree customer instance.
	 *
	 * @param Gateway $gateway
	 * @param mixed   $id
	 * 
	 * @return void
	 */
	public function __construct(Gateway $gateway, $id = null)
	{
		$this->gateway = $gateway;
		
		if ($id instanceof Braintree_Customer) {
			$this->braintree_customer = $id;
			$this->id = $this->braintree_customer->id;
		}
		else if (null !== $id) {
			$this->id = $id;
		}
	}
	
	/**
	 * Gets the id of this instance.
	 * 
	 * @return mixed
	 */
	public function id()
	{
		return $this->id;
	}

	public function clientToken($default = false)
	{
		if($default)
		{
			return Braintree_ClientToken::generate(['customerId'=>$this->id]);
		}

		return Braintree_ClientToken::generate();

	}
	
	/**
	 * Gets info for a customer.
	 * 
	 * @return array|null
	 */
	public function info()
	{
		if (!$this->id) {
			return null;
		}
		
		if (!$this->braintree_customer) {
			$this->braintree_customer = Braintree_Customer::find($this->id);
		}
		
		if (!$this->braintree_customer) {
			return null;
		}
		
		return array(
			'id'          => $this->id,
			'description' => null,
			'email'       => $this->braintree_customer->email,
			'created_at'  => date('Y-m-d H:i:s', $this->braintree_customer->createdAt->getTimestamp()),
			'discounts'   => array(), // Customer-specific discounts not supported.
		);
	}
	
	/**
	 * Create a new customer.
	 *
	 * @param array $properties
	 * 
	 * @return Customer
	 */
	public function create(array $properties = array())
	{

		if(Arr::get($properties,'card_token')):		
			$properties = array_merge($properties,['paymentMethodNonce'=>Arr::get($properties,'card_token')]);
			Arr::forget($properties,'card_token');
		endif;
		
		$result = Braintree_Customer::create( array_filter($properties) );

		//Check if success or not before continue !! throw exception
		if($result->success)
		{
			$this->braintree_customer = $result->customer;
		}
		else
		{
			$verification = $result->creditCardVerification;
			$error = "";

			if($verification->status == 'processor_declined')
			{
				$error = $verification->processorResponseText;
			}

			if($verification->status == 'gateway_rejected')
			{
				$error = $verification->gatewayRejectionReason;
			}

			// return Response::json($result, 422);
			throw new Exception($error);

		}

		$this->id = $this->braintree_customer->id;
		
		return $this;
	}
	
	/**
	 * Update a customer.
	 *
	 * @param array $properties
	 * 
	 * @return Customer
	 */
	public function update(array $properties = array())
	{
		$props = array();
		if (!empty($properties['email'])) {
			$props['email'] = $properties['email'];
		}
		
		Braintree_Customer::update($this->id, $props);
		$this->braintree_customer = null;
		
		return $this;
	}
	
	/**
	 * Delete a customer.
	 * 
	 * @return Customer
	 */
	public function delete()
	{
		Braintree_Customer::delete($this->id);
		$this->braintree_customer = null;
		return $this;
	}
	
	/**
	 * Gets all subscriptions for a customer.
	 *
	 * @return array
	 */
	public function subscriptions()
	{
		$this->info();
		
		if (!$this->braintree_customer) {
			return array();
		}
		
		$subscriptions_array = array();
		foreach ($this->braintree_customer->creditCards as $card) {
			foreach ($card->subscriptions as $subscription) {
				$subscriptions_array[] = $this->gateway->subscription($subscription, $this);
			}
		}
		
		return $subscriptions_array;
	}
	
	/**
	 * Gets all credit cards for a customer.
	 *
	 * @return array
	 */
	public function cards()
	{
		$this->info();
		
		if (!$this->braintree_customer) {
			return array();
		}
		
		$cards = $this->braintree_customer->creditCards;
		
		$cards_array = array();
		foreach ($cards as $card) {
			$cards_array[] = $this->card($card);
		}
		
		return $cards_array;
	}
	
	/**
	 * Fetch a customer card instance.
	 *
	 * @param mixed $id
	 * 
	 * @return Card
	 */
	public function card($id = null)
	{
		return new Card($this->gateway, $this->getNativeResponse(),$id);
	}
	
	/**
	 * Gets all invoices for a customer.
	 *
	 * @return array
	 */
	public function invoices()
	{
		if (!$this->id) {
			return array();
		}
		
		$invoices = Braintree_Transaction::search(array(
			Braintree_TransactionSearch::customerId()->is($this->id),
		));
		
		$invoices_array = array();
		foreach ($invoices as $invoice) {
			if (empty($invoice->subscriptionId)) {
				continue;
			}
			
			$invoices_array[] = $this->invoice($invoice);
		}
		
		return $invoices_array;
	}
	
	/**
	 * Fetch an invoice instance.
	 *
	 * @param mixed $id
	 * 
	 * @return Invoice
	 */
	public function invoice($id = null)
	{
		return new Invoice($this->gateway, $this->getNativeResponse(), $id);
	}
	
	/**
	 * Gets all charges for a customer.
	 *
	 * @return array
	 */
	public function charges()
	{
		if (!$this->id) {
			return array();
		}
		
		$charges = Braintree_Transaction::search(array(
			Braintree_TransactionSearch::customerId()->is($this->id),
		));
		
		$charges_array = array();
		foreach ($charges as $charge) {
			$charges_array[] = $this->charge($charge);
		}
		
		return $charges_array;
	}
	
	/**
	 * Fetch an charge instance.
	 *
	 * @param mixed $id
	 * 
	 * @return Charge
	 */
	public function charge($id = null)
	{
		return new Charge($this->gateway, $this->getNativeResponse(), $id);
	}
	
	/**
	 * Gets the native customer response.
	 *
	 * @return Braintree_Customer
	 */
	public function getNativeResponse()
	{
		$this->info();
		return $this->braintree_customer;
	}
}
