# Dr. Brief — chatbot assistant temps réel sur un crawl

## Verdict de faisabilité

**Oui, faisable**, sans changement d'infra majeur. Les briques nécessaires existent toutes :

- **Function calling Gemini** : le modèle décide quand appeler un outil `run_sql`. C'est natif sur tous les modèles Gemini 2.x.
- **Streaming Gemini** : l'endpoint `streamGenerateContent` renvoie du SSE (chunks de texte + appels de fonction au fil de l'eau). Permet l'UX "Dr. Brief est en train d'écrire / d'exécuter une requête / de te répondre" en temps réel.
- **Sécurité SQL** : on réutilise tel quel le pipeline existant de `QueryController` (whitelist stricte des tables, SELECT-only, substitution de `pages` → `pages_<crawl_id>`, limites). Dr. Brief n'aura **aucun privilège SQL supplémentaire** par rapport à ce que l'utilisateur peut faire dans le SQL Explorer.
- **Clé Gemini + modèle** déjà configurables dans Settings (admin).

Effort estimé : ~2-3 jours de dev focalisé pour un MVP propre.

---

## Vision UX

### Le widget

- **Bulle flottante** en bas à droite du dashboard (style Intercom), visible **uniquement quand on est dans le contexte d'un crawl** (`dashboard.php?crawl=X`).
- Avatar / nom **Dr. Brief**. Icône stéthoscope ou similaire.
- Clic → s'ouvre en **panneau latéral** de 400-500px de large, hauteur full-viewport. Bouton ✕ pour réduire (l'historique est conservé).
- Conserve l'historique de la session courante via une table DB (voir plus bas). Reload de la page → la conversation est restaurée.
- Bouton "Nouvelle conversation" pour repartir à zéro.

### Le flux d'un message

L'utilisateur tape *"Combien j'ai d'URLs en 404 ?"* puis Enter.

```
You · 14:32
Combien j'ai d'URLs en 404 ?

Dr. Brief · ⌛ écrit…
  └─ ✨ Réflexion…              ← phase 1 (1-2s)
  └─ 🛠 Préparation d'une requête SQL :
        SELECT COUNT(*) FROM pages WHERE code = 404
  └─ ⚡ Exécution… (0.3s)
  └─ ✓ 23 résultats

Dr. Brief · 14:32
Tu as **23 URLs en 404** dans ce crawl.
[Voir la liste détaillée dans le SQL Explorer →]
```

Le bloc "Réflexion / Préparation / Exécution" est **collapsible** (replié par défaut une fois la réponse arrivée — l'utilisateur peut le déplier pour voir la SQL exacte qui a été exécutée). Touche pro qui inspire confiance et permet le debug.

### Les types de réponses

Dr. Brief peut répondre 4 façons selon ce que la requête renvoie :

1. **Scalaire** (count, sum, avg) → "Tu as **23 URLs en 404**." Texte naturel + bold sur les chiffres clés.
2. **Petite liste** (≤ 10 lignes) → tableau compact inline.
3. **Grande liste** (> 10 lignes) → 10 premiers + *"…et 1 247 autres. [Voir tout dans le SQL Explorer →]"* avec un lien deeplink (`/dashboard.php?crawl=X&page=sql-explorer&q=<sql encodée>`).
4. **Erreur** (SQL invalide, table refusée, timeout) → message d'erreur clair + Dr. Brief peut auto-retry une fois avec correction.

### Multi-tour

L'utilisateur peut continuer : *"Et combien parmi elles ont au moins un inlink ?"*. Dr. Brief utilise le contexte précédent pour formuler la requête de suivi. L'historique est passé à chaque appel Gemini.

---

## Architecture technique

### Vue d'ensemble

```
┌──────────────────────────┐
│  Browser (dr-brief.js)   │
│  EventSource SSE         │
└────────────┬─────────────┘
             │ POST /api/dr-brief/chat (stream)
             ▼
┌──────────────────────────┐
│  DrBriefController       │
│  - SSE response handler  │
│  - boucle tool calls     │
└────────────┬─────────────┘
             │
     ┌───────┴──────────────────┐
     ▼                          ▼
┌──────────────┐         ┌─────────────────┐
│ ChatAgent    │◄────────│ SqlQueryTool    │
│ - Gemini SSE │         │ - réutilise     │
│ - stream     │         │   QueryController│
│   forward    │         │   logic (sécu)  │
└──────┬───────┘         └─────────────────┘
       │
       ▼
┌──────────────┐
│ Gemini API   │
│ streamGenerate│
└──────────────┘
```

### Nouveaux fichiers

```
app/AI/
  ChatAgent.php             — orchestre la boucle stream + tool calls
  DrBriefPrompt.php         — system prompt (persona + schéma + contexte crawl)
  GeminiStream.php          — client SSE bas niveau pour streamGenerateContent
  Tools/
    SqlQueryTool.php        — définition + exécution sécurisée de `run_sql`

app/Http/Controllers/
  DrBriefController.php     — endpoints chat / history / clear

app/Database/
  ChatRepository.php        — accès aux nouvelles tables

web/components/
  dr-brief-widget.php       — bulle + panneau + JS embarqué (chargé par dashboard.php)

migrations/
  20XX-XX-XX-dr-brief-tables.php
```

### Schéma DB

```sql
CREATE TABLE chat_sessions (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    crawl_id    INTEGER NOT NULL,
    title       VARCHAR(255),                -- auto-généré : 1ers mots de la 1ère question
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_chat_sessions_user_crawl ON chat_sessions(user_id, crawl_id);

CREATE TABLE chat_messages (
    id            SERIAL PRIMARY KEY,
    session_id    INTEGER NOT NULL REFERENCES chat_sessions(id) ON DELETE CASCADE,
    role          VARCHAR(10) NOT NULL CHECK (role IN ('user','assistant','tool')),
    content       TEXT,                       -- texte naturel
    tool_calls    JSONB,                      -- [{name, args}, ...] si role=assistant
    tool_results  JSONB,                      -- [{rows, columns, truncated}, ...] si role=tool
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_chat_messages_session ON chat_messages(session_id, created_at);
```

### Protocole streaming (SSE)

L'endpoint `POST /api/dr-brief/chat` répond en `text/event-stream`. Évents :

| Event             | Payload                                  | UI fait quoi                              |
|-------------------|------------------------------------------|-------------------------------------------|
| `thinking`        | `{}`                                     | Affiche "Réflexion…"                      |
| `tool_call_start` | `{tool: 'run_sql'}`                      | Affiche "Préparation d'une requête SQL…"  |
| `tool_call_ready` | `{tool: 'run_sql', args: {query: '…'}}`  | Affiche le SQL dans un code block         |
| `tool_executing`  | `{tool: 'run_sql'}`                      | Spinner "Exécution…"                      |
| `tool_result`     | `{rows, columns, total, truncated}`      | Affiche le mini-tableau ou le scalaire    |
| `text_delta`      | `{delta: '…'}`                           | Append au message en cours                |
| `done`            | `{usage: {in, out}, session_id, msg_id}` | Marque le message complet                 |
| `error`           | `{message}`                              | Affiche l'erreur en rouge                 |

Côté PHP : il faut désactiver l'output buffering (`header('X-Accel-Buffering: no')` pour nginx + `@ini_set('output_buffering', 'off')` + `ob_implicit_flush(true)`).

### Boucle tool calls côté serveur (pseudo-code)

```
function streamChat(session, userMessage):
    save user message to DB
    messages = load history from DB (truncated to last N turns)
    messages.push(userMessage)

    loop:
        emit("thinking")
        response = gemini.streamGenerateContent(system_prompt, messages, [SqlQueryTool])

        for chunk in response:
            if chunk.is_text:
                emit("text_delta", chunk.text)
                append to current_assistant_message

            if chunk.is_function_call:
                emit("tool_call_ready", chunk.tool_call)
                emit("tool_executing")
                result = SqlQueryTool.execute(chunk.tool_call.args, crawl_id)
                emit("tool_result", result)
                messages.push(assistant_partial_with_tool_call)
                messages.push(tool_result_message)
                continue outer loop          # Gemini doit continuer après le tool

        save assistant message + tool calls/results to DB
        emit("done")
        break
```

### Le SqlQueryTool

```json
{
  "name": "run_sql",
  "description": "Execute a read-only PostgreSQL SELECT query against the current crawl's data and return rows. Use this whenever you need data from the crawl (URLs, counts, links, categories, ...).",
  "parameters": {
    "type": "object",
    "properties": {
      "query": {
        "type": "string",
        "description": "A single PostgreSQL SELECT statement. Use bare table names (pages, links, ...) — the partition suffix is added automatically. Always include a LIMIT clause unless you're computing an aggregate."
      },
      "purpose": {
        "type": "string",
        "description": "One short sentence explaining what this query is meant to answer. Shown to the user."
      }
    },
    "required": ["query", "purpose"]
  }
}
```

L'exécution :
1. Récupère `query` et `crawl_id` du contexte
2. **Réutilise la classe** `QueryController` / la logique de validation existante (à factoriser dans un `SqlExecutor` service partagé) : whitelist tables, SELECT-only, substitution `pages` → `pages_<id>`, LIMIT max 10 000
3. Exécute via PDO
4. Renvoie `{rows: [...], columns: [...], total_rows: int, truncated: bool, sql_executed: string}`
5. Si erreur, renvoie `{error: "...", sql_attempted: "..."}` — Dr. Brief peut auto-corriger sur le tour suivant

### Le system prompt (DrBriefPrompt)

Structure :
1. **Persona** : *"Tu es Dr. Brief, un assistant SEO chevronné qui aide à comprendre un crawl. Tu réponds en [langue de l'utilisateur], en restant concis et factuel."*
2. **Contexte du crawl courant** : domaine, ID, nombre d'URLs total, date du crawl, profondeur max, présence ou non d'extracteurs custom, etc. — injecté côté serveur à chaque session.
3. **Schéma DB** : copier-coller le schéma déjà rédigé pour `SqlGenPrompt` (mêmes tables, mêmes conventions Scouter).
4. **Règles d'usage du tool** :
   - Toujours utiliser `run_sql` quand une question demande des données chiffrées du crawl
   - Toujours mettre `LIMIT 10` sur les listes (pour la preview) sauf si la question demande explicitement un compte
   - Sur les listes > 10 lignes, terminer par un lien vers le SQL Explorer avec la requête complète (sans LIMIT)
5. **Format de réponse** :
   - Mettre les chiffres en `**gras**`
   - Utiliser des listes à puces si pertinent
   - Pas plus de 3-4 phrases sauf si la question est complexe

Schéma + conventions seront **cachés via le prompt caching de Gemini** (1h de cache) — économise tokens et latence sur les tours suivants.

### Deep-link vers SQL Explorer

Le SQL Explorer accepte déjà un paramètre `?q=...` (à vérifier — sinon, à ajouter facilement). Dr. Brief construit le lien :

```
/dashboard.php?crawl=<id>&page=sql-explorer&q=<base64-encoded-sql>
```

Côté SQL Explorer, le JS au load lit `?q=`, décode et préremplit le textarea CodeMirror. L'utilisateur clique Exécuter quand il veut. → Pas de SQL auto-exécuté à l'arrivée pour rester sûr.

---

## Sécurité

| Risque                                | Mitigation                                                                 |
|---------------------------------------|----------------------------------------------------------------------------|
| Gemini émet du SQL malicieux          | Whitelist tables stricte côté `QueryController` (déjà en place)            |
| Dépassement de tokens / coût          | Truncation conversation (garder les 10 derniers tours), prompt caching     |
| Exfiltration cross-crawl              | `crawl_id` injecté serveur-side, jamais lu depuis les args du tool         |
| Spam / DOS                            | Rate limit par user : ex. 30 messages/h, 200/jour                          |
| Injection prompt depuis un H1 crawlé  | Le SQL renvoie des données mais on **n'évalue jamais** comme du prompt — c'est du contenu cité au modèle |
| Fuite session entre users             | Tout scopé par `user_id` + `crawl_id` (FK + check)                         |

---

## Phasing

### MVP (v1) — ~3 jours
- Widget bulle + panneau, persistance DB
- Streaming SSE basique (états : thinking / tool_running / text)
- 1 seul tool : `run_sql`
- Affichage scalaire + petite liste + deep-link SQL Explorer
- Modèle = celui choisi dans Settings (même clé)

### v2 — nice to have
- Affichage tableau riche dans le chat (tri local, mini-chart auto pour les agrégations time-series)
- 2ᵉ tool : `explain_metric` (Dr. Brief explique ce que veut dire "PageRank interne", "compliant", etc.)
- Streaming des deltas SQL (l'utilisateur voit la requête s'écrire caractère par caractère — purement cosmétique)
- Export conversation en .md
- "Suggested questions" au démarrage (3 propositions basées sur le crawl : *"Combien de 404 ? Quelles pages les plus profondes ? Y a-t-il du duplicate content ?"*)
- Multi-crawl (Dr. Brief peut comparer 2 crawls si l'utilisateur en mentionne un autre)

### v3 — futur
- Multi-sessions (l'utilisateur peut avoir plusieurs conversations parallèles sur un même crawl)
- Partage de conversation par lien
- Recherche dans l'historique

---

## Questions à trancher avant de coder

1. **Persistance** : conversation sauvegardée en DB par défaut, ou éphémère (perdue au reload) ?
   *Mon avis : DB, plus utile pour reprendre une analyse. Bouton "Effacer" si l'utilisateur veut.*

2. **Scope crawl** : Dr. Brief reste sur le crawl courant, ou peut interroger d'autres crawls du même projet via la syntaxe `pages@<id>` du SQL Explorer ?
   *Mon avis : MVP scope crawl courant uniquement, multi-crawl en v2.*

3. **Granularité du "thinking"** : montrer chaque étape (Réflexion → SQL → Exécution → Résultat) ou juste un spinner global "Dr. Brief réfléchit…" jusqu'à la réponse finale ?
   *Mon avis : granulaire — c'est ce qui rend ça impressionnant et inspire confiance.*

4. **Streaming des deltas de texte caractère par caractère** ou par paragraphe ?
   *Mon avis : par delta natif Gemini (typique = ~10-30 caractères par chunk), donne un effet "machine à écrire" naturel sans bricolage.*

5. **Rate limit** : combien de messages par user et par heure ?
   *Mon avis : 30 messages/h, 200/jour. Configurable plus tard si besoin.*

6. **Le widget est visible pour qui** ? Tous les users d'un projet ou seulement le owner / les users avec accès `manage` ?
   *Mon avis : tous les users avec accès au projet — Dr. Brief lit la même chose qu'eux dans le SQL Explorer.*

7. **Auto-suggested questions** au démarrage : on les inclut dans le MVP ou on attend la v2 ?
   *Mon avis : v2, vu que ça demande de pré-calculer/templer des questions pertinentes.*

8. **Format des réponses** : Markdown rendu côté client (tableaux, bold, code blocks) ou texte brut ?
   *Mon avis : Markdown — le rendu est trivial avec une lib légère type `marked.js` (~10ko) et améliore énormément la lisibilité.*

9. **Le nom dans l'UI** : "Dr. Brief" seul ou "Dr. Brief — Assistant IA Scouter" ?

10. **Avatar** : on génère un avatar custom ou on utilise une icône Material Symbols (`smart_toy`, `psychology`, `medical_information`...) ?

---

## Estimation détaillée

| Tâche                                    | Effort  |
|------------------------------------------|---------|
| Migration DB + ChatRepository            | 0.5j    |
| GeminiStream (SSE bas niveau)            | 0.5j    |
| ChatAgent (boucle stream + tool calls)   | 0.75j   |
| SqlQueryTool (factorisation depuis QueryController) | 0.5j |
| DrBriefController + routes               | 0.25j   |
| Widget UI (HTML/CSS bulle + panneau)     | 0.5j    |
| JS chat (EventSource, rendu markdown, états) | 0.75j |
| Persona + prompt + tests manuels         | 0.25j   |
| **Total MVP**                            | **~4 jours** |

(plus large que mon estimation initiale, mais c'est honnête)

---

## Prochaine étape

Tu réponds aux 10 questions ci-dessus (ou tu en survoles seulement celles qui te tiennent à cœur, je prends mes recommandations par défaut pour le reste), et on attaque dans l'ordre : migration → backend → UI.
