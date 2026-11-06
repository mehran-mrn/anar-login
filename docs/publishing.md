# Publishing Anar Toolkit

The approved WordPress.org slug is `anar-toolkit`; the GitHub source repository is `mehran-mrn/anar-login`. The historical PHP entry point and internal identifiers remain `anar-login.php` and `anar_login_*` to preserve existing installations.

1. Update the plugin header, `ANAR_LOGIN_VERSION`, `readme.txt` stable tag and changelog together. Finish and test the release on `main`.
2. Configure repository Actions secrets `SVN_USERNAME` (`mrnwp`, case sensitive) and `SVN_PASSWORD` (the separate WordPress.org SVN password).
3. Publish a stable GitHub release with a tag matching the version, such as `0.7.0` or `v0.7.0`.
4. The publishing workflow validates the tag, runs unit, coding-standard, JavaScript and actual-package checks, builds the ZIP, and publishes its contents to SVN trunk and the version tag. Directory images come from `.wordpress-org`.
5. Wait for the Actions run to succeed. Verify the public plugin version and download from WordPress.org. A GitHub release alone is not proof of successful SVN publication.

Ordinary commits run checks only. Drafts and prereleases do not publish to WordPress.org. Each new stable version needs a new version number and release tag. Do not overwrite an existing WordPress.org version tag.

The runtime ZIP uses an allowlist and contains all built-in providers, including IPPanel and Panelchi. Tests, tools, Composer dependencies, development documents and local credentials are excluded. Compiled Persian MO/PHP catalogs are bundled so a fresh Persian install works without a separate pack. Editable PO sources remain in GitHub. Official or custom WordPress language packs retain priority. The directory language badge is managed separately by translate.wordpress.org and requires approved translations.

Reference: https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/
