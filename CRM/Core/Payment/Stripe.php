<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

use Civi\Api4\PaymentprocessorWebhook;
use CRM_Stripe_ExtensionUtil as E;
use Civi\Payment\PropertyBag;
use Stripe\Stripe;
use Civi\Payment\Exception\PaymentProcessorException;
use Stripe\Webhook;

/**
 * Class CRM_Core_Payment_Stripe
 */
class CRM_Core_Payment_Stripe extends CRM_Core_Payment {

  use CRM_Core_Payment_MJWTrait;

  /**
   * @var \Stripe\StripeClient
   */
  public $stripeClient;

  /**
   * Custom properties used by this payment processor
   *
   * @var string[]
   */
  private $customProperties = ['paymentIntentID', 'paymentMethodID', 'setupIntentID'];

  /**
   * Constructor
   *
   * @param string $mode
   *   (deprecated) The mode of operation: live or test.
   * @param array $paymentProcessor
   */
  public function __construct($mode, $paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;

    if (defined('STRIPE_PHPUNIT_TEST') && isset($GLOBALS['mockStripeClient'])) {
      // When under test, prefer the mock.
      $this->stripeClient = $GLOBALS['mockStripeClient'];

    }
    else {
      // Normally we create a new stripe client.
      $secretKey = self::getSecretKey($this->_paymentProcessor);
      // You can configure only one of live/test so don't initialize StripeClient if keys are blank
      if (!empty($secretKey)) {
        $this->setAPIParams();
        $this->stripeClient = new \Stripe\StripeClient($secretKey);
      }
    }
  }

  /**
   * @param array $paymentProcessor
   *
   * @return string
   */
  public static function getSecretKey($paymentProcessor) {
    return trim($paymentProcessor['password'] ?? '');
  }

  /**
   * @param array $paymentProcessor
   *
   * @return string
   */
  public static function getPublicKey($paymentProcessor) {
    return trim($paymentProcessor['user_name'] ?? '');
  }

  /**
   * @return string
   */
  public function getWebhookSecret(): string {
    return trim($this->_paymentProcessor['signature']);
  }

  /**
   * Given a payment processor id, return the public key
   *
   * @param $paymentProcessorId
   *
   * @return string
   */
  public static function getPublicKeyById($paymentProcessorId) {
    try {
      $paymentProcessor = civicrm_api3('PaymentProcessor', 'getsingle', [
        'id' => $paymentProcessorId,
      ]);
      $key = self::getPublicKey($paymentProcessor);
    }
    catch (CiviCRM_API3_Exception $e) {
      return '';
    }
    return $key;
  }

  /**
   * Given a payment processor id, return the secret key
   *
   * @param $paymentProcessorId
   *
   * @return string
   */
  public static function getSecretKeyById($paymentProcessorId) {
    try {
      $paymentProcessor = civicrm_api3('PaymentProcessor', 'getsingle', [
        'id' => $paymentProcessorId,
      ]);
      $key = self::getSecretKey($paymentProcessor);
    }
    catch (CiviCRM_API3_Exception $e) {
      return '';
    }
    return $key;
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return null|string
   *   The error message if any.
   */
  public function checkConfig() {
    $error = [];

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return string
   */
  public function getPaymentTypeName() {
    return 'credit_card';
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return string
   */
  public function getPaymentTypeLabel() {
    return E::ts('Stripe');
  }

  /**
   * We can use the stripe processor on the backend
   * @return bool
   */
  public function supportsBackOffice() {
    return TRUE;
  }

  /**
   * We can edit stripe recurring contributions
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return FALSE;
  }

  public function supportsRecurring() {
    return TRUE;
  }

  /**
   * Does this payment processor support refund?
   *
   * @return bool
   */
  public function supportsRefund() {
    return TRUE;
  }

  /**
   * Can we set a future recur start date?  Stripe allows this but we don't (yet) support it.
   * @return bool
   */
  public function supportsFutureRecurStartDate() {
    return TRUE;
  }

  /**
   * Is an authorize-capture flow supported.
   *
   * @return bool
   */
  protected function supportsPreApproval() {
    return TRUE;
  }

  /**
   * Does this processor support cancelling recurring contributions through code.
   *
   * If the processor returns true it must be possible to take action from within CiviCRM
   * that will result in no further payments being processed.
   *
   * @return bool
   */
  protected function supportsCancelRecurring() {
    return TRUE;
  }

  /**
   * Does the processor support the user having a choice as to whether to cancel the recurring with the processor?
   *
   * If this returns TRUE then there will be an option to send a cancellation request in the cancellation form.
   *
   * This would normally be false for processors where CiviCRM maintains the schedule.
   *
   * @return bool
   */
  protected function supportsCancelRecurringNotifyOptional() {
    return TRUE;
  }

  /**
   * Get the amount for the Stripe API formatted in lowest (ie. cents / pennies).
   *
   * @param array|PropertyBag $params
   *
   * @return string
   */
  public function getAmount($params = []): string {
    $amount = number_format((float) $params['amount'] ?? 0.0, CRM_Utils_Money::getCurrencyPrecision($this->getCurrency($params)), '.', '');
    // Stripe amount required in cents.
    $amount = preg_replace('/[^\d]/', '', strval($amount));
    return $amount;
  }

  /**
   * Set API parameters for Stripe (such as identifier, api version, api key)
   */
  public function setAPIParams() {
    // Use CiviCRM log file
    Stripe::setLogger(\Civi::log());
    // Attempt one retry (Stripe default is 0) if we can't connect to Stripe servers
    Stripe::setMaxNetworkRetries(1);
    // Set plugin info and API credentials.
    Stripe::setAppInfo('CiviCRM', CRM_Utils_System::version(), CRM_Utils_System::baseURL());
    Stripe::setApiKey(self::getSecretKey($this->_paymentProcessor));
    Stripe::setApiVersion(CRM_Stripe_Check::API_VERSION);
  }

  /**
   * Handle an error from Stripe API and notify the user
   *
   * @param array $err
   * @param string $bounceURL
   *
   * @return string errorMessage
   */
  public function handleErrorNotification($err, $bounceURL = NULL) {
    try {
      self::handleError("{$err['type']} {$err['code']}", $err['message'], $bounceURL);
    }
    catch (Exception $e) {
      return $e->getMessage();
    }
  }

  /**
   * Stripe exceptions contain a json object in the body "error". This function extracts and returns that as an array.
   * @param String $op
   * @param Exception $e
   * @param Boolean $log
   *
   * @return array $err
   */
  public static function parseStripeException($op, $e, $log = FALSE) {
    $body = $e->getJsonBody();
    if ($log) {
      Civi::log()->error("Stripe_Error {$op}: " . print_r($body, TRUE));
    }
    // Get the error array. Creat a "fake" error code if error is not set.
    return $body['error'] ?? ['code' => 9000];
  }

  /**
   * Create or update a Stripe Plan
   *
   * @param array $params
   * @param integer $amount
   *
   * @return \Stripe\Plan
   */
  public function createPlan($params, $amount) {
    $currency = $this->getCurrency($params);
    $planId = "every-{$params['recurFrequencyInterval']}-{$params['recurFrequencyUnit']}-{$amount}-" . strtolower($currency);

    if ($this->_paymentProcessor['is_test']) {
      $planId .= '-test';
    }

    // Try and retrieve existing plan from Stripe
    // If this fails, we'll create a new one
    try {
      $plan = $this->stripeClient->plans->retrieve($planId);
    }
    catch (\Stripe\Exception\InvalidRequestException $e) {
      $err = self::parseStripeException('plan_retrieve', $e, FALSE);
      if ($err['code'] === 'resource_missing') {
        $formatted_amount = CRM_Utils_Money::formatLocaleNumericRoundedByCurrency(($amount / 100), $currency);
        $productName = "CiviCRM " . (isset($params['membership_name']) ? $params['membership_name'] . ' ' : '') . "every {$params['recurFrequencyInterval']} {$params['recurFrequencyUnit']}(s) {$currency}{$formatted_amount}";
        if ($this->_paymentProcessor['is_test']) {
          $productName .= '-test';
        }
        $product = $this->stripeClient->products->create([
          "name" => $productName,
          "type" => "service"
        ]);
        // Create a new Plan.
        $stripePlan = [
          'amount' => $amount,
          'interval' => $params['recurFrequencyUnit'],
          'product' => $product->id,
          'currency' => $currency,
          'id' => $planId,
          'interval_count' => $params['recurFrequencyInterval'],
        ];
        $plan = $this->stripeClient->plans->create($stripePlan);
      }
    }

    return $plan;
  }
  /**
   * Override CRM_Core_Payment function
   *
   * @return array
   */
  public function getPaymentFormFields() {
    return [];
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    return [];
  }

  /**
   * Get billing fields required for this processor.
   *
   * We apply the existing default of returning fields only for payment processor type 1. Processors can override to
   * alter.
   *
   * @param int $billingLocationID
   *
   * @return array
   */
  public function getBillingAddressFields($billingLocationID = NULL) {
    if ((boolean) \Civi::settings()->get('stripe_nobillingaddress')) {
      return [];
    }
    else {
      return parent::getBillingAddressFields($billingLocationID);
    }
  }

  /**
   * Get form metadata for billing address fields.
   *
   * @param int $billingLocationID
   *
   * @return array
   *    Array of metadata for address fields.
   */
  public function getBillingAddressFieldsMetadata($billingLocationID = NULL) {
    if ((boolean) \Civi::settings()->get('stripe_nobillingaddress')) {
      return [];
    }
    else {
      $metadata = parent::getBillingAddressFieldsMetadata($billingLocationID);
      if (!$billingLocationID) {
        // Note that although the billing id is passed around the forms the idea that it would be anything other than
        // the result of the function below doesn't seem to have eventuated.
        // So taking this as a param is possibly something to be removed in favour of the standard default.
        $billingLocationID = CRM_Core_BAO_LocationType::getBilling();
      }

      // Stripe does not require some of the billing fields but users may still choose to fill them in.
      $nonRequiredBillingFields = [
        "billing_state_province_id-{$billingLocationID}",
        "billing_postal_code-{$billingLocationID}"
      ];
      foreach ($nonRequiredBillingFields as $fieldName) {
        if (!empty($metadata[$fieldName]['is_required'])) {
          $metadata[$fieldName]['is_required'] = FALSE;
        }
      }

      return $metadata;
    }
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    // Don't use \Civi::resources()->addScriptFile etc as they often don't work on AJAX loaded forms (eg. participant backend registration)
    $jsVars = [
      'id' => $form->_paymentProcessor['id'],
      'currency' => $this->getDefaultCurrencyForForm($form),
      'billingAddressID' => CRM_Core_BAO_LocationType::getBilling(),
      'publishableKey' => CRM_Core_Payment_Stripe::getPublicKeyById($form->_paymentProcessor['id']),
      'paymentProcessorTypeID' => $form->_paymentProcessor['payment_processor_type_id'],
      'locale' => CRM_Stripe_Api::mapCiviCRMLocaleToStripeLocale(),
      'apiVersion' => CRM_Stripe_Check::API_VERSION,
      'csrfToken' => NULL,
      'country' => \Civi::settings()->get('stripe_country'),
    ];
    if (class_exists('\Civi\Firewall\Firewall')) {
      $firewall = new \Civi\Firewall\Firewall();
      $jsVars['csrfToken'] = $firewall->generateCSRFToken();
      // This is used for MOTO payments. We need to check if the form creating the paymentIntent is a frontend (public) or backoffice (admin) form.
      // We cannot check via javascript because that would be insecure (could be changed by the user submitting the form).
      // So we store the data in the session using the csrfToken as key which is unique to the rendered form and is submitted when creating the paymentIntent.
      CRM_Core_Session::singleton()->set($jsVars['csrfToken'], ['isBackOffice' => $form->isBackOffice ?? FALSE], E::SHORT_NAME);
    }

    // Add CSS via region (it won't load on drupal webform if added via \Civi::resources()->addStyleFile)
    CRM_Core_Region::instance('billing-block')->add([
      'styleUrl' => \Civi::service('asset_builder')->getUrl(
        'elements.css',
        [
          'path' => \Civi::resources()->getPath(E::LONG_NAME, 'css/elements.css'),
          'mimetype' => 'text/css',
        ]
      ),
      'weight' => -1,
    ]);
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => \Civi::service('asset_builder')->getUrl(
        'civicrmStripe.js',
        [
          'path' => \Civi::resources()->getPath(E::LONG_NAME, 'js/civicrm_stripe.js'),
          'mimetype' => 'application/javascript',
        ]
      ),
      // Load after other scripts on form (default = 1)
      'weight' => 100,
    ]);

    // Add the future recur start date functionality
    CRM_Stripe_Recur::buildFormFutureRecurStartDate($form, $this, $jsVars);

    \Civi::resources()->addVars(E::SHORT_NAME, $jsVars);
    // Assign to smarty so we can add via Card.tpl for drupal webform and other situations where jsVars don't get loaded on the form.
    // This applies to some contribution page configurations as well.
    $form->assign('stripeJSVars', $jsVars);
    CRM_Core_Region::instance('billing-block')->add(
      ['template' => 'CRM/Core/Payment/Stripe/Card.tpl', 'weight' => -1]);

    // Enable JS validation for forms so we only (submit) create a paymentIntent when the form has all fields validated.
    $form->assign('isJsValidate', TRUE);
  }

  /**
   * Function to action pre-approval if supported
   *
   * @param array $params
   *   Parameters from the form
   *
   * This function returns an array which should contain
   *   - pre_approval_parameters (this will be stored on the calling form & available later)
   *   - redirect_url (if set the browser will be redirected to this.
   *
   * @return array
   */
  public function doPreApproval(&$params) {
    foreach ($this->customProperties as $property) {
      $preApprovalParams[$property] = CRM_Utils_Request::retrieveValue($property, 'String', NULL, FALSE, 'POST');
    }
    return ['pre_approval_parameters' => $preApprovalParams ?? []];
  }

  /**
   * Get any details that may be available to the payment processor due to an approval process having happened.
   *
   * In some cases the browser is redirected to enter details on a processor site. Some details may be available as a
   * result.
   *
   * @param array $storedDetails
   *
   * @return array
   */
  public function getPreApprovalDetails($storedDetails) {
    return $storedDetails ?? [];
  }

  /**
   * Process payment
   * Submit a payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   * Payment processors should set payment_status_id/payment_status.
   *
   * @param array|PropertyBag $propertyBag
   *   Assoc array of input parameters for this transaction.
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$propertyBag, $component = 'contribute') {
    /* @var \Civi\Payment\PropertyBag $propertyBag */
    $propertyBag = \Civi\Payment\PropertyBag::cast($propertyBag);

    $zeroAmountPayment = $this->processZeroAmountPayment($propertyBag);
    if ($zeroAmountPayment) {
      return $zeroAmountPayment;
    }
    $propertyBag = $this->beginDoPayment($propertyBag);

    $isRecur = ($propertyBag->getIsRecur() && $this->getRecurringContributionId($propertyBag));

    // Now try to retrieve the payment "token". One of setupIntentID, paymentMethodID, paymentIntentID is required (in that order)
    $paymentMethodID = NULL;
    $propertyBag = $this->getTokenParameter('setupIntentID', $propertyBag, FALSE);
    if ($propertyBag->has('setupIntentID')) {
      $setupIntentID = $propertyBag->getCustomProperty('setupIntentID');
      $setupIntent = $this->stripeClient->setupIntents->retrieve($setupIntentID);
      $paymentMethodID = $setupIntent->payment_method;
    }
    else {
      $propertyBag = $this->getTokenParameter('paymentMethodID', $propertyBag, FALSE);
      if ($propertyBag->has('paymentMethodID')) {
        $paymentMethodID = $propertyBag->getCustomProperty('paymentMethodID');
      }
      else {
        $propertyBag = $this->getTokenParameter('paymentIntentID', $propertyBag, TRUE);
      }
    }

    // @fixme DO NOT SET ANYTHING ON $propertyBag or $params BELOW THIS LINE (we are reading from both)
    $params = $this->getPropertyBagAsArray($propertyBag);

    // We don't actually use this hook with Stripe, but useful to trigger so listeners can see raw params
    $newParams = [];
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $newParams);

    $amountFormattedForStripe = self::getAmount($params);
    $email = $this->getBillingEmail($params, $propertyBag->getContactID());

    // See if we already have a stripe customer
    $customerParams = [
      'contact_id' => $propertyBag->getContactID(),
      'processor_id' => $this->_paymentProcessor['id'],
      'email' => $email,
      // Include this to allow redirect within session on payment failure
      'error_url' => $propertyBag->getCustomProperty('error_url'),
    ];

    // Get the Stripe Customer:
    //   1. Look for an existing customer.
    //   2. If no customer (or a deleted customer found), create a new one.
    //   3. If existing customer found, update the metadata that Stripe holds for this customer.
    $stripeCustomerId = CRM_Stripe_Customer::find($customerParams);
    // Customer not in civicrm database.  Create a new Customer in Stripe.
    if (!isset($stripeCustomerId)) {
      $stripeCustomer = CRM_Stripe_Customer::create($customerParams, $this);
    }
    else {
      // Customer was found in civicrm database, fetch from Stripe.
      try {
        $stripeCustomer = $this->stripeClient->customers->retrieve($stripeCustomerId);
      } catch (Exception $e) {
        $err = self::parseStripeException('retrieve_customer', $e);
        $errorMessage = $this->handleErrorNotification($err, $propertyBag->getCustomProperty('error_url'));
        throw new PaymentProcessorException('Failed to retrieve Stripe Customer: ' . $errorMessage);
      }

      if ($stripeCustomer->isDeleted()) {
        // Customer doesn't exist, create a new one
        CRM_Stripe_Customer::delete($customerParams);
        try {
          $stripeCustomer = CRM_Stripe_Customer::create($customerParams, $this);
        } catch (Exception $e) {
          // We still failed to create a customer
          $err = self::parseStripeException('create_customer', $e);
          $errorMessage = $this->handleErrorNotification($err, $propertyBag->getCustomProperty('error_url'));
          throw new PaymentProcessorException('Failed to create Stripe Customer: ' . $errorMessage);
        }
      }
    }

    // Attach the paymentMethod to the customer and set as default for new invoices
    if (isset($paymentMethodID)) {
      $paymentMethod = $this->stripeClient->paymentMethods->retrieve($paymentMethodID);
      $paymentMethod->attach(['customer' => $stripeCustomer->id]);
      $customerParams['invoice_settings']['default_payment_method'] = $paymentMethodID;
    }

    CRM_Stripe_Customer::updateMetadata($customerParams, $this, $stripeCustomer->id);

    // Handle recurring payments in doRecurPayment().
    if ($isRecur) {
      // We're processing a recurring payment - for recurring payments we first saved a paymentMethod via the browser js.
      // Now we use that paymentMethod to setup a stripe subscription and take the first payment.
      // This is where we save the customer card
      // @todo For a recurring payment we have to save the card. For a single payment we'd like to develop the
      //   save card functionality but should not save by default as the customer has not agreed.
      return $this->doRecurPayment($propertyBag, $amountFormattedForStripe, $stripeCustomer);
    }
    // @fixme FROM HERE we are using $params ONLY - SET things if required ($propertyBag is not used beyond here)
    //   Note that we set both $propertyBag and $params paymentIntentID in the case of participants above

    $intentParams = [
      'customer' => $stripeCustomer->id,
      'description' => $this->getDescription($params, 'description'),
    ];
    $intentParams['statement_descriptor_suffix'] = $this->getDescription($params, 'statement_descriptor_suffix');
    $intentParams['statement_descriptor'] = $this->getDescription($params, 'statement_descriptor');

    // This is where we actually charge the customer
    try {
      $intent = $this->stripeClient->paymentIntents->retrieve($propertyBag->getCustomProperty('paymentIntentID'));
      if ($intent->amount != $this->getAmount($params)) {
        $intentParams['amount'] = $this->getAmount($params);
      }
      $intent = $this->stripeClient->paymentIntents->update($intent->id, $intentParams);
    }
    catch (Exception $e) {
      $this->handleError($e->getCode(), $e->getMessage(), $params['error_url']);
    }

    $params = $this->processPaymentIntent($params, $intent);

    // For a single charge there is no stripe invoice, we set OrderID to the ChargeID.
    if (empty($this->getPaymentProcessorOrderID())) {
      $this->setPaymentProcessorOrderID($this->getPaymentProcessorTrxnID());
    }

    // For contribution workflow we have a contributionId so we can set parameters directly.
    // For events/membership workflow we have to return the parameters and they might get set...
    return $this->endDoPayment($params);
  }

  /**
   * @param \Civi\Payment\PropertyBag $params
   *
   * @return bool
   */
  private function isPaymentForEventAdditionalParticipants($params) {
    if ($params->getter('additional_participants', TRUE)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Submit a recurring payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   *
   * @param \Civi\Payment\PropertyBag $propertyBag
   *   PropertyBag for this transaction.
   * @param int $amountFormattedForStripe
   *   Transaction amount in cents.
   * @param \Stripe\Customer $stripeCustomer
   *   Stripe customer object generated by Stripe API.
   *
   * @return array
   *   The result in a nice formatted array (or an error object).
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \CRM_Core_Exception
   */
  public function doRecurPayment($propertyBag, $amountFormattedForStripe, $stripeCustomer) {
    $params = $this->getPropertyBagAsArray($propertyBag);

    // @fixme FROM HERE we are using $params array (but some things are READING from $propertyBag)

    // We set payment status as pending because the IPN will set it as completed / failed
    $params = $this->setStatusPaymentPending($params);

    $required = NULL;
    if (empty($this->getRecurringContributionId($propertyBag))) {
      $required = 'contributionRecurID';
    }
    if (!isset($params['recurFrequencyUnit'])) {
      $required = 'recurFrequencyUnit';
    }
    if ($required) {
      Civi::log()->error('Stripe doRecurPayment: Missing mandatory parameter: ' . $required);
      throw new CRM_Core_Exception('Stripe doRecurPayment: Missing mandatory parameter: ' . $required);
    }

    // Make sure recurFrequencyInterval is set (default to 1 if not)
    empty($params['recurFrequencyInterval']) ? $params['recurFrequencyInterval'] = 1 : NULL;

    // Create the stripe plan
    $planId = self::createPlan($params, $amountFormattedForStripe);

    // Attach the Subscription to the Stripe Customer.
    $subscriptionParams = [
      'proration_behavior' => 'none',
      'plan' => $planId,
      'metadata' => ['Description' => $params['description']],
      'expand' => ['latest_invoice.payment_intent'],
      'customer' => $stripeCustomer->id,
      'off_session' => TRUE,
    ];
    // This is the parameter that specifies the start date for the subscription.
    // If omitted the subscription will start immediately.
    $billingCycleAnchor = $this->getRecurBillingCycleDay($params);
    if ($billingCycleAnchor) {
      $subscriptionParams['billing_cycle_anchor'] = $billingCycleAnchor;
    }

    // Create the stripe subscription for the customer
    $stripeSubscription = $this->stripeClient->subscriptions->create($subscriptionParams);
    $this->setPaymentProcessorSubscriptionID($stripeSubscription->id);

    $nextScheduledContributionDate = $this->calculateNextScheduledDate($params);
    $contributionRecur = \Civi\Api4\ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $this->getRecurringContributionId($propertyBag))
      ->addValue('processor_id', $this->getPaymentProcessorSubscriptionID())
      ->addValue('auto_renew', 1)
      ->addValue('next_sched_contribution_date', $nextScheduledContributionDate)
      ->addValue('cycle_day', date('d', strtotime($nextScheduledContributionDate)));

    if (!empty($params['installments'])) {
      // We set an end date if installments > 0
      if (empty($params['receive_date'])) {
        $params['receive_date'] = date('YmdHis');
      }
      if ($params['installments']) {
        $contributionRecur
          ->addValue('end_date', $this->calculateEndDate($params))
          ->addValue('installments', $params['installments']);
      }
    }

    if ($stripeSubscription->status === 'incomplete') {
      $contributionRecur->addValue('contribution_status_id:name', 'Failed');
    }
    // Update the recurring payment
    $contributionRecur->execute();

    if ($stripeSubscription->status === 'incomplete') {
      // For example with test card 4000000000000341 (Attaching this card to a Customer object succeeds, but attempts to charge the customer fail)
      \Civi::log()->warning('Stripe subscription status=incomplete. ID:' . $stripeSubscription->id);
      throw new PaymentProcessorException('Payment failed');
    }

    // artfulrobot: Q. what do we normally get here?
    if ($stripeSubscription->latest_invoice) {
      // Get the paymentIntent for the latest invoice
      $intent = $stripeSubscription->latest_invoice['payment_intent'];
      $params = $this->processPaymentIntent($params, $intent);

      // Set the orderID (trxn_id) to the invoice ID
      // The IPN will change it to the charge_id
      $this->setPaymentProcessorOrderID($stripeSubscription->latest_invoice['id']);
    }
    else {
      // Update the paymentIntent in the CiviCRM database for later tracking
      // If we are not starting the recurring series immediately we probably have a "setupIntent" which needs confirming
      $intentParams = [
        'stripe_intent_id' => $intent->id ?? $stripeSubscription->pending_setup_intent ?? $propertyBag->getCustomProperty('paymentMethodID'),
        'payment_processor_id' => $this->_paymentProcessor['id'],
        'contribution_id' =>  $params['contributionID'] ?? NULL,
        'identifier' => $params['qfKey'] ?? NULL,
        'contact_id' => $params['contactID'],
      ];
      try {
        $intentParams['id'] = civicrm_api3('StripePaymentintent', 'getvalue', ['stripe_intent_id' => $propertyBag->getCustomProperty('paymentMethodID'), 'return' => 'id']);
      }
      catch (Exception $e) {
        // Do nothing, we should already have a StripePaymentintent record but we don't so we'll create one.
      }

      if (empty($intentParams['contribution_id'])) {
        $intentParams['flags'][] = 'NC';
      }
      CRM_Stripe_BAO_StripePaymentintent::create($intentParams);
      // Set the orderID (trxn_id) to the subscription ID because we don't yet have an invoice.
      // The IPN will change it to the invoice_id and then the charge_id
      $this->setPaymentProcessorOrderID($stripeSubscription->id);
    }

    return $this->endDoPayment($params);
  }

  /**
   * Get the billing cycle day (timestamp)
   * @param array $params
   *
   * @return int|null
   */
  private function getRecurBillingCycleDay($params) {
    if (isset($params['receive_date'])) {
      $receiveDateTimestamp = strtotime($params['receive_date']);
      // If `receive_date` was set to "now" it will be in the past (by a few seconds) by the time we actually send it to Stripe.
      if ($receiveDateTimestamp > strtotime('now')) {
        // We've specified a receive_date in the future, use it!
        return $receiveDateTimestamp;
      }
    }
    // Either we had no receive_date or receive_date was in the past (or "now" when form was submitted).
    return NULL;
  }

  /**
   * This performs the processing and recording of the paymentIntent for both recurring and non-recurring payments
   * @param array $params
   * @param \Stripe\PaymentIntent $intent
   *
   * @return array $params
   */
  private function processPaymentIntent($params, $intent) {
    $contactId = $params['contactID'];
    $email = $this->getBillingEmail($params, $contactId);

    try {
      if ($intent->status === 'requires_confirmation') {
        $intent->confirm();
      }

      switch ($intent->status) {
        case 'requires_capture':
          $intent->capture();
        case 'succeeded':
          // Return fees & net amount for Civi reporting.
          $stripeCharge = $intent->charges->data[0];
          try {
            $stripeBalanceTransaction = $this->stripeClient->balanceTransactions->retrieve($stripeCharge->balance_transaction);
          }
          catch (Exception $e) {
            $err = self::parseStripeException('retrieve_balance_transaction', $e, FALSE);
            $errorMessage = $this->handleErrorNotification($err, $params['error_url']);
            throw new PaymentProcessorException('Failed to retrieve Stripe Balance Transaction: ' . $errorMessage);
          }
          if (($stripeCharge['currency'] !== $stripeBalanceTransaction->currency)
              && (!empty($stripeBalanceTransaction->exchange_rate))) {
            $params['fee_amount'] = CRM_Stripe_Api::currencyConversion($stripeBalanceTransaction->fee, $stripeBalanceTransaction['exchange_rate'], $stripeCharge['currency']);
          }
          else {
            // We must round to currency precision otherwise payments may fail because Contribute BAO saves but then
            // can't retrieve because it tries to use the full unrounded number when it only got saved with 2dp.
            $params['fee_amount'] = round($stripeBalanceTransaction->fee / 100, CRM_Utils_Money::getCurrencyPrecision($stripeCharge['currency']));
          }
          // Success!
          // Set the desired contribution status which will be set later (do not set on the contribution here!)
          $params = $this->setStatusPaymentCompleted($params);
          // Transaction ID is always stripe Charge ID.
          $this->setPaymentProcessorTrxnID($stripeCharge->id);

        case 'requires_action':
          // We fall through to this in requires_capture / requires_action so we always set a receipt_email
          if ((boolean) \Civi::settings()->get('stripe_oneoffreceipt')) {
            // Send a receipt from Stripe - we have to set the receipt_email after the charge has been captured,
            //   as the customer receives an email as soon as receipt_email is updated and would receive two if we updated before capture.
            $this->stripeClient->paymentIntents->update($intent->id, ['receipt_email' => $email]);
          }
          break;
      }
    }
    catch (Exception $e) {
      $this->handleError($e->getCode(), $e->getMessage(), $params['error_url']);
    }
    finally {
      // Always update the paymentIntent in the CiviCRM database for later tracking
      $intentParams = [
        'stripe_intent_id' => $intent->id,
        'payment_processor_id' => $this->_paymentProcessor['id'],
        'status' => $intent->status,
        'contribution_id' =>  $params['contributionID'] ?? NULL,
        'description' => $this->getDescription($params, 'description'),
        'identifier' => $params['qfKey'] ?? NULL,
        'contact_id' => $params['contactID'],
        'extra_data' => ($errorMessage ?? '') . ';' . ($email ?? ''),
      ];
      if (empty($intentParams['contribution_id'])) {
        $intentParams['flags'][] = 'NC';
      }
      CRM_Stripe_BAO_StripePaymentintent::create($intentParams);
    }

    return $params;
  }

  /**
   * Submit a refund payment
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @return array
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doRefund(&$params) {
    $requiredParams = ['trxn_id', 'amount'];
    foreach ($requiredParams as $required) {
      if (!isset($params[$required])) {
        $message = 'Stripe doRefund: Missing mandatory parameter: ' . $required;
        Civi::log()->error($message);
        throw new PaymentProcessorException($message);
      }
    }

    $refundParams = [
      'charge' => $params['trxn_id'],
    ];
    $refundParams['amount'] = $this->getAmount($params);
    try {
      $refund = $this->stripeClient->refunds->create($refundParams);
    }
    catch (Exception $e) {
      $this->handleError($e->getCode(), $e->getMessage());
    }

    switch ($refund->status) {
      case 'pending':
        $refundStatus = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
        $refundStatusName = 'Pending';
        break;

      case 'succeeded':
        $refundStatus = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
        $refundStatusName = 'Completed';
        break;

      case 'failed':
        $refundStatus = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
        $refundStatusName = 'Failed';
        break;

      case 'canceled':
        $refundStatus = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Cancelled');
        $refundStatusName = 'Cancelled';
        break;
    }

    $refundParams = [
      'refund_trxn_id' => $refund->id,
      'refund_status_id' => $refundStatus,
      'refund_status_name' => $refundStatusName,
      'processor_result' => $refund->jsonSerialize(),
    ];
    return $refundParams;
  }

  /**
   * Get a description field
   * @param array $params
   * @param string $type
   *   One of description, statement_descriptor, statement_descriptor_suffix
   *
   * @return string
   */
  private function getDescription($params, $type = 'description') {
    # See https://stripe.com/docs/statement-descriptors
    # And note: both the descriptor and the descriptor suffix must have at
    # least one alphabetical character - so we ensure that all returned
    # statement descriptors minimally have an "X".
    $disallowed_characters = ['<', '>', '\\', "'", '"', '*'];

    $contactContributionID = $params['contactID'] . 'X' . ($params['contributionID'] ?? 'XX');
    switch ($type) {
      // For statement_descriptor / statement_descriptor_suffix:
      // 1. Get it from the setting if defined.
      // 2. Generate it from the contact/contribution ID + description (event/contribution title).
      // 3. Set it to the current "domain" name in CiviCRM.
      // 4. If we end up with a blank descriptor Stripe will reject it - https://lab.civicrm.org/extensions/stripe/-/issues/293
      //   so we set it to ".".
      case 'statement_descriptor':
        $description = trim(\Civi::settings()->get('stripe_statementdescriptor'));
        if (empty($description)) {
          $description = trim("{$contactContributionID} {$params['description']}");
          if (empty($description)) {
            $description = \Civi\Api4\Domain::get(FALSE)
              ->setCurrentDomain(TRUE)
              ->addSelect('name')
              ->execute()
              ->first()['name'];
          }
        }
        $description = str_replace($disallowed_characters, '', $description);
        if (empty($description)) {
          $description = 'X';
        }
        return substr($description, 0, 22);

      case 'statement_descriptor_suffix':
        $description = trim(\Civi::settings()->get('stripe_statementdescriptorsuffix'));
        if (empty($description)) {
          $description = trim("{$contactContributionID} {$params['description']}");
          if (empty($description)) {
            $description = \Civi\Api4\Domain::get(FALSE)
              ->setCurrentDomain(TRUE)
              ->addSelect('name')
              ->execute()
              ->first()['name'];
          }
        }
        $description = str_replace($disallowed_characters, '', $description);
        if (empty($description)) {
          $description = 'X';
        }
        return substr($description,0,12);

      default:
        // The (paymentIntent) full description has no restriction on characters that are allowed/disallowed.
        return "{$params['description']} " . $contactContributionID . " #" . ($params['invoiceID'] ?? '');
    }
  }

  /**
   * Calculate the end_date for a recurring contribution based on the number of installments
   * @param $params
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function calculateEndDate($params) {
    $requiredParams = ['receive_date', 'installments', 'recurFrequencyInterval', 'recurFrequencyUnit'];
    foreach ($requiredParams as $required) {
      if (!isset($params[$required])) {
        $message = 'Stripe calculateEndDate: Missing mandatory parameter: ' . $required;
        Civi::log()->error($message);
        throw new CRM_Core_Exception($message);
      }
    }

    switch ($params['recurFrequencyUnit']) {
      case 'day':
        $frequencyUnit = 'D';
        break;

      case 'week':
        $frequencyUnit = 'W';
        break;

      case 'month':
        $frequencyUnit = 'M';
        break;

      case 'year':
        $frequencyUnit = 'Y';
        break;
    }

    $numberOfUnits = $params['installments'] * $params['recurFrequencyInterval'];
    $endDate = new DateTime($params['receive_date']);
    $endDate->add(new DateInterval("P{$numberOfUnits}{$frequencyUnit}"));
    return $endDate->format('Ymd') . '235959';
  }

  /**
   * Calculate the end_date for a recurring contribution based on the number of installments
   * @param $params
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function calculateNextScheduledDate($params) {
    $requiredParams = ['recurFrequencyInterval', 'recurFrequencyUnit'];
    foreach ($requiredParams as $required) {
      if (!isset($params[$required])) {
        $message = 'Stripe calculateNextScheduledDate: Missing mandatory parameter: ' . $required;
        Civi::log()->error($message);
        throw new CRM_Core_Exception($message);
      }
    }
    if (empty($params['receive_date']) && empty($params['next_sched_contribution_date'])) {
      $startDate = date('YmdHis');
    }
    elseif (!empty($params['next_sched_contribution_date'])) {
      if ($params['next_sched_contribution_date'] < date('YmdHis')) {
        $startDate = $params['next_sched_contribution_date'];
      }
    }
    else {
      $startDate = $params['receive_date'];
    }

    switch ($params['recurFrequencyUnit']) {
      case 'day':
        $frequencyUnit = 'D';
        break;

      case 'week':
        $frequencyUnit = 'W';
        break;

      case 'month':
        $frequencyUnit = 'M';
        break;

      case 'year':
        $frequencyUnit = 'Y';
        break;
    }

    $numberOfUnits = $params['recurFrequencyInterval'];
    $endDate = new DateTime($startDate);
    $endDate->add(new DateInterval("P{$numberOfUnits}{$frequencyUnit}"));
    return $endDate->format('Ymd');
  }

  /**
   * Default payment instrument validation.
   *
   * Implement the usual Luhn algorithm via a static function in the CRM_Core_Payment_Form if it's a credit card
   * Not a static function, because I need to check for payment_type.
   *
   * @param array $values
   * @param array $errors
   */
  public function validatePaymentInstrument($values, &$errors) {
    // Use $_POST here and not $values - for webform fields are not set in $values, but are in $_POST
    CRM_Core_Form::validateMandatoryFields($this->getMandatoryFields(), $_POST, $errors);
  }

  /**
   * Attempt to cancel the subscription at Stripe.
   *
   * @param \Civi\Payment\PropertyBag $propertyBag
   *
   * @return array|null[]
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doCancelRecurring(\Civi\Payment\PropertyBag $propertyBag) {
    // By default we always notify the processor and we don't give the user the option
    // because supportsCancelRecurringNotifyOptional() = FALSE
    if (!$propertyBag->has('isNotifyProcessorOnCancelRecur')) {
      // If isNotifyProcessorOnCancelRecur is NOT set then we set our default
      $propertyBag->setIsNotifyProcessorOnCancelRecur(TRUE);
    }
    $notifyProcessor = $propertyBag->getIsNotifyProcessorOnCancelRecur();

    if (!$notifyProcessor) {
      return ['message' => E::ts('Successfully cancelled the subscription in CiviCRM ONLY.')];
    }

    if (!$propertyBag->has('recurProcessorID')) {
      $errorMessage = E::ts('The recurring contribution cannot be cancelled (No reference (trxn_id) found).');
      \Civi::log()->error($errorMessage);
      throw new PaymentProcessorException($errorMessage);
    }

    try {
      $subscription = $this->stripeClient->subscriptions->retrieve($propertyBag->getRecurProcessorID());
      if (!$subscription->isDeleted()) {
        $subscription->cancel();
      }
    }
    catch (Exception $e) {
      $errorMessage = E::ts('Could not delete Stripe subscription: %1', [1 => $e->getMessage()]);
      \Civi::log()->error($errorMessage);
      throw new PaymentProcessorException($errorMessage);
    }

    return ['message' => E::ts('Successfully cancelled the subscription at Stripe.')];
  }

  /**
   * Process incoming payment notification (IPN).
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Stripe\Exception\UnknownApiErrorException
   */
  public function handlePaymentNotification() {
    // Set default http response to 200
    http_response_code(200);
    $rawData = file_get_contents("php://input");
    $event = json_decode($rawData);
    $ipnClass = new CRM_Core_Payment_StripeIPN($this);
    $ipnClass->setEventID($event->id);
    if (!$ipnClass->setEventType($event->type)) {
      // We don't handle this event
      return;
    }

    $webhookSecret = $this->getWebhookSecret();
    if (!empty($webhookSecret)) {
      $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'];

      try {
        Webhook::constructEvent(
          $rawData, $sigHeader, $webhookSecret
        );
        $ipnClass->setVerifyData(FALSE);
      } catch (\UnexpectedValueException $e) {
        // Invalid payload
        \Civi::log()->error('Stripe webhook signature validation error: ' . $e->getMessage());
        http_response_code(400);
        exit();
      } catch (\Stripe\Exception\SignatureVerificationException $e) {
        // Invalid signature
        \Civi::log()->error('Stripe webhook signature validation error: ' . $e->getMessage());
        http_response_code(400);
        exit();
      }
    }
    else {
      $ipnClass->setVerifyData(TRUE);
    }

    $ipnClass->onReceiveWebhook();
  }

  /**
   * @param int $paymentProcessorID
   *   The actual payment processor ID that should be used.
   * @param $rawData
   *   The "raw" data, eg. a JSON string that is saved in the civicrm_system_log.context table
   * @param bool $verifyRequest
   *   Should we verify the request data with the payment processor (eg. retrieve it again?).
   * @param null|int $emailReceipt
   *   Override setting of email receipt if set to 0, 1
   *
   * @return bool
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function processPaymentNotification($paymentProcessorID, $rawData, $verifyRequest = TRUE, $emailReceipt = NULL) {
    // Set default http response to 200
    http_response_code(200);

    $event = json_decode($rawData);
    $paymentProcessorObject = \Civi\Payment\System::singleton()->getById($paymentProcessorID);
    if (!($paymentProcessorObject instanceof CRM_Core_Payment_Stripe)) {
      throw new PaymentProcessorException('Failed to get payment processor');
    }
    $ipnClass = new CRM_Core_Payment_StripeIPN($paymentProcessorObject);
    $ipnClass->setEventID($event->id);
    if (!$ipnClass->setEventType($event->type)) {
      // We don't handle this event
      return FALSE;
    };
    $ipnClass->setVerifyData($verifyRequest);
    if (!$verifyRequest) {
      $ipnClass->setData($event->data);
    }
    $ipnClass->setExceptionMode(FALSE);
    if (isset($emailReceipt)) {
      $ipnClass->setSendEmailReceipt($emailReceipt);
    }
    return $ipnClass->processWebhookEvent()->ok;
  }

  /**
   * Called by mjwshared extension's queue processor api3 Job.process_paymentprocessor_webhooks
   *
   * The array parameter contains a row of PaymentprocessorWebhook data, which represents a single GC event
   *
   * Return TRUE for success, FALSE if there's a problem
   */
  public function processWebhookEvent(array $webhookEvent) :bool {
    // If there is another copy of this event in the table with a lower ID, then
    // this is a duplicate that should be ignored. We do not worry if there is one with a higher ID
    // because that means that while there are duplicates, we'll only process the one with the lowest ID.
    $duplicates = PaymentprocessorWebhook::get(FALSE)
      ->selectRowCount()
      ->addWhere('event_id', '=', $webhookEvent['event_id'])
      ->addWhere('id', '<', $webhookEvent['id'])
      ->execute()->count();
    if ($duplicates) {
      PaymentprocessorWebhook::update(FALSE)
        ->addWhere('id', '=', $webhookEvent['id'])
        ->addValue('status', 'error')
        ->addValue('message', 'Refusing to process this event as it is a duplicate.')
        ->execute();
      return FALSE;
    }

    $handler = new CRM_Core_Payment_StripeIPN($this);
    return $handler->processQueuedWebhookEvent($webhookEvent);
  }

  /**
   * Get help text information (help, description, etc.) about this payment,
   * to display to the user.
   *
   * @param string $context
   *   Context of the text.
   *   Only explicitly supported contexts are handled without error.
   *   Currently supported:
   *   - contributionPageRecurringHelp (params: is_recur_installments, is_email_receipt)
   *   - contributionPageContinueText (params: amount, is_payment_to_existing)
   *   - cancelRecurDetailText:
   *     params:
   *       mode, amount, currency, frequency_interval, frequency_unit,
   *       installments, {membershipType|only if mode=auto_renew},
   *       selfService (bool) - TRUE if user doesn't have "edit contributions" permission.
   *         ie. they are accessing via a "self-service" link from an email receipt or similar.
   *   - cancelRecurNotSupportedText
   *
   * @param array $params
   *   Parameters for the field, context specific.
   *
   * @return string
   */
  public function getText($context, $params) {
    $text = parent::getText($context, $params);

    switch ($context) {
      case 'cancelRecurDetailText':
        // $params['selfService'] added via https://github.com/civicrm/civicrm-core/pull/17687
        $params['selfService'] = $params['selfService'] ?? TRUE;
        if ($params['selfService']) {
          $text .= ' <br/><strong>' . E::ts('Stripe will be automatically notified and the subscription will be cancelled.') . '</strong>';
        }
        else {
          $text .= ' <br/><strong>' . E::ts("If you select 'Send cancellation request..' then Stripe will be automatically notified and the subscription will be cancelled.") . '</strong>';
        }
    }
    return $text;
  }

  /*
   * Sets a mock stripe client object for this object and all future created
   * instances. This should only be called by phpunit tests.
   *
   * Nb. cannot change other already-existing instances.
   */
  public function setMockStripeClient($stripeClient) {
    if (!defined('STRIPE_PHPUNIT_TEST')) {
      throw new \RuntimeException("setMockStripeClient was called while not in a STRIPE_PHPUNIT_TEST");
    }
    $GLOBALS['mockStripeClient'] = $this->stripeClient = $stripeClient;
  }

  /**
   * Handle an error and notify the user
   * @fixme: Remove when min version of mjwshared is 1.2.3.
   *   This is a copy of the updated function that throws an exception on error instead of returning FALSE
   *
   * @param string $errorCode
   * @param string $errorMessage
   * @param string $bounceURL
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   *   (or statusbounce if URL is specified)
   */
  private function handleError($errorCode = NULL, $errorMessage = NULL, $bounceURL = NULL) {
    $errorCode = empty($errorCode) ? '' : $errorCode . ': ';
    $errorMessage = empty($errorMessage) ? 'Unknown System Error.' : $errorMessage;
    $message = $errorCode . $errorMessage;

    Civi::log()->error($this->getPaymentTypeLabel() . ' Payment Error: ' . $message);
    if ($this->handleErrorThrowsException) {
      // We're in a test environment. Throw exception.
      throw new \Exception('Exception thrown to avoid statusBounce because handleErrorThrowsException is set.' . $message);
    }

    if ($bounceURL) {
      CRM_Core_Error::statusBounce($message, $bounceURL, $this->getPaymentTypeLabel());
    }
    throw new PaymentProcessorException($errorMessage, $errorCode);
  }
}
