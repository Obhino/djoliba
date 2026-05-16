# Djoliba — Documentation des Commandes

## Commandes Symfony Console

### Installation & Setup

| Commande | Description |
|---|---|
| `composer install` | Installe les dépendances PHP |
| `php bin/console doctrine:database:create` | Crée la base de données PostgreSQL |
| `php bin/console doctrine:migrations:migrate -n` | Exécute toutes les migrations en attente |
| `php bin/console make:migration` | Génère une nouvelle migration à partir des entités modifiées |
| `php bin/console messenger:setup-transports` | Crée la table `messenger_messages` en base de données |
| `php bin/console cache:clear` | Vide le cache Symfony |

---

### Serveur de développement

| Commande | Description |
|---|---|
| `symfony serve -d` | Lance le serveur de développement en arrière-plan (https://127.0.0.1:8000) |
| `symfony server:stop` | Arrête le serveur de développement |
| `symfony server:log` | Affiche les logs du serveur en temps réel |

---

### Messenger (File d'attente asynchrone)

| Commande | Description |
|---|---|
| `php bin/console messenger:setup-transports` | Crée les tables nécessaires au transport Doctrine |
| `php bin/console messenger:consume async -vv` | Lance le worker qui traite les messages de la file `async` |
| `php bin/console messenger:consume async failed -vv` | Lance le worker pour traiter les messages en échec |
| `php bin/console messenger:failed:show` | Liste les messages en échec |
| `php bin/console messenger:failed:retry` | Retente l'envoi des messages en échec |

> **Production** : utiliser Supervisor pour maintenir le worker actif en permanence.
> ```ini
> [program:djoliba_worker]
> command=php /path/to/bin/console messenger:consume async --time-limit=3600
> autostart=true
> autorestart=true
> ```

---

### Commandes Métier (App)

#### `app:projects:cleanup`
Supprime les projets dont la date d'expiration (`expires_at`) est dépassée.

```bash
# Simuler sans supprimer (recommandé avant la 1ère exécution en prod)
php bin/console app:projects:cleanup --dry-run

# Supprimer effectivement les projets expirés
php bin/console app:projects:cleanup
```

**Options :**

| Option | Description |
|---|---|
| `--dry-run` | Affiche les projets qui seraient supprimés, sans effectuer de changement en BDD |
| `-v` | Augmente la verbosité des logs |

**Planification (cron job recommandé) :**
```bash
# Tous les jours à 2h du matin
0 2 * * * /usr/bin/php /path/to/djoliba/bin/console app:projects:cleanup --env=prod >> /var/log/djoliba_cleanup.log 2>&1
```

---

### Débogage & Diagnostics

| Commande | Description |
|---|---|
| `php bin/console debug:router` | Liste toutes les routes enregistrées |
| `php bin/console debug:container <service>` | Inspecte un service dans le conteneur DI |
| `php bin/console debug:event-dispatcher` | Liste tous les événements et leurs listeners |
| `php bin/console doctrine:schema:validate` | Vérifie que le mapping Doctrine est valide par rapport à la BDD |
| `php bin/console doctrine:query:sql "SELECT * FROM project LIMIT 5"` | Exécute une requête SQL directement |

---

### Git — Conventions de commit

Ce projet suit la convention **Conventional Commits** :

| Préfixe | Usage |
|---|---|
| `feat:` | Nouvelle fonctionnalité |
| `fix:` | Correction de bug |
| `fix(security):` | Correction de vulnérabilité de sécurité |
| `refactor:` | Refactoring sans changement de comportement |
| `docs:` | Mise à jour de la documentation |
| `chore:` | Tâche de maintenance (dépendances, config) |

**Exemple :**
```bash
git add . ; git commit -m "feat: creation du service ProjectManager"
```

---

### Tests API — Exemples curl

```bash
# Revue de littérature
curl -X POST https://127.0.0.1:8000/api/literature/review \
  -H "Content-Type: application/json" \
  -d '{"query": "bien-être au travail en Afrique", "project_id": 1}'

# Suggestions d'articles (limit optionnel, défaut: 5, max: 10)
curl -X POST https://127.0.0.1:8000/api/literature/suggestions \
  -H "Content-Type: application/json" \
  -d '{"query": "bien-être au travail en Afrique", "limit": 5}'

# Streaming SSE (affichage progressif)
curl -N -X POST https://127.0.0.1:8000/api/stream \
  -H "Content-Type: application/json" \
  -H "Accept: text/event-stream" \
  -d '{"prompt": "Résume cet article en 3 paragraphes", "project_id": 1}'
```

---

*Dernière mise à jour : 2026-05-16*
