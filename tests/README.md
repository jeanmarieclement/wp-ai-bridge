# Tests

Suite di smoke test che girano con il solo binario `php`, senza WordPress,
senza Composer e senza Docker.

```bash
./tests/run.sh          # tutte le suite
php tests/test-query-filters.php   # una sola
```

Exit code diverso da zero se qualcosa fallisce, quindi sono utilizzabili in CI
così come sono.

## Cosa coprono

| Suite | Copre |
|-------|-------|
| `test-rest-helper.php` | Logica pura di `WPAIB_Rest_Helper`: limite per pagina, `after_id` assente contro `after_id=0`, espansione di `status=any`, cursore successivo |
| `test-query-filters.php` | L'SQL che i filtri producono davvero: cursore su post, commenti, utenti e termini, e la restrizione di visibilità sugli elenchi |
| `test-openapi-schema.php` | Il documento OpenAPI: serializzabile, con tutti i path attesi, `operationId` unici, parametri ben formati |

## Perché esistono

Ogni asserzione qui dentro corrisponde a un difetto che una review ha trovato
davvero, non a un caso inventato per fare numero. Le più importanti:

- **Sticky post.** `WP_Query` considera `is_home` una query sul post type `post`,
  e il cursore rimuove `paged`: senza `ignore_sticky_posts` gli articoli in
  evidenza finivano in testa a *ogni* pagina del cursore, duplicati, e venivano
  ripescati con `post_status => 'publish'` anche in una richiesta di bozze.
- **Cache dei commenti.** `WP_Comment_Query` costruisce la chiave di cache dalle
  sole query var che conosce e senza includere l'SQL. La query var custom del
  cursore veniva scartata, quindi con Redis o Memcached ogni pagina poteva
  restituire di nuovo la prima. Il cursore viaggia in `cache_domain`, che nella
  chiave ci entra.
- **`status=any`.** Il letterale `'any'` di `WP_Query` esclude ogni stato
  registrato con `exclude_from_search`, ed è così che diversi plugin editoriali
  dichiarano gli stati di workflow riservati: quei contenuti sparivano da un
  export che si dichiarava completo.
- **Visibilità degli elenchi.** Una chiave con ruolo Autore riceveva 403 su
  `GET /posts/45` e lo stesso post, con tutto il corpo, da
  `GET /posts?status=draft`. Il test controlla anche la parentesizzazione della
  clausola: `AND status IN (...) OR author = N` senza parentesi restituirebbe
  l'intero database.

## Cosa NON coprono

Sono test di unità su logica isolata, con WordPress simulato da stub. **Non**
verificano il comportamento reale degli endpoint: autenticazione, capability
applicate da WordPress, serializzazione delle risposte, interazione con il DB.
Per quello serve il giro manuale su `docker compose up`.

Gli stub di WordPress stanno dentro ciascuna suite, non in `bootstrap.php`.
Non è una svista: `get_post_stati()` serve con i flag `internal` /
`exclude_from_search` in una suite e con `public` nell'altra, e condividerne
una sola versione farebbe passare un test mentre in realtà ne verifica un'altra
cosa. Ogni suite gira nel proprio processo, quindi la duplicazione non costa
nulla e ciascun file si legge da solo.

## Regressioni su WordPress reale

`review-regressions.php` verifica autenticazione REST/MCP, scope OAuth, API key,
visibilità dei commenti e degli allegati, paginazione, cache e validazione MIME
sullo stack di sviluppo. Eseguirlo separatamente dalle suite con stub:

```bash
docker exec -i wpaib-wordpress php < tests/review-regressions.php
```

Le fixture e le credenziali temporanee sono annullate con rollback. Usare
un'installazione di sviluppo con il plugin attivo e un amministratore.
