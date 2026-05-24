#!/bin/bash
# Se place à la racine du repo pour que docker-compose.local.yml résolve.
cd "$(dirname "$0")/.." || exit 1
docker-compose -f docker-compose.local.yml exec worker php app/bin/reset-jobs.php
