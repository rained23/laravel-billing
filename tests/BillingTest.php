<?php

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as Eloquent;

class BillingTest extends Orchestra\Testbench\TestCase
{

    protected function getPackageProviders()
    {
        return ['Mmanos\Billing\BillingServiceProvider'];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('laravel-billing::default','braintree');
        $app['config']->set('laravel-billing::gateways.braintree',[
          'environment' => 'sandbox',
    			'merchant'    => getenv('BRAINTREE_MERCHANT_ID'),
    			'public'      => getenv('BRAINTREE_PUBLIC_KEY'),
    			'private'     => getenv('BRAINTREE_PRIVATE_KEY'),
        ]);
    }

    public static function setUpBeforeClass()
    {
        if (file_exists(__DIR__.'/../.env')) {
            $dotenv = new Dotenv\Dotenv(__DIR__.'/../');
            $dotenv->load();
        }
    }

    public function setUp()
    {
          parent::setUp();

          Eloquent::unguard();
          $this->faker = Faker\Factory::create();

          $db = new DB;
          $db->addConnection([
              'driver' => 'sqlite',
              'database' => ':memory:',
          ]);
          $db->bootEloquent();
          $db->setAsGlobal();

          $this->schema()->create('users', function ($table) {
              $table->increments('id');
              $table->string('email');
              $table->string('name');
              $table->string('billing_id')->nullable();
        			$table->text('billing_cards')->nullable();
        			$table->text('billing_discounts')->nullable();
              $table->tinyInteger('billing_active')->default(0);
        			$table->string('billing_subscription')->nullable();
        			$table->tinyInteger('billing_free')->default(0);
        			$table->string('billing_plan', 25)->nullable();
        			$table->integer('billing_amount')->default(0);
        			$table->string('billing_interval')->nullable();
        			$table->integer('billing_quantity')->default(0);
        			$table->string('billing_card')->nullable();
        			$table->timestamp('billing_trial_ends_at')->nullable();
        			$table->timestamp('billing_subscription_ends_at')->nullable();
        			$table->text('billing_subscription_discounts')->nullable();
              $table->timestamps();
          });

      }

      public function tearDown()
      {
          $this->schema()->drop('users');
      }


      public function testCustomerCanBeCreated()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => $this->faker->name
        ]);

        $user->billing()->create(array(
                    'email' => $user->email,
                  ));

        $this->assertTrue($user->readyForBilling());

        $user->billing()->delete();

      }

      public function testUserBillingStatus()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => $this->faker->name
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => $this->faker->name
        ]);

        $this->assertTrue(! $user->subscribed());

        $user->subscription('premium-monthly')->withCardToken($this->getTestToken())
          ->create();

        $this->assertTrue($user->subscribed());

        $user->billing()->delete();

      }

      public function testSubscriptionsCanBeCreated()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => $this->faker->name
        ]);

        $user->subscription('premium-monthly')->withCardToken($this->getTestToken())
          ->create();

        $this->assertEquals(1,count($user->creditcards()->get()));
        $this->assertTrue($user->billingIsActive());
        $this->assertEquals(1,count($user->subscriptions()->get()));

        $user->billing()->delete();

      }


      public function testUserSubscriptionCanBeCanceled()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => $this->faker->name
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => $this->faker->name
        ]);

        $user->subscription('premium-monthly')->withCardToken($this->getTestToken())
          ->create();

        $this->assertTrue($user->subscribed());

        $user->subscription()->cancel();

        $this->assertTrue($user->canceled());
        $this->assertTrue($user->billingIsActive());
        $this->assertNotNull($user->billing_subscription_ends_at);
        $this->assertTrue($user->onGracePeriod());

        $user->billing()->delete();

      }


      public function testSubscriptionOnAddedPaymentMethodEarlier()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => $this->faker->name
        ]);

        $user->billing()->withCardToken($this->getTestToken())->create([
          'email' => $user->email,
          'firstName' => $user->name
        ]);

        $user->subscription('premium-monthly')->create();

        $this->assertTrue($user->billingIsActive());

        $user->billing()->delete();

      }

      public function testSubscriptionCanBeSwapped()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => 'SwapFromMonthlyToYearly'
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => 'SwapFromMonthlyToYearly'
        ]);

        $user->subscription('premium-monthly')->withCardToken($this->getTestToken())->create();

        $this->assertEquals(1, count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-monthly', $user->billing_plan);

        $user->subscription('premium-yearly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-yearly', $user->billing_plan);

        $user->billing()->delete();

      }

      public function testSubscriptionProrateYearlyToMonthly()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => 'SwapYtoM'
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => 'SwapYtoM'
        ]);

        $user->subscription('premium-yearly')->withCardToken($this->getTestToken())->create();

        $this->assertEquals(1, count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-yearly', $user->billing_plan);

        $user->subscription('premium-monthly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-monthly', $user->billing_plan);

        $user->billing()->delete();

      }

      public function testSubscriptionProrateYearlyToMonthlyToYearly()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => 'SwapYtoM'
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => 'SwapYtoMtoY'
        ]);

        $user->subscription('premium-yearly')->withCardToken($this->getTestToken())->create();

        $this->assertEquals(1, count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-yearly', $user->billing_plan);

        $user->subscription('premium-monthly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-monthly', $user->billing_plan);

        $user->subscription('premium-yearly')->swap();

        $this->assertEquals(1, count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-yearly', $user->billing_plan);

        $user->billing()->delete();

      }

      public function testUpgradeSubscriptionOnSameInterval()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => 'FromBasicToPremium'
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => 'FromBasicToPremium'
        ]);

        $user->subscription('basic-monthly')->withCardToken($this->getTestToken())->create();

        $this->assertEquals(1, count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('basic-monthly', $user->billing_plan);

        $user->subscription('premium-monthly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-monthly', $user->billing_plan);

        $user->billing()->delete();
      }

      public function testDowngradeSubscriptionOnSameInterval()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => 'FromPremiumToBasic'
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => 'FromPremiumToBasic'
        ]);

        $user->subscription('premium-monthly')->withCardToken($this->getTestToken())->create();

        $this->assertEquals(1, count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-monthly', $user->billing_plan);

        $user->subscription('basic-monthly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('basic-monthly', $user->billing_plan);

        $user->billing()->delete();

      }

      public function testUpgradeDowngradeSubscriptionOnSameInterval()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => 'FromBtoPtoB'
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => 'FromBtoPtoB'
        ]);

        $user->subscription('basic-monthly')->withCardToken($this->getTestToken())->create();

        $this->assertEquals(1, count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('basic-monthly', $user->billing_plan);

        $user->subscription('premium-monthly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-monthly', $user->billing_plan);

        $user->subscription('basic-monthly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('basic-monthly', $user->billing_plan);

        $user->billing()->delete();

      }

      public function testDowngradeUpgradeSubscriptionOnSameInterval()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => 'DowngradeUpgrade'
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => 'DowngradeUpgrade'
        ]);

        $user->subscription('premium-monthly')->withCardToken($this->getTestToken())->create();

        $this->assertEquals(1, count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-monthly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());

        //Downgrade
        $user->subscription('basic-monthly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('basic-monthly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());

        //Upgrade
        $user->subscription('premium-monthly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-monthly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());
        $user->billing()->delete();

      }

      public function testUpgradeSubscriptionOnDifferentInterval()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => 'UpgradeSubscriptionOnDifferentInterval'
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => 'UpgradeSubscriptionOnDifferentInterval'
        ]);

        $user->subscription('basic-monthly')->withCardToken($this->getTestToken())->create();

        $this->assertEquals(1, count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('basic-monthly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());

        //Upgrade
        $user->subscription('premium-yearly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-yearly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());

        $user->billing()->delete();

      }

      public function testDowngradeSubscriptionOnDifferentInterval()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => 'DowngradeSubscriptionOnDifferentInterval'
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => 'DowngradeSubscriptionOnDifferentInterval'
        ]);

        $user->subscription('premium-yearly')->withCardToken($this->getTestToken())->create();

        $this->assertEquals(1, count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-yearly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());

        //Upgrade
        $user->subscription('basic-monthly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('basic-monthly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());

        $user->billing()->delete();

      }

      public function testUpgradeSubscriptionOnDifferentIntervalSwapOnSameInterval()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => 'testUpgradeSubscriptionOnDifferentIntervalSwapOnSameInterval'
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => 'testUpgradeSubscriptionOnDifferentIntervalSwapOnSameInterval'
        ]);

        $user->subscription('basic-monthly')->withCardToken($this->getTestToken())->create();

        $this->assertEquals(1, count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('basic-monthly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());

        //Upgrade
        $user->subscription('premium-yearly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-yearly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());

        //Downgrade
        $user->subscription('premium-monthly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-monthly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());
        $user->billing()->delete();

      }

      public function testDowngradeSubscriptionOnDifferentIntervalSwapOnSameInterval()
      {
        $user = User::create([
            'email' => $this->faker->email,
            'name' => 'testDowngradeSubscriptionOnDifferentIntervalSwapOnSameInterval'
        ]);

        $user->billing()->create([
          'email' => $user->email,
          'firstName' => 'testDowngradeSubscriptionOnDifferentIntervalSwapOnSameInterval'
        ]);

        $user->subscription('premium-monthly')->withCardToken($this->getTestToken())->create();

        $this->assertEquals(1, count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('premium-monthly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());

        //Downgrade
        $user->subscription('basic-yearly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('basic-yearly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());

        //swap
        $user->subscription('basic-monthly')->swap();

        $this->assertEquals(1,count($user->subscriptions()->get()));
        $this->assertNotNull($user->billing_subscription);
        $this->assertEquals('basic-monthly', $user->billing_plan);
        $this->assertTrue($user->billingIsActive());
        $user->billing()->delete();

      }


      protected function getTestToken()
      {
          return 'fake-valid-nonce';
      }

      /**
       * Schema Helpers.
       */
      protected function schema()
      {
          return $this->connection()->getSchemaBuilder();
      }

      protected function connection()
      {
          return Eloquent::getConnectionResolver()->connection();
      }

}

class User extends Eloquent
{
    use Mmanos\Billing\CustomerBillableTrait;
    use Mmanos\Billing\SubscriptionBillableTrait;

    protected $cardUpFront = false;

    protected $dates = ['billing_subscription_ends_at'];

}
