# Gestion des Secrets - Djoliba

Ce document décrit la stratégie de sécurisation et de gestion des secrets et des clés API pour l'application Djoliba.

## Secrets Requis
Les secrets critiques suivants doivent être définis dans l'environnement de production :
- `DEEPSEEK_API_KEY` : Clé API pour la génération d'articles et de synthèses via l'IA DeepSeek.
- `OPENSERP_API_KEY` : Clé API / Token pour l'interrogation du moteur de recherche OpenSERP.
- `DB_PASSWORD` : Mot de passe de connexion à la base de données PostgreSQL.

---

## Stratégie de Déploiement en Production

### 1. Variables d'Environnement Système (Recommandé)
En production, il est recommandé d'injecter directement ces valeurs sous forme de variables d'environnement système via votre orchestrateur (Docker Compose, Kubernetes, ou la configuration de l'hébergeur) :
```bash
export DEEPSEEK_API_KEY="sk_prod_..."
export OPENSERP_API_KEY="os_prod_..."
export DB_PASSWORD="mot_de_passe_robuste"
```

### 2. Fichier de Production `.env.prod`
Si vous ne pouvez pas injecter les variables directement au niveau du système, vous pouvez placer un fichier nommé `.env.prod` à la racine du projet sur le serveur de production.

> [!CAUTION]
> Le fichier `.env.prod` contient des clés réelles et **ne doit JAMAIS être committé dans le dépôt Git**. Il est répertorié dans le fichier `.gitignore` du projet.

Un modèle est disponible dans le dépôt sous le nom `.env.prod`.

---

## Commandes et Validation

### Validation à l'initialisation (Boot)
Une validation stricte est exécutée à chaque démarrage de l'application (`Kernel::boot()`) en environnement de production (`prod`). Si l'un des secrets requis est vide ou contient une valeur par défaut de template, l'application s'arrête immédiatement en levant une `RuntimeException`.

### Vérification Manuelle via Console
Vous pouvez à tout moment inspecter l'état de configuration des secrets en exécutant la commande :
```bash
php bin/console app:check-secrets
```
Cette commande affiche un tableau récapitulatif indiquant pour chaque secret s'il est configuré, s'il contient une valeur par défaut, et masque les valeurs réelles pour des raisons de sécurité.
