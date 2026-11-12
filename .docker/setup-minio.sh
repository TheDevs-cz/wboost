#!/bin/bash

mc alias set local "$MINIO_SERVER_URL" "$MINIO_ACCESS_KEY" "$MINIO_SECRET_KEY"
mc mb --ignore-existing "local/$BUCKET_NAME"
mc anonymous set public "local/$BUCKET_NAME"

# AI scan files (docs/architecture/ai-scan.md § "Storage"): private — no
# `mc anonymous set`, so nothing in it is reachable by a URL.
if [ -n "$PRIVATE_BUCKET_NAME" ]; then
    mc mb --ignore-existing "local/$PRIVATE_BUCKET_NAME"
fi
