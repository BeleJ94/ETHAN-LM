#!/usr/bin/env bash
# Télécharge les bibliothèques front auto-hébergées, versions épinglées (cf. spécification A.2).
set -euo pipefail
cd "$(dirname "$0")/../public/assets"

fetch() { mkdir -p "$(dirname "$2")"; curl -fsSL "$1" -o "$2"; echo "  $2"; }

NPM=https://cdn.jsdelivr.net/npm
fetch "$NPM/jquery@3.7.1/dist/jquery.min.js"                       vendor/jquery/jquery.min.js
fetch "$NPM/datatables.net@2.1.8/js/dataTables.min.js"             vendor/datatables/dataTables.min.js
fetch "$NPM/datatables.net-dt@2.1.8/css/dataTables.dataTables.min.css" vendor/datatables/dataTables.dataTables.min.css
fetch "$NPM/chart.js@4.4.7/dist/chart.umd.min.js"                  vendor/chartjs/chart.umd.min.js
fetch "$NPM/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"       vendor/sweetalert2/sweetalert2.all.min.js
fetch "$NPM/sweetalert2@11.14.5/dist/sweetalert2.min.css"          vendor/sweetalert2/sweetalert2.min.css
fetch "$NPM/lucide@0.460.0/dist/umd/lucide.min.js"                 vendor/lucide/lucide.min.js
fetch "$NPM/exceljs@4.4.0/dist/exceljs.min.js"                     vendor/exceljs/exceljs.min.js
fetch "$NPM/pdfmake@0.2.14/build/pdfmake.min.js"                   vendor/pdfmake/pdfmake.min.js
fetch "$NPM/pdfmake@0.2.14/build/vfs_fonts.js"                     vendor/pdfmake/vfs_fonts.js

PLEX=$NPM/@ibm/plex-sans@1.1.0/fonts/complete/woff2
fetch "$PLEX/IBMPlexSans-Regular.woff2"  fonts/IBMPlexSans-Regular.woff2
fetch "$PLEX/IBMPlexSans-Medium.woff2"   fonts/IBMPlexSans-Medium.woff2
fetch "$PLEX/IBMPlexSans-SemiBold.woff2" fonts/IBMPlexSans-SemiBold.woff2
echo "OK"
