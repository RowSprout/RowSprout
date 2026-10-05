#!/bin/sh
# Renders the WordPress.org assets in ../ from the sources in this folder with headless Chrome (Windows, Git Bash).
#   sh .wordpress-org/src/render.sh
# icon.svg (the animated icon) is hand-written; icon-navy.svg is its static end frame for the PNG fallbacks.
cd "$(dirname "$0")" || exit 1
SRC=$(cygpath -m "$(pwd -P)")
CHROME=${CHROME:-"/c/Program Files/Google/Chrome/Application/chrome.exe"}
PROFILE=$(mktemp -d)
shot() { # <source> <w,h> <out> <scale>
	"$CHROME" --headless=new --disable-gpu --hide-scrollbars --user-data-dir="$PROFILE" \
		--force-device-scale-factor="$4" --window-size="$2" --screenshot="$SRC/../$3" "file:///$SRC/$1" >/dev/null 2>&1
}
shot banner.html 772,250 banner-772x250.png 1
shot banner.html 772,250 banner-1544x500.png 2
shot icon-navy.svg 256,256 icon-256x256.png 1
shot icon-navy.svg 256,256 icon-128x128.png 0.5
rm -rf "$PROFILE"
