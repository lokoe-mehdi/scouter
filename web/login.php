<?php
require_once(__DIR__ . '/config/i18n.php');

require("../vendor/autoload.php");

// CSRF token
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

use App\Auth\Auth;
use App\Database\UserRepository;

$auth = new Auth();
$users = new UserRepository();

// Récupérer l'URL de redirection si présente
$redirectRaw = isset($_GET['redirect']) ? $_GET['redirect'] : (isset($_POST['redirect']) ? $_POST['redirect'] : '');
// Prevent open redirect — only allow relative paths
$redirect = (!empty($redirectRaw) && !preg_match('#^https?://#i', $redirectRaw) && !str_starts_with($redirectRaw, '//'))
    ? $redirectRaw : '';

// Si déjà connecté, rediriger vers l'URL demandée ou l'accueil
if ($auth->isLoggedIn()) {
    header('Location: ' . ($redirect ?: 'index.php'));
    exit;
}

$error = '';

// CSRF validation for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid CSRF token. Please refresh and try again.';
    }
}

// Traitement du formulaire de connexion
if (empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = __('login.error_fill_fields');
    } elseif ($auth->login($email, $password)) {
        // Rediriger vers l'URL demandée ou l'accueil
        header('Location: ' . ($redirect ?: 'index.php'));
        exit;
    } else {
        $error = __('login.error_invalid_credentials');
    }
}

// Traitement du formulaire de création du premier utilisateur
if (empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
    if (!$auth->hasUsers()) {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $error = __('login.error_fill_fields');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = __('login.error_invalid_email');
        } elseif ($password !== $confirmPassword) {
            $error = __('login.error_password_mismatch');
        } elseif (strlen($password) < 6) {
            $error = __('login.error_password_short');
        } else {
            try {
                $users->create($email, $password, 'admin');
                // Auto-login après création
                $auth->login($email, $password);
                header('Location: ' . ($redirect ?: 'index.php'));
                exit;
            } catch (Exception $e) {
                $error = __('login.error_account_creation', ['message' => $e->getMessage()]);
            }
        }
    }
}

$needsSetup = !$auth->hasUsers();

?>
<!DOCTYPE html>
<html lang="<?= I18n::getInstance()->getLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $needsSetup ? __('login.page_title_setup') : __('login.page_title_login') ?> - Scouter</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/vendor/material-symbols/material-symbols.css" />
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
                    <?= $needsSetup ? __('login.title_setup') : __('login.title_login') ?>
                </h1>
                <p class="login-subtitle">
                    <?= $needsSetup ? __('login.subtitle_setup') : __('login.subtitle_login') ?>
                </p>
            </div>

            <?php if ($needsSetup): ?>
                <div class="setup-alert">
                    <span class="material-symbols-outlined setup-alert-icon">info</span>
                    <div class="setup-alert-content">
                        <strong><?= __('login.alert_first_use') ?></strong><br>
                        <?= __('login.alert_first_use_desc') ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error-message">
                    <strong><?= __('login.error_label') ?></strong> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($needsSetup): ?>
                <!-- Formulaire de configuration initiale -->
                <form method="POST" class="login-form">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="form-group">
                        <label for="email" class="form-label"><?= __('login.label_email_required') ?></label>
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
                        <label for="password" class="form-label"><?= __('login.label_password_required') ?></label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-input"
                            placeholder="••••••••"
                            required
                        >
                        <div class="form-helper"><?= __('login.helper_min_chars') ?></div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label"><?= __('login.label_confirm_password') ?></label>
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
                        <?= __('login.btn_create_account') ?>
                    </button>
                </form>
            <?php else: ?>
                <!-- Formulaire de connexion -->
                <form method="POST" class="login-form">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="form-group">
                        <label for="email" class="form-label"><?= __('login.label_email') ?></label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-input"
                            placeholder="<?= __('login.placeholder_email') ?>"
                            required
                            autofocus
                        >
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label"><?= __('login.label_password') ?></label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-input"
                            placeholder="<?= __('login.placeholder_password') ?>"
                            required
                        >
                    </div>

                    <button type="submit" name="login" class="btn-login">
                        <span class="material-symbols-outlined">login</span>
                        <?= __('login.btn_login') ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<div style="position: fixed; bottom: 1rem; right: 1rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.85rem;">
    <span style="font-size: 0.9rem; color: rgba(255,255,255,0.3); letter-spacing: 0.3px;">Scouter v0.5</span>
    <?php foreach (I18n::getInstance()->getSupportedLanguages() as $lang): ?>
        <a href="?lang=<?= $lang ?><?= $redirect ? '&redirect=' . urlencode($redirect) : '' ?>"
           style="color: <?= $lang === I18n::getInstance()->getLang() ? '#2C3E50' : 'rgba(255,255,255,0.7)' ?>; background: <?= $lang === I18n::getInstance()->getLang() ? 'white' : 'rgba(255,255,255,0.2)' ?>; padding: 0.3rem 0.6rem; border-radius: 4px; text-decoration: none; font-weight: <?= $lang === I18n::getInstance()->getLang() ? '600' : '400' ?>; text-transform: uppercase;">
            <?= $lang ?>
        </a>
    <?php endforeach; ?>
</div>
</body>
</html>
