<?php namespace Mmanos\Billing\Gateways\Braintree;

use Mmanos\Billing\Gateways\SubscriptionInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Braintree_Customer;
use Braintree_Subscription;
use Braintree_Plan;
use Exception;
use LogicException;
use Carbon\Carbon;

class Subscription implements SubscriptionInterface
{
	/**
	 * The gateway instance.
	 *
	 * @var Gateway
	 */
	protected $gateway;

	/**
	 * Braintree customer object.
	 *
	 * @var Braintree_Customer
	 */
	protected $braintree_customer;

	/**
	 * Primary identifier.
	 *
	 * @var mixed
	 */
	protected $id;

	/**
	 * Braintree subscription object.
	 *
	 * @var Braintree_Subscription
	 */
	protected $braintree_subscription;

	/**
	 * Create a new Braintree subscription instance.
	 *
	 * @param Gateway         $gateway
	 * @param Braintree_Customer $customer
	 * @param mixed           $id
	 *
	 * @return void
	 */
	public function __construct(Gateway $gateway, Braintree_Customer $customer = null, $id = null)
	{
		$this->gateway = $gateway;
		$this->braintree_customer = $customer;

		if ($id instanceof Braintree_Subscription) {
			$this->braintree_subscription = $id;
			$this->id = $this->braintree_subscription->id;
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

	/**
	 * Gets info for a subscription.
	 *
	 * @return array|null
	 */
	public function info()
	{
		if (!$this->id) {
			return null;
		}

		if (!$this->braintree_subscription) {
			$this->braintree_subscription = Braintree_Subscription::find($this->id);
		}

		if (!$this->braintree_subscription) {
			return null;
		}

		$trial_ends_at = null;
		if ($this->braintree_subscription->trialPeriod) {
			$created_at = clone $this->braintree_subscription->createdAt;
			$trial_ends_at = date('Y-m-d H:i:s', $created_at->add(
				date_interval_create_from_date_string(
					$this->braintree_subscription->trialDuration . ' '
					. $this->braintree_subscription->trialDurationUnit . 's'
				)
			)->getTimestamp());
		}

		$period_started_at = date('Y-m-d H:i:s', $this->braintree_subscription->createdAt->getTimestamp());
		if ($this->braintree_subscription->billingPeriodStartDate) {
			$period_started_at = date('Y-m-d H:i:s', $this->braintree_subscription->billingPeriodStartDate->getTimestamp());
		}
		$period_ends_at = $trial_ends_at;
		if ($this->braintree_subscription->billingPeriodEndDate) {
			$period_ends_at = date('Y-m-d H:i:s', $this->braintree_subscription->billingPeriodEndDate->getTimestamp());
		}

		$interval = 1;
		foreach (Braintree_Plan::all() as $plan) {
			if ($plan->id == $this->braintree_subscription->planId) {
				$interval = $plan->billingFrequency;
				break;
			}
		}

		$discounts = array();
		foreach ($this->braintree_subscription->discounts as $discount) {
			$started_at = date('Y-m-d H:i:s', $this->braintree_subscription->firstBillingDate->getTimestamp());
			$ends_at = null;
			if (!$discount->neverExpires) {
				$cycle = $interval * 60 * 60 * 24 * 30;
				$ends_at = date('Y-m-d H:i:s', strtotime($started_at) + ($cycle * $discount->numberOfBillingCycles));
			}

			$discounts[] = array(
				'coupon'      => $discount->id,
				'amount_off'  => ((float) $discount->amount * 100),
				'percent_off' => null,
				'started_at'  => $started_at,
				'ends_at'     => $ends_at,
			);
		}

		return array(
			'id'                => $this->id,
			'plan'              => $this->braintree_subscription->planId,
			'amount'            => ((float) $this->braintree_subscription->price * 100),
			'interval'          => ($interval == 1) ? 'monthly' : (($interval == 3) ? 'quarterly' : 'yearly'),
			'active'            => ('Canceled' != $this->braintree_subscription->status),
			'quantity'          => 1,
			'started_at'        => date('Y-m-d H:i:s', $this->braintree_subscription->createdAt->getTimestamp()),
			'period_started_at' => $period_started_at,
			'period_ends_at'    => $period_ends_at,
			'trial_ends_at'     => $trial_ends_at,
			'card'              => $this->braintree_subscription->paymentMethodToken,
			'discounts'         => $discounts,
		);
	}

	/**
	 * Create a new subscription.
	 *
	 * @param mixed $plan
	 * @param array $properties
	 *
	 * @return Subscription
	 */
	public function create($plan, array $properties = array(),array $options = [])
	{

		// We need to prepare the token of payment method as required by Braintree to create a subscription
		// If no card token present we will use the default one

		$token = null;

		if (!$token = Arr::get($properties, 'card')) {

			//If no card specified, its better to check the card status first before wasting time using it.
			$cards = $this->braintree_customer->creditCards;

			foreach($cards as $card):
				if(! $card->expired)
					$token = $card->token;
			endforeach;

			if(!$token)
				throw new Exception('No available payment method to subscribe');

		}

		$props = array(
			'paymentMethodToken' => $token,
			'planId'             => $plan,
		);

		if (!empty($properties['coupon'])) {
			$props['discounts']['add'][] = array('inheritedFromId' => $properties['coupon']);
		}

		if (!empty($properties['trial_ends_at'])) {
			$now = time();
			$tends = strtotime($properties['trial_ends_at']);
			if ($tends < $now) {
				$props['trialDuration'] = 0;
				$props['trialDurationUnit'] = 'day';
			}
			else if ($tends - $now < (60*60*24*30)) {
				$props['trialDuration'] = round(($tends - $now) / (60*60*24));
				$props['trialDurationUnit'] = 'day';
			}
			else {
				$props['trialDuration'] = round(($tends - $now) / (60*60*24*30));
				$props['trialDurationUnit'] = 'month';
			}
		}


		if(!empty($properties['firstBillingDate']))
		{
			$props['firstBillingDate'] = $properties['firstBillingDate'];
		}

		$props = array_merge($props, $options);

		$response = Braintree_Subscription::create($props);

		if (! $response->success) {
        throw new Exception('Braintree failed to create subscription: '.$response->message);
    }

		$this->braintree_subscription = $response->subscription;

		$this->id = $this->braintree_subscription->id;

		return $this;
	}

	/**
	 * Update a subscription.
	 *
	 * @param array $properties
	 *
	 * @return Subscription
	 */
	public function update(array $properties = array())
	{
			$info = $this->info();

			$plan = $this->findPlan(Arr::get($properties,'plan'));

			if ($this->wouldChangeBillingFrequency($plan)) {
					return $this->swapAcrossFrequencies($plan);
			}

			$response = Braintree_Subscription::update($this->braintree_subscription->id, [
					 'planId' => $plan->id,
					 'price' => $plan->price * (1 + (0 / 100)),
					 'neverExpires' => true,
					 'numberOfBillingCycles' => null,
					 'options' => [
							 'prorateCharges' => true,
					 ],
			 ]);

			 if (! $response->success) {
					 throw new Exception('Braintree failed to swap plans: '.$response->message);
			 }

			 $this->braintree_subscription = $response->subscription;

			 $this->id = $this->braintree_subscription->id;

		return $this;
	}

	protected function findPlan($id)
	{
		$plans = Braintree_Plan::all();
		foreach ($plans as $plan) {
					 if ($plan->id === $id) {
							return $plan;
					 }
		}
	}

	/**
	* Determine if the given plan would alter the billing frequency.
	*
	* @param  string  $plan
	* @return bool
	*/
	protected function wouldChangeBillingFrequency($plan)
	{
		return $plan->billingFrequency !==
			$this->findPlan($this->braintree_subscription->planId)->billingFrequency;
	}

	/**
	* Swap the subscription to a new Braintree plan with a different frequency.
	*
	* @param  string  $plan
	* @return $this
	*/
		protected function swapAcrossFrequencies($plan)
		{
				$currentPlan = $this->findPlan($this->braintree_subscription->planId);
				// $discount = $this->switchingToMonthlyPlan($currentPlan, $plan)
				// 												? $this->getDiscountForSwitchToMonthly($currentPlan, $plan)
				// 												: $this->getDiscountForSwitchToYearly();

				$discount = $this->getProrate($currentPlan,$plan);

				//We might need to carry forward any balance from previous subscription
				$balance = 0;
				if($this->braintree_subscription->status === 'Active')
				{
					if( (float) $this->braintree_subscription->balance < 0)
						$balance = abs($this->braintree_subscription->balance);

				}

				$options = [];
				if ($discount->amount > 0 || $balance > 0) {
						$options = ['discounts' => ['add' => [
								[
										'inheritedFromId' => 'plan-credit',
										'amount' => (float) number_format($discount->amount+$balance,2),
										'numberOfBillingCycles' => 1,
								],
						]]];
				}

				Braintree_Subscription::cancel($this->braintree_subscription->id);

				return $this->create($plan->id,[],$options);
		}

		/**
     * Determine if the user is switching form yearly to monthly billing.
     *
     * @param  BraintreePlan  $currentPlan
     * @param  BraintreePlan  $plan
     * @return bool
     */
    protected function switchingToMonthlyPlan($currentPlan, $plan)
    {
        return $currentPlan->billingFrequency == 12 && $plan->billingFrequency == 1;
    }

    /**
     * Get the discount to apply when switching to a monthly plan.
     *
     * @param  BraintreePlan  $currentPlan
     * @param  BraintreePlan  $plan
     * @return object
     */
    protected function getProrate($currentPlan, $plan)
    {
        // return (object) [
        //     'amount' => $plan->price,
        //     'numberOfBillingCycles' => floor(
        //         $this->moneyRemainingOnYearlyPlan($currentPlan) / $plan->price
        //     ),
        // ];
				$discount = $this->moneyRemaining($currentPlan);
				return (object) [
						'amount' => $discount,
						'numberOfBillingCycles' => ($discount) ? 1 : 0
				];
    }
    /**
     * Calculate the amount of discount to apply to a swap to monthly billing.
     *
     * @param  BraintreePlan  $plan
     * @return float
     */
    protected function moneyRemaining($plan)
    {
				$estimated = ($plan->price / ($plan->billingFrequency * 30)) * Carbon::today()->diffInDays(Carbon::instance(
            $this->braintree_subscription->billingPeriodEndDate), false);
        return ($estimated > $plan->price) ? $plan->price : $estimated;
    }
    /**
     * Get the discount to apply when switching to a yearly plan.
     *
     * @return object
     */
    protected function getDiscountForSwitchToYearly()
    {
        $amount = 0;
        foreach ($this->braintree_subscription->discounts as $discount) {
            if ($discount->id == 'plan-credit') {
                $amount += (float) $discount->amount * $discount->numberOfBillingCycles;
            }
        }
        return (object) [
            'amount' => $amount,
            'numberOfBillingCycles' => 1,
        ];
    }

	/**
	 * Cancel a subscription.
	 *
	 * @param bool $at_period_end
	 *
	 * @return Subscription
	 */
	public function cancel($at_period_end = true)
	{
		// Braintree_Subscription::cancel($this->id);
		// $this->braintree_subscription = null;

		// Soft cancel the subscription
		Braintree_Subscription::update($this->id, [
							 'numberOfBillingCycles' => $this->braintree_subscription->currentBillingCycle,
					 ]);

		return $this;

	}

	public function resume()
	{

			Braintree_Subscription::update($this->id, [
					'neverExpires' => true,
					'numberOfBillingCycles' => null,
			]);

			return $this;

	}

	/**
	 * Gets the native subscription response.
	 *
	 * @return Braintree_Customer
	 */
	public function getNativeResponse()
	{
		$this->info();
		return $this->braintree_subscription;
	}
}
