<?php

namespace Eugenefvdm\Billing\Http\Controllers;

use Exception;
use Eugenefvdm\Billing\Cashier;
use Eugenefvdm\Billing\Events\PaymentSucceeded;
use Eugenefvdm\Billing\Events\SubscriptionCancelled;
use Eugenefvdm\Billing\Events\SubscriptionCreated;
use Eugenefvdm\Billing\Events\SubscriptionPaymentSucceeded;
use Eugenefvdm\Billing\Events\WebhookHandled;
use Eugenefvdm\Billing\Events\WebhookReceived;
use Eugenefvdm\Billing\Exceptions\InvalidMorphModelInPayload;
use Eugenefvdm\Billing\Exceptions\MissingSubscription;
use Eugenefvdm\Billing\Facades\Payfast;
use Eugenefvdm\Billing\Payment;
use Eugenefvdm\Billing\Receipt;
use Eugenefvdm\Billing\Subscription;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    /**
     * Handle a Payfast webhook call and determine what to do with it.
     *
     * @param Request $request
     * @return Response
     */
    public function __invoke(Request $request)
    {
        $message = 'Incoming Webhook from Payfast';
        Log::info($message);
        ray($message)->blue();

        $payload = $request->all();

        Log::debug($payload);
        ray($payload)->green();

        if (isset($payload['ping'])) {
            return new Response();
        }

        WebhookReceived::dispatch($payload);

        config('billing.payfast.debug') ?? Log::debug("Checking what kind of webhook received...");

        try {
            // Non subscription payment handling
            if (! isset($payload['token'])) {
                ray("Non subscription payment received");
                $this->nonSubscriptionPaymentReceived($payload);

                WebhookHandled::dispatch([
                    'action' => 'ad_hoc_payment_received',
                    'payload' => $payload,
                ]);

                return new Response('Webhook ad-hoc payment received (nonSubscriptionPaymentReceived) handled');
            }

            // New token received, so let's create a subscription
            if (! $this->findSubscription($payload['token'])) {
                config('billing.payfast.debug') ?? Log::debug("New token received, so let's create a subscription");

                $this->createSubscription($payload);

                WebhookHandled::dispatch([
                    'action' => 'subscription_created_payment_applied',
                    'payload' => $payload,
                ]);

                return new Response('Webhook createSubscription/applySubscriptionPayment handled');
            }

            if ($payload['payment_status'] == Subscription::Deleted) {
                ray("Subscrition cancellation received");
                $this->cancelSubscription($payload);

                WebhookHandled::dispatch([
                    'action' => 'subscription_cancelled',
                    'payload' => $payload,
                ]);

                return new Response('Webhook cancelSubscription handled');
            }

            if ($payload['payment_status'] == Payment::COMPLETE) {
                ray("Payment received");

                $this->applySubscriptionPayment($payload);

                WebhookHandled::dispatch($payload);

                return new Response('Webhook applySubscriptionPayment handled');
            }
        } catch (Exception $e) {
            $message = $e->getMessage();

            Log::critical($message);

            ray($e)->red();

            return response('An exception occurred in the Payfast webhook controller', 500);
        }

        Log::error("Abnormal Webhook termination. No Webhook interpreter was found.");
    }

    /**
     * Handle one-time payment succeeded.
     *
     * @param  array  $payload
     * @return void
     */
    protected function nonSubscriptionPaymentReceived(array $payload)
    {
        $message = "Creating a non-subscription payment receipt...";

        Log::info($message);

        ray($message)->orange();

        $receipt = Receipt::create([
            'merchant_payment_id' => $payload['m_payment_id'],
            'payfast_payment_id' => $payload['pf_payment_id'],
            'payment_status' => $payload['payment_status'],
            'item_name' => $payload['item_name'],
            'item_description' => $payload['item_description'],
            'amount_gross' => $payload['amount_gross'],
            'amount_fee' => $payload['amount_fee'],
            'amount_net' => $payload['amount_net'],
            'billable_id' => $payload['custom_int1'],
            'billable_type' => $payload['custom_str1'],
            'received_at' => now(),
        ]);

        PaymentSucceeded::dispatch($receipt, $payload);

        $message = "Created the non-subscription payment receipt.";

        Log::notice($message);

        ray($message)->green();
    }

    protected function createSubscription(array $payload)
    {
        config('billing.payfast.debug') ?? Log::debug("createSubscription() with payload in webhook");

        $customer = $this->findOrCreateCustomer($payload);

        $subscription = $customer->subscriptions()->create([
            'name' => 'default',
            'provider_id' => $payload['token'],
            'type' => $payload['custom_str2'], // Full plan identifier (e.g., '0|monthly')            
            'status' => $payload['payment_status'],
            'next_bill_at' => $payload['billing_date'] ?? null, // This happens when subscription was never created but then cancelled
        ]);

        SubscriptionCreated::dispatch($customer, $subscription, $payload);

        config('billing.payfast.debug') ?? Log::debug("Subscription created/reactivated for $customer->email and now applying payment...");

        $this->applySubscriptionPayment($payload);

        config('billing.payfast.debug') ?? Log::debug("Subscription payment applied or subscription reactivated for $customer->email");
    }

    /**
     * Apply a subscription payment.
     *
     * Gets triggered after first payment, and every subsequent payment that has a token. If the
     * payload item_name is empty we're working with an existing subscription that has been
     * reactivated. Check status of subscription post payment to update next_bill_at.
     *
     * @param array $payload
     * @return \Illuminate\Http\Response
     * @throws Exception
     */
    protected function applySubscriptionPayment(array $payload)
    {
        $billable = $this->findSubscription($payload['token'])->billable;

        if (is_null($payload['item_name'])) {
            $payload['item_name'] = $this->getSubscriptionName($payload);

            $message = "Reactivating subscription for $billable->email";
        } else {
            $message = "Applying a subscription payment to " . $payload['token'] . "...";
        }

        Log::debug($message . ' applySubscriptionPayment()');

        if (! isset($payload['amount_gross'])) {
            throw new Exception("Unable to apply a payment to an existing subscription because amount_gross is not set. Probably cause the subscription was deleted.");
        }

        // Create a receipt
        $receipt = $billable->receipts()->create([
            'provider_id' => $payload['token'],
            'order_id' => $payload['m_payment_id'],
            'merchant_payment_id' => $payload['m_payment_id'],
            'payfast_payment_id' => $payload['pf_payment_id'],
            'payment_status' => $payload['payment_status'],
            'item_name' => $payload['item_name'],
            'item_description' => $payload['item_description'] ?? null,
            'amount_gross' => $payload['amount_gross'],
            'amount_fee' => $payload['amount_fee'],
            'amount_net' => $payload['amount_net'],
            'billable_id' => $payload['custom_int1'],
            'billable_type' => $payload['custom_str1'],
            'billing_date' => $payload['billing_date'],
            'received_at' => now(),
        ]);

        // Obtain fresh subscription information from Payfast which includes "run_date"

        // First get first subscription attached to this token
        $subscription = Subscription::where('provider_id', $payload['token'])->first();

        // Next get the current subscription data from Payfast
        $result = Payfast::fetchSubscription($payload['token']);

        // Update the subscription with the fresh data
        $subscription->updatePayfastSubscription($result);

        // Raise an event
        SubscriptionPaymentSucceeded::dispatch($billable, $receipt, $payload);

        // Payfast requires a 200 response after a successful payment application
        return response("Subscription payment applied or subscription reactivated for $billable->email", 200);
    }

    /**
     * Handle subscription cancelled.
     *
     * @param  array  $payload
     * @return void
     */
    protected function cancelSubscription(array $payload)
    {
        ray("Cancelling subscription " . $payload['token'] . "...")->orange();

        if (! $subscription = $this->findSubscription($payload['token'])) {
            throw new MissingSubscription();
        }

        if (is_null($subscription->ends_at)) {
            $subscription->ends_at = $subscription->onTrial()
                ? $subscription->trial_ends_at
                : $subscription->next_bill_at->subMinutes(1);
        }

        ray("The subscription will end at " . $subscription->ends_at->format('Y-m-d'));

        $subscription->cancelled_at = now();
        $subscription->status = $payload['payment_status'];
        $subscription->paused_at = null;
        $subscription->save();

        SubscriptionCancelled::dispatch($subscription, $payload);
    }

    private function findSubscription(string $subscriptionId)
    {
        return Cashier::$subscriptionModel::firstWhere('provider_id', $subscriptionId);
    }

    /**
     * Get the subscription name from the payload's plan identifier.
     * Parses custom_str2 (e.g., '0|monthly') to reconstruct the same
     * item_name format used during initial subscription creation.
     * This is only invoked during webhooks when item_name is null.
     */
    private function getSubscriptionName($payload)
    {
        // custom_str2 contains the full plan identifier (e.g., '0|monthly')
        // We need to parse it to get the plan details
        list($planId, $frequency) = explode('|', $payload['custom_str2']);
        
        $plan = config('billing.billables.user.plans')[$planId];
        
        $recurringType = match($frequency) {
            'monthly' => 'Monthly',
            'yearly' => 'Yearly',
            default => ucfirst($frequency)
        };
        
        // Return the same format as used in initial subscription creation
        return $plan['name'] . " $recurringType";
    }

    /**
     * Based on custom_str1 (e.g. App\Models\User) and custom_int1 which is the
     * model ID go and find the billable model and either create a new one
     * if it doesn't exist otherwise just retrieve the existing one.
     */
    private function findOrCreateCustomer(array $passthrough)
    {
        config('billing.payfast.debug') ?? Log::debug("findOrCreateCustomer in webhook");

        if (! isset($passthrough['custom_str1'], $passthrough['custom_int1'])) {
            throw new InvalidMorphModelInPayload($passthrough['custom_str1'] . "|" . $passthrough['custom_int1']);
        }

        $customer = Cashier::$customerModel::firstOrCreate([
            'billable_id' => $passthrough['custom_int1'],
            'billable_type' => $passthrough['custom_str1'],
        ], [
            'name' => $passthrough['name_first'], // TODO due to a bug in sending we get name_first and name_last as the full name
            'email' => $passthrough['email_address'],
        ])->billable;

        config('billing.payfast.debug') ?? Log::debug("Found this customer", $customer);

        return $customer;
    }
}
