CoinGecko AI Mistral Quantique - Agent & Bulk Analyse
Une application web d'analyse de marché crypto de masse (Bulk Analyse) combinant les données en temps réel de CoinGecko et la puissance de raisonnement de l'IA de Mistral (Free Tier) à travers un prisme algorithmique quantique (simulation d'états et de probabilités de marché).

Le projet est conçu de manière ultra-légère (HTML/PHP/JS natif) pour être déployé instantanément sur n'importe quel serveur ou en local.

🚀 Fonctionnalités principales
L'application est divisée en deux interfaces majeures interconnectées (index et l'interface de contrôle globale) :

Dashboard & Bulk Analyse (Analyse de Masse) :

Extraction automatique et en direct du Top des crypto-monnaies via l'API publique CoinGecko.

Traitement par lots (Bulk) : Soumission simultanée ou séquentielle des métriques graphiques et financières (prix, volume, capitalisation, variations de prix à court/moyen terme) à l'agent IA.

Agent IA Quantique (Mistral Integration) :

Évaluation des tendances selon un prompt système orienté "finance quantique" (analyse de corrélations de variables, recherche d'anomalies de prix, niveaux d'énergie du carnet d'ordres simulés).

Génération automatique de fiches de recommandations (Achat, Vente, Consolidation) avec un score de confiance.

Historisation et Sauvegarde Locale :

Les données analysées et les réponses de l'IA sont structurées pour une consultation rapide sans surcharger les requêtes d'API.

⚙️ Comment ça marche ?
[ CoinGecko API ] ──(Données du Marché)──> [ Interface PHP / JS ]
                                                    │
[ Utilisateur (Sélection Bulk) ] ───────────────────┤
                                                    ▼
[ Prompt Quantique Structuré ] ─────────────────> [ Agent IA Mistral ]
                                                    │
                                                    ▼
                                        [ Rapport d'Analyse Final ]
Récupération des données : Le script interroge l'API de CoinGecko pour obtenir les métriques de marché essentielles (ex: prix, volume 24h, % de variation).

Formulation de la requête à l'IA : L'interface compile ces variables brutes et les injecte dans un prompt système spécifique qui demande à Mistral d'agir comme un expert en analyse financière quantique.

Appels asynchrones (Bulk) : Le code Javascript de l'interface envoie les requêtes en arrière-plan à Mistral pour chaque crypto-monnaie sélectionnée, évitant ainsi le gel de la page.

🔑 Comment obtenir une clé API Mistral (Free Tier) ?
Le projet utilise le niveau gratuit de l'API Mistral AI, parfait pour les développeurs et les projets personnels. Pour l'obtenir :

Rendez-vous sur la plateforme officielle : Mistral AI Console.

Créez un compte ou connectez-vous avec votre profil GitHub/Google.

Allez dans la section Codex / API Keys (Clés API) dans le menu de gauche.

Cliquez sur Create New Key (Créer une nouvelle clé).

Donnez un nom à votre clé (ex: coingecko-agent), puis copiez-la précieusement.

Note : Le Free Tier offre un quota gratuit généreux par minute/mois, idéal pour exécuter les analyses de ce projet sans frais.

💻 Mode d'emploi de l'Interface & du Site
1. Installation rapide
Glissez simplement les fichiers du dépôt dans le répertoire racine de votre serveur web (ex: Apache/NGINX avec PHP activé, ou via des outils comme XAMPP / WampServer en local).

2. Configuration initiale
Ouvrez l'interface sur votre navigateur.

Saisissez votre clé API Mistral directement dans le champ prévu à cet effet en haut de la page (ou configurez-la de manière persistante si le fichier de configuration locale le permet).

3. Utilisation de l'outil
Sélection des Tokens : Cochez les crypto-monnaies que vous souhaitez analyser au sein de la liste récupérée depuis CoinGecko. Vous pouvez lancer une analyse globale ou sélectionner des actifs spécifiques.

Lancement du Bulk : Cliquez sur le bouton "Lancer l'analyse IA de masse".

Lecture des résultats : L'agent IA génère une boîte d'analyse sous chaque crypto-monnaie. Vous y trouverez :

Un résumé exécutif des métriques.

L'avis de l'agent quantique (Acheter / Attendre / Vendre).

Les indicateurs clés mis en évidence par l'IA.

🛠️ Stack Technique
Backend / Scripting : PHP (léger, adapté aux appels d'API et à la distribution de templates).

Frontend : HTML5, CSS3 (mise en page moderne et adaptative pour le Dashboard), JavaScript natif (pour la gestion des requêtes asynchrones fetch vers l'API Mistral).

APIs de confiance : CoinGecko REST API v3 & Mistral AI API (Endpoint de complétion de chat).

📄 Licence
Ce projet est distribué sous la licence MIT. Consultez le fichier LICENSE pour plus de détails.
