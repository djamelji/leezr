#!/bin/zsh
L=$1; J=$L/jar.txt; U="http://127.0.0.1:8827/api/realtime/stream?company_id=109"
active() { curl -s http://127.0.0.1:8827/fpm-status | awk -F: '/^active processes/{gsub(/ /,"",$2);print $2}'; }
redis() { redis-cli --scan --pattern 'leezr827:realtime:*' | while read k; do echo -n "$k=$(redis-cli get $k 2>/dev/null) "; done; echo; }
for K in 0 2 4 6; do
  for i in $(seq 1 $K | head -n $K); do curl -s -N -b $J -H "Referer: http://127.0.0.1:8827/" -H "Accept: text/event-stream" -o $L/stream-$K-$i.txt -w "%{http_code} %{time_total}\n" "$U" > $L/stream-$K-$i.meta & done
  sleep 3
  a=$(active)
  t=$(curl -s -o /dev/null -b $J -H "Referer: http://127.0.0.1:8827/" -H "Accept: application/json" -w "%{http_code} %{time_total}" http://127.0.0.1:8827/api/me)
  echo "A K=$K streams | fpm active=$a/4 | GET /api/me -> $t s"
  wait
  for i in $(seq 1 $K | head -n $K); do echo "   stream $i: $(cat $L/stream-$K-$i.meta) heartbeats=$(grep -c heartbeat $L/stream-$K-$i.txt)"; done
  sleep 1
done
echo "B redis after all streams ended: $(redis)"
curl -s -N -b $J -H "Referer: http://127.0.0.1:8827/" -H "Accept: text/event-stream" -o /dev/null "$U" & P=$!
sleep 2; echo "C before kill: fpm active=$(active)/4 ; redis: $(redis)"; kill -9 $P; T0=$(date +%s)
while [ "$(active)" -gt 1 ]; do sleep 0.5; done; echo "C worker released $(( $(date +%s) - T0 ))s after client kill ; redis: $(redis)"
