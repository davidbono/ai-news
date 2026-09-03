# News IA

Ce site publie chaque jour un digest de l'actualité IA de six entreprises
(Google, Apple, OpenAI, Anthropic, Mistral AI, DeepSeek), un classement des
meilleurs LLM et un classement des meilleurs "harnesses"/agents de code
(Claude Code, Cursor, Codex CLI...), ainsi que le cours de bourse des
entreprises cotées parmi elles.

Accessible sur [penloup.eu/news-ai.php](https://penloup.eu/news-ai.php).

## Fonctionnement

- Un workflow [n8n](https://n8n.io) ("News IA quotidien") s'exécute chaque
  jour à 7h : une IA recherche sur le web l'actualité récente de chaque
  entreprise ainsi que les deux classements, à partir de sources publiques,
  puis récupère le cours de bourse de Google et Apple (seules entreprises
  cotées parmi les six suivies — OpenAI, Anthropic, Mistral AI et DeepSeek
  sont privées, la page l'indique plutôt que d'inventer un chiffre).
- Le contenu du jour **remplace** celui de la veille dans la base SQLite ;
  avant d'être remplacée, la page telle qu'elle était est figée en HTML
  statique dans `archives/news-ai-{date}.php`, servie à l'identique pour
  toujours (voir `news-ai-archives.php` pour l'index de ces archives).
- `archives/` peut aussi contenir des pages écrites à la main (traductions,
  analyses...) plutôt que générées par le workflow — elles apparaissent
  automatiquement dans une section "Articles" de l'index des archives,
  distincte des éditions quotidiennes.

## Fichiers

- `n8n/news-ai-quotidien.json` — export du workflow n8n "News IA quotidien"
  (déclencheur quotidien 7h, recherche web + extraction structurée pour
  l'actu et les deux classements, cours de bourse via l'API Yahoo Finance,
  publication vers `news-ai.php`). Réimportable tel quel dans n8n ; les
  identifiants de credentials référencés (OpenAI, jeton HTTP) devront être
  remappés vers des credentials existants sur l'instance cible, le secret
  lui-même n'étant jamais inclus dans l'export.
- `news-ai.php` — l'application (rendu de la page en GET, réception de la
  publication quotidienne du workflow n8n en POST).
- `news-ai-archives.php` — index des archives (éditions quotidiennes +
  articles).
- `lib.php` — schéma de la base, logique de rendu partagée entre la page
  en direct et les archives figées, liste des entreprises suivies.
- `logos/` — logos SVG des entreprises ([Simple Icons](https://simpleicons.org),
  CC0), recolorés en blanc sur un cercle de la couleur de marque.
- `archives/` — voir ci-dessus. Les éditions quotidiennes auto-générées
  (`news-ai-????-??-??.php`) ne sont pas versionnées ; les pages écrites à
  la main le sont.
- `config.php` *(non versionné)* — jeton de publication.
- `news.db` *(non versionné)* — données générées automatiquement,
  reconstruites chaque jour par le workflow.

## Licence

Ce projet est distribué sous licence [GNU GPL v3](https://www.gnu.org/licenses/gpl-3.0.html)
ou toute version ultérieure — voir le fichier [`LICENSE`](LICENSE).
