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

/**
 * Populate the CiviCRM civicrm_paymentprocessor_webhook with Stripe events.
 *
 * This api will take all stripe events known to Stripe that are of the type
 * invoice.payment_succeeded and add them * to the civicrm_paymentprocessor_webhook table.
 * It will not add an event that has already been added, so it can be run multiple times.
 * Once added, they will be automatically processed by the Job.process_paymentprocessor_webhooks api call.
 */

use CRM_Stripe_ExtensionUtil as E;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentprocessorWebhook;

/**
 * Stripe.Populatewebhookqueue API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_Populatewebhookqueue_spec(&$spec) {
  $spec['ppid']['title'] = E::ts('The id of the payment processor.');
  $spec['type']['title'] = E::ts('The event type - defaults to invoice.payment_succeeded.');
  $spec['type']['api.default'] = 'invoice.payment_succeeded';
}

/**
 * Stripe.Populatewebhookqueue API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \API_Exception
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_stripe_Populatewebhookqueue($params) {
  if (!$params['ppid']) {
    // By default, select the live stripe processor (we expect there to be only one).
    $paymentProcessors = PaymentProcessor::get(FALSE)
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('is_test', '=', FALSE)
      ->addWhere('payment_processor_type_id:name', '=', 'Stripe')
      ->execute();
    if ($paymentProcessors->rowCount !== 1) {
      throw new API_Exception("Expected one live Stripe payment processor, but found none or more than one. Please specify ppid=.", 2234);
    }
    else {
      $params['ppid'] = $paymentProcessors->first()['id'];
    }
  }

  $listEventsParams['limit'] = 100;
  $listEventsParams['ppid'] = $params['ppid'];
  $items = [];
  $last_item = NULL;
  while(1) {
    if ($last_item) {
      $listEventsParams['starting_after'] = $last_item['id'];
    }
    $events = civicrm_api3('Stripe', 'Listevents', $listEventsParams)['values'];

    if (count($events) == 0) {
      // No more!
      break;
    }
    $items = array_merge($items, $events);
    $last_item = end($events);

    // Support the standard API3 limit clause
    if (isset($params['options']['limit']) && $params['options']['limit'] > 0 && count($items) >= $params['options']['limit']) {
      break;
    }
  }

  $results = [];
  $eventIDs = CRM_Utils_Array::collect('id', $items);

  $paymentprocessorWebhookEventIDs = PaymentprocessorWebhook::get(FALSE)
    ->addWhere('payment_processor_id', '=', $params['ppid'])
    ->addWhere('event_id', 'IN', $eventIDs)
    ->execute()->column('event_id');

  $missingEventIDs = array_diff($eventIDs, $paymentprocessorWebhookEventIDs);

  foreach($items as $item) {
    if (!in_array($item['id'], $missingEventIDs)) {
      continue;
    }

    $webhookUniqueIdentifier = ($item['charge'] ?? '') . ':' . ($item['invoice'] ?? '') . ':' . ($item['subscription'] ?? '');
    // In mjwshared 1.1 status defaults to NULL. In 1.2 status defaults to "new".
    PaymentprocessorWebhook::create(FALSE)
      ->addValue('payment_processor_id', $params['ppid'])
      ->addValue('trigger', $item['type'])
      ->addValue('identifier', $webhookUniqueIdentifier)
      ->addValue('event_id', $item['id'])
      ->execute();

    $results[] = $item['id'];
  }
  return civicrm_api3_create_success($results);
}
