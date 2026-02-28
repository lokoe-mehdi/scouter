# Installation

## Prerequis

- Docker Desktop installe
- Git (optionnel)

## Installation Docker (recommandee)

### Production

1. Cloner le projet
```bash
git clone <repo>
cd Scouter
```

2. Configurer les variables d'environnement sur le serveur:
   - `POSTGRES_DB=scouter`
   - `POSTGRES_USER=scouter`
   - `POSTGRES_PASSWORD=mot_de_passe_fort`

3. Demarrer
```bash
docker-compose up -d
```

### Developpement local

```bash
# Utiliser docker-compose.local.yml (credentials en clair)
./start.sh      # Linux/Mac
start.bat       # Windows
```

L'application demarre sur http://localhost:8080

## Premier lancement

1. Se connecter
2. Creer un nouveau crawl via le bouton "Nouveau Crawl"
3. Configurer l'URL de depart et les options
4. Lancer le crawl

## Configuration Docker

### Fichiers principaux

- `docker-compose.yml` - Configuration production
- `docker-compose.local.yml` - Override pour developpement local
- `Dockerfile` - Build de l'image PHP/Nginx
- `docker/postgres/init.sql` - Schema PostgreSQL initial
- `docker/postgres/Dockerfile` - Build de l'image PostgreSQL avec init.sql

### Variables d'environnement

Fichier `.env` (production):
```bash
POSTGRES_DB=scouter
POSTGRES_USER=scouter
POSTGRES_PASSWORD=votre_mot_de_passe_fort
```

Ces variables sont utilisees dans `docker-compose.yml`:
```yaml
DATABASE_URL: postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@postgres:5432/${POSTGRES_DB}
```

### Volumes

- `postgres_data` - Donnees PostgreSQL persistantes
- `./crawls` - Fichiers de crawl (logs, exports)

## Ports

- `8080` - Interface web (Nginx)
- `5432` - PostgreSQL (interne)

## Commandes Docker

```bash
# Rebuild complet
docker-compose build --no-cache

# Logs en temps reel
docker-compose logs -f

# Acces au container
docker exec -it scouter bash

# Reset base de donnees
docker-compose down -v
docker-compose up -d
```
