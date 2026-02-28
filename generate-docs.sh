#!/bin/bash
# ===========================================
# Scouter - Script de génération de documentation
# Préserve l'index personnalisé et le dark mode
# ===========================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "🚀 Génération de la documentation Scouter..."

# 1. Sauvegarder les fichiers personnalisés
echo "📦 Sauvegarde des fichiers personnalisés..."
mkdir -p .doctum/backup

# Sauvegarder l'index personnalisé s'il existe
if [ -f "docs/phpdoc/index.html" ]; then
    cp docs/phpdoc/index.html .doctum/backup/index.html
    echo "   ✓ index.html sauvegardé"
fi

# Sauvegarder le CSS dark mode s'il existe
if [ -f "docs/phpdoc/css/dark-theme.css" ]; then
    cp docs/phpdoc/css/dark-theme.css .doctum/backup/dark-theme.css
    echo "   ✓ dark-theme.css sauvegardé"
fi

# 2. Nettoyer le cache Doctum pour forcer la mise à jour
echo "🧹 Nettoyage du cache..."
rm -rf .doctum/cache

# 3. Régénérer la documentation avec Doctum
echo "📝 Génération avec Doctum..."
php doctum.phar update doctum.php --force

# 3. Restaurer le CSS dark mode
echo "🎨 Application du thème dark mode..."
if [ -f ".doctum/backup/dark-theme.css" ]; then
    cp .doctum/backup/dark-theme.css docs/phpdoc/css/dark-theme.css
    echo "   ✓ dark-theme.css restauré"
fi

# 4. Ajouter le CSS dark mode à toutes les pages HTML
echo "🔗 Injection du CSS dans toutes les pages..."

# Injection pour TOUS les fichiers HTML avec différents niveaux de profondeur
find docs/phpdoc -name "*.html" -type f | while read file; do
    if ! grep -q "dark-theme.css" "$file"; then
        # Niveau 0 : docs/phpdoc/*.html -> css/dark-theme.css
        sed -i 's|<link rel="stylesheet" type="text/css" href="css/doctum.css">|<link rel="stylesheet" type="text/css" href="css/doctum.css">\n        <link rel="stylesheet" type="text/css" href="css/dark-theme.css">|g' "$file"
        # Niveau 1 : docs/phpdoc/App/*.html -> ../css/dark-theme.css
        sed -i 's|<link rel="stylesheet" type="text/css" href="\.\./css/doctum.css">|<link rel="stylesheet" type="text/css" href="../css/doctum.css">\n        <link rel="stylesheet" type="text/css" href="../css/dark-theme.css">|g' "$file"
        # Niveau 2 : docs/phpdoc/App/Core/*.html -> ../../css/dark-theme.css
        sed -i 's|<link rel="stylesheet" type="text/css" href="\.\./\.\./css/doctum.css">|<link rel="stylesheet" type="text/css" href="../../css/doctum.css">\n        <link rel="stylesheet" type="text/css" href="../../css/dark-theme.css">|g' "$file"
        # Niveau 3 : docs/phpdoc/App/Http/Controllers/*.html -> ../../../css/dark-theme.css
        sed -i 's|<link rel="stylesheet" type="text/css" href="\.\./\.\./\.\./css/doctum.css">|<link rel="stylesheet" type="text/css" href="../../../css/doctum.css">\n        <link rel="stylesheet" type="text/css" href="../../../css/dark-theme.css">|g' "$file"
        # Niveau 4 : docs/phpdoc/App/Http/Controllers/Sub/*.html -> ../../../../css/dark-theme.css
        sed -i 's|<link rel="stylesheet" type="text/css" href="\.\./\.\./\.\./\.\./css/doctum.css">|<link rel="stylesheet" type="text/css" href="../../../../css/doctum.css">\n        <link rel="stylesheet" type="text/css" href="../../../../css/dark-theme.css">|g' "$file"
    fi
done

echo "   ✓ CSS injecté dans tous les fichiers HTML"

# 5. Restaurer l'index personnalisé
echo "📄 Restauration de l'index personnalisé..."
if [ -f ".doctum/backup/index.html" ]; then
    cp .doctum/backup/index.html docs/phpdoc/index.html
    echo "   ✓ index.html restauré"
fi

echo ""
echo "✅ Documentation générée avec succès !"
echo "   📁 Chemin : docs/phpdoc/index.html"
echo ""
