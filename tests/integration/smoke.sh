#!/usr/bin/env bash
# CP smoke test: logs in to the harness and requests every Carrier screen, reporting status codes.
# Needs fixtures from smoke-fixtures.php (ids in .smoke-state.json).
set -u
STATE=$(cat "$(dirname "$0")/.smoke-state.json")
CONN=$(echo "$STATE" | python3 -c 'import json,sys;print(json.load(sys.stdin)["connection"])')
METHOD=$(echo "$STATE" | python3 -c 'import json,sys;print(json.load(sys.stdin)["method"])')
ORDER=$(echo "$STATE" | python3 -c 'import json,sys;print(json.load(sys.stdin)["orders"][0])')
ORDER2=$(echo "$STATE" | python3 -c 'import json,sys;print(json.load(sys.stdin)["orders"][1])')
docker exec ddev-plugin-testing-web bash -c "
cd /tmp; rm -f jar
B=http://127.0.0.1
TOKEN=\$(curl -s -c jar -b jar \$B/admin/login | grep -o 'name=\"CRAFT_CSRF_TOKEN\" value=\"[^\"]*\"' | head -1 | sed 's/.*value=\"//;s/\"//')
curl -s -c jar -b jar -o /dev/null -w 'login %{http_code}\n' -H 'Accept: application/json' --data-urlencode \"CRAFT_CSRF_TOKEN=\$TOKEN\" --data 'loginName=admin&password=claudepassword' \$B/admin/actions/users/login
for path in carrier carrier/shipments carrier/problems carrier/pickups carrier/methods carrier/methods/new carrier/methods/$METHOD carrier/connections carrier/connections/new 'carrier/connections/new?carrier=mock' carrier/connections/$CONN carrier/points 'carrier/points?connection=$CONN' carrier/log 'carrier/log?errors=1' carrier/orders/$ORDER carrier/orders/$ORDER2 'carrier/shipments?status=labelled' settings/plugins/carrier; do
  code=\$(curl -s -b jar -o /tmp/page.html -w '%{http_code}' \"\$B/admin/\$path\")
  err=\$(grep -o 'Internal Server Error\|Twig\\\\Error[^<]*\|An error occurred[^<]*' /tmp/page.html | head -1)
  echo \"\$code \$path \$err\"
done
LOGID=\$(curl -s -b jar \$B/admin/carrier/log | grep -o 'carrier/log/[0-9]*' | head -1)
[ -n \"\$LOGID\" ] && echo \"\$(curl -s -b jar -o /dev/null -w '%{http_code}' \$B/admin/\$LOGID) \$LOGID\"
LABEL=\$(curl -s -b jar \$B/admin/carrier/orders/$ORDER | grep -o 'labelId=[0-9]*' | head -1)
echo \"\$(curl -s -b jar -o /tmp/label.pdf -w '%{http_code} %{content_type}' \"\$B/admin/actions/carrier/shipments/label?\$LABEL\") label \$(head -c4 /tmp/label.pdf)\"
echo \"\$(curl -s -b jar -o /tmp/print.bin -w '%{http_code} %{content_type}' \"\$B/admin/actions/carrier/shipments/print?orderIds=$ORDER,$ORDER2\") print \$(head -c4 /tmp/print.bin)\"
"
