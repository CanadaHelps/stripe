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

use CRM_Stripe_ExtensionUtil as E;

/**
 * Stripe.Importcharges
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_importcharge_spec(&$spec) {
  $spec['ppid']['title'] = ts("Use the given Payment Processor ID");
  $spec['ppid']['type'] = CRM_Utils_Type::T_INT;
  $spec['ppid']['api.required'] = TRUE;
  $spec['contact_id']['title'] = ts("Contact ID");
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['contact_id']['api.required'] = TRUE;
  $spec['charge']['title'] = ts('Import a specific charge');
  $spec['charge']['type'] = CRM_Utils_Type::T_STRING;
  $spec['charge']['api.required'] = FALSE;
  $spec['financial_type_id'] = [
    'title' => 'Financial Type ID',
    'name' => 'financial_type_id',
    'type' => CRM_Utils_Type::T_INT,
    'pseudoconstant' => [
      'table' => 'civicrm_financial_type',
      'keyColumn' => 'id',
      'labelColumn' => 'name',
    ],
  ];
  $spec['payment_instrument_id']['api.aliases'] = ['payment_instrument'];
  $spec['contribution_source'] = [
    'title' => 'Contribution Source (optional description for contribution)',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['contribution_id']['title'] = ts("Optionally, provide contribution ID of existing contribution you want to link to.");
  $spec['contribution_id']['type'] = CRM_Utils_Type::T_INT;

}

/**
 * Stripe.Importcharges API
 *
 * @param array $params
 *
 * @return array
 * @throws \CiviCRM_API3_Exception
 * @throws \Stripe\Exception\UnknownApiErrorException
 */
function civicrm_api3_stripe_importcharge($params) {
  $ppid = $params['ppid'];

  // Get the payment processor and activate the Stripe API
  $payment_processor = civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $ppid]);
  $processor = new CRM_Core_Payment_Stripe('', $payment_processor);
  $processor->setAPIParams();

  // Retrieve the Stripe charge.
  $charge = \Stripe\Charge::retrieve($params['charge']);

  // Get the related invoice.
  $stripeInvoice = \Stripe\Invoice::retrieve($charge->invoice);
  if (!$stripeInvoice) {
    throw new \CiviCRM_API3_Exception(E::ts("The charge does not have an invoice, it cannot be imported."));
  }

  // Determine source text.
  if (!empty(CRM_Stripe_Api::getObjectParam('description', $stripeInvoice))) {
    $sourceText = CRM_Stripe_Api::getObjectParam('description', $stripeInvoice);
  }
  elseif (!empty($params['contribution_source'])) {
    $sourceText = $params['contribution_source'];
  }
  else {
    $sourceText = 'Stripe: Manual import via API';
  }

  $is_test = isset($paymentProcessor['is_test']) && $paymentProcessor['is_test'] ? 1 : 0;

  // Check for a subscription.
  $subscription = CRM_Stripe_Api::getObjectParam('subscription_id', $stripeInvoice);
  $contribution_recur_id = NULL;
  if ($subscription) {
    // Lookup the contribution_recur_id.
    $cr_results = \Civi\Api4\ContributionRecur::get()
      ->addWhere('trxn_id', '=', $subscription)
      ->execute();
    $contribution_recur = $cr_results->first();
    if (!$contribution_recur) {
      throw new \CiviCRM_API3_Exception(E::ts("The charge has a subscription, but the subscription is not in CiviCRM. Please import the subscription and try again."));
    }
    $contribution_recur_id = $contribution_recur['id'];
  }


  // Prepare to either create or update a contribution record in CiviCRM.
  $contributionParams = [];

  // We update these parameters regardless if it's a new contribution
  // or an existing contributions.
  $contributionParams['receive_date'] = CRM_Stripe_Api::getObjectParam('receive_date', $stripeInvoice);
  $contributionParams['contribution_status_id'] = CRM_Stripe_Api::getObjectParam('status_id', $stripeInvoice);
  $contributionParams['total_amount'] = CRM_Stripe_Api::getObjectParam('total_amount', $stripeInvoice);

  // Check if a contribution already exists.
  $contribution_id = NULL;
  if ($params['contribution_id']) {
    // From user input.
    $contribution_id = $params['contribution_id'];
  }
  else {
    // Check database.
    $c_results = \Civi\Api4\Contribution::get()
        ->addWhere('trxn_id', 'LIKE', '%'. $params['charge'].'%')
        ->addWhere('is_test', '=', $is_test)
        ->execute();
    $contribution = $c_results->first();
    if ($contribution) {
      $contribution_id = $contribution['id'];
    }
  }

  // If it exists, we update by adding the id. 
  if ($contribution_id) {
    $contributionParams['id'] = $contribution_id;
  }
  else {
    // We have to build all the parameters.
    $contributionParams['contact_id'] = $params['contact_id'];
    $contributionParams['total_amount'] = CRM_Stripe_Api::getObjectParam('amount', $stripeInvoice);
    $contributionParams['currency'] = CRM_Stripe_Api::getObjectParam('currency', $stripeInvoice);
    $contributionParams['receive_date'] = CRM_Stripe_Api::getObjectParam('receive_date', $stripeInvoice);
    $contributionParams['trxn_id'] = CRM_Stripe_Api::getObjectParam('charge_id', $stripeInvoice);
    $contributionParams['contribution_status_id'] = CRM_Stripe_Api::getObjectParam('status_id', $stripeInvoice);
    $contributionParams['payment_instrument_id'] = !empty($params['payment_instrument_id']) ? $params['payment_instrument_id'] : 'Credit Card';
    $contributionParams['financial_type_id'] = !empty($params['financial_type_id']) ? $params['financial_type_id'] : 'Donation';
    $contributionParams['is_test'] = isset($paymentProcessor['is_test']) && $paymentProcessor['is_test'] ? 1 : 0;
    $contributionParams['contribution_source'] = $sourceText;
    $contributionParams['is_test'] = $is_test;
    if ($contribution_recur_id) {
      $contributionParams['contribution_recur_id'] = $contribution_recur_id;
    }
  } 

  $contribution = civicrm_api3('Contribution', 'create', $contributionParams);
  return civicrm_api3_create_success($contribution['values']);
}