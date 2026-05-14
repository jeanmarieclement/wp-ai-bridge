#!/usr/bin/env bash
# =============================================================================
# WP AI Bridge — Build Script
# Genera il pacchetto .zip pronto per l'installazione su WordPress
# =============================================================================

set -euo pipefail

# ---------- Configurazione ---------------------------------------------------
PLUGIN_SLUG="wp-ai-bridge"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/${PLUGIN_SLUG}"
BUILD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/build"
DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/dist"

# Legge la versione direttamente dal file principale del plugin
VERSION=$(grep -m1 "Version:" "${PLUGIN_DIR}/wp-ai-bridge.php" | awk '{print $NF}')

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="${DIST_DIR}/${ZIP_NAME}"

# ---------- Pulizia -----------------------------------------------------------
echo "🧹  Pulizia cartelle di build precedenti..."
rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}/${PLUGIN_SLUG}"
mkdir -p "${DIST_DIR}"

# ---------- Copia file del plugin --------------------------------------------
echo "📦  Copia dei file del plugin..."

# File e directory da includere nel pacchetto
INCLUDE=(
    "admin"
    "includes"
    "languages"
    "index.php"
    "mcp-connector.php"
    "uninstall.php"
    "wp-ai-bridge.php"
    "README.md"
)

for item in "${INCLUDE[@]}"; do
    src="${PLUGIN_DIR}/${item}"
    if [ -e "${src}" ]; then
        cp -r "${src}" "${BUILD_DIR}/${PLUGIN_SLUG}/"
    else
        echo "  ⚠️  File/cartella non trovata, saltata: ${item}"
    fi
done

# ---------- Pulizia file di sviluppo ----------------------------------------
echo "🗑️   Rimozione file di sviluppo e cache..."

# Rimuovi index.php di sicurezza vuoti che non servono (li teniamo, sono buoni)
# Rimuovi eventuali file .DS_Store, Thumbs.db, ecc.
find "${BUILD_DIR}" -name ".DS_Store" -delete
find "${BUILD_DIR}" -name "Thumbs.db" -delete
find "${BUILD_DIR}" -name "*.log" -delete
find "${BUILD_DIR}" -name ".gitignore" -delete
find "${BUILD_DIR}" -name ".gitkeep" -delete
# Rimuovi file di sviluppo PHP se presenti
find "${BUILD_DIR}" -name "*.map" -delete

# ---------- Genera file .pot per i traduttori --------------------------------
echo "🌐  Generazione file di traduzione .pot..."
if command -v wp &> /dev/null; then
    wp i18n make-pot "${PLUGIN_DIR}/" "${PLUGIN_DIR}/languages/${PLUGIN_SLUG}.pot" --domain="${PLUGIN_SLUG}"
    echo "✓   Generato languages/${PLUGIN_SLUG}.pot"
else
    echo "⚠️   WP-CLI non trovato — .pot non generato (installa con: https://wp-cli.org)"
fi

# ---------- Creazione ZIP ----------------------------------------------------
echo "🗜️   Creazione archivio ${ZIP_NAME}..."
cd "${BUILD_DIR}"
zip -r "${ZIP_PATH}" "${PLUGIN_SLUG}/" --quiet

# ---------- Riepilogo --------------------------------------------------------
ZIP_SIZE=$(du -sh "${ZIP_PATH}" | cut -f1)
echo ""
echo "✅  Build completata con successo!"
echo "────────────────────────────────────────"
echo "   Plugin:    ${PLUGIN_SLUG}"
echo "   Versione:  ${VERSION}"
echo "   File:      ${ZIP_PATH}"
echo "   Dimensione: ${ZIP_SIZE}"
echo "────────────────────────────────────────"
echo ""
echo "💡  Installazione: WordPress Admin → Plugin → Carica plugin → seleziona ${ZIP_NAME}"
