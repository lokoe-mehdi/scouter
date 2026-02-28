#!/bin/bash

echo "================================"
echo "  Scouter - REBUILD COMPLET"
echo "================================"
echo ""

echo "[1/4] Arrêt forcé des conteneurs ET suppression des volumes..."
# Le flag -v supprime les volumes nommés (postgres_data)
docker-compose -f docker-compose.local.yml down --remove-orphans -v

echo ""
echo "[2/4] Nettoyage brutal (Prune system & volumes)..."
# Force le nettoyage de tout ce qui traîne
docker volume prune -f
docker system prune -f

echo ""
echo "[3/4] Construction propre (No Cache)..."
docker-compose -f docker-compose.local.yml build --no-cache

echo ""
echo "[4/4] Démarrage..."
docker-compose -f docker-compose.local.yml up -d --scale worker=4 --remove-orphans

echo ""
echo "================================"
echo "  Terminé ! Vérifie avec 'docker ps'"
echo "================================"
