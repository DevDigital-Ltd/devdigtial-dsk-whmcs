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
php -S 127.0.0.1:8098 -t $R >/dev/null 2>&1 &
sleep 1
CB=$SYSTEM_URL/modules/gateways/callback
fail=0
ok() { if [ "$2" = "$3" ]; then echo "PASS $1"; else echo "FAIL $1: got '$2' want '$3'"; fail=1; fi; }
npay() { php -r '$s=json_decode(@file_get_contents("'$S'/state.json"),true);echo count($s["payments"]??[]);'; }
sig() { php -r 'echo hash_hmac("sha256",implode("|",[1001,'$1',"978"]),"secret");'; }
loc() { sed -n 's/^Location: //Ip' | tr -d '\r'; }

# 1 bad signature rejected
c=$(curl -s -o /dev/null -w '%{http_code}' -d "action=pay&invoiceid=1001&amount=1&currency=978&sig=bad" $CB/dskvpos.php)
ok "tampered form rejected" $c 400
# 2 signed pay -> redirect to bank form
L=$(curl -si -d "action=pay&invoiceid=1001&amount=12000&currency=978&sig=$(sig 12000)" $CB/dskvpos.php | loc)
ok "redirect to formUrl" "$L" "https://uat.example/pay?mdOrder=gw-0"
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
L=$(curl -si -d "action=pay&invoiceid=1001&amount=500&currency=978&sig=$(sig 500)" $CB/dskvpos.php | loc)
curl -s "127.0.0.1:8099/_set?orderId=gw-1&status=2" >/dev/null
CS=$(php -r '$d=["mdOrder"=>"gw-1","operation"=>"deposited","status"=>"1"];ksort($d);$p="";foreach($d as $k=>$v)$p.="$k;$v;";echo strtoupper(hash_hmac("sha256",$p,"cbsecret"));')
curl -s "$CB/dskvpos_notify.php?mdOrder=gw-1&operation=deposited&status=1&checksum=$CS" >/dev/null
ok "notification credits payment" $(npay) 2
# 8b customer return after notification already credited: redirect, no duplicate, no blank page
L=$(curl -si "$CB/dskvpos.php?invoiceid=1001&orderId=gw-1" | loc)
ok "return after notify -> paymentsuccess" "$L" "$SYSTEM_URL/viewinvoice.php?id=1001&paymentsuccess=true"
ok "no duplicate after return" $(npay) 2
# 8c bank amount above invoice balance is not credited
curl -s -o /dev/null -d "action=pay&invoiceid=1001&amount=50000&currency=978&sig=$(sig 50000)" $CB/dskvpos.php
curl -s "127.0.0.1:8099/_set?orderId=gw-2&status=2" >/dev/null
L=$(curl -si "$CB/dskvpos.php?invoiceid=1001&orderId=gw-2" | loc)
ok "overpaid order rejected" "$L" "$SYSTEM_URL/viewinvoice.php?id=1001&paymentfailed=true"
ok "still two payments" $(npay) 2
# 8d bank currency differs from invoice currency is not credited
S840=$(php -r 'echo hash_hmac("sha256",implode("|",[1001,100,"840"]),"secret");')
curl -s -o /dev/null -d "action=pay&invoiceid=1001&amount=100&currency=840&sig=$S840" $CB/dskvpos.php
curl -s "127.0.0.1:8099/_set?orderId=gw-3&status=2" >/dev/null
L=$(curl -si "$CB/dskvpos.php?invoiceid=1001&orderId=gw-3" | loc)
ok "wrong currency rejected" "$L" "$SYSTEM_URL/viewinvoice.php?id=1001&paymentfailed=true"
ok "still two payments (currency)" $(npay) 2
# 9 unsupported currency -> no button
php -r 'define("WHMCS",1);require "/src/modules/gateways/dskvpos.php";echo strpos(dskvpos_link(["currency"=>"JPY","invoiceid"=>1,"amount"=>"1","systemurl"=>"x","password"=>"p","langpaynow"=>"Pay"]),"not supported")!==false?"y":"n";' > /tmp/o; ok "unsupported currency message" $(cat /tmp/o) y
# 10 refund (staged module talks to mock bank; gw-0 is captured)
php -r 'define("WHMCS",1);require "'$R'/modules/gateways/dskvpos.php";$p=["userName"=>"test-api","password"=>"secret","testMode"=>1,"amount"=>"120.00"];echo dskvpos_refund($p+["transid"=>"gw-0"])["status"]," ",dskvpos_refund($p+["transid"=>"nope"])["status"];' > /tmp/o
ok "refund success / declined" "$(cat /tmp/o)" "success declined"
exit $fail
