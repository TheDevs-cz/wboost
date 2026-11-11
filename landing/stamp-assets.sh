#!/bin/sh
# Fingerprint every /landing/ asset URL with its content hash: /landing/x.css -> /landing/x.css?v=<md5>.
# Runs ONLY inside the image build (Dockerfile), on the copied document root, so src/ stays plain
# and dev (bind mount, unstamped) needs no step. nginx caches any `?v=` request for a year
# (nginx.conf), so a changed file gets a new URL and a stale copy is never served.
#
# Order matters: CSS and JS are stamped first (they reference fonts and the demo), so their own
# hash, taken afterwards by the HTML pass, covers the stamped references.
set -eu
cd "$1"

stamp() {
    for f in "$@"; do
        for ref in $(grep -oE '/landing/[A-Za-z0-9_./-]+\.(css|js|woff2|webp|png|svg|html)' "$f" | sort -u); do
            [ -f ".$ref" ] || { echo "stamp-assets: $f references missing $ref" >&2; exit 1; }
            hash=$(md5sum ".$ref" | cut -c1-10)
            re=$(printf '%s' "$ref" | sed 's/\./\\./g')
            # only a whole URL (followed by a quote or a paren), never twice
            sed -i "s#$re\([\"')]\)#$ref?v=$hash\1#g" "$f"
        done
    done
}

stamp landing/landing.css landing/legal.css landing/landing.js
stamp ./*.html

# A reference left unstamped would be cached for only 10 minutes — fail the build instead.
if grep -nE '/landing/[A-Za-z0-9_./-]+\.(css|js|woff2|webp|png|svg|html)["'"'"')]' ./*.html landing/landing.css landing/legal.css landing/landing.js; then
    echo 'stamp-assets: the references above were not fingerprinted' >&2
    exit 1
fi
