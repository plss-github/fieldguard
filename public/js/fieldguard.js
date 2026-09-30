/* global jQuery */
/**
 * Field Guard - runtime enforcement.
 *
 * Reads the locks for the active profile from the `fieldguard:locks` meta tag
 * that the plugin injects into <head>, then greys out or hides the matching
 * fields. This layer is cosmetic only: `pre_item_add` / `pre_item_update` on the
 * PHP side is what actually prevents a locked value from being written.
 */
(function () {
    'use strict';

    var PICK_PARAM = '_fieldguard_pick';

    /**
     * Names that are never user-editable form fields.
     * Kept in sync with PluginFieldguardLock::NEVER_FILTERED and picker.js.
     */
    var SKIPPED_NAMES = /^(_glpi_csrf_token|_glpi_simple_form|_read_date_mod|id|entities_id|is_recursive|add|update|purge|delete|restore)$/;
    var SKIPPED_TYPES = /^(hidden|submit|button|reset|image|file)$/;

    function normalizeField(name) {
        return String(name || '').trim().replace(/(\[[^\]]*\])+$/, '');
    }

    function readMeta(name, fallback) {
        var meta = document.querySelector('meta[name="' + name + '"]');
        if (!meta || !meta.content) {
            return fallback;
        }
        try {
            return JSON.parse(meta.content) || fallback;
        } catch (e) {
            return fallback;
        }
    }

    function readLocks() {
        return readMeta('fieldguard:locks', {});
    }

    /**
     * Lookups for forms whose action does not name their itemtype.
     *
     * A plugin may post several itemtypes to one endpoint - every custom field
     * tab of the Additional Fields plugin goes to `container.form.php` - so the
     * server ships a map keyed on the hidden inputs that do identify the
     * target. Mirrors PluginFieldguardLock::getFormResolvers().
     */
    function readResolvers() {
        return readMeta('fieldguard:resolvers', []);
    }

    function itemtypeFromResolvers(form, resolvers) {
        for (var i = 0; i < resolvers.length; i++) {
            var keys = resolvers[i].keys || [];
            var parts = [];

            for (var k = 0; k < keys.length; k++) {
                var input = form.querySelector('[name="' + keys[k] + '"]');
                if (!input || input.value === '') {
                    parts = null;
                    break;
                }
                parts.push(input.value);
            }

            if (parts !== null) {
                var hit = resolvers[i].map[parts.join('|')];
                if (hit) {
                    return hit;
                }
            }
        }

        return null;
    }

    /**
     * Best-effort itemtype for a form, matched case-insensitively against the
     * itemtypes we actually hold locks for.
     *
     * GLPI form actions look like `/front/networkequipment.form.php`, so the
     * slug before `.form.php` is the lowercased class name.
     */
    function itemtypeForForm(form, knownTypes, resolvers) {
        // A form's own action wins outright. One page hosts several itemtypes -
        // a Ticket's timeline also renders the ITILFollowup, TicketTask and
        // ITILSolution forms, each with its own `content` - so falling back to
        // the page URL for a form that names a different target would apply the
        // wrong itemtype's locks. The page URL is only used for a form that
        // declares no action at all.
        var source = (form && form.getAttribute('action')) || window.location.pathname;

        var match = /\/([a-z0-9_]+)(?:\.form)?\.php/i.exec(source.split('?')[0]);
        if (match) {
            var slug = match[1].toLowerCase();
            for (var j = 0; j < knownTypes.length; j++) {
                if (knownTypes[j].toLowerCase() === slug) {
                    return knownTypes[j];
                }
            }
        }

        return form ? itemtypeFromResolvers(form, resolvers) : null;
    }

    /** The row wrapping a field, used as the target for greying or hiding. */
    function containerFor(el) {
        return el.closest('.form-field') ||
            el.closest('.row') ||
            el.parentElement;
    }

    function isCandidate(el) {
        var name = el.getAttribute('name');
        if (!name) {
            return false;
        }
        if (SKIPPED_NAMES.test(normalizeField(name))) {
            return false;
        }
        if (el.tagName === 'INPUT' && SKIPPED_TYPES.test((el.getAttribute('type') || 'text').toLowerCase())) {
            return false;
        }
        return true;
    }

    function lockElement(el, mode) {
        var container = containerFor(el);

        if (mode === 'hidden') {
            if (container) {
                container.classList.add('fieldguard-hidden');
            } else {
                el.classList.add('fieldguard-hidden');
            }
            el.disabled = true;
            return;
        }

        if (container) {
            container.classList.add('fieldguard-locked');
        }
        el.classList.add('fieldguard-locked-input');

        if (el.tagName === 'SELECT' || el.type === 'checkbox' || el.type === 'radio') {
            // `readonly` is a no-op on these, so disable them. Disabled controls
            // are not submitted, which leaves the stored value untouched.
            el.disabled = true;
        } else {
            el.readOnly = true;
        }
        el.setAttribute('tabindex', '-1');

        // Keep select2 widgets in sync with the underlying control.
        if (window.jQuery && jQuery(el).data('select2')) {
            jQuery(el).trigger('change.select2');
        }
    }

    /** Rich text areas are replaced by TinyMCE, which needs its own call. */
    function lockEditors(lockedNames) {
        if (!window.tinymce || !window.tinymce.editors) {
            return;
        }
        window.tinymce.editors.forEach(function (editor) {
            var name = normalizeField(editor.id || '');
            if (!lockedNames[name]) {
                return;
            }
            try {
                editor.mode.set('readonly');
            } catch (e) {
                /* editor not ready yet; the DOM-level lock still applies */
            }
        });
    }

    function apply(root) {
        var locks = readLocks();
        var knownTypes = Object.keys(locks);
        if (knownTypes.length === 0) {
            return;
        }

        var forms = root.querySelectorAll('form');
        var lockedNames = {};
        var resolvers = readResolvers();

        Array.prototype.forEach.call(forms, function (form) {
            var itemtype = itemtypeForForm(form, knownTypes, resolvers);
            if (!itemtype) {
                return;
            }
            var fields = locks[itemtype] || {};

            Array.prototype.forEach.call(form.querySelectorAll('input[name], select[name], textarea[name]'), function (el) {
                if (!isCandidate(el) || el.dataset.fieldguardApplied === '1') {
                    return;
                }
                var mode = fields[normalizeField(el.getAttribute('name'))];
                if (!mode) {
                    return;
                }
                el.dataset.fieldguardApplied = '1';
                lockedNames[normalizeField(el.getAttribute('name'))] = mode;
                lockElement(el, mode);
            });
        });

        lockEditors(lockedNames);
    }

    function start() {
        if (new URLSearchParams(window.location.search).has(PICK_PARAM)) {
            // The configuration preview must show the form unlocked so the admin
            // can still see and click every field.
            document.body.classList.add('fieldguard-picking');
            return;
        }

        apply(document);

        // GLPI injects tabs, modals and timeline forms after load.
        var observer = new MutationObserver(function () {
            apply(document);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
