#!/bin/bash

# Hämta absolut sökväg till detta skripts mapp
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Sökväg till delade SCSS-filer (style-core i WordPress-roten)
# Från plugins/tranas-forms/ → plugins/ → wp-content/ → public/ → style-core
STYLE_CORE="$SCRIPT_DIR/../../../style-core"

# Kompilera SCSS med --load-path för att hitta delade filer
npx sass --watch --style=compressed --load-path="$STYLE_CORE" assets/scss:assets/css
