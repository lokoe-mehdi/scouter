<?php

// Initialisation et vérification d'authentification automatique
require_once(__DIR__ . '/init.php');

// SÉCURITÉ: Cette page est réservée aux administrateurs
$auth->requireAdmin(false);

use App\Database\UserRepository;

$userRepo = new UserRepository();

// Récupérer tous les utilisateurs
$users = $userRepo->getAll();

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - Scouter</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/crawl-panel.css">
    <link rel="icon" type="image/png" href="/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <style>
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 1.25rem;
            background: var(--bg-secondary);
            border-radius: 8px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .users-table {
            width: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .users-table thead {
            background: var(--bg-secondary);
        }
        
        .users-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-color);
        }
        
        .users-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .users-table tr:last-child td {
            border-bottom: none;
        }
        
        .users-table tr:hover {
            background: var(--bg-hover);
        }
        
        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            background: #E3F2FD;
            color: #1976D2;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .user-badge-current {
            background: #E8F5E9;
            color: #2E7D32;
        }
        
        /* Role Badges */
        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .role-badge-admin {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .role-badge-user {
            background: #E3F2FD;
            color: #1565C0;
        }
        
        .role-badge-viewer {
            background: #FFF3E0;
            color: #E65100;
        }
        
        .role-badge .material-symbols-outlined {
            font-size: 14px;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-icon-danger {
            padding: 0.5rem;
            background: transparent;
            color: #95a5a6;
            border: 1px solid #E1E8ED;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-icon-danger:hover:not(:disabled) {
            background: transparent;
            color: var(--danger-color);
            border-color: var(--danger-color);
        }
        
        .btn-icon-danger:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        
        .empty-state-admin {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--text-secondary);
        }
        
        /* Styles pour les inputs de la modal */
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-group small {
            display: block;
            margin-top: 0.25rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        /* Role Select Dropdown Styles */
        .role-dropdown {
            position: relative;
            width: 100%;
        }
        
        .role-dropdown-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s ease;
        }
        
        .role-dropdown-trigger:hover {
            border-color: var(--primary-color);
        }
        
        .role-dropdown.open .role-dropdown-trigger {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(78, 205, 196, 0.15);
        }
        
        .role-dropdown-trigger .material-symbols-outlined {
            font-size: 20px;
            color: var(--text-secondary);
            transition: transform 0.2s ease;
        }
        
        .role-dropdown.open .role-dropdown-trigger .material-symbols-outlined {
            transform: rotate(180deg);
        }
        
        .role-dropdown-value {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .role-dropdown-value .role-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .role-dropdown-value .role-icon.admin {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .role-dropdown-value .role-icon.user {
            background: #E3F2FD;
            color: #1565C0;
        }
        
        .role-dropdown-value .role-icon.viewer {
            background: #FFF3E0;
            color: #E65100;
        }
        
        .role-dropdown-value .role-icon .material-symbols-outlined {
            font-size: 18px;
            color: inherit;
        }
        
        .role-dropdown-text {
            display: flex;
            flex-direction: column;
        }
        
        .role-dropdown-name {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .role-dropdown-desc {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        
        .role-dropdown-options {
            position: fixed;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            z-index: 2147483647;
            display: none;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            overflow: hidden;
        }
        
        .role-dropdown-option {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: background 0.15s ease;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .role-dropdown-option:last-child {
            border-bottom: none;
        }
        
        .role-dropdown-option:hover {
            background: #f8fafc;
        }
        
        .role-dropdown-option.selected {
            background: rgba(78, 205, 196, 0.08);
        }
        
        .role-dropdown-option.selected .role-dropdown-name {
            color: var(--primary-color);
        }
        
        /* Edit button */
        .btn-icon-edit {
            padding: 0.5rem;
            background: transparent;
            color: #95a5a6;
            border: 1px solid #E1E8ED;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-icon-edit:hover {
            background: transparent;
            color: var(--primary-color);
            border-color: var(--primary-color);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <?php $headerContext = 'admin'; $isInSubfolder = true; include(__DIR__ . '/../components/top-header.php'); ?>

    <div class="container" style="max-width: 1200px; margin: 2rem auto; padding: 0 2rem;">
        <div class="admin-header">
            <div>
                <h1 class="page-title">Administration des utilisateurs</h1>
                <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                    Gérez les comptes utilisateurs de votre application Scouter
                </p>
            </div>
            <div style="display: flex; gap: 1rem;">
                <button class="btn btn-primary-action" onclick="openAddUserModal()">
                    <span class="material-symbols-outlined">person_add</span>
                    Ajouter un utilisateur
                </button>
            </div>
        </div>

        <?php if (count($users) > 0): ?>
        <table class="users-table">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Rôle</th>
                    <th>Créé le</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div class="user-avatar" style="width: 35px; height: 35px; font-size: 0.95rem;">
                                <?= strtoupper(substr($user->email, 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600; color: var(--text-primary);">
                                    <?= htmlspecialchars($user->email) ?>
                                </div>
                                <?php if ($user->id == $currentUserId): ?>
                                <span class="user-badge user-badge-current">
                                    <span class="material-symbols-outlined" style="font-size: 14px;">check_circle</span>
                                    Vous
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php 
                        $role = $user->role ?? 'user';
                        $roleLabels = [
                            'admin' => ['Admin', 'shield_person', 'role-badge-admin'],
                            'user' => ['Utilisateur', 'person', 'role-badge-user'],
                            'viewer' => ['Lecteur', 'visibility', 'role-badge-viewer']
                        ];
                        $roleInfo = $roleLabels[$role] ?? $roleLabels['user'];
                        ?>
                        <span class="role-badge <?= $roleInfo[2] ?>">
                            <span class="material-symbols-outlined"><?= $roleInfo[1] ?></span>
                            <?= $roleInfo[0] ?>
                        </span>
                    </td>
                    <td>
                        <?= date('d/m/Y H:i', strtotime($user->created_at)) ?>
                    </td>
                    <td>
                        <div class="action-buttons" style="justify-content: center;">
                            <button 
                                class="btn-icon-edit" 
                                onclick="openEditUserModal(<?= $user->id ?>, '<?= htmlspecialchars($user->email, ENT_QUOTES) ?>', '<?= $role ?>', <?= $user->id == $currentUserId ? 'true' : 'false' ?>)"
                                title="Modifier cet utilisateur"
                            >
                                <span class="material-symbols-outlined" style="font-size: 18px;">edit</span>
                            </button>
                            <button 
                                class="btn-icon-danger" 
                                onclick="deleteUser(<?= $user->id ?>, '<?= htmlspecialchars($user->email, ENT_QUOTES) ?>')"
                                <?= $user->id == $currentUserId ? 'disabled' : '' ?>
                                title="<?= $user->id == $currentUserId ? 'Vous ne pouvez pas vous supprimer vous-même' : 'Supprimer cet utilisateur' ?>"
                            >
                                <span class="material-symbols-outlined" style="font-size: 18px;">delete</span>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state-admin">
            <span class="material-symbols-outlined" style="font-size: 4rem; color: var(--text-tertiary);">group</span>
            <h2>Aucun utilisateur</h2>
            <p>Commencez par ajouter des utilisateurs à votre application.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal Ajouter un utilisateur -->
    <div id="addUserModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2>Ajouter un utilisateur</h2>
                <span class="modal-close" onclick="closeAddUserModal()">&times;</span>
            </div>
            <form id="addUserForm" onsubmit="return addUser(event)">
                <div class="form-group">
                    <label for="newEmail">Email *</label>
                    <input type="email" id="newEmail" name="email" required placeholder="john@example.com">
                </div>

                <div class="form-group">
                    <label for="newPassword">Mot de passe *</label>
                    <input type="password" id="newPassword" name="password" required placeholder="••••••••">
                    <small>Minimum 6 caractères</small>
                </div>

                <div class="form-group">
                    <label for="confirmPassword">Confirmer le mot de passe *</label>
                    <input type="password" id="confirmPassword" name="confirm_password" required placeholder="••••••••">
                </div>

                <div class="form-group">
                    <label>Rôle *</label>
                    <input type="hidden" id="newRole" name="role" value="user">
                    <div class="role-dropdown" id="newRoleDropdown">
                        <div class="role-dropdown-trigger" onclick="toggleRoleDropdown('new')">
                            <div class="role-dropdown-value">
                                <div class="role-icon user">
                                    <span class="material-symbols-outlined">person</span>
                                </div>
                                <div class="role-dropdown-text">
                                    <span class="role-dropdown-name">Utilisateur</span>
                                    <span class="role-dropdown-desc">Crée ses projets, voit les partagés</span>
                                </div>
                            </div>
                            <span class="material-symbols-outlined">expand_more</span>
                        </div>
                        <div class="role-dropdown-options">
                            <div class="role-dropdown-option" data-value="admin" onclick="selectRole('new', 'admin')">
                                <div class="role-icon admin">
                                    <span class="material-symbols-outlined">shield_person</span>
                                </div>
                                <div class="role-dropdown-text">
                                    <span class="role-dropdown-name">Administrateur</span>
                                    <span class="role-dropdown-desc">Accès total à l'application</span>
                                </div>
                            </div>
                            <div class="role-dropdown-option selected" data-value="user" onclick="selectRole('new', 'user')">
                                <div class="role-icon user">
                                    <span class="material-symbols-outlined">person</span>
                                </div>
                                <div class="role-dropdown-text">
                                    <span class="role-dropdown-name">Utilisateur</span>
                                    <span class="role-dropdown-desc">Crée ses projets, voit les partagés</span>
                                </div>
                            </div>
                            <div class="role-dropdown-option" data-value="viewer" onclick="selectRole('new', 'viewer')">
                                <div class="role-icon viewer">
                                    <span class="material-symbols-outlined">visibility</span>
                                </div>
                                <div class="role-dropdown-text">
                                    <span class="role-dropdown-name">Lecteur</span>
                                    <span class="role-dropdown-desc">Lecture seule, voit les partagés</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="addUserMessage" class="form-message"></div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAddUserModal()">Annuler</button>
                    <button type="submit" class="btn btn-success">
                        <span class="material-symbols-outlined">person_add</span>
                        Ajouter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Modifier un utilisateur -->
    <div id="editUserModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2>Modifier l'utilisateur</h2>
                <span class="modal-close" onclick="closeEditUserModal()">&times;</span>
            </div>
            <form id="editUserForm" onsubmit="return updateUser(event)">
                <input type="hidden" id="editUserId" name="id">
                
                <div class="form-group">
                    <label for="editEmail">Email *</label>
                    <input type="email" id="editEmail" name="email" required placeholder="john@example.com">
                </div>

                <div class="form-group">
                    <label>Rôle *</label>
                    <input type="hidden" id="editRole" name="role" value="user">
                    <div class="role-dropdown" id="editRoleDropdown">
                        <div class="role-dropdown-trigger" onclick="toggleRoleDropdown('edit')">
                            <div class="role-dropdown-value">
                                <div class="role-icon user">
                                    <span class="material-symbols-outlined">person</span>
                                </div>
                                <div class="role-dropdown-text">
                                    <span class="role-dropdown-name">Utilisateur</span>
                                    <span class="role-dropdown-desc">Crée ses projets, voit les partagés</span>
                                </div>
                            </div>
                            <span class="material-symbols-outlined">expand_more</span>
                        </div>
                        <div class="role-dropdown-options">
                            <div class="role-dropdown-option" data-value="admin" onclick="selectRole('edit', 'admin')">
                                <div class="role-icon admin">
                                    <span class="material-symbols-outlined">shield_person</span>
                                </div>
                                <div class="role-dropdown-text">
                                    <span class="role-dropdown-name">Administrateur</span>
                                    <span class="role-dropdown-desc">Accès total à l'application</span>
                                </div>
                            </div>
                            <div class="role-dropdown-option selected" data-value="user" onclick="selectRole('edit', 'user')">
                                <div class="role-icon user">
                                    <span class="material-symbols-outlined">person</span>
                                </div>
                                <div class="role-dropdown-text">
                                    <span class="role-dropdown-name">Utilisateur</span>
                                    <span class="role-dropdown-desc">Crée ses projets, voit les partagés</span>
                                </div>
                            </div>
                            <div class="role-dropdown-option" data-value="viewer" onclick="selectRole('edit', 'viewer')">
                                <div class="role-icon viewer">
                                    <span class="material-symbols-outlined">visibility</span>
                                </div>
                                <div class="role-dropdown-text">
                                    <span class="role-dropdown-name">Lecteur</span>
                                    <span class="role-dropdown-desc">Lecture seule, voit les partagés</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="editPassword">Nouveau mot de passe</label>
                    <input type="password" id="editPassword" name="password" placeholder="Laisser vide pour ne pas changer">
                    <small>Minimum 6 caractères. Laisser vide pour conserver le mot de passe actuel.</small>
                </div>

                <div id="editUserMessage" class="form-message"></div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeEditUserModal()">Annuler</button>
                    <button type="submit" class="btn btn-success">
                        <span class="material-symbols-outlined">save</span>
                        Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/confirm-modal.js"></script>
    <script>
        function openAddUserModal() {
            document.getElementById('addUserModal').style.display = 'flex';
        }

        function closeAddUserModal() {
            document.getElementById('addUserModal').style.display = 'none';
            document.getElementById('addUserForm').reset();
            document.getElementById('addUserMessage').innerHTML = '';
            // Réinitialiser le dropdown de rôle à "user"
            selectRole('new', 'user');
        }

        async function addUser(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            const password = formData.get('password');
            const confirmPassword = formData.get('confirm_password');
            
            if (password !== confirmPassword) {
                showMessage('addUserMessage', 'Les mots de passe ne correspondent pas', 'error');
                return false;
            }
            
            if (password.length < 6) {
                showMessage('addUserMessage', 'Le mot de passe doit contenir au moins 6 caractères', 'error');
                return false;
            }
            
            try {
                const response = await fetch('../api/users', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage('addUserMessage', 'Utilisateur créé avec succès', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    showMessage('addUserMessage', result.error || 'Erreur lors de la création', 'error');
                }
            } catch (error) {
                showMessage('addUserMessage', 'Erreur de connexion au serveur', 'error');
            }
            
            return false;
        }

        async function deleteUser(userId, email) {
            const confirmed = await customConfirm(
                `Êtes-vous sûr de vouloir supprimer l'utilisateur ${email} ? Cette action est irréversible.`,
                'Supprimer l\'utilisateur',
                'Supprimer',
                'danger'
            );
            
            if (!confirmed) return;
            
            try {
                const response = await fetch('../api/users/' + userId, {
                    method: 'DELETE'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    location.reload();
                } else {
                    alert('Erreur: ' + (result.error || 'Impossible de supprimer l\'utilisateur'));
                }
            } catch (error) {
                alert('Erreur de connexion au serveur');
            }
        }

        // ============================================
        // ÉDITION UTILISATEUR
        // ============================================
        
        function openEditUserModal(userId, email, role, isCurrentUser = false) {
            document.getElementById('editUserId').value = userId;
            document.getElementById('editEmail').value = email;
            document.getElementById('editPassword').value = '';
            document.getElementById('editUserMessage').innerHTML = '';
            // Initialiser le dropdown avec le bon rôle
            selectRole('edit', role);
            
            // Désactiver le changement de rôle si c'est l'utilisateur courant
            const roleDropdown = document.getElementById('editRoleDropdown');
            if (isCurrentUser) {
                roleDropdown.classList.add('disabled');
                roleDropdown.style.pointerEvents = 'none';
                roleDropdown.style.opacity = '0.6';
                roleDropdown.title = 'Vous ne pouvez pas modifier votre propre rôle';
            } else {
                roleDropdown.classList.remove('disabled');
                roleDropdown.style.pointerEvents = '';
                roleDropdown.style.opacity = '';
                roleDropdown.title = '';
            }
            
            document.getElementById('editUserModal').style.display = 'flex';
        }

        function closeEditUserModal() {
            document.getElementById('editUserModal').style.display = 'none';
            document.getElementById('editUserForm').reset();
            document.getElementById('editUserMessage').innerHTML = '';
        }

        async function updateUser(event) {
            event.preventDefault();
            
            const form = event.target;
            const userId = document.getElementById('editUserId').value;
            const email = document.getElementById('editEmail').value;
            const role = document.getElementById('editRole').value;
            const password = document.getElementById('editPassword').value;
            
            // Validation du mot de passe si fourni
            if (password && password.length < 6) {
                showMessage('editUserMessage', 'Le mot de passe doit contenir au moins 6 caractères', 'error');
                return false;
            }
            
            // Construire les données en format URL encoded
            const data = new URLSearchParams();
            data.append('id', userId);
            data.append('email', email);
            data.append('role', role);
            if (password) {
                data.append('password', password);
            }
            
            try {
                const response = await fetch('../api/users', {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: data.toString()
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage('editUserMessage', 'Utilisateur mis à jour avec succès', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    showMessage('editUserMessage', result.error || 'Erreur lors de la mise à jour', 'error');
                }
            } catch (error) {
                showMessage('editUserMessage', 'Erreur de connexion au serveur', 'error');
            }
            
            return false;
        }

        // ============================================
        // DROPDOWN DE RÔLE STYLISÉ
        // ============================================
        
        const roleData = {
            'admin': { name: 'Administrateur', desc: 'Accès total à l\'application', icon: 'shield_person' },
            'user': { name: 'Utilisateur', desc: 'Crée ses projets, voit les partagés', icon: 'person' },
            'viewer': { name: 'Lecteur', desc: 'Lecture seule, voit les partagés', icon: 'visibility' }
        };
        
        function toggleRoleDropdown(prefix) {
            const dropdown = document.getElementById(prefix + 'RoleDropdown');
            const trigger = dropdown.querySelector('.role-dropdown-trigger');
            const options = dropdown.querySelector('.role-dropdown-options');
            
            // Fermer les autres dropdowns
            document.querySelectorAll('.role-dropdown.open').forEach(d => {
                if (d.id !== prefix + 'RoleDropdown') {
                    d.classList.remove('open');
                    d.querySelector('.role-dropdown-options').style.display = 'none';
                }
            });
            
            if (dropdown.classList.contains('open')) {
                dropdown.classList.remove('open');
                options.style.display = 'none';
            } else {
                // Positionner le dropdown
                const rect = trigger.getBoundingClientRect();
                options.style.position = 'fixed';
                options.style.top = (rect.bottom + 4) + 'px';
                options.style.left = rect.left + 'px';
                options.style.width = rect.width + 'px';
                options.style.display = 'block';
                dropdown.classList.add('open');
            }
        }
        
        function selectRole(prefix, role) {
            const dropdown = document.getElementById(prefix + 'RoleDropdown');
            const hiddenInput = document.getElementById(prefix + 'Role');
            const trigger = dropdown.querySelector('.role-dropdown-trigger');
            const data = roleData[role];
            
            // Mettre à jour la valeur cachée
            hiddenInput.value = role;
            
            // Mettre à jour l'affichage du trigger
            const valueDiv = trigger.querySelector('.role-dropdown-value');
            valueDiv.innerHTML = `
                <div class="role-icon ${role}">
                    <span class="material-symbols-outlined">${data.icon}</span>
                </div>
                <div class="role-dropdown-text">
                    <span class="role-dropdown-name">${data.name}</span>
                    <span class="role-dropdown-desc">${data.desc}</span>
                </div>
            `;
            
            // Mettre à jour les états selected
            dropdown.querySelectorAll('.role-dropdown-option').forEach(opt => {
                opt.classList.toggle('selected', opt.dataset.value === role);
            });
            
            // Fermer le dropdown
            dropdown.classList.remove('open');
            dropdown.querySelector('.role-dropdown-options').style.display = 'none';
        }
        
        // Fermer les dropdowns de rôle si clic en dehors
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.role-dropdown')) {
                document.querySelectorAll('.role-dropdown.open').forEach(d => {
                    d.classList.remove('open');
                    d.querySelector('.role-dropdown-options').style.display = 'none';
                });
            }
        });

        function showMessage(elementId, message, type) {
            const element = document.getElementById(elementId);
            element.innerHTML = message;
            element.className = 'form-message ' + (type === 'success' ? 'success' : 'error');
            element.style.display = 'block';
        }

        // Dropdown administration
        function toggleAdminDropdown() {
            document.getElementById('adminDropdownMenu').classList.toggle('show');
        }
        
        // Gérer les clics globaux
        window.addEventListener('click', function(e) {
            // Fermer le dropdown si on clique ailleurs
            if (!e.target.closest('.admin-dropdown')) {
                const menu = document.getElementById('adminDropdownMenu');
                if (menu) {
                    menu.classList.remove('show');
                }
            }
            
            // Fermer les modals si on clique sur le fond
            const addModal = document.getElementById('addUserModal');
            const editModal = document.getElementById('editUserModal');
            if (e.target === addModal) {
                closeAddUserModal();
            }
            if (e.target === editModal) {
                closeEditUserModal();
            }
        });
    </script>
    
    <!-- Crawl Panel -->
    <script src="../assets/crawl-panel.js?v=<?= time() ?>"></script>
    <?php include __DIR__ . '/../components/crawl-panel.php'; ?>
</body>
</html>
