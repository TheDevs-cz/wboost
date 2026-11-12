#!/usr/bin/env bash
# Resource measurement in a linux/amd64 container (architecture §8): replays recorded scans through
# the real engine binary, instruments and renders, with the fake provider in place of the model.
#   eval/container-replay.sh <name> <memory> <think-ms> <recording-id> [<recording-id>]   (one id = concurrency 1, two = 2)
# Needs: the image (docker build --platform linux/amd64 -t wboost-scanner:eval scanner), the local app
# container on the compose network, WBOOST_MCP_TOKEN_FILE, PROJECT, and eval/runs/sdk/<id>/r1 recordings.
set -euo pipefail
cd "$(dirname "$0")/.."
name=$1; memory=$2; think=$3; shift 3
repo=$(cd .. && pwd)
image_of() { node -e "const s=require('./eval/sets.json');const all=[...s.easy,...s.photo,...s.hard];console.log(all.find(x=>x.id===process.argv[1]).image)" "$1"; }
out=eval/runs/_container/$name; mkdir -p "$out"; chmod 777 "$out"
cmd=""; slot=0
for id in "$@"; do
  cmd+="node eval/replay.mjs --recording /rec/$id/r1 --image /repo/$(image_of "$id") --project $PROJECT --out /out/s$slot --slot $slot --think-ms $think --mcp-url http://localhost:8097/_mcp --forward 8097=${APP_CONTAINER:-wboost-scan-web}:80 > /out/s$slot.json 2> /out/s$slot.err & "
  slot=$((slot+1))
done
cmd+="wait; cat /sys/fs/cgroup/memory.peak > /out/memory.peak; cat /sys/fs/cgroup/cpu.stat > /out/cpu.stat; nproc > /out/nproc; uname -m > /out/arch"
start=$(date +%s)
docker run --rm --platform linux/amd64 --network "${APP_NETWORK:-wboost_default}" --cpus 3 --memory "$memory" --memory-swap "$memory" \
  -e WBOOST_MCP_TOKEN="$(cat "$WBOOST_MCP_TOKEN_FILE")" -e SCANNER_OCR_THREADS="${OCR_THREADS:-3}" \
  -v "$PWD/eval/runs/sdk:/rec:ro" -v "$repo/docs/ai-scan:/repo/docs/ai-scan:ro" -v "$PWD/.scratch/variants.json:/scanner/.scratch/variants.json:ro" -v "$PWD/.scratch/standins.json:/scanner/.scratch/standins.json:ro" -v "$PWD/$out:/out" \
  wboost-scanner:eval sh -c "$cmd"
echo "wall $(( $(date +%s) - start )) s; cgroup memory.peak $(( $(cat "$out/memory.peak") / 1048576 )) MB; arch $(cat "$out/arch")"
grep -h usage_usec "$out/cpu.stat"
