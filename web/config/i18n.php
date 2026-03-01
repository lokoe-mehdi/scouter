<?php
/**
 * I18n - Internationalisation pour Scouter
 *
 * Singleton qui gère la détection de langue, le chargement des traductions
 * et le fallback vers l'anglais.
 *
 * Détection : ?lang= param > cookie > Accept-Language header > défaut 'en'
 * Persistance : cookie 'scouter_lang' (1 an)
 */

class I18n {
    private static ?I18n $instance = null;

    private const SUPPORTED = ['en', 'fr', 'es', 'de', 'it', 'pt'];
    private const DEFAULT_LANG = 'en';
    private const COOKIE_NAME = 'scouter_lang';
    private const COOKIE_DURATION = 365 * 24 * 60 * 60; // 1 year

    private string $lang;
    private array $translations = [];
    private array $fallback = [];

    private function __construct() {
        $this->lang = $this->detectLanguage();
        $this->loadTranslations();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Détecte la langue : ?lang= > cookie > Accept-Language > défaut
     */
    private function detectLanguage(): string {
        // 1. Paramètre URL ?lang=xx
        if (!empty($_GET['lang'])) {
            $lang = strtolower(substr($_GET['lang'], 0, 2));
            if (in_array($lang, self::SUPPORTED)) {
                $this->setCookie($lang);
                return $lang;
            }
        }

        // 2. Cookie
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            $lang = strtolower($_COOKIE[self::COOKIE_NAME]);
            if (in_array($lang, self::SUPPORTED)) {
                return $lang;
            }
        }

        // 3. Accept-Language header
        if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $acceptLangs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
            foreach ($acceptLangs as $acceptLang) {
                $lang = strtolower(substr(trim(explode(';', $acceptLang)[0]), 0, 2));
                if (in_array($lang, self::SUPPORTED)) {
                    $this->setCookie($lang);
                    return $lang;
                }
            }
        }

        // 4. Défaut
        return self::DEFAULT_LANG;
    }

    private function setCookie(string $lang): void {
        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, $lang, [
                'expires' => time() + self::COOKIE_DURATION,
                'path' => '/',
                'httponly' => false,
                'samesite' => 'Lax'
            ]);
        }
    }

    /**
     * Charge les fichiers JSON de traduction
     */
    private function loadTranslations(): void {
        $langDir = __DIR__ . '/../lang/';

        // Toujours charger l'anglais comme fallback
        $fallbackFile = $langDir . self::DEFAULT_LANG . '.json';
        if (file_exists($fallbackFile)) {
            $this->fallback = json_decode(file_get_contents($fallbackFile), true) ?: [];
        }

        // Charger la langue active
        if ($this->lang === self::DEFAULT_LANG) {
            $this->translations = $this->fallback;
        } else {
            $langFile = $langDir . $this->lang . '.json';
            if (file_exists($langFile)) {
                $this->translations = json_decode(file_get_contents($langFile), true) ?: [];
            }
        }
    }

    /**
     * Traduit une clé avec fallback et substitution de paramètres
     */
    public function translate(string $key, array $params = []): string {
        $text = $this->translations[$key] ?? $this->fallback[$key] ?? $key;

        foreach ($params as $name => $value) {
            $text = str_replace(':' . $name, (string)$value, $text);
        }

        return $text;
    }

    public function getLang(): string {
        return $this->lang;
    }

    public function getSupportedLanguages(): array {
        return self::SUPPORTED;
    }

    /**
     * Retourne les traductions filtrées par préfixes pour injection JS
     * Si aucun préfixe fourni, retourne toutes les traductions
     */
    public function getJsTranslations(array $prefixes = []): string {
        $source = !empty($this->translations) ? $this->translations : $this->fallback;

        if (empty($prefixes)) {
            return json_encode($source, JSON_UNESCAPED_UNICODE);
        }

        $filtered = [];
        foreach ($source as $key => $value) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $filtered[$key] = $value;
                    break;
                }
            }
        }

        // Also include fallback keys not present in translations
        if ($this->lang !== self::DEFAULT_LANG) {
            foreach ($this->fallback as $key => $value) {
                if (isset($filtered[$key])) continue;
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($key, $prefix)) {
                        $filtered[$key] = $value;
                        break;
                    }
                }
            }
        }

        return json_encode($filtered, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Retourne le locale pour Intl (ex: fr-FR, en-US)
     */
    public function getLocale(): string {
        return match($this->lang) {
            'fr' => 'fr-FR',
            'es' => 'es-ES',
            'de' => 'de-DE',
            'it' => 'it-IT',
            'pt' => 'pt-PT',
            default => 'en-US',
        };
    }
}

/**
 * Fonction helper globale pour traduire
 */
function __(string $key, array $params = []): string {
    return I18n::getInstance()->translate($key, $params);
}

// Eagerly initialize to set the cookie before any output
I18n::getInstance();
