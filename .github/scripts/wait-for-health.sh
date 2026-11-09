#!/usr/bin/env bash
# Waits until the API container answers GET /api/health with 200 (max ~2 minutes).
# Fails early with the container log if the container stopped, e.g. because a
# migration or the seed failed during startup (D19).
set -euo pipefail

for i in $(seq 1 60); do
  if curl -fsS http://localhost:8080/api/health; then
    echo
    echo "healthy after ~$((i * 2)) s"
    exit 0
  fi
  if [ "$(docker inspect -f '{{.State.Running}}' api)" != "true" ]; then
    echo "container stopped during startup:"
    docker logs api 2>&1 | tail -50
    exit 1
  fi
  sleep 2
done

echo "not healthy after 2 minutes:"
docker logs api 2>&1 | tail -50
exit 1
