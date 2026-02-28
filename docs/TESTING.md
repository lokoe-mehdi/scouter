# Tests Unitaires avec Pest PHP

Ce document décrit la mise en place des tests unitaires pour le crawler Scouter en utilisant **Pest PHP**, un framework de test moderne et élégant pour PHP.

---

## Table des matières

1. [Pourquoi Pest ?](#pourquoi-pest-)
2. [Installation de Pest](#installation-de-pest)
3. [Configuration](#configuration)
4. [Structure des tests](#structure-des-tests)
5. [Liste des tests proposés](#liste-des-tests-proposés)
6. [Commandes utiles](#commandes-utiles)
7. [Intégration Docker / CI](#intégration-docker--ci)
8. [Bonnes pratiques](#bonnes-pratiques)

---

## Pourquoi Pest ?

**Pest** est un framework de test PHP construit sur PHPUnit, offrant :

- **Syntaxe expressive** : Tests lisibles avec `it()`, `test()`, `expect()`
- **Moins de boilerplate** : Pas besoin de classes, juste des fonctions
- **Compatible PHPUnit** : Fonctionne avec l'écosystème existant
- **Assertions fluides** : Chaînage d'expectations élégant
- **Rapide à écrire** : Idéal pour démarrer rapidement

Exemple de syntaxe Pest :
```php
it('calcule correctement le hash CRC32 d\'une URL', function () {
    $url = 'https://example.com/page';
    $hash = hash('crc32', $url, false);
    
    expect($hash)->toBeString()->toHaveLength(8);
});
```

---

## Installation de Pest

### Étape 1 : Ajouter Pest comme dépendance de développement

```bash
composer require pestphp/pest --dev --with-all-dependencies
```

### Étape 2 : Initialiser Pest dans le projet

```bash
./vendor/bin/pest --init
```

Cette commande crée :
- `tests/Pest.php` : Fichier de configuration Pest
- `tests/TestCase.php` : Classe de base pour les tests (optionnel)
- `tests/Feature/` : Dossier pour les tests fonctionnels
- `tests/Unit/` : Dossier pour les tests unitaires

### Étape 3 : Configurer l'autoload pour les tests

Modifier `composer.json` pour ajouter l'autoload des tests :

```json
{
    "require": {
        "chuyskywalker/rolling-curl": "^3.1",
        "mitseo/scraper": "*",
        "xparse/element-finder": "*",
        "mustangostang/spyc": "^0.6.2",
        "fivefilters/readability.php": "^3.3"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Charts\\": "web/charts/"
        }
    },
    "require-dev": {
        "symfony/var-dumper": "^3.4",
        "pestphp/pest": "^2.0"
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "config": {
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    }
}
```

Puis régénérer l'autoload :

```bash
composer dump-autoload
```

### Étape 4 : Créer le fichier phpunit.xml

Créer `phpunit.xml` à la racine du projet :

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
</phpunit>
```

---

## Configuration

### Fichier tests/Pest.php

Ce fichier configure Pest pour le projet :

```php
<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Vous pouvez définir une classe TestCase de base ici si nécessaire.
*/

// pest()->extend(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
| Vous pouvez ajouter des expectations personnalisées ici.
*/

// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
| Fonctions helpers globales pour les tests.
*/

function sampleHtml(): string {
    return '<!DOCTYPE html><html><head><title>Test</title></head><body><h1>Hello</h1></body></html>';
}

function sampleUrl(): string {
    return 'https://example.com/page';
}
```

---

## Structure des tests

```
tests/
├── Pest.php                    # Configuration Pest
├── TestCase.php                # Classe de base (optionnel)
├── Unit/                       # Tests unitaires (sans dépendances externes)
│   ├── SimhashTest.php         # Tests de l'algorithme Simhash
│   ├── RobotsTxtTest.php       # Tests du parser robots.txt
│   ├── PageTest.php            # Tests de l'extraction de données
│   └── UrlHelperTest.php       # Tests des helpers URL
└── Feature/                    # Tests fonctionnels (avec mocks/stubs)
    ├── PageCrawlerTest.php     # Tests du crawl de page
    └── JsRendererTest.php      # Tests du renderer JS
```

---

## Liste des tests proposés

### 1. Tests de la classe `Simhash` (Priorité: Haute)

Fichier : `tests/Unit/SimhashTest.php`

| Test | Description |
|------|-------------|
| `it('returns null for empty text')` | Vérifie que `compute()` retourne `null` pour un texte vide |
| `it('computes a 64-bit hash for valid text')` | Vérifie que le hash retourné est un entier 64-bit |
| `it('returns same hash for identical texts')` | Vérifie la déterminisme du hash |
| `it('returns similar hashes for similar texts')` | Vérifie que des textes similaires ont des hashes proches |
| `it('returns different hashes for different texts')` | Vérifie que des textes différents ont des hashes différents |
| `it('calculates correct hamming distance')` | Vérifie le calcul de la distance de Hamming |
| `it('detects similar content with areSimilar()')` | Vérifie la détection de contenu similaire |
| `it('normalizes text correctly')` | Vérifie la normalisation (minuscules, ponctuation) |

### 2. Tests de la classe `RobotsTxt` (Priorité: Haute)

Fichier : `tests/Unit/RobotsTxtTest.php`

| Test | Description |
|------|-------------|
| `it('allows all URLs when no robots.txt')` | Vérifie le comportement par défaut |
| `it('blocks URLs matching Disallow rules')` | Vérifie le blocage par règle Disallow |
| `it('allows URLs matching Allow rules')` | Vérifie l'autorisation par règle Allow |
| `it('handles wildcard * in rules')` | Vérifie le support du wildcard `*` |
| `it('handles $ end anchor in rules')` | Vérifie le support de l'ancre `$` |
| `it('respects User-Agent specificity')` | Vérifie le respect des User-Agents |
| `it('ignores comments in robots.txt')` | Vérifie l'ignorance des commentaires |
| `it('handles malformed robots.txt gracefully')` | Vérifie la robustesse aux erreurs |

### 3. Tests de la classe `Page` - Extraction de données (Priorité: Haute)

Fichier : `tests/Unit/PageTest.php`

| Test | Description |
|------|-------------|
| `it('extracts title from HTML')` | Vérifie l'extraction du `<title>` |
| `it('extracts H1 from HTML')` | Vérifie l'extraction du `<h1>` |
| `it('extracts meta description')` | Vérifie l'extraction de la meta description |
| `it('extracts canonical URL')` | Vérifie l'extraction du lien canonical |
| `it('detects noindex directive')` | Vérifie la détection de `noindex` |
| `it('detects nofollow directive')` | Vérifie la détection de `nofollow` |
| `it('extracts links from page')` | Vérifie l'extraction des liens `<a href>` |
| `it('converts relative URLs to absolute')` | Vérifie la conversion rel2abs |
| `it('filters invalid links (mailto, javascript)')` | Vérifie le filtrage des liens invalides |
| `it('detects external vs internal links')` | Vérifie la classification interne/externe |
| `it('calculates word count correctly')` | Vérifie le comptage de mots |
| `it('detects multiple H1 tags')` | Vérifie la détection de H1 multiples |
| `it('detects missing heading levels')` | Vérifie la détection de niveaux manquants |
| `it('extracts JSON-LD schema types')` | Vérifie l'extraction des schemas |

### 4. Tests de la classe `Page` - Détection de type (Priorité: Moyenne)

Fichier : `tests/Unit/PageTypeDetectionTest.php`

| Test | Description |
|------|-------------|
| `it('detects HTML content type')` | Vérifie la détection de `text/html` |
| `it('detects PDF by extension')` | Vérifie la détection de `.pdf` |
| `it('detects images by magic bytes')` | Vérifie la détection par signature binaire |
| `it('detects binary content by printable ratio')` | Vérifie la détection par ratio de caractères |

### 5. Tests des helpers URL (Priorité: Moyenne)

Fichier : `tests/Unit/UrlHelperTest.php`

| Test | Description |
|------|-------------|
| `it('generates consistent CRC32 hash')` | Vérifie le hash d'URL |
| `it('adds trailing slash to domain-only URLs')` | Vérifie l'ajout du slash final |
| `it('extracts domain from URL')` | Vérifie l'extraction du domaine |
| `it('converts relative to absolute URLs')` | Vérifie la conversion rel2abs |
| `it('handles .. and . in paths')` | Vérifie la résolution de chemins |
| `it('preserves query strings')` | Vérifie la préservation des query strings |

### 6. Tests de la classe `JsRenderer` (Priorité: Basse - nécessite mock)

Fichier : `tests/Feature/JsRendererTest.php`

| Test | Description |
|------|-------------|
| `it('constructs with default URL')` | Vérifie la construction par défaut |
| `it('constructs with custom URL from env')` | Vérifie la lecture de `RENDERER_URL` |
| `it('sets timeout correctly')` | Vérifie le setter de timeout |

### 7. Tests de configuration du crawl (Priorité: Moyenne)

Fichier : `tests/Unit/CrawlConfigTest.php`

| Test | Description |
|------|-------------|
| `it('configures very_slow speed correctly')` | Vérifie la config `very_slow` |
| `it('configures slow speed correctly')` | Vérifie la config `slow` |
| `it('configures fast speed correctly')` | Vérifie la config `fast` |
| `it('configures unlimited speed correctly')` | Vérifie la config `unlimited` |
| `it('respects MAX_CONCURRENT_CURL env override')` | Vérifie l'override par env |

---

## Commandes utiles

### Lancer tous les tests

```bash
./vendor/bin/pest
```

### Lancer uniquement les tests unitaires

```bash
./vendor/bin/pest --testsuite=Unit
```

### Lancer un fichier de test spécifique

```bash
./vendor/bin/pest tests/Unit/SimhashTest.php
```

### Lancer un test spécifique par nom

```bash
./vendor/bin/pest --filter="computes a 64-bit hash"
```

### Mode verbose (détails)

```bash
./vendor/bin/pest -v
```

### Avec couverture de code (nécessite Xdebug ou PCOV)

```bash
./vendor/bin/pest --coverage
```

### Arrêter au premier échec

```bash
./vendor/bin/pest --stop-on-failure
```

### Mode watch (relance automatique)

Installer le plugin watch :
```bash
composer require pestphp/pest-plugin-watch --dev
```

Puis lancer :
```bash
./vendor/bin/pest --watch
```

---

## Intégration Docker / CI

### Option 1 : Tests dans le Dockerfile (Build-time)

Ajouter une étape de test dans le `Dockerfile` pour bloquer le build si les tests échouent :

```dockerfile
# Stage de test
FROM php:8.2-cli AS test

WORKDIR /app
COPY . .

RUN composer install --dev
RUN ./vendor/bin/pest --stop-on-failure

# Stage de production (seulement si les tests passent)
FROM php:8.2-cli AS production
# ... reste du Dockerfile
```

**Avantage** : Le build Docker échoue si les tests ne passent pas.
**Inconvénient** : Augmente le temps de build.

### Option 2 : Tests dans docker-compose (Run-time)

Ajouter un service de test dans `docker-compose.yml` :

```yaml
services:
  # ... autres services ...
  
  test:
    build: .
    command: ./vendor/bin/pest
    volumes:
      - .:/app
    profiles:
      - test
```

Lancer les tests :
```bash
docker-compose --profile test run test
```

### Option 3 : Script de test séparé

Créer un script `run-tests.sh` :

```bash
#!/bin/bash
set -e

echo "🧪 Running Pest tests..."
docker-compose exec scouter ./vendor/bin/pest "$@"

if [ $? -eq 0 ]; then
    echo "✅ All tests passed!"
else
    echo "❌ Tests failed!"
    exit 1
fi
```

### Recommandation

Pour Scouter, je recommande l'**Option 3** (script séparé) car :
- Les tests ne ralentissent pas le déploiement normal
- On peut lancer les tests à la demande
- Flexibilité pour CI/CD externe (GitHub Actions, GitLab CI, etc.)

Si vous voulez bloquer le déploiement en cas d'échec, ajoutez l'appel au script dans votre pipeline CI/CD plutôt que dans le Dockerfile.

---

## Bonnes pratiques

### 1. Nommage des tests

Utiliser des noms descriptifs en anglais avec `it()` :
```php
it('extracts canonical URL from link tag', function () { ... });
```

### 2. Arrangement AAA (Arrange-Act-Assert)

```php
it('calculates hamming distance correctly', function () {
    // Arrange
    $hash1 = 0b1010101010101010;
    $hash2 = 0b1010101010101011;
    
    // Act
    $distance = Simhash::hammingDistance($hash1, $hash2);
    
    // Assert
    expect($distance)->toBe(1);
});
```

### 3. Tests isolés

Chaque test doit être indépendant et ne pas dépendre de l'état d'un autre test.

### 4. Données de test

Utiliser des fixtures ou des helpers pour les données de test :
```php
// Dans tests/Pest.php
function sampleRobotsTxt(): string {
    return <<<TXT
User-agent: *
Disallow: /admin/
Allow: /admin/public/
TXT;
}
```

### 5. Mocking

Pour les classes avec dépendances externes (DB, HTTP), utiliser des mocks :
```php
it('handles renderer timeout', function () {
    // Mock de curl pour simuler un timeout
    // ...
});
```

---

## Prochaines étapes

1. **Installer Pest** : `composer require pestphp/pest --dev --with-all-dependencies`
2. **Initialiser** : `./vendor/bin/pest --init`
3. **Créer les premiers tests** : Commencer par `SimhashTest.php` et `RobotsTxtTest.php`
4. **Itérer** : Ajouter les tests au fur et à mesure

---

## Ressources

- [Documentation Pest](https://pestphp.com/docs/installation)
- [Expectations Pest](https://pestphp.com/docs/expectations)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
