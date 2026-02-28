# Guide d'utilisation

## Page d'accueil

Liste tous les crawls avec:
- Domaine et date
- Nombre d'URLs crawlees
- Statut (en cours, termine, erreur)
- Actions (voir, supprimer)

## Creer un crawl

1. Cliquer "Nouveau Crawl"
2. Configurer:
   - **URL de depart** - Page initiale du crawl
   - **Profondeur max** - Nombre de niveaux a explorer
   - **Vitesse** - Slow/Medium/Fast
   - **User-Agent** - Identification du crawler

### Options avancees

- **Domaines autorises** - Liste des domaines a crawler
- **Headers HTTP** - Headers personnalises
- **Extracteurs XPath** - Extraction de donnees custom
- **Extracteurs Regex** - Patterns regex

## Dashboard

Vue d'ensemble du crawl avec:
- Statistiques globales (URLs, temps moyen, codes HTTP)
- Graphiques de repartition
- Acces aux pages d'analyse

## Pages d'analyse

### Explorer
Table complete des URLs avec filtres et colonnes configurables.

### Categorize
Attribution de categories aux URLs avec regles de patterns.

### Inlinks
Analyse des liens entrants par page.

### PageRank
Classement des pages par importance interne.

### Response Time
Analyse des temps de reponse.

### SQL Explorer
Requetes SQL personnalisees sur les donnees du crawl.

## Export

- Export CSV depuis l'Explorer
- Selection des colonnes a exporter
- Filtres appliques a l'export

## Administration

Menu Administration (icone utilisateur):
- Gestion des utilisateurs
- Creation/suppression de comptes
