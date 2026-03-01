/**
 * ScouterI18n - Client-side internationalization for Scouter
 *
 * Initialized from PHP with translations and current language.
 * Provides a global __() function for translating keys.
 */

const ScouterI18n = {
    translations: {},
    lang: 'en',

    /**
     * Initialize with translations from PHP
     * @param {Object} translations - Key-value translation map
     * @param {string} lang - Current language code
     */
    init(translations, lang) {
        this.translations = translations || {};
        this.lang = lang || 'en';
    },

    /**
     * Translate a key with optional parameter substitution
     * @param {string} key - Dot-notation translation key
     * @param {Object} params - Parameters to substitute (:param)
     * @returns {string} Translated string or key if not found
     */
    translate(key, params) {
        let text = this.translations[key] || key;

        if (params) {
            Object.keys(params).forEach(name => {
                text = text.replace(':' + name, params[name]);
            });
        }

        return text;
    },

    /**
     * Get current language code
     * @returns {string}
     */
    getLang() {
        return this.lang;
    },

    /**
     * Get locale string for Intl APIs (e.g., fr-FR, en-US)
     * @returns {string}
     */
    getLocale() {
        const locales = { 'fr': 'fr-FR', 'en': 'en-US' };
        return locales[this.lang] || 'en-US';
    }
};

/**
 * Global translate function
 */
function __(key, params) {
    return ScouterI18n.translate(key, params);
}
