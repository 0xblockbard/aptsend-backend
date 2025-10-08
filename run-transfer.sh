#!/bin/bash
# ================================================================
# run-aptos-transfer.sh
# ================================================================

# Set the timeout duration (in seconds)
TIMEOUT_DURATION=120  # 2 minutes

echo "Executing Aptos transfer processor"

# Get the absolute path to the project root
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Make sure tsx is installed
if [ ! -f "$PROJECT_ROOT/node_modules/.bin/tsx" ]; then
  echo "Installing tsx..."
  npm install --save-dev tsx
fi

# Create log directory if it doesn't exist
mkdir -p "$PROJECT_ROOT/storage/logs/aptos"

# Get the transfer data from the first argument
TRANSFER_DATA="$1"

if [ -z "$TRANSFER_DATA" ]; then
  echo "Error: No transfer data provided"
  echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] ERROR: No transfer data provided" >> "$PROJECT_ROOT/storage/logs/aptos/run-transfer-error.log"
  exit 1
fi

# Log the start of the transfer
echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] Starting Aptos transfer processor" >> "$PROJECT_ROOT/storage/logs/aptos/run-transfer.log"

# Run with tsx and timeout
timeout $TIMEOUT_DURATION "$PROJECT_ROOT/node_modules/.bin/tsx" \
  "$PROJECT_ROOT/resources/js/aptos/transfer.ts" \
  "$TRANSFER_DATA"

# Capture the exit code
EXIT_CODE=$?

# Check if the command timed out
if [ $EXIT_CODE -eq 124 ]; then
  echo "Command timed out after $TIMEOUT_DURATION seconds"
  echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] ERROR: Command timed out after $TIMEOUT_DURATION seconds" >> "$PROJECT_ROOT/storage/logs/aptos/run-transfer-error.log"
elif [ $EXIT_CODE -ne 0 ]; then
  echo "Command failed with exit code $EXIT_CODE"
  echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] ERROR: Command failed with exit code $EXIT_CODE" >> "$PROJECT_ROOT/storage/logs/aptos/run-transfer-error.log"
else
  echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] Successfully completed Aptos transfer" >> "$PROJECT_ROOT/storage/logs/aptos/run-transfer.log"
fi

# Exit with the exit code
exit $EXIT_CODE