<?php

/**
 * Test fixture: render the VCR settings tab through real WooCommerce and
 * report what the merchant would see.
 *
 * This is the only check that the setup checklist reaches the API at all:
 * the panel asks `GET /whoami` through the Strauss-vendored SDK, over the
 * container's real network, against the mock VCR. A unit test can prove the
 * wording; only this can prove the wording is driven by the register's own
 * answer.
 *
 * It also pins which section each field lives in. Moving the API key out of
 * the default section, or the base URL back into it, is exactly the kind of
 * edit that looks harmless in a diff.
 *
 * Echoes JSON: `{"sections": [...], "generalIds": [...], "advancedIds":
 * [...], "checklist": "<html>"}`.
 */

// The probe caches for an hour; a fixture has to see this request's truth.
delete_transient('vcr_connection_state');
delete_transient('vcr_cashiers_cache');
delete_transient('vcr_departments_cache');

$pages = apply_filters('woocommerce_get_settings_pages', []);

$tab = null;
foreach (is_array($pages) ? $pages : [] as $page) {
    if ($page instanceof WC_Settings_Page && $page->get_id() === 'vcr') {
        $tab = $page;
        break;
    }
}

if ($tab === null) {
    echo wp_json_encode(['error' => 'VCR settings tab not registered']) . "\n";

    return;
}

/**
 * @param array<int, mixed> $fields
 * @return list<string>
 */
$idsOf = static function (array $fields): array {
    $ids = [];
    foreach ($fields as $field) {
        if (! is_array($field) || ! isset($field['id'], $field['type'])) {
            continue;
        }
        // `title` and `sectionend` rows carry the section's own id, not a
        // field's. Including them would make "which fields are on this
        // screen" unreadable.
        if (in_array($field['type'], ['title', 'sectionend'], true)) {
            continue;
        }
        if (is_string($field['id']) && $field['id'] !== '') {
            $ids[] = $field['id'];
        }
    }

    return $ids;
};

$general = $tab->get_settings('');
$advanced = $tab->get_settings('advanced');

$checklist = '';
foreach ($general as $field) {
    if (is_array($field) && ($field['type'] ?? '') === 'title' && isset($field['desc'])) {
        $checklist = is_string($field['desc']) ? $field['desc'] : '';
        break;
    }
}

echo wp_json_encode([
    'sections' => array_keys($tab->get_sections()),
    'generalIds' => $idsOf($general),
    'advancedIds' => $idsOf($advanced),
    'checklist' => $checklist,
]) . "\n";
