-- Production helper (Hostinger / phpMyAdmin): reset misassigned account
-- currencies. Existing users with euro marketplace history (wallet
-- transactions, deposits, orders / order items) who were persisted as
-- GBP or USD by the old resolver (legacy header cookie, billing country,
-- or IP) should be EUR.
--
-- Safe to run by hand. Review the two SELECTs first, then run the UPDATE.
-- The UPDATE never touches:
--   * users whose display_currency is already EUR or NULL
--   * users with a GBP/USD wallet that has a non-zero balance or reserved
--     balance (list them in the manual-review SELECT)
--   * users with a GBP/USD deposit (charge_currency) — also listed for review
--
-- Re-run the preview SELECT after the UPDATE to confirm.

-- 1) Preview: euro history + GBP/USD display currency, safe to reset.
SELECT
  u.id,
  u.email,
  u.country,
  u.display_currency,
  EXISTS (SELECT 1 FROM deposit_requests dr WHERE dr.user_id = u.id) AS has_deposit,
  EXISTS (SELECT 1 FROM wallet_transactions wt WHERE wt.user_id = u.id) AS has_wallet_tx,
  EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id) AS has_order,
  EXISTS (
    SELECT 1
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE o.user_id = u.id
  ) AS has_order_item_as_buyer,
  EXISTS (
    SELECT 1
    FROM order_items oi
    INNER JOIN sites s ON s.id = oi.site_id
    WHERE s.publisher_id = u.id
  ) AS has_order_item_as_publisher
FROM users u
WHERE UPPER(IFNULL(u.display_currency, '')) IN ('GBP', 'USD')
  AND (
    EXISTS (SELECT 1 FROM deposit_requests dr WHERE dr.user_id = u.id)
    OR EXISTS (SELECT 1 FROM wallet_transactions wt WHERE wt.user_id = u.id)
    OR EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)
    OR EXISTS (
      SELECT 1
      FROM order_items oi
      INNER JOIN orders o ON o.id = oi.order_id
      WHERE o.user_id = u.id
    )
    OR EXISTS (
      SELECT 1
      FROM order_items oi
      INNER JOIN sites s ON s.id = oi.site_id
      WHERE s.publisher_id = u.id
    )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM wallets w
    WHERE w.user_id = u.id
      AND UPPER(IFNULL(w.currency, '')) IN ('GBP', 'USD')
      AND (
        IFNULL(w.balance, 0) <> 0
        OR IFNULL(w.reserved_balance, 0) <> 0
      )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM deposit_requests dr
    WHERE dr.user_id = u.id
      AND UPPER(IFNULL(dr.charge_currency, '')) IN ('GBP', 'USD')
  )
ORDER BY u.id;

-- 2) Manual review: same euro-history + GBP/USD currency, but they have a
--    GBP/USD wallet with a non-zero balance/reserve OR a GBP/USD deposit.
--    Do not auto-update these — check each account.
SELECT
  u.id,
  u.email,
  u.country,
  u.display_currency,
  EXISTS (
    SELECT 1
    FROM wallets w
    WHERE w.user_id = u.id
      AND UPPER(IFNULL(w.currency, '')) IN ('GBP', 'USD')
      AND (
        IFNULL(w.balance, 0) <> 0
        OR IFNULL(w.reserved_balance, 0) <> 0
      )
  ) AS has_nonzero_gbp_usd_wallet,
  EXISTS (
    SELECT 1
    FROM deposit_requests dr
    WHERE dr.user_id = u.id
      AND UPPER(IFNULL(dr.charge_currency, '')) IN ('GBP', 'USD')
  ) AS has_gbp_usd_deposit
FROM users u
WHERE UPPER(IFNULL(u.display_currency, '')) IN ('GBP', 'USD')
  AND (
    EXISTS (SELECT 1 FROM deposit_requests dr WHERE dr.user_id = u.id)
    OR EXISTS (SELECT 1 FROM wallet_transactions wt WHERE wt.user_id = u.id)
    OR EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)
    OR EXISTS (
      SELECT 1
      FROM order_items oi
      INNER JOIN orders o ON o.id = oi.order_id
      WHERE o.user_id = u.id
    )
    OR EXISTS (
      SELECT 1
      FROM order_items oi
      INNER JOIN sites s ON s.id = oi.site_id
      WHERE s.publisher_id = u.id
    )
  )
  AND (
    EXISTS (
      SELECT 1
      FROM wallets w
      WHERE w.user_id = u.id
        AND UPPER(IFNULL(w.currency, '')) IN ('GBP', 'USD')
        AND (
          IFNULL(w.balance, 0) <> 0
          OR IFNULL(w.reserved_balance, 0) <> 0
        )
    )
    OR EXISTS (
      SELECT 1
      FROM deposit_requests dr
      WHERE dr.user_id = u.id
        AND UPPER(IFNULL(dr.charge_currency, '')) IN ('GBP', 'USD')
    )
  )
ORDER BY u.id;

-- 3) Reset the preview set to EUR. Same WHERE as SELECT 1.
UPDATE users u
SET u.display_currency = 'EUR'
WHERE UPPER(IFNULL(u.display_currency, '')) IN ('GBP', 'USD')
  AND (
    EXISTS (SELECT 1 FROM deposit_requests dr WHERE dr.user_id = u.id)
    OR EXISTS (SELECT 1 FROM wallet_transactions wt WHERE wt.user_id = u.id)
    OR EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)
    OR EXISTS (
      SELECT 1
      FROM order_items oi
      INNER JOIN orders o ON o.id = oi.order_id
      WHERE o.user_id = u.id
    )
    OR EXISTS (
      SELECT 1
      FROM order_items oi
      INNER JOIN sites s ON s.id = oi.site_id
      WHERE s.publisher_id = u.id
    )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM wallets w
    WHERE w.user_id = u.id
      AND UPPER(IFNULL(w.currency, '')) IN ('GBP', 'USD')
      AND (
        IFNULL(w.balance, 0) <> 0
        OR IFNULL(w.reserved_balance, 0) <> 0
      )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM deposit_requests dr
    WHERE dr.user_id = u.id
      AND UPPER(IFNULL(dr.charge_currency, '')) IN ('GBP', 'USD')
  );
