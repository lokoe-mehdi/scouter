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

    // Clean any prior test rows
    $db = PostgresDatabase::getInstance()->getConnection();
    $db->exec("DELETE FROM app_settings WHERE key LIKE 'ai.gemini.%' OR key LIKE 'test.%'");
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
    $db->exec("DELETE FROM app_settings WHERE key LIKE 'ai.gemini.%' OR key LIKE 'test.%'");
});

describe('AppSettings round-trip', function () {

    it('stores and retrieves a sensitive value through encryption', function () {
        $secret = 'AIzaSy_FAKE_KEY_for_test_123456789';
        expect(AppSettings::set('ai.gemini.api_key', $secret))->toBeTrue();

        AppSettings::flushCache();
        expect(AppSettings::get('ai.gemini.api_key'))->toBe($secret);
    });

    it('stores the encrypted blob (not the plaintext) in the database', function () {
        $secret = 'AIzaSy_should_not_appear_in_db_in_plaintext';
        AppSettings::set('ai.gemini.api_key', $secret);

        $db = PostgresDatabase::getInstance()->getConnection();
        $raw = $db->query("SELECT value FROM app_settings WHERE key = 'ai.gemini.api_key'")->fetchColumn();

        expect($raw)->not->toBe($secret);
        expect($raw)->not->toContain('should_not_appear_in_db_in_plaintext');
        // The encrypted format is tagged with the version prefix.
        expect($raw)->toStartWith('enc:v1:');
    });

    it('round-trips a non-sensitive value as plaintext', function () {
        AppSettings::set('ai.gemini.model', 'gemini-2.5-flash');

        $db = PostgresDatabase::getInstance()->getConnection();
        $raw = $db->query("SELECT value FROM app_settings WHERE key = 'ai.gemini.model'")->fetchColumn();
        expect($raw)->toBe('gemini-2.5-flash');

        AppSettings::flushCache();
        expect(AppSettings::get('ai.gemini.model'))->toBe('gemini-2.5-flash');
    });

    it('returns null for an unknown key', function () {
        expect(AppSettings::get('ai.gemini.api_key'))->toBeNull();
    });

    it('updates an existing key via upsert', function () {
        AppSettings::set('ai.gemini.api_key', 'first-value');
        AppSettings::set('ai.gemini.api_key', 'second-value');

        AppSettings::flushCache();
        expect(AppSettings::get('ai.gemini.api_key'))->toBe('second-value');
    });
});

describe('AppSettings encryption guard', function () {

    it('refuses to set a sensitive value when no encryption key is configured', function () {
        putenv('SCOUTER_ENCRYPTION_KEY');
        AppSettings::flushCache();

        expect(AppSettings::hasEncryptionKey())->toBeFalse();
        expect(AppSettings::set('ai.gemini.api_key', 'whatever'))->toBeFalse();

        $db = PostgresDatabase::getInstance()->getConnection();
        $stored = $db->query("SELECT value FROM app_settings WHERE key = 'ai.gemini.api_key'")->fetchColumn();
        expect($stored)->toBeFalse(); // no row inserted
    });

    it('still allows non-sensitive sets even without an encryption key', function () {
        putenv('SCOUTER_ENCRYPTION_KEY');
        AppSettings::flushCache();

        expect(AppSettings::set('ai.gemini.model', 'gemini-2.5-pro'))->toBeTrue();
        AppSettings::flushCache();
        expect(AppSettings::get('ai.gemini.model'))->toBe('gemini-2.5-pro');
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
