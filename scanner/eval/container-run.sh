#!/usr/bin/env bash
# One whole scan in the PRODUCTION IMAGE (linux/amd64) against the local app — the real service, the real
# protocol, the real instruments and engine binary, the app's own renders — and what it cost the container:
# cgroup memory peak, wall time, the stop.
#
#   eval/container-run.sh <input id of eval/sets.json> [memory=3g] [image=wboost-scanner:dev] [think-ms=1500]
#
# The provider: with CLAUDE_CODE_OAUTH_TOKEN (the plan's token, `claude setup-token`) or ANTHROPIC_API_KEY in
# the environment the container talks to the real provider. Without one — a container has no plan login —
# the model's answers are replayed from the recorded scan eval/runs/sdk/<input>/r1 (eval/replay-provider.mjs).
# Needs: the local app (compose network wboost_default, mode on, SCAN_NETWORK_CIDR covering that network),
# WBOOST_LOGIN_FILE, no other scanner running.
set -euo pipefail
cd "$(dirname "$0")/.."
input=$1; memory=${2:-3g}; image=${3:-wboost-scanner:dev}; think=${4:-1500}
name=wboost-scanner-run; network=${APP_NETWORK:-wboost_default}; app=${APP_CONTAINER:-wboost-web-1}
out=eval/runs/_container/$input-$(date +%H%M%S); mkdir -p "$out"
provider=()
if [ -n "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]; then provider=(-e CLAUDE_CODE_OAUTH_TOKEN); mode=plan-token
elif [ -n "${ANTHROPIC_API_KEY:-}" ]; then provider=(-e ANTHROPIC_API_KEY); mode=api-key
else
  node eval/replay-provider.mjs --recording "eval/runs/sdk/$input/r1" --port 8790 --think-ms "$think" > "$out/provider.log" 2>&1 & ppid=$!
  trap 'kill $ppid 2>/dev/null || true' EXIT
  sleep 1
  provider=(-e ANTHROPIC_API_KEY=sk-ant-replay-not-a-key -e ANTHROPIC_BASE_URL=http://host.docker.internal:8790); mode=replay
fi
docker rm -f $name >/dev/null 2>&1 || true
docker run -d --name $name --platform linux/amd64 --network "$network" --init --read-only --tmpfs /tmp:rw,exec,size=512m \
  --memory "$memory" --memory-swap "$memory" --cpus 3 --pids-limit 512 --stop-timeout 45 \
  -e SCANNER_APP_URL="http://$app" -e SCANNER_TOKEN="${SCANNER_TOKEN:-dev-scanner-token-not-a-secret-0000}" -e SCANNER_LOG=json "${provider[@]}" "$image" >/dev/null
for i in $(seq 1 60); do [ "$(docker inspect -f '{{.State.Health.Status}}' $name 2>/dev/null)" = healthy ] && break; sleep 1; done
echo "container: $(docker inspect -f '{{.State.Health.Status}}' $name) · provider: $mode · memory limit $memory · image $image"
idle=$(docker exec $name cat /sys/fs/cgroup/memory.peak)
start=$(date +%s)
node eval/e2e.mjs "$input" --out "$out" > "$out/e2e.json" 2> "$out/e2e.err" || true
wall=$(( $(date +%s) - start ))
peak=$(docker exec $name cat /sys/fs/cgroup/memory.peak); cur=$(docker exec $name cat /sys/fs/cgroup/memory.current)
docker exec $name cat /sys/fs/cgroup/memory.events > "$out/memory.events"; docker exec $name cat /sys/fs/cgroup/cpu.stat > "$out/cpu.stat"
docker exec $name sh -c 'ls -A /tmp/wboost-scanner | wc -l' > "$out/scratch.entries"
stop0=$(date +%s); docker stop $name >/dev/null; stop=$(( $(date +%s) - stop0 ))
exit_code=$(docker inspect -f '{{.State.ExitCode}}' $name); oom=$(docker inspect -f '{{.State.OOMKilled}}' $name)
docker logs $name > "$out/scanner.log" 2>&1; docker rm $name >/dev/null
node -e '
const [out, wall, idle, peak, cur, stop, code, oom, mode, memory] = process.argv.slice(1);
const fs = require("fs"); const mb = (b) => Math.round(Number(b) / 1048576);
const d = JSON.parse(fs.readFileSync(out + "/e2e.json", "utf8").trim().split("\n")[0] || "{}");
const cpu = Object.fromEntries(fs.readFileSync(out + "/cpu.stat", "utf8").trim().split("\n").map((l) => l.split(" ")));
const ev = Object.fromEntries(fs.readFileSync(out + "/memory.events", "utf8").trim().split("\n").map((l) => l.split(" ")));
const r = { input: d.name, provider: mode, memoryLimit: memory, outcome: d.status, wallS: d.wallS, scriptWallS: +wall, cgroupPeakMb: mb(peak), cgroupIdlePeakMb: mb(idle), cgroupAfterMb: mb(cur), cpuSeconds: +(Number(cpu.usage_usec) / 1e6).toFixed(1), oomKills: +ev.oom_kill, oomKilled: oom, scratchEntriesAfter: +fs.readFileSync(out + "/scratch.entries", "utf8"), stopS: +stop, exitCode: +code, usageRows: d.usageRows, ledger: d.ledger, stages: d.stages, report: d.report };
fs.writeFileSync(out + "/container.json", JSON.stringify(r, null, 1)); console.log(JSON.stringify(r, null, 1));
' "$out" "$wall" "$idle" "$peak" "$cur" "$stop" "$exit_code" "$oom" "$mode" "$memory"
