# Gemini Translator — Update Notes

## v0.3.7 — HTML Entity Normalization Fix

### Problem
Strings containing ampersands (e.g., "Terms & Conditions") were not being translated on the frontend. The database stored the raw text with literal `&` characters, but the HTML output used `&amp;` entities. The `strtr()` replacement couldn't find matches because the strings were different:
- Database: `Terms & Conditions` (18 chars)
- HTML: `Terms &amp; Conditions` (22 chars)

### Solution
Updated `gt_normalize_html()` to decode common HTML entities before matching:
- `&amp;`, `&#38;`, `&#x26;` → `&`
- `&quot;`, `&#34;`, `&#x22;` → `"`
- `&#39;`, `&#x27;`, `&apos;` → `'`
- `&nbsp;`, `&#160;`, `&#xa0;` → ` ` (space)

This ensures both the stored original strings and the rendered HTML are normalized to the same form, allowing `strtr()` to find and replace matches correctly.

### Debug Improvements
- Added entity-aware debug output in the final check to show both raw and entity-encoded forms

---

# v0.2.0 — Multi-Page Locations & AJAX Editing

## Branches

| Branch | Status | PR |
|---|---|---|
| `feature/html-support` | Pushed | Pending review |
| `feature/ajax-saving` | Pushed (includes html-support) | Pending review |

---

## What Changed

### 1. Multi-Page String Locations

**Problem:** If the same string appeared on Page A and Page B, it only showed under whichever page was scanned first. The dashboard grouped by a single `source_id` stored directly on the translation row.

**Solution:** New junction table `gt_string_locations` that maps each translation to every page it appears on. The same string now shows under all its pages in the Translations dashboard.

- Existing data is backfilled automatically on upgrade (no manual migration needed).
- Cleanup functions (orphan removal, clear Elementor/WooCommerce) updated to work through the new table.

### 2. Raw HTML Editing

**Problem:** Strings with HTML (e.g. `<b>Hola</b>`) rendered visually in the table. When clicking Edit, the textarea showed plain text without tags — users couldn't maintain the HTML structure.

**Solution:** Each row now carries `data-raw-original` and `data-raw-translation` attributes. The Edit button reads from these to populate the textarea with the actual HTML source.

### 3. Original String Pre-fill for Empty Translations

**Problem:** Clicking Edit on a pending (untranslated) string opened a blank textarea. Users had to type the full HTML structure from scratch.

**Solution:** If the translation is empty, the textarea auto-fills with the raw original string. Users can replace just the words while keeping all HTML tags intact.

### 4. AJAX Inline Saving

**Problem:** Clicking Save submitted a full POST form, causing a page reload. Editing multiple strings in a row was slow.

**Solution:** Save now uses an AJAX request. The page never reloads. On success:
- The display text updates in place
- The status badge switches to "edited" (blue)
- A brief "Saved!" confirmation appears for 2 seconds

---

## How to Test on Staging

### Pre-requisite
Deactivate and reactivate the plugin once to trigger the database upgrade (creates the `gt_string_locations` table and backfills existing data).

### Test 1 — Multi-page string locations
1. Go to **Translator > Dashboard** and run an Elementor scan.
2. If any string exists on multiple pages, it should now appear under each page's accordion group in **Translator > Translations**.

### Test 2 — Raw HTML editing
1. Go to **Translations** and find a string that contains HTML (bold, links, etc.).
2. Click **Edit** — the textarea should show the raw HTML tags (e.g. `<a href="...">Link</a>`), not rendered text.
3. Modify the translation, click Save, and confirm the display renders the HTML correctly.

### Test 3 — Empty translation pre-fill
1. Find a string with status **Pending** (no translation yet).
2. Click **Edit** — the textarea should pre-fill with the original string including HTML tags.
3. Replace the text content (keep the tags), save.

### Test 4 — AJAX saving
1. Click **Edit** on any string.
2. Type or modify the translation and click **Save**.
3. Confirm: no page reload, the display updates instantly, the status badge turns blue ("edited"), and "Saved!" flashes briefly.
4. Repeat on 2-3 more strings to verify the flow is smooth.

### Test 5 — Orphan cleanup
1. Trash a page that has scanned strings.
2. Go to **Translations** — the warning banner should appear with the orphan count.
3. Click clean up — strings and their location rows should be removed.

---

## Technical Notes

- DB version bumped to `0.2.0`. The upgrade runs automatically via `admin_init` hook.
- The old synchronous POST save handler has been removed entirely — saving is AJAX-only now.
- No changes to the translation API, scanning logic, or frontend switcher.
