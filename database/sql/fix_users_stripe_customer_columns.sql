-- Hostinger / phpMyAdmin: Stripe saved-card columns on users
-- Fixes: Unknown column 'stripe_customer_id' in 'field list'
-- Safe to re-run: ignore "Duplicate column" / "Duplicate key" errors.

ALTER TABLE `users`
  ADD COLUMN `stripe_customer_id` varchar(255) NULL;

ALTER TABLE `users`
  ADD COLUMN `stripe_default_payment_method_id` varchar(255) NULL;

-- Unique index for Stripe customer id (ignore if already exists)
ALTER TABLE `users`
  ADD UNIQUE KEY `users_stripe_customer_id_unique` (`stripe_customer_id`);
