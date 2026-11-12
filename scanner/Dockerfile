# syntax=docker/dockerfile:1
# The AI scan scanner image (linux/amd64). Build context: scanner/ — the app image does not carry this
# directory, and this image does not carry the app.
#   docker build --platform linux/amd64 --build-arg SCANNER_BUILD=$(git rev-parse HEAD) -t wboost-scanner scanner
#
# What is inside: node + the scanner (bin/, src/), the Agent SDK's engine binary for linux-x64 (glibc),
# python with the pinned OCR stack (requirements.lock) and the OCR model files, libheif's converter for
# HEIC uploads, tini. What is NOT: a prompt or a schema (the app sends the definition with every claim),
# a credential (ANTHROPIC_API_KEY and SCANNER_TOKEN come from the environment), tests, the evaluation.
#
# The base is pinned by digest (node 24.22.3, Debian bookworm); bump tag and digest together.
ARG BASE=node:24.22.3-bookworm-slim@sha256:dc26ae92c9fb15cf6b1d63e20f0944b2efe0f115b20e00be48fb23fceb061c54

# ---- node dependencies: production only, one engine binary -------------------------------------------
FROM ${BASE} AS node-deps
WORKDIR /scanner
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund \
    && rm -rf node_modules/@anthropic-ai/claude-agent-sdk-linux-x64-musl \
              node_modules/@img/sharp-linuxmusl-x64 node_modules/@img/sharp-libvips-linuxmusl-x64 node_modules/@img/sharp-wasm32 \
    && test -x node_modules/@anthropic-ai/claude-agent-sdk-linux-x64/claude \
    && npm cache clean --force

# ---- the OCR sidecar: a virtualenv with every package pinned, models fetched at BUILD time -------------
FROM ${BASE} AS ocr
RUN apt-get update && apt-get install -y --no-install-recommends python3 python3-venv \
    && rm -rf /var/lib/apt/lists/*
COPY requirements.lock /tmp/requirements.lock
RUN python3 -m venv /opt/ocr \
    && /opt/ocr/bin/pip install --no-cache-dir --no-deps -r /tmp/requirements.lock \
    && /opt/ocr/bin/pip check
COPY src/instruments/ocr /tmp/ocr
# RapidOCR downloads its detector / classifier / recogniser on first use: do it here, and prove the result
RUN /opt/ocr/bin/python /tmp/ocr/ocr.py /tmp/ocr/selftest.png --threads 2 | grep -q '"Letni"' \
    && /opt/ocr/bin/pip uninstall -y pip setuptools >/dev/null 2>&1 || true

# ---- the image -----------------------------------------------------------------------------------------
FROM ${BASE}
# python for the OCR sidecar (its OpenCV is the headless build: no X or GL libraries), libheif's converter
# for HEIC uploads, tini as init
# (zombies; `init: true` in compose does the same and is harmless next to it)
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 libheif-examples tini ca-certificates \
    && rm -rf /var/lib/apt/lists/* /usr/share/doc /usr/share/man \
    && rm -rf /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/corepack /usr/local/bin/npm /usr/local/bin/npx /usr/local/bin/corepack /opt/yarn-* /usr/local/bin/yarn /usr/local/bin/yarnpkg

WORKDIR /scanner
COPY --from=ocr /opt/ocr /opt/ocr
COPY --from=node-deps /scanner/node_modules ./node_modules
COPY package.json ./
COPY bin ./bin
COPY src ./src

ARG SCANNER_BUILD=""
ENV NODE_ENV=production \
    SCANNER_BUILD=${SCANNER_BUILD} \
    SCANNER_OCR_PYTHON=/opt/ocr/bin/python \
    SCANNER_SCRATCH_DIR=/tmp/wboost-scanner \
    SCANNER_HEALTH_PORT=8787 \
    PYTHONDONTWRITEBYTECODE=1
LABEL org.opencontainers.image.title="wboost-scanner" \
      org.opencontainers.image.description="WBoost AI scan — the scanner service" \
      org.opencontainers.image.source="https://github.com/TheDevs-cz/wboost" \
      org.opencontainers.image.revision="${SCANNER_BUILD}"

# not root; nothing in the image is writable by it — the scanner writes under /tmp only (scratch, the engine's home)
USER node
EXPOSE 8787
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=3 CMD ["node", "bin/scanner.mjs", "healthcheck"]
STOPSIGNAL SIGTERM
ENTRYPOINT ["tini", "--"]
CMD ["node", "bin/scanner.mjs", "serve"]
