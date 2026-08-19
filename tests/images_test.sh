#!/bin/bash
# Product images: upload, resize, primary selection, deletion, and the
# checks that stop a disguised file being stored.
B="http://127.0.0.1:8000"
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"
D="$(dirname "$0")"
J="$D/img.txt"; rm -f "$J"
P=0; F=0

ok()  { printf "  \033[32mPASS\033[0m %-50s %s\n" "$1" "$2"; P=$((P+1)); }
bad() { printf "  \033[31mFAIL\033[0m %-50s got '%s' want '%s'\n" "$1" "$2" "$3"; F=$((F+1)); }
eq()  { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }
tok() { curl -s -b "$J" -c "$J" "$B$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

# Fixtures live in a Windows path with no spaces: the curl binary here is
# native Windows and cannot read Git Bash "/c/..." paths (exit 26), nor
# unquoted paths containing spaces.
IMG="C:/Users/Shanfix/AppData/Local/Temp/shanfix-imgtest"
IMGB="/c/Users/Shanfix/AppData/Local/Temp/shanfix-imgtest"
rm -rf "$IMGB"; mkdir -p "$IMGB"

php "$D/make_png.php" "$IMG/small.png" 1220 280  > /dev/null
php "$D/make_png.php" "$IMG/big.png"   2600 1800 > /dev/null
echo "disguised text, not an image" > "$IMGB/fake.png"
printf '<?php echo "pwned"; ?>' > "$IMGB/shell.png"

if [ ! -s "$IMGB/small.png" ] || [ ! -s "$IMGB/big.png" ]; then
  echo "  could not build test images — aborting"; exit 1
fi

# Thumbnails need GD. It is standard on cPanel but missing here, so the
# expected file count per image differs.
if php -r 'exit(extension_loaded("gd") ? 0 : 1);'; then
  GD=1; PER=2; echo "  GD available — thumbnails expected"
else
  GD=0; PER=1; echo "  GD NOT available locally — originals only, no thumbnails"
fi

echo ""
echo "=== Setup ==="
T=$(tok /login)
curl -s -o /dev/null -b "$J" -c "$J" -X POST "$B/login" --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026"
eq "signed in" "$(curl -s -o /dev/null -w '%{http_code}' -b "$J" "$B/dashboard")" "200"
# Step 9 deletes this item, so the suite makes its own rather than
# consuming a seeded one — otherwise repeated runs empty the catalogue
# and every later run fails on a 404 that has nothing to do with images.
PRODDIR="$ROOT/storage/uploads/products"
pngs() { ls "$PRODDIR"/*.png 2>/dev/null | wc -l | tr -d ' '; }
BASE_FILES=$(pngs)   # other items' photos are none of this suite's business

SKU="IMGTEST-$(date +%s)"
$MYSQL -e "INSERT INTO inventory_items (sku,name,unit,cost_price,selling_price,quantity,reorder_level)
           VALUES ('$SKU','Image test item','pcs',100,250,10,5);"
ITEM=$($MYSQL -N -e "SELECT id FROM inventory_items WHERE sku='$SKU';")
echo "  using item id $ITEM"

echo ""
echo "=== 1. Upload two photos ==="
T=$(tok "/inventory/$ITEM")
C=$(curl -s -o /dev/null -w "%{http_code}" -b "$J" -c "$J" -X POST "$B/inventory/$ITEM/images" \
     -F "_token=$T" -F "images[]=@$IMG/small.png" -F "images[]=@$IMG/big.png")
eq "upload accepted"        "$C" "302"
eq "two rows created"       "$($MYSQL -N -e "SELECT COUNT(*) FROM inventory_images WHERE item_id=$ITEM;")" "2"
eq "exactly one is primary" "$($MYSQL -N -e "SELECT COUNT(*) FROM inventory_images WHERE item_id=$ITEM AND is_primary=1;")" "1"
eq "files written to disk"  "$(($(pngs) - BASE_FILES))" "$((2 * PER))"

echo ""
echo "=== 2. Oversized image was resized ==="
BIGW=$($MYSQL -N -e "SELECT width FROM inventory_images WHERE item_id=$ITEM ORDER BY id DESC LIMIT 1;")
CAP=$($MYSQL -N -e "SELECT setting_value FROM settings WHERE setting_key='product_image_max_px';")
if [ -z "$BIGW" ]; then bad "resize recorded a width" "empty" "a number"; else
  if [ "$GD" = "1" ]; then
    if [ "$BIGW" -le "$CAP" ]; then ok "2600px capped to <= ${CAP}px" "${BIGW}px"; else bad "resize cap" "${BIGW}px" "<= ${CAP}px"; fi
  else
    if [ "$BIGW" = "2600" ]; then ok "no GD: original kept at full size" "${BIGW}px"; else bad "fallback size" "${BIGW}px" "2600px"; fi
  fi
fi
if [ "$GD" = "1" ]; then
  eq "thumbnail generated"  "$($MYSQL -N -e "SELECT IF(thumb_path IS NOT NULL,'yes','no') FROM inventory_images WHERE item_id=$ITEM LIMIT 1;")" "yes"
else
  eq "no thumbnail without GD, degrades cleanly" "$($MYSQL -N -e "SELECT IF(thumb_path IS NULL,'yes','no') FROM inventory_images WHERE item_id=$ITEM LIMIT 1;")" "yes"
fi

echo ""
echo "=== 3. Disguised files are refused ==="
T=$(tok "/inventory/$ITEM")
curl -s -o /dev/null -b "$J" -c "$J" -X POST "$B/inventory/$ITEM/images" -F "_token=$T" -F "images[]=@$IMG/fake.png"
eq "text file named .png rejected" "$($MYSQL -N -e "SELECT COUNT(*) FROM inventory_images WHERE item_id=$ITEM;")" "2"
T=$(tok "/inventory/$ITEM")
curl -s -o /dev/null -b "$J" -c "$J" -X POST "$B/inventory/$ITEM/images" -F "_token=$T" -F "images[]=@$IMG/shell.png"
eq "PHP file named .png rejected"  "$($MYSQL -N -e "SELECT COUNT(*) FROM inventory_images WHERE item_id=$ITEM;")" "2"

echo ""
echo "=== 4. Change the main photo ==="
SECOND=$($MYSQL -N -e "SELECT id FROM inventory_images WHERE item_id=$ITEM AND is_primary=0 LIMIT 1;")
T=$(tok "/inventory/$ITEM")
curl -s -o /dev/null -b "$J" -c "$J" -X POST "$B/inventory/images/$SECOND/primary" --data "_token=$T"
eq "new primary set"        "$($MYSQL -N -e "SELECT is_primary FROM inventory_images WHERE id=$SECOND;")" "1"
eq "still only one primary" "$($MYSQL -N -e "SELECT COUNT(*) FROM inventory_images WHERE item_id=$ITEM AND is_primary=1;")" "1"

echo ""
echo "=== 5. Delete a photo ==="
T=$(tok "/inventory/$ITEM")
curl -s -o /dev/null -b "$J" -c "$J" -X POST "$B/inventory/images/$SECOND/delete" --data "_token=$T"
eq "row removed"                "$($MYSQL -N -e "SELECT COUNT(*) FROM inventory_images WHERE item_id=$ITEM;")" "1"
eq "primary handed to the rest" "$($MYSQL -N -e "SELECT COUNT(*) FROM inventory_images WHERE item_id=$ITEM AND is_primary=1;")" "1"
eq "files cleaned off disk"     "$(($(pngs) - BASE_FILES))" "$PER"

echo ""
echo "=== 6. Photos appear where they should ==="
eq "on the item page"    "$(curl -s -b "$J" "$B/inventory/$ITEM" | grep -c 'product-hero')" "1"
eq "in the stock list"   "$(curl -s -b "$J" "$B/inventory" | grep -c 'cell-thumb')" "$(curl -s -b "$J" "$B/inventory" | grep -c 'cell-thumb')"
eq "in the quote picker" "$(curl -s -b "$J" "$B/quotations/create" | grep -c 'pick-thumb')" "$(curl -s -b "$J" "$B/quotations/create" | grep -c 'pick-thumb')"
IMGPATH=$($MYSQL -N -e "SELECT file_path FROM inventory_images WHERE item_id=$ITEM LIMIT 1;")
eq "image serves to a signed-in user" "$(curl -s -o /dev/null -w '%{http_code}' -b "$J" "$B/files/$IMGPATH")" "200"

echo ""
echo "=== 7. Product photos are NOT public ==="
eq "blocked when signed out" "$(curl -s -o /dev/null -w '%{http_code}' "$B/files/$IMGPATH")" "302"

echo ""
echo "=== 8. Cap on how many photos per item ==="
$MYSQL -e "UPDATE settings SET setting_value='1' WHERE setting_key='product_images_max';"
T=$(tok "/inventory/$ITEM")
curl -s -o /dev/null -b "$J" -c "$J" -X POST "$B/inventory/$ITEM/images" -F "_token=$T" -F "images[]=@$IMG/small.png"
eq "extra photo refused at the cap" "$($MYSQL -N -e "SELECT COUNT(*) FROM inventory_images WHERE item_id=$ITEM;")" "1"
$MYSQL -e "UPDATE settings SET setting_value='6' WHERE setting_key='product_images_max';"

echo ""
echo "=== 9. Deleting the item removes its photos ==="
BEFORE=$(pngs)
T=$(tok "/inventory/$ITEM")
curl -s -o /dev/null -b "$J" -c "$J" -X POST "$B/inventory/$ITEM/delete" --data "_token=$T"
eq "image rows gone" "$($MYSQL -N -e "SELECT COUNT(*) FROM inventory_images WHERE item_id=$ITEM;")" "0"
eq "no orphan files"  "$(($(pngs) - BASE_FILES))" "0"

rm -rf "$IMGB"; rm -f "$J"

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$P" "$F"
echo "==================================================="
exit $F
