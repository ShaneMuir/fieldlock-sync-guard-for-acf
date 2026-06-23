#!/bin/sh

set -eu

git config core.hooksPath .githooks
chmod +x .githooks/pre-commit

printf 'Configured the repository pre-commit hook.\n'
