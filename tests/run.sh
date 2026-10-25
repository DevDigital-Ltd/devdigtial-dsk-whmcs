#!/bin/sh
# Offline test: stages a fake WHMCS root, runs mock bank + module, asserts the flows.
# Usage: docker run --rm -v "$PWD/..":/src php:8.2-cli sh /src/tests/run.sh
set -e
R=/tmp/stage; S=/tmp/state; rm -rf $R $S; mkdir -p $R $S
cp -r /src/modules $R/modules
cp /src/tests/stubs/init.php $R/init.php; cp -r /src/tests/stubs/includes $R/includes
sed -i "s#https://uat.dskbank.bg/payment/rest/#http://127.0.0.1:8099/#" $R/modules/gateways/dskvpos/Api.php
export STATE_DIR=$S SYSTEM_URL=http://127.0.0.1:8098
php -S 127.0.0.1:8099 /src/tests/mock_gateway.php >/dev/null 2>&1 &
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8098 -t $R >/dev/null 2>&1 &
sleep 1
CB=$SYSTEM_URL/modules/gateways/callback
fail=0
ok() { if [ "$2" = "$3" ]; then echo "PASS $1"; else echo "FAIL $1: got '$2' want '$3'"; fail=1; fi; }
npay() { php -r '$s=json_decode(@file_get_contents("'$S'/state.json"),true);echo count($s["payments"]??[]);'; }
sig() { php -r '$k=hash_hmac("sha256","dskvpos-pay-v1","secret");echo hash_hmac("sha256",implode("|",[1001,'$1',"978"]),$k);'; }
loc() { sed -n 's/^Location: //Ip' | tr -d '\r'; }

# orders are created at the bank directly; the module never starts a payment without 3-D Secure
mkorder() { curl -s -d "userName=test-api&password=secret&orderNumber=$1&amount=$2&currency=$3" 127.0.0.1:8099/register.do; }
# 1 old hosted-page start is gone: no order, no redirect to a bank page
L=$(curl -si -d "action=pay&invoiceid=1001&amount=12000&currency=978&sig=$(sig 12000)" $CB/dskvpos.php | loc)
ok "old action=pay no longer starts a payment" "$L" "$SYSTEM_URL/viewinvoice.php?id=0"
ok "no order created by old action=pay" "$(curl -s 127.0.0.1:8099/_order?orderId=gw-0)" null
mkorder 1001-1 12000 978 >/dev/null
# 3 return before paying -> not credited
L=$(curl -si "$CB/dskvpos.php?invoiceid=1001&orderId=gw-0" | loc)
ok "unpaid return -> paymentfailed" "$L" "$SYSTEM_URL/viewinvoice.php?id=1001&paymentfailed=true"
ok "no payment recorded" $(npay) 0
# 4 return with other invoice id -> mismatch
curl -s "127.0.0.1:8099/_set?orderId=gw-0&status=2" >/dev/null
L=$(curl -si "$CB/dskvpos.php?invoiceid=2002&orderId=gw-0" | loc)
ok "foreign invoice rejected" "$L" "$SYSTEM_URL/viewinvoice.php?id=2002&paymentfailed=true"
ok "still no payment" $(npay) 0
# 5 paid return -> credited 120.00
L=$(curl -si "$CB/dskvpos.php?invoiceid=1001&orderId=gw-0" | loc)
ok "paid return -> paymentsuccess" "$L" "$SYSTEM_URL/viewinvoice.php?id=1001&paymentsuccess=true"
ok "one payment recorded" $(npay) 1
# 6 notification: bad checksum
c=$(curl -s -o /dev/null -w '%{http_code}' "$CB/dskvpos_notify.php?mdOrder=gw-0&operation=deposited&status=1&orderNumber=x&checksum=BAD")
ok "bad checksum rejected" $c 403
# 7 notification: good checksum, duplicate not double-credited
CS=$(php -r '$d=["mdOrder"=>"gw-0","operation"=>"deposited","orderNumber"=>"1001-1","status"=>"1"];ksort($d);$p="";foreach($d as $k=>$v)$p.="$k;$v;";echo strtoupper(hash_hmac("sha256",$p,"cbsecret"));')
curl -s "$CB/dskvpos_notify.php?mdOrder=gw-0&operation=deposited&status=1&orderNumber=1001-1&checksum=$CS" >/dev/null
ok "duplicate notification ignored" $(npay) 1
# 8 notification credits a second order when return page never reached
mkorder 1001-2 500 978 >/dev/null
curl -s "127.0.0.1:8099/_set?orderId=gw-1&status=2" >/dev/null
CS=$(php -r '$d=["mdOrder"=>"gw-1","operation"=>"deposited","status"=>"1"];ksort($d);$p="";foreach($d as $k=>$v)$p.="$k;$v;";echo strtoupper(hash_hmac("sha256",$p,"cbsecret"));')
curl -s "$CB/dskvpos_notify.php?mdOrder=gw-1&operation=deposited&status=1&checksum=$CS" >/dev/null
ok "notification credits payment" $(npay) 2
# 8b customer return after notification already credited: redirect, no duplicate, no blank page
L=$(curl -si "$CB/dskvpos.php?invoiceid=1001&orderId=gw-1" | loc)
ok "return after notify -> paymentsuccess" "$L" "$SYSTEM_URL/viewinvoice.php?id=1001&paymentsuccess=true"
ok "no duplicate after return" $(npay) 2
# 8c bank amount above invoice balance is not credited
mkorder 1001-3 50000 978 >/dev/null
curl -s "127.0.0.1:8099/_set?orderId=gw-2&status=2" >/dev/null
L=$(curl -si "$CB/dskvpos.php?invoiceid=1001&orderId=gw-2" | loc)
ok "overpaid order rejected" "$L" "$SYSTEM_URL/viewinvoice.php?id=1001&paymentfailed=true"
ok "still two payments" $(npay) 2
# 8d bank currency differs from invoice currency is not credited
mkorder 1001-4 100 840 >/dev/null
curl -s "127.0.0.1:8099/_set?orderId=gw-3&status=2" >/dev/null
L=$(curl -si "$CB/dskvpos.php?invoiceid=1001&orderId=gw-3" | loc)
ok "wrong currency rejected" "$L" "$SYSTEM_URL/viewinvoice.php?id=1001&paymentfailed=true"
ok "still two payments (currency)" $(npay) 2
# 8e return and notification at the same moment: credited once
mkorder 1001-5 100 978 >/dev/null
curl -s "127.0.0.1:8099/_set?orderId=gw-4&status=2" >/dev/null
CS=$(php -r '$d=["mdOrder"=>"gw-4","operation"=>"deposited","status"=>"1"];ksort($d);$p="";foreach($d as $k=>$v)$p.="$k;$v;";echo strtoupper(hash_hmac("sha256",$p,"cbsecret"));')
curl -s "$CB/dskvpos_notify.php?mdOrder=gw-4&operation=deposited&status=1&checksum=$CS" >/dev/null &
P1=$!
curl -s "$CB/dskvpos.php?invoiceid=1001&orderId=gw-4" >/dev/null &
P2=$!
wait $P1 $P2
ok "concurrent return+notify credits once" $(npay) 3
# 8f malformed orderId never reaches the bank
L=$(curl -si "$CB/dskvpos.php?invoiceid=1001&orderId=x%27%3Bdrop" | loc)
ok "malformed orderId ignored" "$L" "$SYSTEM_URL/viewinvoice.php?id=1001"
# 9 unsupported currency -> no button
php -r 'define("WHMCS",1);require "/src/modules/gateways/dskvpos.php";echo strpos(dskvpos_link(["currency"=>"JPY","invoiceid"=>1,"amount"=>"1","systemurl"=>"x","password"=>"p","langpaynow"=>"Pay"]),"not supported")!==false?"y":"n";' > /tmp/o; ok "unsupported currency message" $(cat /tmp/o) y
# 10 refund (staged module talks to mock bank; gw-0 is captured)
php -r 'define("WHMCS",1);require "'$R'/modules/gateways/dskvpos.php";$p=["userName"=>"test-api","password"=>"secret","testMode"=>1,"amount"=>"120.00"];echo dskvpos_refund($p+["transid"=>"gw-0","currency"=>"EUR"])["status"]," ",dskvpos_refund($p+["transid"=>"nope","currency"=>"EUR"])["status"]," ",dskvpos_refund(["amount"=>"500.00","transid"=>"gw-0","currency"=>"EUR"]+$p)["status"]," ",dskvpos_refund(["amount"=>"0","transid"=>"gw-0","currency"=>"EUR"]+$p)["status"]," ",dskvpos_refund(["currency"=>"USD"]+$p+["transid"=>"gw-0"])["status"];' > /tmp/o
ok "refund: ok / unknown order / over amount / zero / wrong currency" "$(cat /tmp/o)" "success declined declined declined declined"

# ---- Cards: pay with our own form, saved cards, verification, autopay -----------
csrf() { php -r '$k=hash_hmac("sha256","dskvpos-csrf-v1","secret");echo hash_hmac("sha256","",$k);'; }
sigf() { php -r '$k=hash_hmac("sha256","dskvpos-pay-v1","secret");echo hash_hmac("sha256",implode("|",['$1','$2',"978"]),$k);'; }
acsurl() { sed -n 's/.*<form method="post" action="\([^"]*\)".*/\1/p'; }
fld() { sed -n 's/.*name="'$1'" value="\([^"]*\)".*/\1/p'; }
oidf() { H=$(cat); case "$H" in *creq*) echo "$H" | fld creq | sed 's/creq-//';; *) echo "$H" | fld MD;; esac; }
ncards() { php -r '$s=json_decode(@file_get_contents("'$S'/state.json"),true);echo count($s["tables"]["mod_dskvpos_cards"]??[]);'; }
NEW="card_holder=A+B&card_number=4111111111111111&card_month=12&card_year=2030&card_cvc=123"
PAYF() { echo "action=pay&csrf=$(csrf)&invoiceid=$1&amount=$2&currency=978&sig=$(sigf $1 $2)"; }
CARDEP=$CB/dskvpos_card.php
# 12 missing / wrong csrf rejected
c=$(curl -s -o /dev/null -w '%{http_code}' -d "action=pay&csrf=bad&invoiceid=1002&amount=1500&currency=978&sig=$(sigf 1002 1500)&$NEW" $CARDEP)
ok "bad csrf rejected" $c 400
# 13 new card, saved: goes to 3-D Secure, order forced 3DS, bank got card
H=$(curl -si -A TestUA -b uid=1 -d "$(PAYF 1002 1500)&save_card=1&$NEW" --data-urlencode 'browser_info={"sw":1920,"sh":1080}' $CARDEP)
O1=$(echo "$H" | oidf)
ok "new card -> POST to issuer 3DS page" "$(echo "$H" | acsurl)" "https://uat.example/acs"
ok "3DS forced on order (FORCE_TDS)" "$(curl -s "127.0.0.1:8099/_order?orderId=$O1" | php -r '$o=json_decode(stream_get_contents(STDIN),true);echo $o["req"]["features"];')" FORCE_TDS
ok "browser data forwarded" "$(curl -s "127.0.0.1:8099/_order?orderId=$O1" | php -r '$o=json_decode(stream_get_contents(STDIN),true);$b=json_decode($o["browser"],true);echo $b["screenWidth"],$b["userAgent"];')" 1920TestUA
ok "card number not in module state" "$(grep -c 4111111111111111 $S/state.json || true)" 0
# 14 3DS done -> credited, card saved
curl -s "127.0.0.1:8099/_set?orderId=$O1&status=2" >/dev/null
L=$(curl -si "$CB/dskvpos.php?invoiceid=1002&orderId=$O1" | loc)
ok "card payment credited" "$L" "$SYSTEM_URL/viewinvoice.php?id=1002&paymentsuccess=true"
ok "payments after card pay" $(npay) 4
ok "card saved after payment" $(ncards) 1
# 15 saved card: needs login
L=$(curl -si -d "$(PAYF 1002 500)&card_id=1" $CARDEP | loc)
ok "saved card without login rejected" "$L" "$SYSTEM_URL/viewinvoice.php?id=1002&paymentfailed=true"
H=$(curl -si -b uid=1 -d "$(PAYF 1002 500)&card_id=1" $CARDEP)
ok "saved card -> 3DS page (tii F)" "$(echo "$H" | acsurl)" "https://uat.example/acs"
# 16 someone else's card id
L=$(curl -si -b uid=2 -d "$(PAYF 1002 500)&card_id=1" $CARDEP | loc)
ok "other client's card rejected" "$L" "$SYSTEM_URL/viewinvoice.php?id=1002&paymentfailed=true"
# 17 guest, no save: still 3DS
H=$(curl -si -d "$(PAYF 1002 500)&$NEW" $CARDEP)
ok "guest card -> 3DS page" "$(echo "$H" | acsurl)" "https://uat.example/acs"
# 18 bad Luhn / declined card
L=$(curl -si -d "$(PAYF 1002 500)&card_holder=A&card_number=4111111111111112&card_month=12&card_year=2030&card_cvc=123" $CARDEP | loc)
ok "bad card number rejected" "$L" "$SYSTEM_URL/viewinvoice.php?id=1002&paymentfailed=true"
L=$(curl -si -d "$(PAYF 1002 500)&card_holder=A&card_number=4000000000000002&card_month=12&card_year=2030&card_cvc=123" $CARDEP | loc)
ok "bank decline -> paymentfailed" "$L" "$SYSTEM_URL/viewinvoice.php?id=1002&paymentfailed=true"
# 19 add card: login required
L=$(curl -si -d "action=add&csrf=$(csrf)&card_holder=A&card_number=5555555555554444&card_month=12&card_year=2030&card_cvc=123" $CARDEP | loc)
ok "add card needs login" "$L" "$SYSTEM_URL/clientarea.php"
H=$(curl -si -b uid=1 -d "action=add&csrf=$(csrf)&card_holder=A&card_number=5555555555554444&card_month=12&card_year=2030&card_cvc=123" $CARDEP)
O2=$(echo "$H" | oidf)
ok "add card (3DS2 creq) -> issuer page" "$(echo "$H" | acsurl)|$(echo "$H" | fld creq)" "https://uat.example/acs2|creq-$O2"
ok "verification is a pre-authorisation" "$(curl -s "127.0.0.1:8099/_order?orderId=$O2" | php -r '$o=json_decode(stream_get_contents(STDIN),true);echo $o["pre"]?"y":"n";')" y
L=$(curl -si "$CARDEP?action=return&orderId=$O2" | loc)
ok "return before 3DS done -> failed" "$L" "$SYSTEM_URL/index.php?m=dskvposcards&status=failed"
curl -s "127.0.0.1:8099/_set?orderId=$O2&status=1" >/dev/null
L=$(curl -si "$CARDEP?action=return&orderId=$O2" | loc)
ok "return after 3DS -> added" "$L" "$SYSTEM_URL/index.php?m=dskvposcards&status=added"
ok "second card saved" $(ncards) 2
ok "hold released" "$(curl -s "127.0.0.1:8099/_order?orderId=$O2" | php -r '$o=json_decode(stream_get_contents(STDIN),true);echo $o["orderStatus"];')" 3
L=$(curl -si "$CARDEP?action=return&orderId=$O2" | loc)
ok "refresh of return page still added" "$L" "$SYSTEM_URL/index.php?m=dskvposcards&status=added"
ok "no duplicate card on refresh" $(ncards) 2
ok "no money captured by verification" $(npay) 4

# ---- 3-D Secure 2 with the 3DS method step (no browser data), stricter mode, no non-3DS path -------
H=$(curl -si -d "$(PAYF 1002 500)&card_holder=A&card_number=4000000000003220&card_month=12&card_year=2030&card_cvc=123" $CARDEP)
ok "3DS2: method page posts to 3DS server" "$(echo "$H" | sed -n 's/.*<form id="m" method="post" action="\([^"]*\)".*/\1/p')" "https://uat.example/method"
BLOB=$(echo "$H" | sed -n 's/.*name="blob" value="\([^"]*\)".*/\1/p')
ok "3DS2: card not readable in the page" "$(echo "$H" | grep -c 4000000000003220 || true)" 0
H2=$(curl -si -d "action=3ds2&blob=$BLOB" $CARDEP)
ok "3DS2: second call -> challenge to issuer" "$(echo "$H2" | acsurl)|$(echo "$H2" | fld creq | cut -c1-5)" "https://uat.example/acs3|creq-"
c=$(curl -s -o /dev/null -w '%{http_code}' -d "action=3ds2&blob=${BLOB}x" $CARDEP)
L=$(curl -si -d "action=3ds2&blob=${BLOB}x" $CARDEP | loc)
ok "3DS2: tampered blob rejected" "$L" "$SYSTEM_URL/clientarea.php"
ok "3DS2: notification page notifies parent" "$(curl -s "$CARDEP?action=3ds2m" | grep -c dskvposDone)" 1
# stricter mode is sent to the bank
FT=$(php -r 'require "'$R'/modules/gateways/dskvpos/Api.php";$a=new WHMCS\Module\Gateway\DskVpos\Api(["userName"=>"test-api","password"=>"secret","testMode"=>1,"tdsMode"=>"FORCE_FULL_TDS"]);echo $a->register("1002-9",100,"978","r","f","d","en",null,null,[],true)["orderId"];')
ok "FORCE_FULL_TDS mode reaches the bank" "$(curl -s "127.0.0.1:8099/_order?orderId=$FT" | php -r '$o=json_decode(stream_get_contents(STDIN),true);echo $o["req"]["features"];')" FORCE_FULL_TDS
# every payment started by the module asks for 3DS
ok "all module-started orders force 3DS" "$(php -r '$o=json_decode(file_get_contents("'$S'/orders.json"),true);$n=0;foreach($o as $k=>$x){if(isset($x["req"]["features"])===false&&strpos($x["orderNumber"],"1001-")!==0)$n++;}echo $n;')" 0

# 20 autopay: bank declines first, a later day it succeeds
PHPRUN() { php -r 'define("ROOTDIR","'$R'");require "'$R'/init.php";require "'$R'/modules/gateways/dskvpos/Autopay.php";'"$1"; }
mails() { php -r '$s=json_decode(file_get_contents("'$S'/state.json"),true);echo count(array_filter($s["mail"]??[],function($m){return strpos($m,"Payment Failed")!==false;}));'; }
touch $S/decline
PHPRUN 'dskvpos_setAutopay(1, 1); dskvpos_runAutopay();' >/dev/null
ok "autopay declined: nothing credited" $(npay) 4
ok "autopay declined: customer emailed per invoice" $(mails) 2
rm $S/decline
PHPRUN 'dskvpos_runAutopay();' >/dev/null
ok "autopay not retried same day" $(npay) 4
PHPRUN 'foreach ([1002,1003] as $i) \WHMCS\Database\Capsule::table("mod_dskvpos_autopay")->where("invoice_id",$i)->update(["last_attempt"=>"2000-01-01"]);' >/dev/null
PHPRUN 'dskvpos_runAutopay();' >/dev/null
ok "autopay next day charges both invoices, no 3DS" $(npay) 6
PHPRUN 'foreach ([1002,1003] as $i) \WHMCS\Database\Capsule::table("mod_dskvpos_autopay")->where("invoice_id",$i)->update(["last_attempt"=>"2000-01-01"]);dskvpos_runAutopay();' >/dev/null
ok "paid invoices not charged again" $(npay) 6
# 21 autopay turned off: no charge
PHPRUN 'dskvpos_setAutopay(1, null);' >/dev/null
ok "autopay off flag" "$(php -r '$s=json_decode(file_get_contents("'$S'/state.json"),true);echo array_sum(array_column($s["tables"]["mod_dskvpos_cards"],"autopay"));')" 0
# 22 remove card
PHPRUN 'dskvpos_deleteCard(1, 1);' >/dev/null
ok "card removed" $(ncards) 1
# 11 return endpoint is rate limited per IP
n=0; for i in $(seq 1 40); do c=$(curl -s -o /dev/null -w '%{http_code}' "$CB/dskvpos.php?invoiceid=1001&orderId=gw-0"); [ "$c" = 429 ] && n=$((n+1)); done
ok "rate limit returns 429" $([ $n -gt 0 ] && echo y || echo n) y
exit $fail
