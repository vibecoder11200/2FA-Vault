#!/bin/bash
# Generate PWA icons from SVG source

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
ICONS_DIR="$PROJECT_ROOT/public/icons"
SVG_SOURCE="$ICONS_DIR/pwa-icon.svg"

# Check if ImageMagick is installed
if ! command -v convert &> /dev/null; then
    echo "❌ ImageMagick is not installed. Please install it:"
    echo "   Ubuntu/Debian: sudo apt install imagemagick"
    echo "   macOS: brew install imagemagick"
    exit 1
fi

echo "🎨 Generating PWA icons from $SVG_SOURCE"

# Icon sizes required for PWA
SIZES=(72 96 128 144 152 192 384 512)

for size in "${SIZES[@]}"; do
    output="$ICONS_DIR/pwa-${size}x${size}.png"
    echo "  📦 Generating ${size}x${size}..."
    convert -background none -resize "${size}x${size}" "$SVG_SOURCE" "$output"
done

# Generate Apple Touch Icon (180x180)
echo "  🍎 Generating Apple Touch Icon (180x180)..."
convert -background none -resize "180x180" "$SVG_SOURCE" "$ICONS_DIR/apple-touch-icon.png"

# Generate favicon (32x32, 16x16)
echo "  🔖 Generating favicons..."
convert -background none -resize "32x32" "$SVG_SOURCE" "$ICONS_DIR/favicon-32x32.png"
convert -background none -resize "16x16" "$SVG_SOURCE" "$ICONS_DIR/favicon-16x16.png"

# Generate ICO file (multi-resolution)
echo "  💾 Generating favicon.ico..."
convert "$ICONS_DIR/favicon-16x16.png" "$ICONS_DIR/favicon-32x32.png" "$ICONS_DIR/favicon.ico"

echo "✅ All PWA icons generated successfully!"
echo ""
echo "Generated icons:"
for size in "${SIZES[@]}"; do
    echo "  ✓ pwa-${size}x${size}.png"
done
echo "  ✓ apple-touch-icon.png"
echo "  ✓ favicon-32x32.png"
echo "  ✓ favicon-16x16.png"
echo "  ✓ favicon.ico"
