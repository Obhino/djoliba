# DJOLIBA - CONTEXTE RESTRUCTURATION (VERSION 3.0)
Dernière mise à jour : 2026-06-27
Auteur : Ulrich
Objectif : Restructuration profonde de l’architecture pour passer d’une logique « par activité » à une logique « gestion de projet de recherche »

1. IDENTITÉ PROJET
Nom : Djoliba (DjolibaSearch)

Sous-titre : La 1ère plateforme autonome de recherche scientifique en Afrique

Marché cible : Côte d'Ivoire, Sénégal – chercheurs académiques

Langues : Français et anglais (bilingue)

Phase 1 : Gratuit (financement participatif 6 mois)

Phase 2 : Payant (Étudiant 3-5k CFA, Enseignant 15k+ CFA, Laboratoire forfait)

2. OBJECTIF DE LA RESTRUCTURATION
Passer d’une logique « par activité » à une logique « gestion de projet de recherche ».

Plus de « projet de synthèse », « projet de lecture », « projet d’écriture ».

Un seul type de projet parent : Projet de recherche (ResearchProject).

Les activités (Lecture, Synthèse, Écriture, Thèse) deviennent des sous-projets liés à ce projet parent.

Les sous-projets peuvent être orphelins si aucun projet actif n’est sélectionné.

3. CONTRAINTES
Rétrocompatibilité : Les anciens projets doivent continuer de fonctionner.

Évolutivité : La structure doit permettre d’ajouter de nouveaux types de sous-projets.

Performance : Les requêtes doivent rester fluides (optimisation des indexes).

Cohérence : Les données existantes (anciennes) doivent être migrées progressivement (sans perte).

4. NOUVELLE STRUCTURE DE DONNÉES
Entité ResearchProject (projet parent)
Champ	Type	Contraintes
id	SERIAL/UUID	PRIMARY KEY
user_id	FK	NOT NULL
title	VARCHAR(255)	NOT NULL
description	TEXT	NULLABLE
status	VARCHAR(50)	DEFAULT 'active'
is_template	BOOLEAN	DEFAULT false
created_at	TIMESTAMP	NOT NULL
updated_at	TIMESTAMP	NULLABLE
Entité SubProject (sous-projet)
Champ	Type	Contraintes
id	SERIAL/UUID	PRIMARY KEY
research_project_id	FK	NULLABLE (si orphelin)
user_id	FK	NOT NULL
type	ENUM	'reading', 'literature', 'writing', 'thesis'
name	VARCHAR(255)	NOT NULL
content	TEXT	NULLABLE (Markdown)
status	VARCHAR(50)	DEFAULT 'active'
metadata	JSONB	NULLABLE (stockage des métadonnées spécifiques)
created_at	TIMESTAMP	NOT NULL
updated_at	TIMESTAMP	NULLABLE
Entité ProjectMember (collaboration)
Champ	Type	Contraintes
id	SERIAL	PRIMARY KEY
research_project_id	FK	NOT NULL
user_id	FK	NOT NULL
role	ENUM	'owner', 'editor', 'viewer'
invited_at	TIMESTAMP	NOT NULL
joined_at	TIMESTAMP	NULLABLE
status	ENUM	'pending', 'active', 'declined'
Entité ProjectActivity (chronologie)
Champ	Type	Contraintes
id	SERIAL	PRIMARY KEY
research_project_id	FK	NOT NULL
user_id	FK	NOT NULL
action	VARCHAR(50)	NOT NULL
metadata	JSONB	NULLABLE
created_at	TIMESTAMP	NOT NULL
5. INTERFACE UTILISATEUR
Sidebar (nouvelle)
text
┌─────────────────────────────────────────────────────────────┐
│  🏠 Tableau de bord                                        │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  🔬 Projets de recherche                                     │
│  ├── Microscope confocale                  [● actif]        │
│  ├── IA en santé Afrique                                    │
│  └── + Nouveau projet                                       │
│                                                              │
│  📚 Sous-projets (projet actif)                             │
│  ├── 📖 Lecture (3)                                         │
│  ├── 📄 Synthèse (5)                                        │
│  ├── ✍️ Écriture (2)                                        │
│  └── 🎓 Thèse (1)                                           │
│                                                              │
│  💻 Assistant de codage                                     │
│  ⚙️ Paramètres                                              │
└─────────────────────────────────────────────────────────────┘
Comportement attendu
Action	Comportement
Clic sur un projet	Affiche la gestion du projet : liste des sous-projets, statistiques, actions.
Clic sur un type de sous-projet	Affiche tous les sous-projets de ce type (liés au projet actif ou orphelins).
Clic sur un sous-projet	Affiche l’interface associée (lecture, synthèse, écriture, thèse).
Bouton « Nouveau »	Menu déroulant : créer un projet de recherche, ou un sous-projet (Lecture, Synthèse, Écriture, Thèse).
Projet actif	Mis en évidence (surbrillance professionnelle) dans la sidebar.
6. NOUVELLES ROUTES API
ResearchProject
text
GET    /api/research-projects
POST   /api/research-projects
GET    /api/research-projects/{id}
PUT    /api/research-projects/{id}
DELETE /api/research-projects/{id}
GET    /api/research-projects/{id}/sub-projects
POST   /api/research-projects/{id}/sub-projects
POST   /api/research-projects/{id}/share
PUT    /api/research-projects/{id}/members/{userId}
DELETE /api/research-projects/{id}/members/{userId}
SubProject
text
GET    /api/sub-projects
POST   /api/sub-projects
GET    /api/sub-projects/{id}
PUT    /api/sub-projects/{id}
DELETE /api/sub-projects/{id}
GET    /api/sub-projects/type/{type}
POST   /api/sub-projects/{id}/attach/{researchProjectId}
POST   /api/sub-projects/{id}/detach
ProjectActivity
text
GET    /api/research-projects/{id}/activities
GET    /api/research-projects/{id}/activities/{action}
7. NOUVEAUX SERVICES
ResearchProjectManager
createForUser(user, title, description, isTemplate)

getActiveProjectsForUser(user)

getProject(id)

updateProject(project, data)

deleteProject(project)

archiveProject(project)

setActiveProject(user, project) (session)

SubProjectManager
createForUser(user, type, name, researchProject = null)

getSubProjectsForUser(user)

getSubProjectsForProject(researchProject)

getOrphanSubProjectsForUser(user)

attachToProject(subProject, researchProject)

detachFromProject(subProject)

deleteSubProject(subProject)

ProjectSwitcher
setActiveProject(user, project)

getActiveProject(user)

clearActiveProject(user)

ProjectCollaborationManager
inviteUser(project, email, role)

acceptInvitation(project, user)

declineInvitation(project, user)

removeMember(project, user)

changeRole(project, user, role)

ProjectActivityManager
logActivity(project, user, action, metadata)

getActivitiesForProject(project, limit = 50)

getActivitiesForUser(user, limit = 50)

8. PROMPTS ANTIGRAVITY (PAR ORDRE)
Prompt 1 – Nouvelles entités et migration
markdown
Je restructure mon projet Symfony (Djoliba) pour passer d'une logique "par activité" à une logique "gestion de projet".

Objectif :
1. Créer l'entité `ResearchProject` liée à User (OneToMany)
2. Créer l'entité `SubProject` liée à ResearchProject ou orpheline
3. Créer les entités `ProjectMember` et `ProjectActivity`
4. Ajouter une colonne `research_project_id` nullable aux anciennes entités Project (pour compatibilité)
5. Créer les services associés (ResearchProjectManager, SubProjectManager, etc.)
6. Créer les migrations et un script de migration des données existantes

Contraintes :
- Rétrocompatibilité : les anciens projets continuent de fonctionner
- Migration progressive : les anciennes données sont converties en sous-projets orphelins ou rattachés à un nouveau ResearchProject

Génère le code complet.
Prompt 2 – Services de gestion
markdown
Je crée les services pour la gestion des projets et sous-projets.

Services à créer :
1. ResearchProjectManager
2. SubProjectManager
3. ProjectSwitcher (gestion du projet actif en session)
4. ProjectCollaborationManager
5. ProjectActivityManager

Génère le code complet des services.
Prompt 3 – Sidebar dynamique
markdown
Je crée la nouvelle sidebar pour Djoliba avec Stimulus.

Structure :
- Liste des projets de recherche (projet actif en surbrillance)
- Liste des types de sous-projets (Lecture, Synthèse, Écriture, Thèse) avec compteurs
- Bouton "Nouveau" (menu déroulant)

Comportement :
- Clic sur un projet → redirection vers la gestion
- Clic sur un type → affichage des sous-projets (liés ou orphelins)
- Le projet actif est stocké en session

Génère le code Twig + Stimulus.
Prompt 4 – Interface de gestion de projet
markdown
Je crée la page de gestion d'un ResearchProject.

Contenu :
- En-tête : titre, description, statut
- Liste des sous-projets (Lecture, Synthèse, Écriture, Thèse) avec actions
- Statistiques (nombre de sous-projets, dernières activités)
- Bouton "Exporter le projet" (ZIP)
- Gestion des membres (invitation, rôles)

Génère le code Twig, le contrôleur, et les routes associées.
Prompt 5 – Navigation unifiée
markdown
Je unifie la navigation dans Djoliba.

Routes :
- / → Tableau de bord
- /research-project/{id} → Gestion du projet
- /research-project/{id}/sub-projects/{type} → Liste des sous-projets
- /sub-project/{id} → Interface d'activité

Logique :
- Si projet actif → afficher les sous-projets liés
- Si aucun projet actif → afficher les sous-projets orphelins
- Le bouton "Nouveau" permet de créer un projet ou un sous-projet

Génère les routes, les contrôleurs et les vues associées.
Prompt 6 – Collaboration et partage
markdown
J'ajoute la collaboration aux ResearchProjects.

Fonctionnalités :
- Inviter un utilisateur par email (rôle : owner, editor, viewer)
- Accepter/refuser une invitation
- Gérer les membres (changer rôle, retirer)
- Notifications (Messenger + email)
- Affichage des membres dans la page de gestion du projet

Génère les entités, les services, les contrôleurs et les vues associés.
Prompt 7 – Chronologie (journal d'activité)
markdown
J'ajoute un journal d'activité aux ResearchProjects.

Fonctionnalités :
- Log à chaque action : création, modification, ajout de sous-projet, etc.
- Affichage dans la page de gestion du projet (flux chronologique)
- Filtres par type d'action
- Pagination (30 par page)

Génère les entités, les services, les contrôleurs et les vues associés.
9. RÉSUMÉ DES NOUVELLES FONCTIONNALITÉS
Fonctionnalité	Statut	Priorité
ResearchProject (structure parent)	🔜 À implémenter	Haute
SubProject (sous-projets)	🔜 À implémenter	Haute
Sidebar dynamique	🔜 À implémenter	Haute
Gestion de projet (CRUD)	🔜 À implémenter	Haute
Navigation unifiée	🔜 À implémenter	Haute
Collaboration (partage)	🔜 À implémenter	Haute
Chronologie (journal)	🔜 À implémenter	Moyenne
Templates de projet	🔜 À implémenter	Moyenne
Assistant de codage	🔜 À implémenter	Moyenne
Export intelligent	🔜 À implémenter	Basse
10. NOTES DE VERSION
Version : 3.0 (restructuration)
Dernière mise à jour : 2026-06-27
Auteur : Ulrich
Projet : Djoliba – Plateforme autonome de recherche scientifique en Afrique