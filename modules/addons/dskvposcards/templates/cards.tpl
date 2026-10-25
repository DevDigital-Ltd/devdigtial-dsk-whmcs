{if $status eq 'added'}<div class="alert alert-success">{if $bg}Картата е запазена.{else}Card saved.{/if}</div>{/if}
{if $status eq 'failed'}<div class="alert alert-danger">{if $bg}Картата не беше запазена. Проверете данните и опитайте отново.{else}The card was not saved. Check the details and try again.{/if}</div>{/if}
{if $status eq 'removed'}<div class="alert alert-info">{if $bg}Картата е премахната.{else}Card removed.{/if}</div>{/if}
{if $status eq 'autopay_on'}<div class="alert alert-success">{if $bg}Автоматичното плащане е включено.{else}Automatic payment is on.{/if}</div>{/if}
{if $status eq 'autopay_off'}<div class="alert alert-info">{if $bg}Автоматичното плащане е изключено.{else}Automatic payment is off.{/if}</div>{/if}

{if !$gatewayActive}
<div class="alert alert-warning">DSK V-POS is not active.</div>
{else}

<div class="panel panel-default card">
  <div class="panel-heading card-header"><h3 class="panel-title card-title">{if $bg}Запазени карти{else}Saved cards{/if}</h3></div>
  <div class="panel-body card-body">
    {if $cards}
    <table class="table">
      {foreach $cards as $c}
      <tr>
        <td>{$c.masked}</td>
        <td>{$c.expiry}</td>
        <td>{if $c.autopay}<span class="label label-success">{if $bg}Автоматично плащане{else}Autopay{/if}</span>{/if}</td>
        <td class="text-right">
          <form method="post" action="index.php?m=dskvposcards" style="display:inline">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="card_id" value="{$c.id}">
            {if !$c.autopay}<button name="do" value="autopay_on" class="btn btn-default btn-sm">{if $bg}Използвай за автоматично плащане{else}Use for autopay{/if}</button>{/if}
            <button name="do" value="remove" class="btn btn-danger btn-sm" onclick="return confirm('{if $bg}Да премахна ли картата?{else}Remove this card?{/if}')">{if $bg}Премахни{else}Remove{/if}</button>
          </form>
        </td>
      </tr>
      {/foreach}
    </table>
    {if $hasAutopay}
    <form method="post" action="index.php?m=dskvposcards">
      <input type="hidden" name="token" value="{$token}">
      <button name="do" value="autopay_off" class="btn btn-default btn-sm">{if $bg}Изключи автоматичното плащане{else}Turn off autopay{/if}</button>
    </form>
    <p class="text-muted"><small>{if $bg}Фактурите се плащат автоматично в деня на падежа, първо от кредита, после от картата.{else}Invoices are paid on the due date, first from your credit, then from the card.{/if}</small></p>
    {/if}
    {else}
    <p>{if $bg}Нямате запазени карти.{else}You have no saved cards.{/if}</p>
    {/if}
  </div>
</div>

<div class="panel panel-default card">
  <div class="panel-heading card-header"><h3 class="panel-title card-title">{if $bg}Добави карта{else}Add a card{/if}</h3></div>
  <div class="panel-body card-body">
    <form method="post" action="{$addAction}" autocomplete="on" style="max-width:420px">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="csrf" value="{$csrf}">
      <input type="hidden" name="browser_info" value="">
      <input class="form-control" style="margin-bottom:6px" name="card_holder" maxlength="26" autocomplete="cc-name" placeholder="{if $bg}Име на картодържателя{else}Cardholder name{/if}" required>
      <input class="form-control" style="margin-bottom:6px" name="card_number" inputmode="numeric" maxlength="23" autocomplete="cc-number" placeholder="{if $bg}Номер на карта{else}Card number{/if}" required>
      <div style="display:flex;gap:6px">
        <input class="form-control" style="margin-bottom:6px" name="card_month" inputmode="numeric" maxlength="2" autocomplete="cc-exp-month" placeholder="{if $bg}ММ{else}MM{/if}" required>
        <input class="form-control" style="margin-bottom:6px" name="card_year" inputmode="numeric" maxlength="4" autocomplete="cc-exp-year" placeholder="{if $bg}ГГГГ{else}YYYY{/if}" required>
        <input class="form-control" style="margin-bottom:6px" name="card_cvc" inputmode="numeric" maxlength="4" autocomplete="cc-csc" placeholder="CVC" required>
      </div>
      {literal}<script>(function(){var s=document.currentScript,i=s.closest("form").querySelector("input[name=browser_info]");i.value=JSON.stringify({language:navigator.language||"en",sw:screen.width,sh:screen.height,cd:screen.colorDepth,tz:new Date().getTimezoneOffset(),java:false});})();</script>{/literal}
      <button type="submit" class="btn btn-primary">{if $bg}Провери и запази{else}Verify and save{/if}</button>
      <p class="text-muted" style="margin-top:8px"><small>{if $bg}Банката прави кратко блокиране на малка сума, което се освобождава веднага. Изисква се 3-D Secure.{else}Your bank places a small hold that is released at once. 3-D Secure is required.{/if}</small></p>
    </form>
  </div>
</div>

<div class="panel panel-default card">
  <div class="panel-heading card-header"><h3 class="panel-title card-title">{if $bg}Кредит в акаунта{else}Account credit{/if}</h3></div>
  <div class="panel-body card-body">
    <p>{if $bg}Наличен кредит:{else}Available credit:{/if} <strong>{$credit}</strong></p>
    <a href="{$addFundsUrl}" class="btn btn-success">{if $bg}Добави кредит{else}Add credit{/if}</a>
    <p class="text-muted" style="margin-top:8px"><small>{if $bg}Кредитът се използва за плащане на фактури с бутона „Приложи кредит“ или автоматично.{else}Credit pays invoices with the "Apply credit" button or automatically.{/if}</small></p>
  </div>
</div>
{/if}
