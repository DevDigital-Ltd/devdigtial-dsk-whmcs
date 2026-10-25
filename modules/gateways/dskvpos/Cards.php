<?php

use WHMCS\Database\Capsule;

/**
 * Saved cards. Only the bank's binding id and the masked number are stored, never card data.
 */

function dskvpos_ensureSchema()
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_dskvpos_cards')) {
        $schema->create('mod_dskvpos_cards', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('client_id')->index();
            $t->string('binding_id', 64)->unique();
            $t->string('masked_pan', 32);
            $t->string('expiry', 6);
            $t->boolean('autopay')->default(0);
            $t->timestamp('created_at')->nullable();
        });
    }
    if (!$schema->hasTable('mod_dskvpos_autopay')) {
        $schema->create('mod_dskvpos_autopay', function ($t) {
            $t->unsignedInteger('invoice_id')->primary();
            $t->unsignedInteger('attempts')->default(0);
            $t->date('last_attempt')->nullable();
            $t->string('last_error', 255)->nullable();
        });
    }
}

/** Client reference sent to the bank; a binding is only valid for the client it was made for. */
function dskvpos_clientRef($clientId)
{
    return 'whmcs-' . (int) $clientId;
}

/** Logged-in client id, or 0. */
function dskvpos_currentClientId()
{
    $client = (new \WHMCS\Authentication\CurrentUser())->client();
    return $client ? (int) $client->id : 0;
}

function dskvpos_clientCards($clientId)
{
    dskvpos_ensureSchema();
    return Capsule::table('mod_dskvpos_cards')->where('client_id', (int) $clientId)->orderBy('id')->get();
}

function dskvpos_cardOf($clientId, $cardId)
{
    dskvpos_ensureSchema();
    return Capsule::table('mod_dskvpos_cards')->where('client_id', (int) $clientId)->where('id', (int) $cardId)->first();
}

/**
 * Stores the binding found in a captured/authorised order status.
 *
 * @return int|null card id
 */
function dskvpos_saveBinding($clientId, array $status)
{
    $bindingId = (string) ($status['bindingInfo']['bindingId'] ?? '');
    if ($bindingId === '' || (string) ($status['bindingInfo']['clientId'] ?? '') !== dskvpos_clientRef($clientId)) {
        return null;
    }
    dskvpos_ensureSchema();

    $masked = (string) ($status['cardAuthInfo']['maskedPan'] ?? '');
    $expiry = (string) ($status['cardAuthInfo']['expiration'] ?? '');

    // The same physical card added again replaces the old binding.
    $dup = Capsule::table('mod_dskvpos_cards')->where('client_id', (int) $clientId)
        ->where('masked_pan', $masked)->where('expiry', $expiry)->first();
    if ($dup) {
        Capsule::table('mod_dskvpos_cards')->where('id', $dup->id)->update(['binding_id' => $bindingId]);
        return (int) $dup->id;
    }
    $same = Capsule::table('mod_dskvpos_cards')->where('binding_id', $bindingId)->first();
    if ($same) {
        return (int) $same->id;
    }

    return (int) Capsule::table('mod_dskvpos_cards')->insertGetId([
        'client_id' => (int) $clientId,
        'binding_id' => $bindingId,
        'masked_pan' => $masked,
        'expiry' => $expiry,
        'autopay' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function dskvpos_deleteCard($clientId, $cardId)
{
    Capsule::table('mod_dskvpos_cards')->where('client_id', (int) $clientId)->where('id', (int) $cardId)->delete();
}

/** One autopay card per client. $cardId null turns autopay off. */
function dskvpos_setAutopay($clientId, $cardId)
{
    Capsule::table('mod_dskvpos_cards')->where('client_id', (int) $clientId)->update(['autopay' => 0]);
    if ($cardId) {
        Capsule::table('mod_dskvpos_cards')->where('client_id', (int) $clientId)->where('id', (int) $cardId)->update(['autopay' => 1]);
    }
}

/** Validates posted card fields. @return array|null normalised card, null when invalid */
function dskvpos_parseCard(array $post)
{
    $pan = preg_replace('/\D/', '', (string) ($post['card_number'] ?? ''));
    $cvc = preg_replace('/\D/', '', (string) ($post['card_cvc'] ?? ''));
    $month = (int) ($post['card_month'] ?? 0);
    $year = (int) ($post['card_year'] ?? 0);
    $holder = trim((string) ($post['card_holder'] ?? ''));

    if ($year < 100 && $year > 0) {
        $year += 2000;
    }
    $expired = $year < (int) date('Y') || ($year === (int) date('Y') && $month < (int) date('n'));

    if (strlen($pan) < 13 || strlen($pan) > 19 || !\WHMCS\Module\Gateway\DskVpos\Api::luhn($pan)
        || !preg_match('/^\d{3,4}$/', $cvc) || $month < 1 || $month > 12 || $expired
        || $holder === '' || strlen($holder) > 26) {
        return null;
    }

    return ['pan' => $pan, 'cvc' => $cvc, 'month' => sprintf('%02d', $month), 'year' => (string) $year, 'holder' => $holder];
}

/** Two-language UI strings (bg when the client language is Bulgarian). */
function dskvpos_t($key, $lang = 'en')
{
    static $s = [
        'pay' => ['en' => 'Pay by card', 'bg' => 'Плати с карта'],
        'saved' => ['en' => 'Saved card', 'bg' => 'Запазена карта'],
        'newcard' => ['en' => 'New card', 'bg' => 'Нова карта'],
        'holder' => ['en' => 'Cardholder name', 'bg' => 'Име на картодържателя'],
        'number' => ['en' => 'Card number', 'bg' => 'Номер на карта'],
        'month' => ['en' => 'MM', 'bg' => 'ММ'],
        'year' => ['en' => 'YYYY', 'bg' => 'ГГГГ'],
        'cvc' => ['en' => 'CVC', 'bg' => 'CVC'],
        'save' => ['en' => 'Save this card for future payments', 'bg' => 'Запази картата за бъдещи плащания'],
        'secure' => ['en' => 'Every payment is verified with 3-D Secure by your bank.', 'bg' => 'Всяко плащане се потвърждава с 3-D Secure от вашата банка.'],
        'invalid' => ['en' => 'Card details are not valid.', 'bg' => 'Данните на картата не са валидни.'],
    ];
    return $s[$key][$lang === 'bg' ? 'bg' : 'en'] ?? $key;
}
