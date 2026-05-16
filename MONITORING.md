# Monitoring Djoliba Platform

L'application expose un endpoint de santé pour le monitoring externe.

## Endpoint de santé
- **URL** : `/health-check`
- **Méthode** : `GET`
- **Réponse attendue** : `200 OK` avec un corps JSON `{"status": "OK", ...}`

## Configuration UptimeRobot
Pour configurer UptimeRobot pour Djoliba :

1. Connectez-vous à votre tableau de bord [UptimeRobot](https://uptimerobot.com/).
2. Cliquez sur **"Add New Monitor"**.
3. **Monitor Type** : Choisissez `HTTP(s)`.
4. **Friendly Name** : `Djoliba - Health Check`.
5. **URL (or IP)** : Saisissez l'URL complète de votre instance (ex: `https://votre-domaine.com/health-check`).
6. **Monitoring Interval** : Choisissez `5 minutes` (ou selon votre besoin).
7. **HTTP Settings** : 
   - Laissez les paramètres par défaut.
   - Vous pouvez optionnellement vérifier que le contenu contient `"status":"OK"`.
8. Cliquez sur **"Create Monitor"**.

## Alertes
En cas de panne :
- Le status passera à `503 Service Unavailable`.
- UptimeRobot vous enverra une notification par email/push selon vos paramètres.
