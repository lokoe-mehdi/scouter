<?php

use App\Auth\Auth;

/**
 * Tests pour Auth
 * Utilise skipDb=true pour éviter la connexion PostgreSQL
 */

beforeEach(function () {
    // Reset session
    $_SESSION = [];
});

describe('Auth Session - Not Logged In', function () {

    it('isLoggedIn returns false when no session', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->isLoggedIn())->toBeFalse();
    });

    it('getCurrentUserId returns null when not logged in', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->getCurrentUserId())->toBeNull();
    });

    it('getCurrentEmail returns null when not logged in', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->getCurrentEmail())->toBeNull();
    });

    it('getCurrentRole returns null when not logged in', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->getCurrentRole())->toBeNull();
    });

    it('isAdmin returns false when not logged in', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->isAdmin())->toBeFalse();
    });

    it('isViewer returns false when not logged in', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->isViewer())->toBeFalse();
    });

    it('canCreate returns false when not logged in', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->canCreate())->toBeFalse();
    });

});

describe('Auth Session - Admin Role', function () {

    beforeEach(function () {
        $_SESSION['user_id'] = 1;
        $_SESSION['email'] = 'admin@test.com';
        $_SESSION['role'] = 'admin';
        $_SESSION['logged_in'] = true;
    });

    it('isLoggedIn returns true', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->isLoggedIn())->toBeTrue();
    });

    it('getCurrentUserId returns correct id', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->getCurrentUserId())->toBe(1);
    });

    it('getCurrentEmail returns correct email', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->getCurrentEmail())->toBe('admin@test.com');
    });

    it('getCurrentRole returns admin', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->getCurrentRole())->toBe('admin');
    });

    it('isAdmin returns true', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->isAdmin())->toBeTrue();
    });

    it('isViewer returns false', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->isViewer())->toBeFalse();
    });

    it('canCreate returns true', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->canCreate())->toBeTrue();
    });

});

describe('Auth Session - User Role', function () {

    beforeEach(function () {
        $_SESSION['user_id'] = 2;
        $_SESSION['email'] = 'user@test.com';
        $_SESSION['role'] = 'user';
        $_SESSION['logged_in'] = true;
    });

    it('isLoggedIn returns true', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->isLoggedIn())->toBeTrue();
    });

    it('isAdmin returns false', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->isAdmin())->toBeFalse();
    });

    it('isViewer returns false', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->isViewer())->toBeFalse();
    });

    it('canCreate returns true', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->canCreate())->toBeTrue();
    });

});

describe('Auth Session - Viewer Role', function () {

    beforeEach(function () {
        $_SESSION['user_id'] = 3;
        $_SESSION['email'] = 'viewer@test.com';
        $_SESSION['role'] = 'viewer';
        $_SESSION['logged_in'] = true;
    });

    it('isLoggedIn returns true', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->isLoggedIn())->toBeTrue();
    });

    it('isAdmin returns false', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->isAdmin())->toBeFalse();
    });

    it('isViewer returns true', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->isViewer())->toBeTrue();
    });

    it('canCreate returns false for viewer', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        expect($auth->canCreate())->toBeFalse();
    });

});

describe('Auth Logout', function () {

    it('logout clears session data', function () {
        $_SESSION['user_id'] = 1;
        $_SESSION['email'] = 'test@test.com';
        $_SESSION['role'] = 'admin';
        $_SESSION['logged_in'] = true;
        
        $auth = new Auth(null, null, null, skipDb: true);
        $auth->logout();
        
        expect($auth->isLoggedIn())->toBeFalse();
    });

});

