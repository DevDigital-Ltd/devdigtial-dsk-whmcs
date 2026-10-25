# DSK Bank V-POS gateway for WHMCS

## Install

1. Copy the `modules/` folder into your WHMCS root (merge with the existing `modules/`).
2. In WHMCS admin open **System Settings → Payment Gateways → All Payment Gateways** and activate **DSK Bank V-POS**.
3. Fill in the settings on that page:
   - **API username** and **API password**: merchant API account from DSK (username ends with `-api`).
   - **Callback secret**: symmetric key from DSK support.
   - **Test mode**: uses the `uat.dskbank.bg` sandbox. Turn off for live.
   - **Notification URL**: shown read-only on the same page. Send it to DSK support.
4. Save. Make a test payment with Test mode on, then turn Test mode off.

## Cards, autopay, credit

3-D Secure mode (gateway setting): `FORCE_TDS` fails the payment if the card cannot do 3-D Secure. `FORCE_FULL_TDS` also needs the issuer to confirm the cardholder (stricter, more declines). There is no payment path without 3-D Secure; the old hosted-page start was removed.

Customers pay on our own form on the invoice page; they are not sent to a DSK page. Every payment forces 3-D Secure (`features=FORCE_TDS`: the payment fails if the card cannot do 3-D Secure); the customer only visits the issuer's 3-D Secure page.

1. Activate the addon **System Settings → Addon Modules → DSK V-POS Cards** (creates the tables, adds *Billing → Cards and payments*).
2. Enable **Setup → General Settings → Credit → Enable Add Funds** so customers can top up credit. Credit pays invoices with WHMCS "Apply credit".
3. Customers save a card on *Cards and payments* (a small hold, setting **Card verification amount**, is authorised with 3-D Secure and released at once) or tick "Save card" when paying an invoice.
4. **Autopay**: one card per client. The daily cron applies credit first, then charges the card for due DSK V-POS invoices, up to 3 days. Failures send WHMCS's *Credit Card Payment Failed* email and are written to the Activity Log.

DSK must enable these for the merchant account:
- direct card payments (`paymentorder.do`) and 3-D Secure,
- card bindings (saved cards) and merchant-initiated payments with a stored card (`tii=U`),
- `clientBrowserInfo` (3-D Secure 2 from our own form). Tick **Send browser data (3-D Secure 2)** in the gateway settings. If it is off, the browser runs the 3DS method step instead (card data then travels in a 10-minute encrypted blob through the customer's own browser, never stored on the server).

Only the bank's binding id and the masked number are stored. Card numbers pass through the web server to the bank and are never logged or stored. This puts the server in PCI DSS scope (SAQ D): serve over HTTPS only.

Autopay charges have no customer present, so no 3-D Secure step is possible; the card was verified with 3-D Secure when saved.

## Refunds

Use **Refund** on the transaction in the invoice (full or partial). Choose *Refund to card* to send the money back through the bank, or *Add to credit* to give the client account credit (no bank call). The module checks with the bank that the order is captured, the amount does not exceed what was captured and the currency matches.

## Supported currencies

EUR, USD, GBP. The invoice currency must match the currency the client is billed in.

## Safety checks

- Payment result is always re-read from the bank, never trusted from the URL.
- A payment is credited only if the bank amount and currency match the invoice and the amount does not exceed the balance due.
- If the bank captured money but WHMCS cannot credit it, an entry is written to **Utilities → Logs → Activity Log** and the Gateway Log.

## Requirements

- WHMCS 8+, PHP 7.4+ with the `curl` and `openssl` extensions.
- HTTPS and a correct **WHMCS System URL** (System Settings → General).

## Tests

```
docker run --rm -v "$PWD":/src php:8.2-cli sh /src/tests/run.sh
```

## Provider

DevDigital Ltd. See `LICENSE`.
