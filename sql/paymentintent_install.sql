CREATE TABLE IF NOT EXISTS `civicrm_stripe_paymentintent` (
  `id` int unsigned NOT NULL AUTO_INCREMENT  COMMENT 'Unique ID',
  `stripe_intent_id` varchar(255) COMMENT 'The Stripe PaymentIntent/SetupIntent/PaymentMethod ID',
  `contribution_id` int unsigned COMMENT 'FK ID from civicrm_contribution',
  `payment_processor_id` int unsigned COMMENT 'Foreign key to civicrm_payment_processor.id',
  `description` varchar(255) NULL COMMENT 'Description of this paymentIntent',
  `status` varchar(25) NULL COMMENT 'The status of the paymentIntent',
  `identifier` varchar(255) NULL COMMENT 'An identifier that we can use in CiviCRM to find the paymentIntent if we do not have the ID (eg. session key)',
  `contact_id` int unsigned COMMENT 'FK to Contact',
  `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When was paymentIntent created',
  `flags` varchar(100) NULL COMMENT 'Flags associated with this PaymentIntent (NC=no contributionID when doPayment called)',
  `referrer` varchar(255) NULL COMMENT 'HTTP referrer of this paymentIntent',
  `extra_data` varchar(255) NULL COMMENT 'Extra data collected to help with diagnostics (such as email, name)',
  PRIMARY KEY (`id`),
  UNIQUE INDEX `UI_stripe_intent_id` (stripe_intent_id),
  CONSTRAINT FK_civicrm_stripe_paymentintent_payment_processor_id FOREIGN KEY (`payment_processor_id`) REFERENCES `civicrm_payment_processor`(`id`) ON DELETE SET NULL,
  CONSTRAINT FK_civicrm_stripe_paymentintent_contact_id FOREIGN KEY (`contact_id`) REFERENCES `civicrm_contact`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
