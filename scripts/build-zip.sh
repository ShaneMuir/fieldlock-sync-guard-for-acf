#!/bin/sh

set -eu

plugin_slug="fieldlock-sync-guard-for-acf"
output="${1:-dist/${plugin_slug}.zip}"
staging="$(mktemp -d)"

case "$output" in
	/*) output_path="$output" ;;
	*) output_path="$PWD/$output" ;;
esac

cleanup() {
	rm -rf "$staging"
}
trap cleanup EXIT INT TERM

mkdir -p "$staging/$plugin_slug/includes"
mkdir -p "$staging/$plugin_slug/assets/js"
mkdir -p "$(dirname "$output")"

cp fieldlock-sync-guard-for-acf.php readme.txt uninstall.php "$staging/$plugin_slug/"
cp includes/class-fieldlock-sync-guard-for-acf.php "$staging/$plugin_slug/includes/"
cp assets/js/admin-field-group-lock.js "$staging/$plugin_slug/assets/js/"

(
	cd "$staging"
	zip -qr "$plugin_slug.zip" "$plugin_slug"
)

mv "$staging/$plugin_slug.zip" "$output_path"

printf 'Built %s\n' "$output"
