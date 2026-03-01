<?php

require_once __DIR__ . '/../../web/config/i18n.php';

beforeEach(function () {
    // Reset singleton between tests
    $ref = new ReflectionClass(I18n::class);
    $prop = $ref->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);

    $_GET = [];
    $_COOKIE = [];
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = '';
});

// ---------------------------------------------------------------------------
// 1. Language detection (priority chain)
// ---------------------------------------------------------------------------
describe('Language detection', function () {

    it('detects fr from ?lang=fr', function () {
        $_GET['lang'] = 'fr';
        expect(I18n::getInstance()->getLang())->toBe('fr');
    });

    it('detects en from ?lang=en', function () {
        $_GET['lang'] = 'en';
        expect(I18n::getInstance()->getLang())->toBe('en');
    });

    it('ignores unsupported ?lang=xx and falls back to default', function () {
        $_GET['lang'] = 'xx';
        expect(I18n::getInstance()->getLang())->toBe('en');
    });

    it('detects fr from cookie', function () {
        $_COOKIE['scouter_lang'] = 'fr';
        expect(I18n::getInstance()->getLang())->toBe('fr');
    });

    it('ignores invalid cookie value', function () {
        $_COOKIE['scouter_lang'] = 'zz';
        expect(I18n::getInstance()->getLang())->toBe('en');
    });

    it('detects fr from Accept-Language header', function () {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-FR,en;q=0.9';
        expect(I18n::getInstance()->getLang())->toBe('fr');
    });

    it('falls back to en when Accept-Language has no supported lang first', function () {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de,en;q=0.5';
        expect(I18n::getInstance()->getLang())->toBe('en');
    });

    it('defaults to en when nothing is set', function () {
        expect(I18n::getInstance()->getLang())->toBe('en');
    });

    it('gives ?lang priority over cookie', function () {
        $_GET['lang'] = 'en';
        $_COOKIE['scouter_lang'] = 'fr';
        expect(I18n::getInstance()->getLang())->toBe('en');
    });
});

// ---------------------------------------------------------------------------
// 2. Translation and fallback
// ---------------------------------------------------------------------------
describe('Translation and fallback', function () {

    it('returns French text for an existing key in fr', function () {
        $_GET['lang'] = 'fr';
        $i18n = I18n::getInstance();
        expect($i18n->translate('accessibility.card_crawled'))->toBe('URLs Crawlées');
    });

    it('returns English text for an existing key in en', function () {
        $_GET['lang'] = 'en';
        $i18n = I18n::getInstance();
        expect($i18n->translate('accessibility.card_crawled'))->toBe('Crawled URLs');
    });

    it('falls back to English when key is missing in fr', function () {
        $_GET['lang'] = 'fr';
        $i18n = I18n::getInstance();

        // Find a key that exists in en.json — use a key guaranteed present
        // The fallback array is always loaded, so any key present in en but
        // absent from fr will return the English value. We test with a
        // synthetic missing key by checking the raw-key behaviour separately;
        // here we just verify the mechanism works with a known key.
        $enJson = json_decode(
            file_get_contents(__DIR__ . '/../../web/lang/en.json'), true
        );
        $frJson = json_decode(
            file_get_contents(__DIR__ . '/../../web/lang/fr.json'), true
        );

        $missingInFr = null;
        foreach ($enJson as $key => $value) {
            if (!isset($frJson[$key])) {
                $missingInFr = $key;
                break;
            }
        }

        if ($missingInFr !== null) {
            expect($i18n->translate($missingInFr))->toBe($enJson[$missingInFr]);
        } else {
            // All keys present — just verify a known key works
            expect($i18n->translate('accessibility.card_crawled'))->toBe('URLs Crawlées');
        }
    });

    it('returns the raw key when it does not exist anywhere', function () {
        $i18n = I18n::getInstance();
        expect($i18n->translate('totally.nonexistent.key'))->toBe('totally.nonexistent.key');
    });

    it('substitutes a single :param', function () {
        $_GET['lang'] = 'en';
        $i18n = I18n::getInstance();
        $result = $i18n->translate('common.path_copied', ['path' => '/tmp/test']);
        expect($result)->toBe('Path copied: /tmp/test');
    });

    it('substitutes multiple params', function () {
        $_GET['lang'] = 'en';
        $i18n = I18n::getInstance();
        $result = $i18n->translate('categorize.msg_saved_batch', [
            'count' => 42,
            'crawls' => 3,
        ]);
        expect($result)->toBe('Categorization applied (42 URLs). Batch in progress for 3 other crawl(s)...');
    });
});

// ---------------------------------------------------------------------------
// 3. Global __() function
// ---------------------------------------------------------------------------
describe('Global __() function', function () {

    it('returns the translation', function () {
        $_GET['lang'] = 'en';
        I18n::getInstance(); // force init
        expect(__('accessibility.card_crawled'))->toBe('Crawled URLs');
    });

    it('returns the raw key when missing', function () {
        I18n::getInstance();
        expect(__('does.not.exist'))->toBe('does.not.exist');
    });

    it('works with params', function () {
        $_GET['lang'] = 'en';
        I18n::getInstance();
        expect(__('common.path_copied', ['path' => '/x']))->toBe('Path copied: /x');
    });
});

// ---------------------------------------------------------------------------
// 4. JS export (getJsTranslations)
// ---------------------------------------------------------------------------
describe('getJsTranslations', function () {

    it('returns all translations as valid JSON when no prefix given', function () {
        $_GET['lang'] = 'en';
        $i18n = I18n::getInstance();
        $json = $i18n->getJsTranslations();

        $decoded = json_decode($json, true);
        expect($decoded)->toBeArray()->not->toBeEmpty();
    });

    it('filters keys with a single prefix', function () {
        $_GET['lang'] = 'en';
        $i18n = I18n::getInstance();
        $json = $i18n->getJsTranslations(['common.']);
        $decoded = json_decode($json, true);

        expect($decoded)->toBeArray()->not->toBeEmpty();
        foreach (array_keys($decoded) as $key) {
            expect($key)->toStartWith('common.');
        }
    });

    it('filters keys with multiple prefixes (union)', function () {
        $_GET['lang'] = 'en';
        $i18n = I18n::getInstance();
        $json = $i18n->getJsTranslations(['common.', 'accessibility.']);
        $decoded = json_decode($json, true);

        expect($decoded)->toBeArray()->not->toBeEmpty();
        foreach (array_keys($decoded) as $key) {
            expect(
                str_starts_with($key, 'common.') || str_starts_with($key, 'accessibility.')
            )->toBeTrue();
        }
    });

    it('includes fallback keys absent from the active language', function () {
        $_GET['lang'] = 'fr';
        $i18n = I18n::getInstance();

        $enJson = json_decode(
            file_get_contents(__DIR__ . '/../../web/lang/en.json'), true
        );
        $frJson = json_decode(
            file_get_contents(__DIR__ . '/../../web/lang/fr.json'), true
        );

        // Find a prefix that has at least one key missing in fr
        $missingKey = null;
        $prefix = null;
        foreach ($enJson as $key => $value) {
            if (!isset($frJson[$key])) {
                $prefix = explode('.', $key)[0] . '.';
                $missingKey = $key;
                break;
            }
        }

        if ($missingKey !== null) {
            $json = $i18n->getJsTranslations([$prefix]);
            $decoded = json_decode($json, true);
            expect($decoded)->toHaveKey($missingKey);
        } else {
            // All keys present in both — just verify output is valid JSON
            $json = $i18n->getJsTranslations(['common.']);
            expect(json_decode($json, true))->toBeArray();
        }
    });
});

// ---------------------------------------------------------------------------
// 5. Utility methods
// ---------------------------------------------------------------------------
describe('Utility methods', function () {

    it('getLang() returns the current language', function () {
        $_GET['lang'] = 'fr';
        expect(I18n::getInstance()->getLang())->toBe('fr');
    });

    it('getSupportedLanguages() returns [en, fr]', function () {
        expect(I18n::getInstance()->getSupportedLanguages())->toBe(['en', 'fr']);
    });

    it('getLocale() returns fr-FR for French', function () {
        $_GET['lang'] = 'fr';
        expect(I18n::getInstance()->getLocale())->toBe('fr-FR');
    });

    it('getLocale() returns en-US for English', function () {
        $_GET['lang'] = 'en';
        expect(I18n::getInstance()->getLocale())->toBe('en-US');
    });
});

// ---------------------------------------------------------------------------
// 6. JSON file parity
// ---------------------------------------------------------------------------
describe('JSON file parity', function () {

    beforeEach(function () {
        $this->enJson = json_decode(
            file_get_contents(__DIR__ . '/../../web/lang/en.json'), true
        );
        $this->frJson = json_decode(
            file_get_contents(__DIR__ . '/../../web/lang/fr.json'), true
        );
    });

    it('en.json is valid JSON', function () {
        expect($this->enJson)->toBeArray()->not->toBeEmpty();
    });

    it('fr.json is valid JSON', function () {
        expect($this->frJson)->toBeArray()->not->toBeEmpty();
    });

    it('en.json and fr.json have the same keys', function () {
        $enKeys = array_keys($this->enJson);
        $frKeys = array_keys($this->frJson);
        sort($enKeys);
        sort($frKeys);

        $missingInFr = array_diff($enKeys, $frKeys);
        $missingInEn = array_diff($frKeys, $enKeys);

        expect($missingInFr)->toBeEmpty(
            'Keys in en.json missing from fr.json: ' . implode(', ', $missingInFr)
        );
        expect($missingInEn)->toBeEmpty(
            'Keys in fr.json missing from en.json: ' . implode(', ', $missingInEn)
        );
    });

    it('en.json has no empty values', function () {
        $empty = array_filter($this->enJson, fn($v) => trim((string) $v) === '');
        expect($empty)->toBeEmpty(
            'Empty values in en.json: ' . implode(', ', array_keys($empty))
        );
    });

    it('fr.json has no empty values', function () {
        $empty = array_filter($this->frJson, fn($v) => trim((string) $v) === '');
        expect($empty)->toBeEmpty(
            'Empty values in fr.json: ' . implode(', ', array_keys($empty))
        );
    });
});
