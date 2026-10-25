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

## Supported currencies

EUR, BGN, USD, GBP. The invoice currency must match the currency the client is billed in.

## Safety checks

- Payment result is always re-read from the bank, never trusted from the URL.
- A payment is credited only if the bank amount and currency match the invoice and the amount does not exceed the balance due.
- If the bank captured money but WHMCS cannot credit it, an entry is written to **Utilities → Logs → Activity Log** and the Gateway Log.

## Requirements

- WHMCS 8+, PHP 7.4+ with the `curl` extension.
- HTTPS and a correct **WHMCS System URL** (System Settings → General).

## Tests

```
docker run --rm -v "$PWD":/src php:8.2-cli sh /src/tests/run.sh
```
