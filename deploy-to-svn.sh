#!/usr/bin/env bash
# =============================================================================
# WP AI Bridge — WordPress.org SVN Deployment Script
#
# Questo script automatizza la pubblicazione del plugin sul repository SVN
# ufficiale di WordPress.org:
# 1. Verifica consistenza versioni (wp-ai-bridge.php e readme.txt)
# 2. Clona/aggiorna il repository SVN (trunk, tags, assets)
# 3. Sincronizza il codice in trunk/ e tags/<version>/
# 4. Sincronizza gli asset (.wordpress-org/ -> assets/)
# 5. Gestisce aggiunte (svn add) e rimozioni (svn delete)
# 6. Esegue il commit su WordPress.org
# =============================================================================

set -euo pipefail

# Colori per il terminale
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SLUG="ai-bridge"
PLUGIN_SRC="${ROOT_DIR}/wp-ai-bridge"
ASSETS_SRC="${ROOT_DIR}/.wordpress-org"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"
SVN_DIR="${ROOT_DIR}/.svn-checkout"

echo -e "${BLUE}======================================================${NC}"
echo -e "${BLUE}  AI Bridge — WordPress.org SVN Deploy Tool           ${NC}"
echo -e "${BLUE}======================================================${NC}"

# 1. Verifica presenza di svn
if ! command -v svn &> /dev/null; then
    echo -e "${RED}Errore: Subversion (svn) non è installato sul sistema.${NC}"
    echo -e "Installalo eseguendo: ${YELLOW}sudo apt update && sudo apt install -y subversion${NC}"
    exit 1
fi

# 2. Legge e verifica le versioni
PHP_FILE="${PLUGIN_SRC}/ai-bridge.php"
if [ ! -f "${PHP_FILE}" ]; then
    PHP_FILE="${PLUGIN_SRC}/wp-ai-bridge.php"
fi
README_FILE="${PLUGIN_SRC}/readme.txt"

if [ ! -f "${PHP_FILE}" ] || [ ! -f "${README_FILE}" ]; then
    echo -e "${RED}Errore: File ${PHP_FILE} o readme.txt non trovati in ${PLUGIN_SRC}${NC}"
    exit 1
fi

PHP_VERSION=$(grep -m1 "Version:" "${PHP_FILE}" | awk '{print $NF}')
README_TAG=$(grep -m1 -i "Stable tag:" "${README_FILE}" | awk '{print $NF}')

echo -e "Versione in ${YELLOW}wp-ai-bridge.php${NC}: ${GREEN}${PHP_VERSION}${NC}"
echo -e "Versione in ${YELLOW}readme.txt (Stable tag)${NC}: ${GREEN}${README_TAG}${NC}"

if [ "${PHP_VERSION}" != "${README_TAG}" ]; then
    echo -e "${RED}Errore: La versione in wp-ai-bridge.php (${PHP_VERSION}) non coincide con Stable tag in readme.txt (${README_TAG})!${NC}"
    exit 1
fi

# 3. Richiesta credenziali / username WordPress.org
WPORG_USER="${WPORG_USER:-jmclement}"
read -rp "Username WordPress.org [${WPORG_USER}]: " input_user
WPORG_USER="${input_user:-$WPORG_USER}"

echo ""
echo -e "Target SVN: ${BLUE}${SVN_URL}${NC}"
echo -e "Autore:     ${BLUE}${WPORG_USER}${NC}"
echo -e "Versione:   ${BLUE}${PHP_VERSION}${NC}"
echo ""

# 4. Checkout / Update da SVN
mkdir -p "${SVN_DIR}"
if [ -d "${SVN_DIR}/.svn" ]; then
    echo -e "${YELLOW}Aggiornamento checkout SVN esistente...${NC}"
    svn update "${SVN_DIR}"
else
    echo -e "${YELLOW}Checkout repository SVN iniziale da WordPress.org...${NC}"
    echo -e "(Se il plugin non è ancora stato approvato dal team di WP, questo step fallirà finché il repo non viene creato)${NC}"
    svn checkout "${SVN_URL}" "${SVN_DIR}" || {
        echo -e "${RED}Impossibile effettuare il checkout da ${SVN_URL}.${NC}"
        echo -e "${YELLOW}Nota: Se hai appena inviato il plugin per la revisione, attendi l'email di approvazione da WordPress.org prima di eseguire questo script.${NC}"
        exit 1
    }
fi

# 5. Sincronizzazione trunk/
echo -e "${YELLOW}Sincronizzazione file sorgente in trunk/...${NC}"
mkdir -p "${SVN_DIR}/trunk"
# Rimuovi file da trunk (tranne .svn) e ricopia il plugin
find "${SVN_DIR}/trunk" -mindepth 1 -not -path '*/.*' -delete
cp -r "${PLUGIN_SRC}/"* "${SVN_DIR}/trunk/"

# 6. Creazione/Sincronizzazione tag
echo -e "${YELLOW}Creazione directory tag: tags/${PHP_VERSION}/...${NC}"
mkdir -p "${SVN_DIR}/tags/${PHP_VERSION}"
find "${SVN_DIR}/tags/${PHP_VERSION}" -mindepth 1 -not -path '*/.*' -delete
cp -r "${PLUGIN_SRC}/"* "${SVN_DIR}/tags/${PHP_VERSION}/"

# 7. Sincronizzazione assets/
if [ -d "${ASSETS_SRC}" ]; then
    echo -e "${YELLOW}Sincronizzazione asset promozionali in assets/...${NC}"
    mkdir -p "${SVN_DIR}/assets"
    find "${SVN_DIR}/assets" -mindepth 1 -not -path '*/.*' -delete
    # Copia solo immagini (png, jpg, svg)
    cp "${ASSETS_SRC}"/*.png "${SVN_DIR}/assets/" 2>/dev/null || true
    cp "${ASSETS_SRC}"/*.svg "${SVN_DIR}/assets/" 2>/dev/null || true
fi

# 8. Gestione automatica svn add e svn delete
cd "${SVN_DIR}"
echo -e "${YELLOW}Rilevamento modifiche file SVN...${NC}"

# Aggiungi nuovi file
svn status | grep '^\?' | awk '{print $2}' | xargs -r -I {} svn add {}

# Rimuovi file cancellati
svn status | grep '^\!' | awk '{print $2}' | xargs -r -I {} svn delete {}

echo ""
echo -e "${GREEN}Stato modifiche SVN:${NC}"
svn status
echo ""

# 9. Conferma commit
read -rp "Vuoi procedere con il commit su WordPress.org per la versione ${PHP_VERSION}? (s/N): " confirm
if [[ "${confirm}" =~ ^[sSyY]$ ]]; then
    echo -e "${YELLOW}Esecuzione svn commit...${NC}"
    svn commit -m "Tagging version ${PHP_VERSION}" --username "${WPORG_USER}"
    echo -e "${GREEN}✅ Plugin pubblicato con successo su WordPress.org!${NC}"
else
    echo -e "${YELLOW}Operazione annullata. Nessun commit eseguito su SVN.${NC}"
fi
