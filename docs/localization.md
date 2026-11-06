# Persian localization

Since 0.8.1 the WordPress.org package includes the complete compiled `fa_IR` catalogs in `languages/` (`.mo` for WordPress 6.2+ and `.l10n.php` for modern WordPress). Domain Path and init-time registration let WordPress select the interface through the site or user locale. English source strings and LTR remain available. Installing a separate Persian language ZIP is no longer required.

The editable PO source stays in GitHub. Rebuild catalogs with `python tools/build_translations.py`; the version is read from the plugin header. Unit tests check source-message coverage, and packaging tests require both runtime formats. In a disposable local installation without `wp-content/languages/plugins/anar-toolkit-fa_IR.mo` or `.l10n.php`, run `wp eval-file tests/Integration/localization.php` after installing the actual ZIP. It checks Persian login/help/metadata, RTL, English switching and the MO fallback.

Official or custom language packs installed in WordPress's global languages directory retain their normal priority. The directory's supported-language badge is a separate service: it reports approved translations on translate.wordpress.org, not the existence of bundled runtime catalogs. Submitting suggestions does not make them approved. Language packs and the badge depend on the WordPress translation editors' review; no approval date is promised.

The project catalogs must be reviewed by a fluent human translator before submission to translate.wordpress.org. No automated bulk submission or PTE request was sent as part of this release.

References: [WordPress localization](https://developer.wordpress.org/reference/functions/load_plugin_textdomain/), [translation approval and language packs](https://make.wordpress.org/polyglots/handbook/plugin-theme-authors-guide/).
