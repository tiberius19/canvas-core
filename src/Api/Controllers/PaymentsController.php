<?php

declare(strict_types=1);

namespace Canvas\Api\Controllers;

use Baka\Http\Exception\NotFoundException;
use Canvas\Contracts\Cashier\StripeWebhookHandlersTrait;
use Canvas\Models\CompaniesGroups;
use Canvas\Models\CompaniesSettings;
use Canvas\Models\Subscription;
use Canvas\Models\Users;
use Canvas\Template;
use Phalcon\Di;
use Phalcon\Http\Response;

class PaymentsController extends BaseController
{
    /**
     * Stripe Webhook Handlers.
     */
    use StripeWebhookHandlersTrait;

    /**
     * Handle stripe webhook calls.
     *
     * @return Response
     */
    public function handleWebhook() : Response
    {
        //we cant process's if we don't find the stripe header
        if (!$this->request->hasHeader('Stripe-Signature')) {
            throw new NotFoundException('Route not found for this call');
        }

        $request = $this->request->getPostData();
        $type = str_replace('.', '', ucwords(str_replace('_', '', $request['type']), '.'));
        $method = 'handle' . $type;
        $payloadContent = json_encode($request);
        $this->log->info("Webhook Handler Method: {$method} \n");
        $this->log->info("Payload: {$payloadContent} \n");

        if (method_exists($this, $method)) {
            return $this->{$method}($request, $method);
        } else {
            return $this->response(['Missing Method to Handled']);
        }
    }

    /**
     * Handle customer subscription updated.
     *
     * @param  array $payload
     *
     * @return Response
     */
    protected function handleCustomerSubscriptionUpdated(array $payload, string $method) : Response
    {
        $companyGroup = CompaniesGroups::findFirstByStripeId($payload['data']['object']['customer']);
        if ($companyGroup) {
            //We need to send a mail to the user
            $this->sendWebhookResponseEmail($companyGroup, $payload, $method);
        }
        return $this->response(['Webhook Handled']);
    }

    /**
     * Handle customer subscription cancellation.
     *
     * @param  array $payload
     *
     * @return Response
     */
    protected function handleCustomerSubscriptionDeleted(array $payload, string $method) : Response
    {
        $companyGroup = CompaniesGroups::findFirstByStripeId($payload['data']['object']['customer']);
        if ($companyGroup) {
            //Update current subscription's paid column to false and store date of payment
            $this->updateSubscriptionPaymentStatus($companyGroup, $payload);
            $this->sendWebhookResponseEmail($companyGroup, $payload, $method);
        }
        return $this->response(['Webhook Handled']);
    }

    /**
     * Handle customer subscription free trial ending.
     *
     * @param  array $payload
     *
     * @return Response
     */
    protected function handleCustomerSubscriptionTrialwillend(array $payload, string $method) : Response
    {
        $companyGroup = CompaniesGroups::findFirstByStripeId($payload['data']['object']['customer']);
        if ($companyGroup) {
            //We need to send a mail to the user
            $this->sendWebhookResponseEmail($companyGroup, $payload, $method);
            $this->log->info("Email was sent to: {$companyGroup->users->email}\n");
        }
        return $this->response(['Webhook Handled']);
    }

    /**
     * Handle successfully payment.
     *
     * @param array $payload
     *
     * @return Response
     */
    protected function handleChargeSucceeded(array $payload, string $method) : Response
    {
        $companyGroup = CompaniesGroups::findFirstByStripeId($payload['data']['object']['customer']);
        if ($companyGroup) {
            //Update current subscription's paid column to true and store date of payment
            $this->updateSubscriptionPaymentStatus($companyGroup, $payload);
            $this->sendWebhookResponseEmail($companyGroup, $payload, $method);
        }
        return $this->response(['Webhook Handled']);
    }

    /**
     * Handle bad payment.
     *
     * @param array $payload
     *
     * @return Response
     */
    protected function handleChargeFailed(array $payload, string $method) : Response
    {
        $companyGroup = CompaniesGroups::findFirstByStripeId($payload['data']['object']['customer']);
        if ($companyGroup) {
            //We need to send a mail to the user
            $this->updateSubscriptionPaymentStatus($companyGroup, $payload);
            $this->sendWebhookResponseEmail($companyGroup, $payload, $method);
        }
        return $this->response(['Webhook Handled']);
    }

    /**
     * Handle pending payments.
     *
     * @param array $payload
     *
     * @return Response
     */
    protected function handleChargePending(array $payload, string $method) : Response
    {
        $companyGroup = CompaniesGroups::findFirstByStripeId($payload['data']['object']['customer']);
        if ($companyGroup) {
            //We need to send a mail to the user
            $this->sendWebhookResponseEmail($companyGroup, $payload, $method);
        }
        return $this->response(['Webhook Handled']);
    }

    /**
     * Send webhook related emails to user.
     *
     * @param Users $user
     * @param array $payload
     * @param string $method
     *
     * @return void
     */
    protected function sendWebhookResponseEmail(CompaniesGroups $companyGroup, array $payload, string $method) : void
    {
        $templateName = '';
        $title = null;

        switch ($method) {
            case 'handleCustomerSubscriptionTrialwillend':
                $templateName = 'users-trial-end';
                $title = 'Free Trial Ending';
                break;
            case 'handleCustomerSubscriptionUpdated':
                $templateName = 'users-subscription-updated';
                break;

            case 'handleCustomerSubscriptionDeleted':
                $templateName = 'users-subscription-canceled';
                $title = 'Subscription Cancelled';
                break;

            case 'handleChargeSucceeded':
                $templateName = 'users-charge-success';
                $title = 'Invoice';

                break;

            case 'handleChargeFailed':
                $templateName = 'users-charge-failed';
                $title = 'Payment Failed';

                break;

            case 'handleChargePending':
                $templateName = 'users-charge-pending';
                break;

            default:
                break;
        }

        //Search for actual template by templateName
        if ($templateName) {
            $emailTemplate = Template::generate($templateName, $companyGroup->users->toArray());

            Di::getDefault()->getMail()
            ->to($companyGroup->users->email)
            ->subject($this->app->name . ' - ' . $title)
            ->content($emailTemplate)
            ->sendNow();
        }
    }

    /**
     * Updates subscription payment status depending on charge event.
     *
     * @param $user
     * @param $payload
     *
     * @return void
     */
    public function updateSubscriptionPaymentStatus(CompaniesGroups $companyGroup, array $payload) : void
    {
        // Identify if payload comes from mobile payments
        if ($payload['is_mobile']) {
            $chargeDate = $payload['receipt_creation_date'];
            $paidStatus = $payload['paid_status'];
            $subscriptionStatus = $payload['subscription_status'];
        } else {
            $chargeDate = date('Y-m-d H:i:s', (int) $payload['data']['object']['created']);
            $paidStatus = $payload['data']['object']['paid'];
            $subscriptionStatus = $payload['data']['object']['status'];
        }

        //Fetch current user subscription
        $subscription = $companyGroup->subscription();
        $user = $companyGroup->users;

        if (is_object($subscription)) {
            $subscription->paid = $paidStatus ?? 0;
            $subscription->charge_date = $chargeDate;

            $subscription->validateByGracePeriod();

            if ($subscription->paid) {
                $subscription->is_freetrial = 0;
                $subscription->trial_ends_days = 0;
            }

            //Paid status is false if plan has been canceled
            if ($subscriptionStatus == 'canceled') {
                $subscription->paid = 0;
                $subscription->charge_date = null;
            }

            if ($subscription->updateOrFail()) {
                //Update companies setting
                $paidSetting = CompaniesSettings::findFirst([
                    'conditions' => "companies_id = ?0 and name = 'paid' and is_deleted = 0",
                    'bind' => [$user->getDefaultCompany()->getId()]
                ]);

                $paidSetting->value = (string) $subscription->paid;
                $paidSetting->updateOrFail();
            }
            $this->log->info("User with id: {$user->id} charged status was {$paidStatus} \n");
        } else {
            $this->log->error("Subscription not found\n");
        }
    }
}
