#!/bin/bash
docker-compose -f docker-compose.local.yml exec worker php app/bin/reset-jobs.php
