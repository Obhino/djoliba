# 📊 Index de la Base de Données — Djoliba

Ce document recense l'ensemble des index personnalisés (hors clés primaires auto-générées) créés dans la base de données PostgreSQL du projet Djoliba. Chaque index est accompagné de son objectif et des requêtes qu'il accélère.

---

## Vue d'ensemble

| Table              | Colonne(s) indexée(s)     | Migration                | Objectif                                                        |
|--------------------|---------------------------|--------------------------|-----------------------------------------------------------------|
| `sub_project`      | `research_project_id`     | Version20260516141430    | Jointure & filtre par projet de recherche parent                |
| `sub_project`      | `user_id`                 | Version20260516141430    | Filtre par utilisateur propriétaire                             |
| `sub_project`      | `type`                    | Version20260516141430    | Filtre par type (reading, literature, writing, thesis)          |
| `sub_project`      | `status`                  | **Version20260627060618** | Filtre par statut (active, archived, deleted)                  |
| `project`          | `user_id`                 | Version20260516141430    | Filtre par utilisateur propriétaire                             |
| `project`          | `type`                    | Version20260516141430    | Filtre par type de projet                                       |
| `project`          | `status`                  | **Version20260627060618** | Filtre par statut (active, archived, deleted)                  |
| `interaction`      | `project_id`              | Version20260516141430    | Jointure & filtre par projet                                    |
| `interaction`      | `sub_project_id`          | Version20260627034431    | Jointure & filtre par sous-projet                               |
| `interaction`      | `created_at`              | **Version20260627060618** | Tri chronologique des interactions (historique de chat)         |
| `document`         | `project_id`              | Version20260516141430    | Filtre par projet                                               |
| `document`         | `user_id`                 | Version20260516141430    | Filtre par utilisateur                                          |
| `document`         | `sub_project_id`          | Version20260627034431    | Filtre par sous-projet                                          |
| `project_activity` | `research_project_id`     | Version20260516141430    | Filtre par projet de recherche (journal d'activité)             |
| `project_activity` | `user_id`                 | Version20260516141430    | Filtre par utilisateur                                          |
| `project_activity` | `created_at`              | **Version20260627060618** | Tri chronologique du journal d'activité                        |

> Les index marqués en **gras** ont été ajoutés lors de l'optimisation de performance du 27/06/2026.

---

## Détail par table

### `sub_project`

```sql
-- Index existants (clés étrangères + métier)
CREATE INDEX IDX_506B8C1A_research_project_id ON sub_project (research_project_id);
CREATE INDEX IDX_506B8C1A_user_id             ON sub_project (user_id);
CREATE INDEX IDX_506B8C1A_type                ON sub_project (type);

-- ✅ Nouveau (Version20260627060618)
CREATE INDEX CONCURRENTLY IDX_506B8C1A7B00651C ON sub_project (status);
```

**Requêtes accélérées :**
- `SubProjectManager::getSubProjectsForUser()` — filtre `status != 'deleted'`
- `SubProjectManager::getSubProjectsForProject()` — filtre par `research_project_id` + `status`
- `SubProjectManager::getOrphanSubProjectsForUser()` — filtre `research_project_id IS NULL` + `status`

### `project`

```sql
-- Index existants
CREATE INDEX IDX_2FB3D0EE_user_id ON project (user_id);
CREATE INDEX IDX_2FB3D0EE_type    ON project (type);

-- ✅ Nouveau (Version20260627060618)
CREATE INDEX CONCURRENTLY IDX_2FB3D0EE7B00651C ON project (status);
```

**Requêtes accélérées :**
- Toutes les requêtes de listing de projets qui filtrent sur `status != 'deleted'`

### `interaction`

```sql
-- Index existants
CREATE INDEX IDX_378DFDA7_project_id     ON interaction (project_id);
CREATE INDEX IDX_378DFDA7_sub_project_id ON interaction (sub_project_id);

-- ✅ Nouveau (Version20260627060618)
CREATE INDEX CONCURRENTLY IDX_378DFDA78B8E8428 ON interaction (created_at);
```

**Requêtes accélérées :**
- Historique de chat dans `LiteratureController` — `ORDER BY created_at ASC`
- `ReadingChatController` — récupération des interactions récentes d'un sous-projet

### `document`

```sql
-- Index existants (tous créés avec les clés étrangères)
CREATE INDEX IDX_D8698A76_project_id     ON document (project_id);
CREATE INDEX IDX_D8698A76_user_id        ON document (user_id);
CREATE INDEX IDX_D8698A76_sub_project_id ON document (sub_project_id);
```

> Aucun nouvel index nécessaire : les colonnes `project_id` et `sub_project_id` étaient déjà indexées via les contraintes de clé étrangère.

### `project_activity`

```sql
-- Index existants
CREATE INDEX IDX_913A8281_research_project_id ON project_activity (research_project_id);
CREATE INDEX IDX_913A8281_user_id             ON project_activity (user_id);

-- ✅ Nouveau (Version20260627060618)
CREATE INDEX CONCURRENTLY IDX_913A82818B8E8428 ON project_activity (created_at);
```

**Requêtes accélérées :**
- Affichage du journal d'activité sur le hub central — `ORDER BY created_at DESC`

---

## Commande de diagnostic

Utilisez la commande suivante pour vérifier que les index sont bien utilisés par les requêtes :

```bash
# Vérification rapide (EXPLAIN uniquement)
php bin/console app:test-bdd-index

# Vérification approfondie (EXPLAIN ANALYZE — exécute réellement les requêtes)
php bin/console app:test-bdd-index --analyze

# Lister tous les index existants
php bin/console app:test-bdd-index --list-indexes
```

---

## Stratégie de déploiement sans downtime

Tous les nouveaux index utilisent `CREATE INDEX CONCURRENTLY` (PostgreSQL), ce qui permet :
- ✅ **Pas de verrou exclusif** sur la table pendant la création
- ✅ **Lectures et écritures non bloquées** durant l'opération
- ⚠️ La migration est **non-transactionnelle** (`isTransactional(): false`) car `CONCURRENTLY` ne peut pas s'exécuter dans un bloc `BEGIN/COMMIT`

---

## Bonnes pratiques

1. **Surveiller les index inutilisés** : Exécutez régulièrement `app:test-bdd-index` pour détecter les index non utilisés qui consomment de l'espace disque inutilement
2. **Éviter la sur-indexation** : Ne pas ajouter d'index sur des colonnes rarement utilisées dans les clauses `WHERE` ou `ORDER BY`
3. **Index composites** : Si une requête filtre fréquemment sur deux colonnes simultanément (ex: `user_id + status`), un index composite peut être plus performant que deux index simples
