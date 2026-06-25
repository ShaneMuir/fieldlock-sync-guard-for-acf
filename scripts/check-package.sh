#!/bin/sh

set -eu

plugin_slug="fieldlock-sync-guard-for-acf"
workdir="$(mktemp -d)"
archive="$workdir/$plugin_slug.zip"
extracted="$workdir/extracted"

cleanup() {
	rm -rf "$workdir"
}
trap cleanup EXIT INT TERM

sh scripts/build-zip.sh "$archive"
unzip -t "$archive"
mkdir "$extracted"
unzip -q "$archive" -d "$extracted"

expected_files="
$plugin_slug/assets/js/admin-field-group-lock.js
$plugin_slug/includes/class-fieldlock-sync-guard-for-acf.php
$plugin_slug/fieldlock-sync-guard-for-acf.php
$plugin_slug/readme.txt
$plugin_slug/uninstall.php
"

actual_files="$(unzip -Z1 "$archive" | sed '/\/$/d' | sort)"
expected_files="$(printf '%s\n' "$expected_files" | sed '/^$/d' | sort)"

if [ "$actual_files" != "$expected_files" ]; then
	printf 'Unexpected package contents:\n%s\n' "$actual_files" >&2
	exit 1
fi

for file in \
	assets/js/admin-field-group-lock.js \
	includes/class-fieldlock-sync-guard-for-acf.php \
	fieldlock-sync-guard-for-acf.php \
	readme.txt \
	uninstall.php
do
	cmp "$file" "$extracted/$plugin_slug/$file"
done

printf 'Package contents match the source files.\n'
