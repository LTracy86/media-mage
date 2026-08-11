#!/usr/bin/env bash
# Fetch the 26 photographs demo-seed.php expects.
#
# Lorem Picsum serves the Unsplash library and needs no API key. Unsplash's own
# source.unsplash.com endpoint is retired and returns 503. Subjects are random,
# which is why the filenames are ones that suit any photograph.
#
#   ./demo-photos.sh /path/to/photos
set -euo pipefail
DEST="${1:?usage: demo-photos.sh <dest-dir>}"
mkdir -p "$DEST"

NAMES="hero-banner about-header services-intro gallery-01 gallery-02 gallery-03
gallery-04 gallery-05 gallery-06 team-portrait feature-tile-a feature-tile-b
blog-header-january blog-header-february blog-header-march testimonial-bg
contact-map-area product-shot-01 product-shot-02 footer-texture IMG_4821
old-flyer-draft banner-2024-retired headshot-unused untitled-design
screenshot-2025-11-03"

i=0
for n in $NAMES; do
  i=$((i+1))
  curl -sL --max-time 30 -o "$DEST/$n.jpg" "https://picsum.photos/seed/mm$i/1800/1200"
done

# A library of nothing but JPEG looks synthetic, and a 3 MB PNG is the kind of
# thing that actually needs cleaning up.
for n in untitled-design screenshot-2025-11-03 footer-texture; do
  php -r "\$i=imagecreatefromjpeg('$DEST/$n.jpg'); imagepng(\$i,'$DEST/$n.png'); unlink('$DEST/$n.jpg');"
done

echo "$(ls "$DEST" | wc -l) files, $(du -sh "$DEST" | cut -f1)"
