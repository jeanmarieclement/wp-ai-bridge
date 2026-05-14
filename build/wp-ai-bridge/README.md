# WP AI Bridge

Plugin WordPress che espone endpoint REST sicuri per la gestione dei contenuti tramite **API key per utente**. Pensato per integrazione con servizi AI esterni (Claude, ChatGPT, automazioni custom).

**Versione:** 1.0.0  
**Compatibilità:** WordPress 6.0+, PHP 7.4+  
**Licenza:** GPL-2.0-or-later

---

## Cosa fa

Espone un'API REST sotto il namespace `/wp-json/wpaib/v1/` per:

- Lista, creazione, lettura, aggiornamento e cancellazione di articoli
- Upload immagini (multipart o base64) nella media library
- Lista e creazione di categorie e tag

Ogni richiesta è autenticata tramite API key personale, mostrata UNA sola volta alla generazione e salvata nel DB solo come hash SHA-256.

---

## Installazione

1. Carica la cartella `wp-ai-bridge` in `wp-content/plugins/`
2. Vai su **WordPress → Plugin** e attiva "WP AI Bridge"
3. Vai sul tuo **Profilo utente** (Utenti → Profilo)
4. Scorri fino alla sezione **"WP AI Bridge — API Keys"**
5. Inserisci un'etichetta (es. `Laptop casa`) e clicca **Genera chiave**
6. **Copia la chiave subito**: non sarà più visibile

---

## Architettura della sicurezza

Ogni richiesta attraversa 5 controlli in cascata:

| # | Controllo | Cosa fa |
|---|-----------|---------|
| 1 | HTTPS check | Rifiuta richieste su HTTP |
| 2 | Rate limiter | Max 60 richieste/min per chiave |
| 3 | Validazione API key | Hash SHA-256 contro DB, formato regex pre-check |
| 4 | Capability WordPress | Verifica permessi dell'utente collegato alla chiave |
| 5 | Sanitizzazione input | `sanitize_*` + `wp_kses_post` su ogni dato |

**Audit log:** ogni accesso (successo, fallimento auth, rate limit, forbidden) viene loggato nella tabella `wp_wpaib_audit_log` con timestamp, IP, user-agent, endpoint, esito.

**Cosa NON fa il plugin (per design):**
- Non espone endpoint per gestire utenti, ruoli, opzioni, plugin, temi
- Non permette esecuzione di codice arbitrario
- Non scarica file dal server
- Non si fida di header proxy (a meno di configurazione esplicita)

---

## Esempi di chiamate API

Tutte le chiamate richiedono l'header `X-API-Key`.

### Crea una bozza articolo

```bash
curl -X POST https://ditutto-unpo.it/wp-json/wpaib/v1/posts \
  -H "Content-Type: application/json" \
  -H "X-API-Key: wpaib_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  -d '{
    "title": "Il mio articolo di prova",
    "content": "<p>Contenuto HTML del post.</p>",
    "excerpt": "Breve riassunto",
    "status": "draft",
    "categories": [5],
    "tags": ["ai", "wordpress", "automazione"]
  }'
```

### Carica un'immagine (base64)

```bash
curl -X POST https://ditutto-unpo.it/wp-json/wpaib/v1/media \
  -H "Content-Type: application/json" \
  -H "X-API-Key: wpaib_xxxx..." \
  -d '{
    "filename": "cover.png",
    "image_base64": "iVBORw0KGgoAAAANS..."
  }'
```

### Lista categorie

```bash
curl https://ditutto-unpo.it/wp-json/wpaib/v1/categories \
  -H "X-API-Key: wpaib_xxxx..."
```

### Aggiorna un articolo

```bash
curl -X PUT https://ditutto-unpo.it/wp-json/wpaib/v1/posts/123 \
  -H "Content-Type: application/json" \
  -H "X-API-Key: wpaib_xxxx..." \
  -d '{ "status": "publish" }'
```

### Esempio Python

```python
import requests

API_BASE = "https://ditutto-unpo.it/wp-json/wpaib/v1"
HEADERS = {"X-API-Key": "wpaib_xxxx...", "Content-Type": "application/json"}

# 1. Upload immagine in evidenza
with open("cover.png", "rb") as f:
    import base64
    b64 = base64.b64encode(f.read()).decode()

media = requests.post(f"{API_BASE}/media", headers=HEADERS, json={
    "filename": "cover.png",
    "image_base64": b64
}).json()
media_id = media["id"]

# 2. Crea bozza con immagine in evidenza
post = requests.post(f"{API_BASE}/posts", headers=HEADERS, json={
    "title": "Articolo dal mio script",
    "content": "<p>Contenuto.</p>",
    "status": "draft",
    "featured_media": media_id,
    "tags": ["automazione"]
}).json()

print(f"Bozza creata: {post['link']}")
```

---

## Codici di risposta

| Codice | Significato |
|--------|-------------|
| 200 | OK |
| 201 | Risorsa creata |
| 400 | Body invalido o parametri mancanti |
| 401 | API key mancante o non valida |
| 403 | Capability insufficiente o HTTPS richiesto |
| 404 | Risorsa non trovata |
| 413 | File troppo grande |
| 429 | Rate limit superato |
| 500 | Errore server |

Per motivi di sicurezza, gli errori 401 non distinguono tra chiave mancante, errata o revocata (anti-enumeration).

---

## Configurazione avanzata (wp-config.php)

```php
// Solo se il tuo sito è dietro un reverse proxy/CDN FIDATO (Cloudflare, ecc.)
// e vuoi che l'IP loggato sia quello reale del client.
define( 'WPAIB_TRUST_PROXY', true );
```

**Non abilitare** `WPAIB_TRUST_PROXY` se il sito è esposto direttamente: gli header `X-Forwarded-For` sono spoofabili.

---

## Checklist di hardening post-installazione

Il plugin fa la sua parte, ma la sicurezza è una catena. Verifica anche:

- [ ] HTTPS valido e forzato su tutto il sito (Let's Encrypt, HSTS attivo)
- [ ] WordPress, plugin e tema sempre aggiornati
- [ ] Password admin forte + 2FA attivo per gli account con capability elevate
- [ ] Plugin di sicurezza attivo (Wordfence, Solid Security, ecc.)
- [ ] Backup automatici giornalieri off-site (UpdraftPlus, BackWPup)
- [ ] Limita login attempts (anti brute force)
- [ ] File `wp-config.php` con permessi 600
- [ ] Disabilita XML-RPC se non necessario
- [ ] Monitora la tabella `wp_wpaib_audit_log` periodicamente
- [ ] Revoca le API key che non usi più

---

## Disinstallazione

Disattivando il plugin, i dati restano nel database. **Per rimuovere tutto** (chiavi, log, opzioni), usa **Elimina** dalla pagina Plugin: lo script `uninstall.php` pulirà tutto.

---

## Roadmap

- v1.1: webhook in uscita per eventi (nuovo post creato via API, ecc.)
- v1.2: scadenza opzionale delle chiavi (TTL)
- v1.3: scope granulari per chiave (es. "solo lettura")
- v2.0: server MCP nativo integrato

---

## Limiti noti

- Il plugin non implementa cifratura at-rest dei log (le query del log sono in chiaro nel DB, ma non contengono dati sensibili)
- Rate limiter basato su transient: se usi un object cache esterno (Redis/Memcached) ne beneficia automaticamente; altrimenti finisce nel DB
- Upload limitato a 5 MB e ai tipi: jpg, png, gif, webp

---

## Supporto

Per bug, feature request o feedback: apri un issue o contatta lo sviluppatore.
