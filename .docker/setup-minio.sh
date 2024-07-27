#!/bin/bash

mc alias set local "$MINIO_SERVER_URL" "$MINIO_ACCESS_KEY" "$MINIO_SECRET_KEY"
mc mb --ignore-existing "local/$BUCKET_NAME"
mc anonymous set public "local/$BUCKET_NAME"
