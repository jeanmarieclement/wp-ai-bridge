#!/usr/bin/env bash
# Esegue tutte le suite. Exit code diverso da zero se una qualsiasi fallisce.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

status=0

for suite in test-*.php; do
    echo "── ${suite}"
    if ! php "${suite}"; then
        status=1
    fi
    echo
done

if [ "${status}" -eq 0 ]; then
    echo "✅  tutte le suite passano"
else
    echo "❌  almeno una suite è fallita"
fi

exit "${status}"
