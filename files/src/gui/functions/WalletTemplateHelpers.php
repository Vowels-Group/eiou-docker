<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Post-authentication template helpers — for wallet.html and its sub-
 * templates only.
 *
 * This file is the post-auth counterpart to TemplateHelpers.php. It's
 * loaded by Functions.php (which only runs on authenticated requests),
 * so helpers here are free to depend on `Application::getInstance()` /
 * the service container / repository factory — things that aren't
 * guaranteed to be initialised on the unauthenticated login page.
 *
 * The split is deliberate: pure Constants wrappers and date-format
 * helpers stay in TemplateHelpers.php so they're callable from the
 * login page (authenticationForm.html) and other pre-auth sub-
 * templates; anything that reaches into service/DB state lives here.
 *
 * Depends on: the Composer autoloader (loaded via /app/eiou/Functions.php)
 * and a post-auth `Application::getInstance()` context.
 */

/**
 * Get the address-schema types discovered from INFORMATION_SCHEMA, ordered
 * by the canonical security priority (`Constants::VALID_TRANSPORT_INDICES`
 * — Tor > HTTPS > HTTP). Types present in the schema but absent from the
 * priority list (i.e. future transports) are appended in schema-column
 * order so they still get rendered, just after the known ones.
 *
 * Request-scoped function-static cache — `getAllAddressTypes()` hits
 * INFORMATION_SCHEMA each call, and templates iterate this list once per
 * contact row + once per pending-contact modal, so memoizing avoids an
 * O(N) query pattern on larger wallets.
 *
 * @return string[] Address-type column names in display-priority order
 */
function getOrderedAddressSchemaTypes(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $schemaTypes = \Eiou\Core\Application::getInstance()->services
        ->getRepositoryFactory()
        ->get(\Eiou\Database\AddressRepository::class)
        ->getAllAddressTypes();
    $priority = \Eiou\Core\Constants::VALID_TRANSPORT_INDICES;
    $known = array_values(array_intersect($priority, $schemaTypes));
    $unknown = array_values(array_diff($schemaTypes, $priority));
    $cached = array_merge($known, $unknown);
    return $cached;
}

/**
 * Build the flattened, lowercased, space-joined search string for a
 * contact row's `data-contact-address` attribute. Iterates whatever
 * transport columns the `addresses` schema currently exposes so adding
 * a new transport doesn't silently leave it out of the search index.
 * Ordering doesn't matter for substring search, but reusing
 * `getOrderedAddressSchemaTypes` keeps us on one cached list.
 *
 * @param array $contact Row merged with address columns (as emitted by
 *                       getPendingContactRequests / ContactDataBuilder)
 * @return string Space-joined lowercased address values, htmlspecialchars-safe
 */
function contactAddressSearchAttr(array $contact): string {
    $values = [];
    foreach (getOrderedAddressSchemaTypes() as $type) {
        if (!empty($contact[$type])) {
            $values[] = (string) $contact[$type];
        }
    }
    return strtolower(implode(' ', $values));
}

/**
 * Render a wallet section in the standard `form-container` shape.
 *
 * Every wallet sub-template currently hand-writes the same outer
 * boilerplate — a `form-container fade-in-up` div, a `section-header`
 * with an icon + h2, an optional `details.section-intro` wrapper for
 * the explanatory copy, and the body. This helper consolidates the
 * shape so:
 *
 *   1. Plugin authors writing a tab can reuse the same surface and
 *      have their section visually match every core section by
 *      default;
 *   2. Two implicit hook fire sites (`gui.section.before.<id>` and
 *      `gui.section.after.<id>`) appear automatically around every
 *      section, so plugins can inject content into core sections
 *      without forking the template;
 *   3. Section markup stays consistent so future CSS/UX refinements
 *      (e.g. shrinking the intro disclosure on small viewports)
 *      apply everywhere by changing one helper instead of grepping
 *      twelve files.
 *
 * @param array $spec Keys:
 *   - `id`              (string, required) section id (used for the
 *                       `<div id>` and the hook namespace);
 *   - `icon`            (string, default `fas fa-circle`) Font-Awesome
 *                       class for the title icon;
 *   - `title`           (string, required) section heading;
 *   - `headerExtras`    (string, optional) raw HTML rendered inside
 *                       the section-header after the title (badges,
 *                       inline action buttons);
 *   - `intro`           (string|null, optional) explanatory copy. Omit
 *                       to skip the `<details>` block entirely. Pass a
 *                       string that may include `<br>`, `<strong>`,
 *                       `<a>`, etc. — the helper does NOT escape it
 *                       (intros frequently embed inline markup);
 *   - `introTitle`      (string, default "About …") summary text on
 *                       the intro disclosure;
 *   - `body`            (string, required) the section content (table,
 *                       form, list — anything the section displays);
 *   - `class`           (string, default `form-container fade-in-up`)
 *                       outer wrapper class. Override for sections that
 *                       use a different container variant
 *                       (e.g. `dlq-section`).
 *
 * @return string Rendered HTML (echo it).
 */
function renderSection(array $spec): string
{
    $id           = $spec['id']           ?? '';
    $icon         = $spec['icon']         ?? 'fas fa-circle';
    $title        = $spec['title']        ?? '';
    $headerExtras = $spec['headerExtras'] ?? '';
    $intro        = $spec['intro']        ?? null;
    $introTitle   = $spec['introTitle']   ?? 'About this section';
    $body         = $spec['body']         ?? '';
    $class        = $spec['class']        ?? 'form-container fade-in-up';

    if ($id === '' || $title === '') {
        // Fail soft — emit a comment so a misconfigured caller doesn't
        // produce silent gaps.
        return "<!-- renderSection: missing required id/title -->\n";
    }

    // Plugins can inject before/after each section without forking the
    // template. The hook is fired with the section spec as context so
    // listeners can adapt to which section they're inside.
    $hooks = null;
    try {
        $hooks = \Eiou\Core\Application::getInstance()->services->getHooks();
    } catch (\Throwable $_) {
        // Pre-boot or test scaffolding — silently skip the hook fires.
    }

    $beforeHook = $hooks ? $hooks->doRender('gui.section.before.' . $id, $spec) : '';
    $afterHook  = $hooks ? $hooks->doRender('gui.section.after.'  . $id, $spec) : '';

    $idAttr        = htmlspecialchars($id, ENT_QUOTES);
    $classAttr     = htmlspecialchars($class, ENT_QUOTES);
    $iconAttr      = htmlspecialchars($icon, ENT_QUOTES);
    $titleEscaped  = htmlspecialchars($title);
    $introTitleE   = htmlspecialchars($introTitle);

    $out = $beforeHook;
    $out .= "<div id=\"{$idAttr}\" class=\"{$classAttr}\">\n";
    $out .= "    <div class=\"section-header\">\n";
    $out .= "        <h2><i class=\"{$iconAttr}\"></i> {$titleEscaped}</h2>\n";
    if ($headerExtras !== '') {
        $out .= "        {$headerExtras}\n";
    }
    $out .= "    </div>\n";

    if ($intro !== null && $intro !== '') {
        $out .= "    <details class=\"section-intro text-muted\">\n";
        $out .= "        <summary>\n";
        $out .= "            <i class=\"fas fa-info-circle\"></i>\n";
        $out .= "            <span>{$introTitleE}</span>\n";
        $out .= "        </summary>\n";
        $out .= "        <div class=\"section-intro-body\">\n";
        $out .= "            {$intro}\n";
        $out .= "        </div>\n";
        $out .= "    </details>\n";
    }

    $out .= $body;
    $out .= "</div>\n";
    $out .= $afterHook;

    return $out;
}

/**
 * Render a table inside the standard `contacts-table-wrapper` shell.
 *
 * Every paginated table in the wallet (Contacts, Recent Transactions,
 * Payment Requests history, DLQ, API Keys, Plugins) wraps its
 * `<table>` in `<div class="contacts-table-wrapper">` and adds a
 * `contacts-table {variant}-table` class. This helper hides those
 * four lines of boilerplate so plugin-authored tables get the same
 * chrome (and so a future styling refinement applies everywhere).
 *
 * The helper deliberately doesn't try to abstract column definitions
 * or row markup — those are domain-specific and a generic config
 * array would push complexity around without reducing it.
 *
 * @param array $spec Keys:
 *   - `variant`         (string, required) appended to the table
 *                       class as `contacts-table {variant}-table`
 *                       (e.g. `plugins`, `dlq`, `tx`).
 *   - `headers`         (string, required) raw HTML for `<thead>` —
 *                       caller emits `<tr><th>…</th></tr>` so column
 *                       attributes / `data-action="sortX"` etc. stay
 *                       under their control.
 *   - `body`            (string, required) raw HTML for `<tbody>` —
 *                       caller emits the rows.
 *   - `id`              (string, optional) added to the wrapper div
 *                       (e.g. `api-keys-table-wrapper`).
 *   - `wrapperClass`    (string, default `contacts-table-wrapper`)
 *                       extra wrapper classes append onto this
 *                       (e.g. `contacts-table-wrapper api-keys-table-wrapper d-none`).
 *   - `tbodyId`         (string, optional) `<tbody>` id (e.g. for
 *                       JS-populated tables).
 *
 * @return string Rendered HTML (echo it).
 */
function renderTable(array $spec): string
{
    $variant      = $spec['variant']      ?? '';
    $headers      = $spec['headers']      ?? '';
    $body         = $spec['body']         ?? '';
    $wrapperId    = $spec['id']           ?? '';
    $wrapperClass = $spec['wrapperClass'] ?? 'contacts-table-wrapper';
    $tbodyId      = $spec['tbodyId']      ?? '';

    if ($variant === '' || $headers === '') {
        return "<!-- renderTable: missing required variant/headers -->\n";
    }

    $idAttr     = $wrapperId !== '' ? ' id="' . htmlspecialchars($wrapperId, ENT_QUOTES) . '"' : '';
    $classAttr  = htmlspecialchars($wrapperClass, ENT_QUOTES);
    $variantClass = 'contacts-table ' . htmlspecialchars($variant, ENT_QUOTES) . '-table';
    $tbodyAttr  = $tbodyId !== '' ? ' id="' . htmlspecialchars($tbodyId, ENT_QUOTES) . '"' : '';

    return "<div{$idAttr} class=\"{$classAttr}\">\n"
         . "    <table class=\"{$variantClass}\">\n"
         . "        <thead>{$headers}</thead>\n"
         . "        <tbody{$tbodyAttr}>{$body}</tbody>\n"
         . "    </table>\n"
         . "</div>\n";
}
