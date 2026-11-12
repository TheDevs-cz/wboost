# The AI scan scanner image. Build context: scanner/ (the app image does not carry this directory).
#   docker build --platform linux/amd64 -t wboost-scanner scanner
# The image holds no prompt and no schema (the app sends the definition with every claim) and no
# credential: ANTHROPIC_API_KEY and the scanner token come from the environment.
FROM node:24-bookworm-slim

# python for the OCR sidecar; libheif tools for HEIC uploads; tini as init (process-group kills, zombies)
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-venv libgl1 libglib2.0-0 libheif-examples tini ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /scanner
COPY requirements.txt ./
RUN python3 -m venv .venv && .venv/bin/pip install --no-cache-dir -r requirements.txt

COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY bin ./bin
COPY src ./src
COPY eval ./eval
COPY dev ./dev

# the OCR models are fetched at build time, never at run time
RUN node -e "require('sharp')({create:{width:600,height:160,channels:3,background:'#fff'}}).composite([{input:Buffer.from('<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"600\" height=\"160\"><text x=\"30\" y=\"110\" font-size=\"80\" font-family=\"sans-serif\">Ahoj 2027</text></svg>')}]).png().toFile('/tmp/warm.png')" \
    && .venv/bin/python src/instruments/ocr/ocr.py /tmp/warm.png --threads 1 > /dev/null && rm /tmp/warm.png

ENV NODE_ENV=production SCANNER_OCR_PYTHON=/scanner/.venv/bin/python
USER node
ENTRYPOINT ["tini", "-g", "--"]
CMD ["node", "bin/scanner.mjs"]
