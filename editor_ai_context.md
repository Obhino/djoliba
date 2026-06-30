# CONTEXTE DE L'ASSISTANT IA DE L'ÉDITEUR INTERACTIF (WYSIWYG & LaTeX) - DJOLIBA

Ce document définit les spécifications fonctionnelles, techniques et d'intégration de l'assistant IA interactif (Copilote IA) dans l'éditeur de Djoliba.

---

## 1. Philosophie & Principes Directeurs
L'assistant de rédaction de Djoliba fonctionne comme un **copilote interactif et non intrusif** :
- **L'auteur garde le contrôle absolu** : L'IA suggère des corrections ou des pistes de réflexion, mais ne modifie pas le texte à l'insu du chercheur.
- **Déclenchement volontaire** : L'assistant s'active uniquement sur sélection de texte ou via un raccourci clavier.
- **Intégration fluide** : Les modifications acceptées sont insérées à la place du texte sélectionné. Les suggestions non retenues peuvent être archivées ou rejetées.
- **Traçabilité** : Toutes les interactions de l'éditeur sont enregistrées dans l'historique associé au sous-projet (`SubProject`) et à l'utilisateur (`User`).

---

## 2. Fonctionnalités de l'Assistant IA

### 2.1 Fonctionnalités Standard de l'Éditeur
1. **Vérification du raisonnement** : Analyse de la cohérence logique du texte (identification des prémisses, des inférences et de la conclusion) et suggestions d'amélioration de la rigueur scientifique.
2. **Reformulation** : Propose 2 à 3 versions alternatives du texte sélectionné pour en améliorer la clarté et le style académique.
3. **Correction d'équations** : Détection des erreurs (parenthèses, indices, opérateurs) dans les équations LaTeX sélectionnées et propositions de corrections conformes.
4. **Développement d'idées** : Expansion d'une idée concise ou incomplète en lui suggérant 3 à 5 pistes de développement.
5. **Demander à l'IA (Q&A)** : Pose d'une question libre avec envoi automatique du paragraphe sélectionné comme contexte.
6. **Suggestion de références** : Extraction des mots-clés d'un paragraphe et requête vers des APIs académiques réelles (OpenAlex/Crossref) pour proposer des références à insérer.
7. **Détection de redondances** : Identification des répétitions de mots ou de concepts et proposition de synonymes ou d'alternatives concises.
8. **Générateur de code** : Production de scripts scientifiques (Python, R, Julia, Bash) basés sur la description textuelle fournie par le chercheur.

> [!NOTE]
> La fonctionnalité de **visualisation de données** est retirée des spécifications pour le moment.

### 2.2 Fonctionnalités Enrichies (Spécifiques au Contexte de Recherche de Djoliba)
9. **Simulation de Peer Review (Revue par les pairs)** : Analyse le paragraphe ou la section sélectionnée sous l'angle d'un relecteur de revue scientifique (forces, faiblesses méthodologiques, objections potentielles) en accordant une attention particulière à la pertinence et au contexte de la recherche africaine.
10. **Traduction Académique Bilingue** : Traduit le texte sélectionné (Français <-> Anglais) en conservant rigoureusement le vocabulaire de spécialité, les expressions idiomatiques de la discipline, et en préservant le formatage mathématique (KaTeX).
11. **Ajusteur de Registre Académique** : Permet de reformuler le texte sélectionné pour cibler un registre spécifique (ex. voix active pour plus d'impact, ton ultra-formel pour une introduction de thèse, ou vulgarisation scientifique pour une diffusion grand public).
12. **Glossaire & Explication de Concepts** : Fournit une explication encyclopédique et contextuelle d'un terme scientifique ou technique sélectionné.

---

## 3. Architecture Technique

### 3.1 Entité Doctrine : `EditorInteraction`
Une entité dédiée permet d'enregistrer l'historique des interactions avec l'IA dans l'éditeur.

| Champ | Type | Description |
| :--- | :--- | :--- |
| `id` | `SERIAL` (INT) | Identifiant unique. |
| `subProject` | `FK` (`SubProject`) | Lien avec le sous-projet en cours de rédaction. |
| `user` | `FK` (`User`) | Utilisateur à l'origine de la demande. |
| `action` | `VARCHAR(50)` | Action déclenchée (`reasoning`, `reformulate`, `equation`, `expand`, `ask`, `reference`, `redundancy`, `code`, `peer_review`, `translate`, `tone`, `explain`). |
| `selectedText` | `TEXT` | Extrait de texte sélectionné envoyé à l'IA. |
| `suggestion` | `TEXT` | Réponse générée par l'IA. |
| `accepted` | `BOOLEAN` | Indique si le chercheur a appliqué la suggestion dans l'éditeur (null si en attente, true si accepté, false si ignoré/rejeté). |
| `createdAt` | `DATETIME` | Date et heure de l'interaction. |

### 3.2 Services PHP (`src/Service/Editor/`)
Les services héritent d'une interface commune pour normaliser l'appel à DeepSeek :

- **`AIAssistantService`** : Orchestre les requêtes de l'éditeur en coordonnant les services spécialisés et en enregistrant l'historique via `EditorHistoryManager`.
- **`EditorHistoryManager`** : Gère l'écriture, la mise à jour de l'acceptation et la récupération de l'historique d'interactions.
- **Services Spécifiques** :
  - `ReasoningVerifier`
  - `Reformulator`
  - `EquationChecker`
  - `IdeaExpander`
  - `AIResponder` (Streaming)
  - `ReferenceSuggester` (Interfaçage avec `SuggestionService`)
  - `RedundancyDetector`
  - `CodeGenerator`
  - `PeerReviewSimulator`
  - `AcademicTranslator`
  - `AcademicToneAdjuster`
  - `ConceptExplainer`

### 3.3 Endpoints API (`src/Controller/Api/EditorController.php`)
Intégration de nouvelles routes sous le préfixe `/api` :

1. **`POST /api/projects/{id}/editor-ai/execute`** : Pour les requêtes standard (non-streaming) comme la correction d'équations, la suggestion de références, la détection de redondances et l'enregistrement de l'historique.
2. **`POST /api/projects/{id}/editor-ai/stream`** : Route de streaming SSE (Server-Sent Events) pour les réponses longues (reformulation, demande IA, code, revue par les pairs, traduction, ajustement du ton).
3. **`GET /api/projects/{id}/editor-ai/history`** : Récupération de l'historique des interactions de l'éditeur pour le sous-projet.
4. **`POST /api/editor-ai/interaction/{interactionId}/status`** : Met à jour le statut d'acceptation (`accepted` = `true`/`false`).

---

## 4. Prompts d'Ingénierie IA (DeepSeek)

### 4.1 Vérification du raisonnement
```
Tu es un relecteur scientifique rigoureux. Analyse la structure logique du paragraphe suivant. 
Identifie clairement :
1. Les prémisses implicites ou explicites.
2. La validité des inférences ou des liens de cause à effet.
3. La solidité de la conclusion par rapport aux arguments présentés.

Signale toute faille logique (généralisation hâtive, faux dilemme, corrélation confondue avec causalité) et propose une formulation alternative plus rigoureuse.
Texte à analyser : "{text}"
```

### 4.2 Reformulation
```
Propose 3 variations de reformulation académique pour le texte suivant. 
Assure-toi de :
- Conserver strictement le sens scientifique initial.
- Améliorer l'élégance du style, la clarté et la concision.
- Fournir des propositions allant de la plus formelle à la plus directe.

Texte à reformuler : "{text}"
```

### 4.3 Correction d'équations
```
Tu es un expert en notation LaTeX scientifique. Analyse l'équation LaTeX suivante :
"{text}"

Détecte s'il y a des erreurs de syntaxe, des parenthèses ou accolades non fermées, ou des anomalies typographiques. 
Retourne l'équation corrigée au format LaTeX strict ainsi qu'une explication brève de l'erreur identifiée s'il y en a une.
```

### 4.4 Développement d'idées
```
L'idée scientifique suivante est encore embryonnaire ou incomplète. 
Propose 3 à 5 pistes concrètes pour l'approfondir. Structure ta réponse avec :
- Une clarification des concepts clés.
- Des pistes d'exploration empirique ou méthodologique.
- Des angles théoriques complémentaires.

Idée à développer : "{text}"
```

### 4.5 Demander à l'IA
```
En contexte de rédaction de travaux de recherche, réponds à la question suivante : "{question}".
Utilise le paragraphe suivant comme contexte immédiat de rédaction pour calibrer ta réponse :
"{text}"
```

### 4.6 Détection de redondances
```
Analyse le paragraphe suivant et identifie :
1. Les répétitions lexicales excessives.
2. Les redondances d'idées ou les pléonasmes académiques.

Propose une version épurée du paragraphe, plus dynamique et concise, en suggérant des synonymes appropriés au contexte scientifique.
Texte : "{text}"
```

### 4.7 Génération de code
```
Génère un script structuré en langage {language} basé sur la description et les paramètres suivants. 
Le script doit respecter les standards de qualité (bonnes pratiques, commentaires, gestion des erreurs). 
Description : "{text}"
```

### 4.8 Simulation de Peer Review (Enrichi)
```
Simule un rapport de relecture (Peer Review) constructif et exigeant pour le paragraphe ci-dessous.
Fournis :
1. Une critique méthodologique (limites éventuelles).
2. Une évaluation des affirmations (sont-elles étayées par le texte ou spéculatives ?).
3. Une perspective sur l'intégration des réalités et données locales africaines si applicable au sujet.
4. Des recommandations précises de réécriture.

Texte : "{text}"
```

### 4.9 Traduction Académique Bilingue (Enrichi)
```
Traduis le texte scientifique suivant en {target_language}.
Consignes impératives :
- Conserve le ton académique formel et rigoureux.
- Utilise la terminologie exacte de la discipline.
- Préserve intacts les blocs de code et les expressions mathématiques LaTeX (ex: $...$, $$...$$).

Texte : "{text}"
```

### 4.10 Ajusteur de Registre Académique (Enrichi)
```
Reformule le paragraphe ci-dessous pour adopter le registre de rédaction suivant : "{register}".
Choix de registres disponibles :
- "Voix Active Directe" : Rendre le ton plus percutant, éviter la sur-utilisation du passif.
- "Formel & Distancié" : Utiliser le "nous" de modestie ou des structures impersonnelles adaptées aux revues majeures.
- "Vulgarisation & Impact" : Conserver la précision mais simplifier les structures de phrases pour un public non spécialisé.

Texte : "{text}"
```

### 4.11 Glossaire & Concept Explainer (Enrichi)
```
Explique de manière concise et rigoureuse le concept ou terme scientifique suivant : "{text}".
Fournis :
- Une définition consensuelle dans la discipline concernée.
- Son importance ou rôle dans la recherche contemporaine.
- Sa traduction ou équivalent dans l'autre langue (anglais/français).
```

---

## 5. Interface Utilisateur & Interactions

L'assistant s'insère directement dans l'interface de l'éditeur existant :
1. **Barre d'outils contextuelle (Floating Toolbar)** :
   - Apparaît au-dessus du texte WYSIWYG sélectionné.
   - Présente des icônes rapides pour les actions courantes : raisonnement, reformulation, traduction, peer-review, demande à l'IA.
2. **Modales de Résultats (Floating Modals)** :
   - Affiche les suggestions de l'IA (avec streaming si applicable).
   - Bouton **"Remplacer la sélection"** : remplace le texte sélectionné par la suggestion IA.
   - Bouton **"Insérer après"** : insère la suggestion à la suite du paragraphe sélectionné.
   - Boutons **"Accepter"** (enregistre `accepted = true` en base) et **"Ignorer"** (enregistre `accepted = false`).
3. **Raccourcis Clavier** :
   - `Ctrl+Shift+R` : Vérifier le raisonnement.
   - `Ctrl+Shift+F` : Reformuler.
   - `Ctrl+Shift+E` : Vérifier l'équation LaTeX.
   - `Ctrl+Shift+D` : Développer l'idée.
   - `Ctrl+Shift+A` : Demander à l'IA.
   - `Ctrl+Shift+P` : Détecter les redondances.
   - `Ctrl+Shift+C` : Générer du code.
   - `Ctrl+Shift+T` : Traduire (Action Enrichie).
   - `Ctrl+Shift+W` : Peer Review (Action Enrichie).
