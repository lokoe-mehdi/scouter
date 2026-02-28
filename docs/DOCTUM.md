# Documentation PHP avec Doctum

Guide pour générer la documentation API de Scouter avec [Doctum](https://github.com/code-lts/doctum).

---

## Présentation

Doctum est le successeur de Sami, l'outil que Symfony utilisait pour sa documentation. Il génère une documentation API moderne, propre et avec recherche intégrée.

**Avantages :**
- Configuration en PHP (pas de XML)
- Recherche JSON intégrée
- Design moderne et responsive
- Support du versioning

---

## Installation

### Via PHAR (recommandé)

```bash
# Télécharger Doctum
wget https://doctum.long-term.support/releases/latest/doctum.phar

# Vérifier l'installation
php doctum.phar --version
```

---

## Configuration

Le fichier `doctum.php` à la racine du projet :

```php
<?php

use Doctum\Doctum;
use Symfony\Component\Finder\Finder;

$iterator = Finder::create()
    ->files()
    ->name('*.php')
    ->in(__DIR__ . '/app');

return new Doctum($iterator, [
    'title'                => 'Scouter - Documentation API',
    'build_dir'            => __DIR__ . '/docs/api',
    'cache_dir'            => __DIR__ . '/.doctum/cache',
    'default_opened_level' => 2,
]);
```

---

## Génération

```bash
# Générer/mettre à jour la documentation
php doctum.phar update doctum.php

# Forcer la regénération complète
php doctum.phar render doctum.php --force
```

La documentation est générée dans `docs/api/`.

---

## Consulter la documentation

Ouvrir `docs/api/index.html` dans un navigateur.

---

## Scripts Composer

Ajouter dans `composer.json` :

```json
{
    "scripts": {
        "doc": "php doctum.phar update doctum.php",
        "doc:force": "php doctum.phar render doctum.php --force"
    }
}
```

Puis utiliser :

```bash
composer doc
composer doc:force
```

---

## Structure générée

```
docs/api/
├── index.html          # Page d'accueil
├── App.html            # Namespace App
├── App/                # Classes du namespace
│   ├── Crawler.html
│   ├── Page.html
│   └── ...
├── classes.html        # Liste des classes
├── search.html         # Page de recherche
└── doctum-search.json  # Index de recherche
```

---

## DocBlocks

Doctum utilise les mêmes DocBlocks que phpDocumentor :

```php
/**
 * Description courte de la classe.
 * 
 * Description longue avec plus de détails.
 * 
 * @package    Scouter
 * @subpackage Crawler
 * @author     Mehdi Colin
 * @version    2.0.0
 */
class MaClasse
{
    /**
     * Description de la méthode.
     * 
     * @param string $param Description du paramètre
     * @return bool Description du retour
     * @throws \Exception Si erreur
     */
    public function maMethode(string $param): bool
    {
        // ...
    }
}
```

---

## Liens utiles

- [Documentation Doctum](https://github.com/code-lts/doctum)
- [Releases Doctum](https://doctum.long-term.support/releases/)
