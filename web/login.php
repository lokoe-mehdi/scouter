<?php

require("../vendor/autoload.php");

use App\Auth\Auth;
use App\Database\UserRepository;

$auth = new Auth();
$users = new UserRepository();

// Récupérer l'URL de redirection si présente
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : (isset($_POST['redirect']) ? $_POST['redirect'] : '');

// Si déjà connecté, rediriger vers l'URL demandée ou l'accueil
if ($auth->isLoggedIn()) {
    header('Location: ' . ($redirect ?: 'index.php'));
    exit;
}

$error = '';

// Traitement du formulaire de connexion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Veuillez remplir tous les champs';
    } elseif ($auth->login($email, $password)) {
        // Rediriger vers l'URL demandée ou l'accueil
        header('Location: ' . ($redirect ?: 'index.php'));
        exit;
    } else {
        $error = 'Identifiants incorrects';
    }
}

// Traitement du formulaire de création du premier utilisateur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
    if (!$auth->hasUsers()) {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $error = 'Veuillez remplir tous les champs obligatoires';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Veuillez entrer un email valide';
        } elseif ($password !== $confirmPassword) {
            $error = 'Les mots de passe ne correspondent pas';
        } elseif (strlen($password) < 6) {
            $error = 'Le mot de passe doit contenir au moins 6 caractères';
        } else {
            try {
                $users->create($email, $password, 'admin');
                // Auto-login après création
                $auth->login($email, $password);
                header('Location: ' . ($redirect ?: 'index.php'));
                exit;
            } catch (Exception $e) {
                $error = 'Erreur lors de la création du compte: ' . $e->getMessage();
            }
        }
    }
}

$needsSetup = !$auth->hasUsers();

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $needsSetup ? 'Configuration initiale' : 'Connexion' ?> - Scouter</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #4ECDC4 0%, #44A08D 100%);
            padding: 2rem;
        }
        
        .login-box {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 3rem;
            width: 100%;
            max-width: 450px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-logo {
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-logo img {
            width: 120px;
            height: auto;
        }
        
        .login-title {
            font-size: 1.8rem;
            color: var(--text-primary);
            margin: 0 0 0.5rem;
        }
        
        .login-subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }
        
        .setup-alert {
            background: #FFF3CD;
            border: 1px solid #FFE69C;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.75rem;
        }
        
        .setup-alert-icon {
            color: #856404;
            flex-shrink: 0;
        }
        
        .setup-alert-content {
            color: #856404;
            font-size: 0.9rem;
        }
        
        .login-form {
            margin-bottom: 1rem;
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            font-weight: 500;
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #4ECDC4;
            box-shadow: 0 0 0 3px rgba(78, 205, 196, 0.1);
        }
        
        .error-message {
            background: #F8D7DA;
            border: 1px solid #F5C2C7;
            color: #842029;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
        }
        
        .btn-login {
            width: 100%;
            padding: 0.875rem;
            background: linear-gradient(135deg, #4ECDC4 0%, #44A08D 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(78, 205, 196, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .form-helper {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <div class="login-logo">
                    <img src="logo-big.png" alt="Scouter Logo">
                </div>
                <h1 class="login-title">
                    <?= $needsSetup ? 'Configuration initiale' : 'Connexion à Scouter' ?>
                </h1>
                <p class="login-subtitle">
                    <?= $needsSetup ? 'Créez votre premier compte administrateur' : 'Accédez à votre espace de crawl SEO' ?>
                </p>
            </div>

            <?php if ($needsSetup): ?>
                <div class="setup-alert">
                    <span class="material-symbols-outlined setup-alert-icon">info</span>
                    <div class="setup-alert-content">
                        <strong>Première utilisation détectée</strong><br>
                        Créez votre compte administrateur pour sécuriser l'accès à Scouter.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error-message">
                    <strong>Erreur:</strong> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($needsSetup): ?>
                <!-- Formulaire de configuration initiale -->
                <form method="POST" class="login-form">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <div class="form-group">
                        <label for="email" class="form-label">Email *</label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-input" 
                            placeholder="admin@example.com"
                            required
                            autofocus
                        >
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">Mot de passe *</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-input" 
                            placeholder="••••••••"
                            required
                        >
                        <div class="form-helper">Minimum 6 caractères</div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirmer le mot de passe *</label>
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            class="form-input" 
                            placeholder="••••••••"
                            required
                        >
                    </div>

                    <button type="submit" name="setup" class="btn-login">
                        <span class="material-symbols-outlined">check_circle</span>
                        Créer mon compte
                    </button>
                </form>
            <?php else: ?>
                <!-- Formulaire de connexion -->
                <form method="POST" class="login-form">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <div class="form-group">
                        <label for="email" class="form-label">Email</label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-input" 
                            placeholder="Entrez votre email"
                            required
                            autofocus
                        >
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">Mot de passe</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-input" 
                            placeholder="Entrez votre mot de passe"
                            required
                        >
                    </div>

                    <button type="submit" name="login" class="btn-login">
                        <span class="material-symbols-outlined">login</span>
                        Se connecter
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
