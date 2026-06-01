# 📊 Rapport Master : État d'Avancement Permanent — Djoliba (DjolibaSearch)

> **Djoliba** : La 1ère plateforme autonome de recherche scientifique en Afrique.  
> *Conçu pour les chercheurs académiques de Côte d'Ivoire, du Sénégal et du continent africain.*

Ce document récapitule l'**ensemble des fonctionnalités développées depuis le début du projet**, l'architecture technique sous-jacente, les récentes innovations ergonomiques (éditeur de thèse sans en-tête, surbrillance active dans le plan) et l'état d'avancement complet du projet.

---

## 🏗️ 1. Architecture & Stack Technique du Projet
Depuis son lancement, Djoliba est construit sur des technologies robustes, modernes et orientées performance :

| Composant | Technologie | Statut / Rôle |
| :--- | :--- | :--- |
| **Backend Framework** | Symfony 7.x (PHP 8.2+) | Cœur applicatif, injection de services et découplage strict. |
| **Base de Données** | PostgreSQL 16 + pgvector | Stockage relationnel et indexation sémantique vectorielle. |
| **Cache & Sessions** | Redis 7 | Stockage des sessions, cache des synthèses IA et files d'attente. |
| **Asynchronisme (Queue)** | Symfony Messenger | Traitement asynchrone des uploads (antivirus) et exports. |
| **Frontend UI** | Tailwind CSS + Symfony UX (Stimulus) | Interface fluide sans rechargement de page (Single Page Feel). |
| **Moteur d'IA** | DeepSeek API | Génération de synthèses, cohérence et écriture assistée. |
| **SMTP de Dével.** | MailHog / Mailpit | Interception et test des envois de courriels en local. |
| **BDD Admin Dev** | Adminer | Interface web légère d'administration de base de données. |

---

## 💎 2. Chronique Exhaustive des Fonctionnalités (Depuis le début)

### 🔐 MODULE 1 : Authentification & Profil Académique
*   **Enregistrement & Connexion** : Processus d'authentification robuste (`AuthController.php` et `UserAuthenticator.php`) avec hachage sécurisé des mots de passe (Bcrypt) et validation d'e-mail par lien de confirmation signé (`symfonycasts/verify-email-bundle`).
*   **Gestion d'inactivité & Sauvegarde Automatique** : Détection de l'inactivité de session (1 heure) avec sauvegarde automatique sécurisée en base de données du projet actif ouvert en session, déconnexion propre et message flash premium (`SessionTimeoutSubscriber.php`).
*   **Interface de Gestion de Profil par l'Utilisateur (`ProfileController.php`)** :
    *   **Dashboard Personnel Dédié** : Accessible instantanément en cliquant sur l'avatar ou le nom d'utilisateur dans la barre latérale gauche (sidebar).
    *   **Informations Personnelles & Connexion** : Édition en temps réel du prénom, nom de famille et adresse email (avec vérification automatique d'unicité et de validité).
    *   **Identité Académique** : Saisie et contrôle strict de format de l'identifiant chercheur unique **ORCID** (`XXXX-XXXX-XXXX-XXXX`), de l'affiliation institutionnelle (institution / université), du domaine de recherche scientifique, du **statut académique** (Licence, Master, Doctorant, Enseignant-chercheur, etc.), de la **biographie scientifique** et du lien **Google Scholar** pour lier ses publications.
    *   **Préférences Système** : Personnalisation bilingue de la langue de l'interface (FR/EN) et activation de l'assistant d'aide contextuelle à la rédaction.

### 🔍 MODULE 2 : Recherche & Revue de Littérature (pgvector)
*   **Moteur de Recherche Scientifique** : Analyse de requêtes sémantiques combinant mots-clés classiques et similarité cosinusoïdale vectorielle (grâce à l'extension `pgvector` de PostgreSQL).
*   **Revue de Littérature Automatique (`LiteratureService.php`)** :
    *   Synthèse automatique structurée abordant les fondements théoriques, les tendances récentes, les lacunes de recherche courantes et les articles incontournables liés à la thématique.
*   **Suggestions d'Articles Scientifiques** : Extraction et structuration au format JSON (`title`, `authors`, `year`, `abstract`, `doi`) de publications connexes et opportunités de citations.

### 📚 MODULE 3 : Compagnon de Lecture & Analyse de PDF
*   **Upload Sécurisé de Documents (`FileStorageService.php`)** :
    *   Support natif des formats scientifiques : **PDF**, **DOCX**, et **LaTeX (.tex)**.
    *   Validation stricte de la taille (limite à 25 Mo) et scan antivirus en temps réel.
*   **Synthèse de Lecture Interactive** :
    *   Extraction de contenu et génération de points clés synthétiques.
    *   *Optimisation Ergonomique* : Inversion de la grille d'affichage (la zone de chat interactif dynamique est placée à gauche occupant 7 colonnes pour un confort de lecture optimal, tandis que la zone d'import et la synthèse statique occupent 5 colonnes sur la droite).
*   **Chat Interactif Sémantique & Streaming (SSE)** :
    *   Interface de chat dynamique permettant de questionner l'article scientifique importé.
    *   **Gestion d'Historique de Conversation** : Mise en place d'un historique intelligent (`ReadingService` avec limitation aux 10 derniers échanges) pour exploiter le **cache de contexte DeepSeek**. Le fichier PDF n'est envoyé qu'au premier message, ce qui évite la sur-facturation de tokens lors des questions suivantes.
    *   **Cache local Redis de Session** : Intégration d'un cache local Redis (`DeepSeekService->call()`) avec TTL de 24h pour retourner instantanément et gratuitement les réponses aux requêtes identiques au sein de la même session (zéro coût API et zéro latence au rechargement).
    *   **Ajustement Visuel Premium & Font Controls** :
        *   Textes des bulles de messages utilisateur entièrement blancs (`#ffffff !important`) même en cas de balises imbriquées générées par le parseur de markdown.
        *   Intégration de boutons dynamiques de contrôle de la taille de la police (`A-` / `A+`) dans l'en-tête du chat, persistant le choix de l'utilisateur dans le `localStorage` du navigateur.

### ✍️ MODULE 4 : Assistant de Rédaction Scientifique (Rich Editor v2)
*   **Composant d'Édition Réutilisable (`_writing_editor.html.twig`)** :
    *   **Mode Visuel (WYSIWYG)** : Propulsé par **TipTap**, avec barre d'outils sémantique (Gras, Italique, Titres scientifiques H1/H2/H3, Listes, Code block).
    *   **Mode LaTeX Brut** : Propulsé par **CodeMirror** (coloration syntaxique tailored avec le thème sombre `djoliba-latex-theme`), idéal pour le code source et les formules.
    *   *Isolation des données* : Les brouillons WYSIWYG et LaTeX brut sont indépendants, permettant de rédiger deux versions parallèles sans perte.
*   **Prévisualisation LaTeX en Split Screen (50/50)** :
    *   Fenêtre d'aperçu scientifique side-by-side interprétée à la volée par **Marked** et **KaTeX** pour un rendu instantané des formules mathématiques.
*   **Vérification de l'Originalité** :
    *   Détection de plagiat et de texte rédigé par IA, avec suggestions de reformulations académiques.
*   **Orientation de Publication** :
    *   Module suggérant les revues scientifiques cibles optimales en analysant la sémantique et la discipline du texte rédigé.

### 📁 MODULE 5 : Espace de Thèse & Plan Réorganisable par Drag & Drop
*   **IDE Plein Écran Sépare (Split 20/80)** :
    *   *Gauche (20%)* : Plan interactif (arborescence des chapitres et sous-parties) avec ascenseurs de défilement indépendants pour supporter les titres longs.
    *   *Droite (80%)* : Canevas de rédaction complet sans défilement général de page.
*   **Structure Hiérarchique Dynamique (SortableJS)** :
    *   Glisser-déposer hiérarchique à plusieurs niveaux, gérant les chapitres vides par hauteur minimale sur les listes enfants.
    *   Mise à jour en direct (`PUT /api/thesis/structure`) avec Toast de succès.
*   **Optimisation Espace & Épurage** :
    *   **En-tête de Titre de Section Supprimé** : Retrait total de la zone de titre supérieure dans l'éditeur droit pour libérer 80px de hauteur de rédaction.
    *   **Mise en Surbrillance Active (`.thesis-tree-item-active`)** : L'arborescence à gauche porte seule l'information du chapitre sélectionné avec une mise en valeur haut de gamme (fond beige/navy, bordure d'ancrage 3px, texte marine en gras et ombre).
    *   **Autosave Déplacé** : Témoin de sauvegarde automatique discrètement positionné dans le dock d'actions inférieur.
    *   **Suppression des Boutons Doublons** : Retrait des boutons IA superflus de l'éditeur de thèse pour un environnement d'écriture calme et concentré.

### 📤 MODULE 6 : Exportations Premium
*   **Modale de Formats Fluide** : Modale d'exportation plein écran floutée (`backdrop-blur-md bg-[#0B2545]/60`) avec transition d'échelle.
*   **Trois Formats d'Export** : PDF Scientifique, archive ZIP structurée contenant les chapitres séparés et LaTeX brut.
*   **Overlay Loader de Compilation** : Spinner rotatif en temps réel pendant la génération côté serveur avec téléchargement automatique transparent.

---

## 📈 3. Tableau de l'État d'Avancement Permanent

| Module | Fonctionnalité Spécifique | Origine | Statut |
| :--- | :--- | :--- | :--- |
| **Auth / Profil** | Enregistrement, ORCID, affiliations | Inception | **100% Fonctionnel** |
| **Auth / Profil** | Vérification d'e-mail par lien signé (verify-email-bundle) | Sécurité | **100% Fonctionnel** |
| **Auth / Profil** | Détection d'inactivité & Sauvegarde Projet (1 heure) | Session | **100% Fonctionnel** |
| **Auth / Profil** | Interface d'administration & Préférences chercheur | Profil v1 | **100% Fonctionnel** |
| **Recherche** | Similarité vectorielle sémantique (pgvector) | Inception | **100% Fonctionnel** |
| **Recherche** | Génération de revues de littérature structurées | Inception | **100% Fonctionnel** |
| **Analyse PDF** | Upload sécurisé & Antivirus (25 Mo) | Inception | **100% Fonctionnel** |
| **Analyse PDF** | Synthèse interactive & Chat streaming (SSE) | Inception | **100% Fonctionnel** |
| **Analyse PDF** | Cache local Redis de Session (24h) | Inception | **100% Fonctionnel** |
| **Analyse PDF** | Cache contextuel DeepSeek (Historique des 10 échanges) | Inception | **100% Fonctionnel** |
| **Analyse PDF** | Contrôle dynamique de police (A-/A+ & localStorage) | Raffinement | **100% Fonctionnel** |
| **Écriture v2** | TipTap WYSIWYG & CodeMirror LaTeX sombre | v2 | **100% Fonctionnel** |
| **Écriture v2** | Split screen 50/50 side-by-side & KaTeX | v2 | **100% Fonctionnel** |
| **Écriture v2** | Importation DOCX/TEX & Raccourcis format | v2 | **100% Fonctionnel** |
| **Écriture v2** | Détection d'originalité & suggestions revues | Inception | **100% Fonctionnel** |
| **Thèse IDE** | Plan interactif multi-niveaux (SortableJS) | v2 | **100% Fonctionnel** |
| **Thèse IDE** | Surbrillance `.thesis-tree-item-active` (0ms) | Raffinement | **100% Fonctionnel** |
| **Thèse IDE** | Suppression en-tête de titre pour full bleed | Raffinement | **100% Fonctionnel** |
| **Thèse IDE** | Déplacement statut Autosave en bas de page | Raffinement | **100% Fonctionnel** |
| **Thèse IDE** | Nettoyage des doublons/boutons d'IA | Raffinement | **100% Fonctionnel** |
| **Export** | Modale d'exportation premium & Overlay loader | Export | **100% Fonctionnel** |
| **Export** | Générateur ZIP/PDF/TEX unifié (`ExportController`) | Export | **100% Fonctionnel** |
| **Cohérence** | Analyse structurelle IA & réécriture asynchrone | Cohérence | **100% Fonctionnel** |
| **Docker** | PostgreSQL 16, Redis, Mailhog, Workers | Inception | **100% Fonctionnel** |
| **Docker** | Administration de BDD stable via **Adminer** | Docker | **100% Fonctionnel** |
| **Tests Qualité**| Suite fonctionnelle PHPUnit `ThesisControllerTest` | v2 | **100% Validé** |

---

## 🐳 4. Focus Infrastructure Docker & Administration BDD
L'environnement Docker de Djoliba a été affiné pour garantir un développement fluide sur Windows et Linux :

*   **Résolution pgAdmin (Windows)** : pgAdmin provoquait des boucles infinies de crashs/redémarrages à cause de sécurités internes (Gunicorn refusant de s'exécuter sous root) et de conflits de droits d'écriture sous Windows Desktop WSL2.
*   **Intégration d'Adminer (Port 8080)** : Adminer a été choisi comme l'alternative web idéale. Sans aucun volume à gérer ni restrictions d'utilisateurs, il est ultra-léger et se lance instantanément.
*   **Clients Natifs (Port 5433)** : Pour un confort maximal sur Windows, la base de données est exposée sur le port `5433`, permettant d'utiliser de puissantes applications locales comme **DBeaver** ou **TablePlus** avec les identifiants :
    *   *Hôte* : `127.0.0.1` | *Port* : `5433` | *BDD* : `djolibadb` | *User* : `djoliba` | *Pass* : `djoliba_secret`
