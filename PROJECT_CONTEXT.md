# DJOLIBA - CONTEXTE ARCHITECTURE PERMANENT
Version: 1.0 | Dernière mise à jour: 2026-05-16

## IDENTITÉ PROJET
- **Nom**: Djoliba (DjolibaSearch)
- **Sous-titre**: La 1ère plateforme autonome de recherche scientifique en Afrique
- **Marché cible**: Côte d'Ivoire, Sénégal – chercheurs académiques
- **Langues**: Français et anglais (bilingue)
- **Phase 1**: Gratuit (financement participatif 6 mois)
- **Phase 2**: Payant (Étudiant 3-5k CFA, Enseignant 15k+ CFA, Laboratoire forfait)

## STACK TECHNIQUE
| Composant | Technologie |
|-----------|-------------|
| Backend | Symfony 7 |
| Base de données | PostgreSQL + pgvector |
| Cache | Redis |
| Queue | Messenger + Doctrine |
| Frontend | Tailwind CSS + Symfony UX (Stimulus) |
| IA Production | DeepSeek API |
| IA Développement | Antigravity (Gemini 3 Pro / 2.5 Flash) |
| Hébergement | VPS (Hetzner CPX11) |
| CI/CD | GitHub Actions |

## STRUCTURE DES DOSSIERS
src/
├── Controller/
│ ├── AuthController.php
│ ├── ProjectController.php
│ ├── LiteratureController.php
│ ├── ReadingController.php
│ ├── WritingController.php
│ ├── ThesisController.php
│ ├── ExportController.php
│ └── StreamController.php
├── Entity/
│ ├── User.php
│ ├── Project.php
│ ├── Interaction.php
│ ├── Document.php
│ ├── Chapter.php
│ └── DailyMetrics.php
├── Service/
│ ├── IA/
│ │ ├── DeepSeekService.php
│ │ └── CacheService.php
│ ├── Project/
│ │ ├── ProjectManager.php
│ │ └── ProjectExporter.php
│ ├── File/
│ │ └── FileStorageService.php
│ ├── LiteratureService.php
│ ├── ReadingService.php
│ ├── WritingService.php
│ ├── ThesisService.php
│ └── QuotaManager.php (phase 2)
├── Message/
│ ├── ProcessDocumentMessage.php
│ └── ExportProjectMessage.php
├── MessageHandler/
│ ├── ProcessDocumentMessageHandler.php
│ └── ExportProjectMessageHandler.php
└── Security/
└── UserAuthenticator.php

## ENTITÉS DOCTRINE

### User
| Champ | Type | Contraintes |
|-------|------|-------------|
| id | SERIAL/UUID | PRIMARY KEY |
| email | VARCHAR(255) | UNIQUE, NOT NULL |
| password | VARCHAR(255) | NOT NULL (bcrypt) |
| first_name | VARCHAR(100) | NULLABLE |
| last_name | VARCHAR(100) | NULLABLE |
| orcid | VARCHAR(19) | UNIQUE, NULLABLE |
| affiliation | VARCHAR(255) | NULLABLE |
| research_field | VARCHAR(255) | NULLABLE |
| theme_preference | VARCHAR(10) | DEFAULT 'light' |
| language_preference | VARCHAR(2) | DEFAULT 'fr' |
| help_enabled | BOOLEAN | DEFAULT true |
| trial_ends_at | TIMESTAMP | NULLABLE |
| created_at | TIMESTAMP | NOT NULL |
| updated_at | TIMESTAMP | NULLABLE |
| last_login | TIMESTAMP | NULLABLE |
| is_active | BOOLEAN | DEFAULT true |

### Project
| Champ | Type | Contraintes |
|-------|------|-------------|
| id | SERIAL/UUID | PRIMARY KEY |
| user_id | FK | NOT NULL |
| type | ENUM | literature_review, reading, writing, thesis |
| name | VARCHAR(255) | NOT NULL |
| status | ENUM | active, archived, deleted |
| created_at | TIMESTAMP | NOT NULL |
| updated_at | TIMESTAMP | NULLABLE |
| last_accessed_at | TIMESTAMP | NULLABLE |
| expires_at | TIMESTAMP | NULLABLE |
| metadata | JSONB | NULLABLE |

### Interaction
| Champ | Type | Contraintes |
|-------|------|-------------|
| id | SERIAL | PRIMARY KEY |
| project_id | FK | NOT NULL |
| type | ENUM | literature_review, reading_chat, writing_check, writing_suggest_journal, thesis_assist |
| user_prompt | TEXT | NOT NULL |
| ai_response | TEXT | NULLABLE |
| response_time_ms | INTEGER | NULLABLE |
| tokens_used | INTEGER | NULLABLE |
| cost_cfa | DECIMAL(10,2) | NULLABLE |
| created_at | TIMESTAMP | NOT NULL |

### Document
| Champ | Type | Contraintes |
|-------|------|-------------|
| id | SERIAL | PRIMARY KEY |
| project_id | FK | NOT NULL |
| user_id | FK | NOT NULL |
| filename | VARCHAR(255) | NOT NULL |
| stored_path | VARCHAR(512) | NOT NULL |
| mime_type | VARCHAR(100) | NOT NULL |
| size_bytes | INTEGER | NOT NULL |
| is_scanned | BOOLEAN | DEFAULT false |
| virus_found | BOOLEAN | DEFAULT false |
| created_at | TIMESTAMP | NOT NULL |

### Chapter
| Champ | Type | Contraintes |
|-------|------|-------------|
| id | SERIAL | PRIMARY KEY |
| project_id | FK | NOT NULL |
| parent_id | FK | NULLABLE (auto-référence) |
| title | VARCHAR(255) | NOT NULL |
| content | TEXT | NULLABLE |
| order | INTEGER | NOT NULL |
| created_at | TIMESTAMP | NOT NULL |
| updated_at | TIMESTAMP | NULLABLE |

### DailyMetrics
| Champ | Type |
|-------|------|
| date | DATE (PK) |
| active_users | INTEGER |
| new_registrations | INTEGER |
| ia_requests_total | INTEGER |
| ia_requests_literature | INTEGER |
| ia_requests_reading | INTEGER |
| ia_requests_writing | INTEGER |
| ia_requests_thesis | INTEGER |
| files_uploaded | INTEGER |
| avg_deepseek_response_ms | INTEGER |
| error_count | INTEGER |
| exports_count | INTEGER |

## ROUTES API

### Authentification
POST /api/auth/register
POST /api/auth/login
POST /api/auth/logout
POST /api/auth/forgot-password

### Projets
GET /api/projects
POST /api/projects
GET /api/projects/{id}
PUT /api/projects/{id}
DELETE /api/projects/{id}
GET /api/projects/{id}/export

### Revue de littérature
POST /api/literature/review
POST /api/literature/suggestions

### Aide à la lecture
POST /api/reading/upload
POST /api/reading/{id}/synthesize
POST /api/reading/{id}/chat (SSE)

### Aide à l'écriture
POST /api/writing/check
POST /api/writing/suggest-journal

### Rédaction longue
GET /api/thesis/structure
POST /api/thesis/structure
PUT /api/thesis/chapter/{id}
DELETE /api/thesis/chapter/{id}
POST /api/thesis/write

### Streaming
POST /api/stream/chat

### Utilisateur
GET /api/user/profile
PUT /api/user/profile
POST /api/user/zotero/link
POST /api/user/mendeley/import
GET /api/user/quota (phase 2)

## SERVICES CLÉS

### DeepSeekService
- `call(prompt, stream=false)`: Appel API standard
- `stream(prompt, callback)`: Streaming SSE
- Retry automatique (2-3 tentatives)
- Timeout: 60 secondes

### CacheService (Redis)
- `get(key)`: Récupère du cache
- `set(key, value, ttl)`: Stocke en cache
- TTL recherche: 24h
- TTL synthèse: 1h

### ProjectManager
- `createProject(user, type, name)`
- `getUserProjects(user)`
- `getProject(id)`
- `updateProject(project, data)`
- `deleteProject(project)`
- `archiveProject(project)`

### FileStorageService
- `upload(file, project, user)`: Valide 25 Mo, formats PDF/DOCX/LaTeX, scan antivirus
- `getDocument(id)`
- `deleteDocument(document)`

### LiteratureService
- `review(query, project)`: Revue de littérature
- Prompt: "Effectue une revue de littérature sur: {query}. Inclus: fondement théorique, tendances récentes, lacunes, articles incontournables."

### SuggestionService
- `suggest(query, limit=5)`: Suggestions d'articles
- Prompt: "Suggère {limit} articles scientifiques complémentaires à: {query}. Réponse JSON: [{title, authors, year, abstract, doi}]."

### ReadingService
- `synthesize(document)`: Génère synthèse du PDF
- `chat(project, question)`: Chat sur l'article avec contexte

### WritingService
- `checkOriginality(text)`: Vérification originalité
- `suggestJournal(text)`: Suggestion revue cible

### ThesisService
- `getStructure(project)`: Arborescence chapitres
- `addChapter(project, title, parentId)`
- `updateChapter(chapter, title, content)`
- `deleteChapter(chapter)`
- `getConsistency(project)`: Cohérence inter-chapitres

### ProjectExporter
- `exportToZip(project)`: Génère ZIP avec documents, synthèses, historique, métadonnées

## FORMAT RÉPONSE API

### Succès
```json
{
    "success": true,
    "data": { ... }
}

### Erreur
{
    "success": false,
    "error": {
        "code": 400,
        "message": "Format de fichier non supporté",
        "details": "Seuls les fichiers PDF, DOCX et LaTeX sont acceptés"
    }
}