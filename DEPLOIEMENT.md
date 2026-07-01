# 🚀 Guide de Déploiement en Production - Djoliba

Ce guide décrit étape par étape comment configurer un serveur vierge (ex: VPS Ubuntu) et déployer l'application Djoliba en utilisant l'infrastructure Docker de production.

---

## 1️⃣ Prérequis du Serveur

Pour faire tourner Djoliba confortablement (PostgreSQL, Redis, FrankenPHP, OpenSERP, Workers), nous recommandons la configuration suivante :
- **Système d'exploitation** : Ubuntu 22.04 LTS ou 24.04 LTS
- **Mémoire RAM** : 4 Go minimum (2 Go peut suffire avec un fichier d'échange/swap, mais OpenSERP et PostgreSQL consomment de la mémoire).
- **Processeur** : 2 vCPU minimum.
- **Domaine** : Un nom de domaine pointant vers l'adresse IP de votre serveur (nécessaire pour la génération automatique du certificat HTTPS par Caddy).

---

## 2️⃣ Préparation du Serveur (Installation des outils)

Connectez-vous à votre serveur via SSH :
```bash
ssh utilisateur@ip_du_serveur
```

### Mise à jour du système
```bash
sudo apt update && sudo apt upgrade -y
```

### Installation de Git
```bash
sudo apt install git -y
```

### Installation de Docker & Docker Compose
Suivez les instructions officielles, ou utilisez le script d'installation rapide :
```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
```

Ajoutez votre utilisateur au groupe docker pour éviter de taper `sudo` à chaque commande :
```bash
sudo usermod -aG docker $USER
# Déconnectez-vous puis reconnectez-vous pour appliquer les changements
```

---

## 3️⃣ Déploiement de l'Application

### Clonage du Dépôt
Clonez le code source de Djoliba sur votre serveur :
```bash
git clone https://votre-repo-git.com/djolibasearch.git djoliba
cd djoliba
```

### Configuration de l'Environnement de Production
Djoliba utilise un fichier de configuration sécurisé pour la production.

1. Créez votre fichier `.env.prod` à partir du modèle fourni :
```bash
cp .env.prod.dist .env.prod
```

2. Éditez le fichier avec votre éditeur préféré (ex: `nano`) :
```bash
nano .env.prod
```

3. **Paramétrez impérativement les valeurs suivantes** :
- `APP_SECRET` : Générez une chaîne aléatoire (ex: `openssl rand -hex 16`).
- `DB_PASSWORD` : Un mot de passe fort pour PostgreSQL.
- `REDIS_PASSWORD` : Un mot de passe fort pour Redis.
- `DEEPSEEK_API_KEY` : Votre clé d'API DeepSeek réelle.
- `OPENSERP_API_KEY` : Votre clé API ou token pour OpenSERP.
- `SERVER_NAME` : Votre nom de domaine officiel (ex: `djoliba.science`). Caddy s'occupera d'obtenir le certificat HTTPS automatiquement.

*(Sauvegardez et quittez nano : `Ctrl+O`, `Entrée`, `Ctrl+X`)*

---

## 4️⃣ Lancement et Construction (Build)

Grâce au Dockerfile multi-stage, la compilation des assets (Tailwind CSS, AssetMapper) et l'optimisation se feront automatiquement lors de la construction de l'image de production.

Lancez la construction et le démarrage des conteneurs en arrière-plan :
```bash
docker compose -f compose.prod.yaml up -d --build
```
> ⏳ Cette étape peut prendre quelques minutes lors du premier déploiement car elle télécharge les images de base, installe les dépendances PHP (Composer) et compile les assets frontend.

---

## 5️⃣ Initialisation de la Base de Données

Une fois les conteneurs démarrés, vous devez créer le schéma de base de données.

1. Assurez-vous que la base de données est prête (healthy) :
```bash
docker compose -f compose.prod.yaml ps
```

2. Exécutez les migrations Doctrine pour créer les tables :
```bash
docker compose -f compose.prod.yaml exec app php bin/console doctrine:migrations:migrate --no-interaction
```

3. (Optionnel) Validation de l'intégrité des secrets :
```bash
docker compose -f compose.prod.yaml exec app php bin/console app:check-secrets
```

🎉 **Félicitations, Djoliba est maintenant en ligne !** Vous pouvez y accéder via `https://votre-domaine.com`.

---

## 6️⃣ Maintenance et Opérations Courantes

### Voir les logs de l'application
Si vous rencontrez une erreur (erreur 500), regardez les logs du conteneur `app` :
```bash
docker compose -f compose.prod.yaml logs -f app
```

### Vérifier le Worker Messenger
Le worker s'occupe des tâches asynchrones (exports lourds, etc.). Pour vérifier son état :
```bash
docker compose -f compose.prod.yaml logs -f messenger_worker
```

### Mise à jour de l'application (Déploiement d'une nouvelle version)
Lorsque vous poussez du nouveau code sur la branche principale :
```bash
# 1. Récupérer les dernières modifications
git pull origin main

# 2. Reconstruire l'image (pour intégrer les nouvelles dépendances ou assets)
docker compose -f compose.prod.yaml build app messenger_worker

# 3. Redémarrer les conteneurs affectés
docker compose -f compose.prod.yaml up -d

# 4. Jouer les éventuelles nouvelles migrations
docker compose -f compose.prod.yaml exec app php bin/console doctrine:migrations:migrate --no-interaction
```

### Configuration des Tâches Planifiées (Cron)
Djoliba dispose d'une commande de nettoyage des projets expirés. Sur votre serveur Ubuntu, configurez un cron job pour l'exécuter automatiquement.

Ouvrez la crontab de l'utilisateur :
```bash
crontab -e
```
Ajoutez la ligne suivante (à adapter avec le chemin absolu de votre dossier djoliba) pour une exécution tous les jours à 2h du matin :
```cron
0 2 * * * cd /chemin/absolu/vers/djoliba && docker compose -f compose.prod.yaml exec -T app php bin/console app:projects:cleanup
```
