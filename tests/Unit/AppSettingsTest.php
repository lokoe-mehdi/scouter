<?php

use App\Settings\AppSettings;
use App\Database\PostgresDatabase;

/**
 * Unit tests for AppSettings — the wrapper that persists global config
 * with optional AES-256-GCM encryption for sensitive keys.
 *
 * Round-trip is the critical contract: set(key, x) then get(key) must return x.
 * Equally critical: without SCOUTER_ENCRYPTION_KEY, set() of a sensitive key
 * must refuse to write (and certainly must not write plaintext).
 */

beforeEach(function () {
    // Save the env var so we can mutate it per-test
    $this->originalKey = getenv('SCOUTER_ENCRYPTION_KEY');
    putenv('SCOUTER_ENCRYPTION_KEY=test-secret-for-AppSettingsTest-32bytes!');
    AppSettings::flushCache();

    $db = PostgresDatabase::getInstance()->getConnection();
    // The ONLY sensitive key is the real `ai.openrouter.api_key`, so the
    // encryption tests must use it — but we must NOT clobber the admin's
    // configured key. Back up its raw stored blob, work on a clean slate,
    // and restore it verbatim in afterEach.
    $this->backupApiKey = $db->query(
        "SELECT value FROM app_settings WHERE key = 'ai.openrouter.api_key'"
    )->fetchColumn();
    $db->exec("DELETE FROM app_settings WHERE key = 'ai.openrouter.api_key' OR key LIKE 'test.%'");
});

afterEach(function () {
    // Restore env var
    if ($this->originalKey === false) {
        putenv('SCOUTER_ENCRYPTION_KEY');
    } else {
        putenv('SCOUTER_ENCRYPTION_KEY=' . $this->originalKey);
    }
    AppSettings::flushCache();

    $db = PostgresDatabase::getInstance()->getConnection();
    $db->exec("DELETE FROM app_settings WHERE key = 'ai.openrouter.api_key' OR key LIKE 'test.%'");
    // Restore the admin's real API key blob verbatim (it was encrypted with the
    // REAL env key, which we've just restored above — so it stays decryptable).
    if ($this->backupApiKey !== false) {
        $st = $db->prepare(
            "INSERT INTO app_settings (key, value, updated_at) VALUES ('ai.openrouter.api_key', :v, CURRENT_TIMESTAMP)"
        );
        $st->execute([':v' => $this->backupApiKey]);
    }
});

describe('AppSettings round-trip', function () {

    it('stores and retrieves a sensitive value through encryption', function () {
        $secret = 'AIzaSy_FAKE_KEY_for_test_123456789';
        expect(AppSettings::set('ai.openrouter.api_key', $secret))->toBeTrue();

        AppSettings::flushCache();
        expect(AppSettings::get('ai.openrouter.api_key'))->toBe($secret);
    });

    it('stores the encrypted blob (not the plaintext) in the database', function () {
        $secret = 'AIzaSy_should_not_appear_in_db_in_plaintext';
        AppSettings::set('ai.openrouter.api_key', $secret);

        $db = PostgresDatabase::getInstance()->getConnection();
        $raw = $db->query("SELECT value FROM app_settings WHERE key = 'ai.openrouter.api_key'")->fetchColumn();

        expect($raw)->not->toBe($secret);
        expect($raw)->not->toContain('should_not_appear_in_db_in_plaintext');
        // The encrypted format is tagged with the version prefix.
        expect($raw)->toStartWith('enc:v1:');
    });

    it('round-trips a non-sensitive value as plaintext', function () {
        AppSettings::set('test.plain_model', 'gemini-2.5-flash');

        $db = PostgresDatabase::getInstance()->getConnection();
        $raw = $db->query("SELECT value FROM app_settings WHERE key = 'test.plain_model'")->fetchColumn();
        expect($raw)->toBe('gemini-2.5-flash');

        AppSettings::flushCache();
        expect(AppSettings::get('test.plain_model'))->toBe('gemini-2.5-flash');
    });

    it('returns null for an unknown key', function () {
        expect(AppSettings::get('ai.openrouter.api_key'))->toBeNull();
    });

    it('updates an existing key via upsert', function () {
        AppSettings::set('ai.openrouter.api_key', 'first-value');
        AppSettings::set('ai.openrouter.api_key', 'second-value');

        AppSettings::flushCache();
        expect(AppSettings::get('ai.openrouter.api_key'))->toBe('second-value');
    });
});

describe('AppSettings encryption guard', function () {

    it('refuses to set a sensitive value when no encryption key is configured', function () {
        putenv('SCOUTER_ENCRYPTION_KEY');
        AppSettings::flushCache();

        expect(AppSettings::hasEncryptionKey())->toBeFalse();
        expect(AppSettings::set('ai.openrouter.api_key', 'whatever'))->toBeFalse();

        $db = PostgresDatabase::getInstance()->getConnection();
        $stored = $db->query("SELECT value FROM app_settings WHERE key = 'ai.openrouter.api_key'")->fetchColumn();
        expect($stored)->toBeFalse(); // no row inserted
    });

    it('still allows non-sensitive sets even without an encryption key', function () {
        putenv('SCOUTER_ENCRYPTION_KEY');
        AppSettings::flushCache();

        expect(AppSettings::set('test.plain_model', 'gemini-2.5-pro'))->toBeTrue();
        AppSettings::flushCache();
        expect(AppSettings::get('test.plain_model'))->toBe('gemini-2.5-pro');
    });
});

describe('AppSettings::maskSecret', function () {

    it('shows only the last 4 chars and bullets for the rest', function () {
        expect(AppSettings::maskSecret('AIzaSy_abcdEFGH'))->toBe(str_repeat('•', 20) . 'EFGH');
    });

    it('returns empty string for null or empty input', function () {
        expect(AppSettings::maskSecret(null))->toBe('');
        expect(AppSettings::maskSecret(''))->toBe('');
    });
});
