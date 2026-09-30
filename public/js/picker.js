/**
 * Field Guard - rule editor.
 *
 * Loads the real GLPI form for the selected itemtype inside a same-origin
 * iframe, then attaches click handlers straight onto the iframe's fields. The
 * form itself is untouched: everything the admin sees here is added from the
 * parent document.
 *
 * Picks are held in memory for the whole rule - every itemtype at once, seeded
 * from what is already stored - and written by the Save button. Moving between
 * forms therefore keeps unsaved picks, and a removal is expressible because the
 * save replaces the rule's lock set rather than patching it.
 */
(function () {
    'use strict';

    var cfg = JSON.parse(document.getElementById('fieldguard-config').textContent);

    var state = {
        group: Object.keys(cfg.groups)[0] || null,
        entry: null,      // the selected pill's descriptor
        itemtype: null,   // itemtype being configured
        url: null,        // form URL of the host that renders it
        itemId: '',
        locks: cfg.locks || {},  // {itemtype: {field: mode}}, the whole rule
        dirty: false,
        csrf: cfg.csrf_token,
    };

    var els = {
        mode: document.getElementById('fieldguard-mode'),
        groupTabs: document.getElementById('fieldguard-group-tabs'),
        pills: document.getElementById('fieldguard-itemtypes'),
        counter: document.getElementById('fieldguard-counter'),
        total: document.getElementById('fieldguard-total'),
        status: document.getElementById('fieldguard-status'),
        empty: document.getElementById('fieldguard-empty'),
        frameWrap: document.getElementById('fieldguard-frame-wrap'),
        frame: document.getElementById('fieldguard-frame'),
        reload: document.getElementById('fieldguard-reload'),
        clear: document.getElementById('fieldguard-clear'),
        save: document.getElementById('fieldguard-save'),
        itemId: document.getElementById('fieldguard-item-id'),
    };

    // Must stay in sync with PluginFieldguardLock::NEVER_FILTERED and with the
    // same list in fieldguard.js: offering a field here that neither the server
    // nor the runtime will ever act on would create a lock that does nothing.
    var SKIPPED_NAMES = /^(_glpi_csrf_token|_glpi_simple_form|_read_date_mod|id|entities_id|is_recursive|add|update|purge|delete|restore)$/;
    var SKIPPED_TYPES = /^(hidden|submit|button|reset|image)$/;

    function normalizeField(name) {
        return String(name || '').trim().replace(/(\[[^\]]*\])+$/, '');
    }

    /**
     * The token to send with the next write.
     *
     * GLPI 10 consumes a CSRF token on use, so every response hands back a
     * fresh one and it is used for the following request. GLPI 11 preserves
     * the token for XHR, where this is simply a no-op.
     */
    function csrfToken() {
        if (state.csrf) {
            return state.csrf;
        }
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        return meta ? meta.content : '';
    }

    function setStatus(text, isError) {
        els.status.textContent = text || '';
        els.status.className = 'fieldguard-status ' + (isError ? 'text-danger' : 'text-muted');
    }

    /* --------------------------------------------------------------- model --- */

    function isLocked(itemtype, field) {
        return !!(state.locks[itemtype] || {})[field];
    }

    function setLock(itemtype, field, mode) {
        state.locks[itemtype] = state.locks[itemtype] || {};
        state.locks[itemtype][field] = mode;
    }

    function unsetLock(itemtype, field) {
        if (state.locks[itemtype]) {
            delete state.locks[itemtype][field];
            if (Object.keys(state.locks[itemtype]).length === 0) {
                delete state.locks[itemtype];
            }
        }
    }

    function totalLocks() {
        return Object.keys(state.locks).reduce(function (sum, itemtype) {
            return sum + Object.keys(state.locks[itemtype]).length;
        }, 0);
    }

    function markDirty(dirty) {
        state.dirty = dirty;
        els.save.classList.toggle('btn-primary', dirty);
        els.save.classList.toggle('btn-outline-primary', !dirty);
        setStatus(dirty ? cfg.i18n.unsaved : '');
    }

    function save() {
        var body = new URLSearchParams();
        // Sent both ways on purpose: GLPI 11's kernel reads the header on XHR,
        // GLPI 10's Session::checkCSRF() reads the request body.
        body.append('_glpi_csrf_token', csrfToken());
        body.append('rules_id', cfg.rules_id);

        Object.keys(state.locks).forEach(function (itemtype) {
            Object.keys(state.locks[itemtype]).forEach(function (field) {
                body.append('locks[' + itemtype + '][' + field + ']', state.locks[itemtype][field]);
            });
        });

        // An empty rule still has to reach the server, otherwise clearing the
        // last lock would post nothing and leave the stored set untouched.
        setStatus(cfg.i18n.saving);
        els.save.disabled = true;

        fetch(cfg.ajax_base + '/savelocks.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrfToken(),
            },
            body: body.toString(),
        }).then(function (res) {
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }
            return res.json();
        }).then(function (data) {
            if (data && data.csrf_token) {
                state.csrf = data.csrf_token;
            }
            // Adopt what was actually stored: the server drops anything it will
            // not enforce, and the screen should show that rather than pretend.
            state.locks = (data && data.locks) || {};
            markDirty(false);
            setStatus(cfg.i18n.saved);
            if (els.frame.contentDocument) {
                repaint(els.frame.contentDocument);
            }
            updateTotal();
        }).catch(function (err) {
            setStatus(cfg.i18n.error + ' (' + err.message + ')', true);
        }).then(function () {
            els.save.disabled = false;
        });
    }

    /* ------------------------------------------------------------------ UI --- */

    function renderPills() {
        els.pills.innerHTML = '';

        var host = null;
        (cfg.groups[state.group] || []).forEach(function (entry) {
            // The list is ordered host by host; a caption on each change turns a
            // long flat row into "this object and the tabs that hang off it".
            if (entry.host !== host) {
                host = entry.host;
                var caption = document.createElement('span');
                caption.className = 'fieldguard-pill-host';
                caption.textContent = entry.host_label;
                els.pills.appendChild(caption);
            }

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('data-fieldguard-itemtype', entry.itemtype);
            var count = Object.keys(state.locks[entry.itemtype] || {}).length;
            btn.className = 'btn btn-sm ' + (entry.itemtype === state.itemtype ? 'btn-primary' : 'btn-outline-secondary');
            btn.textContent = entry.label;
            if (count > 0) {
                var badge = document.createElement('span');
                badge.className = 'badge bg-warning text-dark ms-1';
                badge.textContent = count;
                btn.appendChild(badge);
            }
            if (entry.needs_id) {
                // Flag the pills that cannot be previewed without an item.
                btn.title = entry.host_label;
                btn.appendChild(document.createTextNode(' ↳'));
            }
            btn.addEventListener('click', function () {
                selectItemtype(entry);
            });
            els.pills.appendChild(btn);
        });
    }

    /** Counts what is locked among the fields actually on screen. */
    function updateCounter() {
        var doc = els.frame.contentDocument;
        var n = doc
            ? Array.prototype.filter.call(
                doc.querySelectorAll('.fieldguard-pick-on'),
                function (el) { return el.offsetParent !== null; }
            ).length
            : 0;
        els.counter.textContent = n + ' ' + cfg.i18n.locked;
        els.counter.className = 'badge ' + (n > 0 ? 'bg-warning text-dark' : 'bg-secondary');
    }

    /** Counts everything the rule holds, across every form. */
    function updateTotal() {
        if (els.total) {
            els.total.textContent = cfg.i18n.total.replace('%d', totalLocks());
        }
        renderPills();
    }

    function refreshAvailability() {
        var ready = state.itemtype !== null;
        els.empty.classList.toggle('d-none', ready);
        els.frameWrap.classList.toggle('d-none', !ready);
        if (!ready) {
            els.empty.textContent = cfg.i18n.pick_form;
        }
        return ready;
    }

    function selectItemtype(entry) {
        state.entry = entry;
        state.itemtype = entry.itemtype;
        state.url = entry.url;
        renderPills();
        loadPreview();
    }

    function loadPreview() {
        if (!refreshAvailability()) {
            return;
        }

        state.itemId = (els.itemId.value || '').trim();

        if (state.entry && state.entry.needs_id && !state.itemId) {
            els.empty.textContent = cfg.i18n.needs_id.replace('%s', state.entry.host_label);
            els.empty.classList.remove('d-none');
            els.frameWrap.classList.add('d-none');
            els.itemId.focus();
            return;
        }

        setStatus(cfg.i18n.loading);
        var url = cfg.root_doc + state.url;
        url += (url.indexOf('?') === -1 ? '?' : '&') + '_fieldguard_pick=1';
        if (state.itemId) {
            url += '&id=' + encodeURIComponent(state.itemId);
        }
        if (state.entry && state.entry.tab) {
            // GLPI keeps the last tab opened per itemtype in the session, so the
            // tab is always named rather than left to whatever was there before.
            url += '&forcetab=' + encodeURIComponent(state.entry.tab);
        }
        els.frame.src = url;
    }

    /* ------------------------------------------------------------ picking --- */

    /* Layout chrome that is not part of the form being configured. */
    var CHROME = '.navbar, .sidebar, aside, header, footer, [role="search"], .modal, ' +
        '.saved-searches-panel, .fuzzysearch, .search-form, .toast, .tab-content-nav';

    function scanRoot(doc) {
        return doc.querySelector('#page') || doc.querySelector('main') || doc.body;
    }

    function fieldsIn(doc) {
        var root = scanRoot(doc);
        if (!root) {
            return [];
        }
        return Array.prototype.slice
            .call(root.querySelectorAll('input[name], select[name], textarea[name]'))
            .filter(function (el) {
                var name = normalizeField(el.getAttribute('name'));
                if (!name || SKIPPED_NAMES.test(name)) {
                    return false;
                }
                if (el.tagName === 'INPUT' && SKIPPED_TYPES.test((el.getAttribute('type') || 'text').toLowerCase())) {
                    return false;
                }
                if (el.classList.contains('select2-search__field') || el.closest(CHROME)) {
                    return false;
                }
                return true;
            });
    }

    /**
     * Smallest wrapper that still includes the field's label.
     *
     * Walks inwards past any wrapper another field already claimed, because
     * GLPI groups several inputs (actors, SLA pairs) inside one `.form-field`
     * row and the last one would otherwise overwrite the others.
     */
    function containerFor(el) {
        var candidates = [
            el.closest('.form-field'),
            el.closest('.field-container'),
            el.parentElement,
            el,
        ];

        for (var i = 0; i < candidates.length; i++) {
            if (candidates[i] && !candidates[i].hasAttribute('data-fieldguard-name')) {
                return candidates[i];
            }
        }
        return el;
    }

    /**
     * The itemtype a form writes to, from its action URL.
     *
     * A page can host several: an existing Ticket renders the ITILFollowup,
     * TicketTask, ITILSolution and TicketValidation forms in its timeline, and
     * they all have a `content` field. Attributing by form is what keeps those
     * four apart instead of collapsing them onto the ticket's own description.
     *
     * Returns null rather than guessing when the action names no known
     * itemtype, so fields of internal forms are left alone.
     */
    function itemtypeForForm(form) {
        var action = form && form.getAttribute('action');
        if (!action) {
            return null;
        }

        var match = /\/([a-z0-9_]+)(?:\.form)?\.php/i.exec(action.split('?')[0]);
        if (match) {
            var slug = match[1].toLowerCase();
            for (var i = 0; i < cfg.itemtypes.length; i++) {
                if (cfg.itemtypes[i].toLowerCase() === slug) {
                    return cfg.itemtypes[i];
                }
            }
        }

        return itemtypeFromResolvers(form);
    }

    /**
     * Itemtype of a form whose action does not name one.
     *
     * Plugins may post several itemtypes to a single endpoint - every custom
     * field tab of the Additional Fields plugin goes to `container.form.php` -
     * so the server sends a lookup built from the hidden inputs that do
     * identify the target. See PluginFieldguardLock::getFormResolvers().
     */
    function itemtypeFromResolvers(form) {
        var resolvers = cfg.resolvers || [];

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

    /** Paint one field according to the picks currently held. */
    function paintField(target) {
        var itemtype = target.getAttribute('data-fieldguard-itemtype');
        var name = target.getAttribute('data-fieldguard-name');
        var mode = (state.locks[itemtype] || {})[name];

        target.classList.toggle('fieldguard-pick-on', !!mode);
        if (mode) {
            target.setAttribute('data-fieldguard-mode', mode);
        } else {
            target.removeAttribute('data-fieldguard-mode');
        }
    }

    function repaint(doc) {
        Array.prototype.forEach.call(doc.querySelectorAll('[data-fieldguard-name]'), paintField);
        updateCounter();
    }

    function toggleField(doc, target) {
        var itemtype = target.getAttribute('data-fieldguard-itemtype');
        var name = target.getAttribute('data-fieldguard-name');

        if (isLocked(itemtype, name)) {
            unsetLock(itemtype, name);
        } else {
            setLock(itemtype, name, els.mode.value);
        }

        paintField(target);
        updateCounter();
        updateTotal();
        markDirty(true);
    }

    /**
     * Show only the block of the itemtype being configured.
     *
     * A hosted itemtype is previewed through its parent's form, which also
     * renders the parent's own fields and the entire timeline. GLPI keeps each
     * child's form in a collapsed `#new-<Itemtype>-block`, so isolating means
     * expanding that one and letting the stylesheet hide the rest.
     *
     * Re-applied on every rescan because the timeline renders after load.
     */
    function applyIsolation(doc) {
        var entry = state.entry;

        if (!entry || !entry.isolate) {
            doc.body.removeAttribute('data-fieldguard-isolate');
            return;
        }

        var block = doc.getElementById('new-' + state.itemtype + '-block');
        if (!block) {
            // Timeline not rendered yet; the next rescan will catch it.
            return;
        }

        doc.body.setAttribute('data-fieldguard-isolate', state.itemtype);
        block.classList.add('show', 'fieldguard-isolated');
    }

    /**
     * Reveal the "add" form some tabs keep behind a button.
     *
     * Costs and approvals render a table plus a button whose handler ajax-loads
     * the real form into an empty div. Nothing is lockable until that happened,
     * so the picker calls GLPI's own handler once per button - which loads
     * exactly what a click would, without depending on the click reaching it.
     */
    function revealSubForms(doc) {
        var win = doc.defaultView;

        Array.prototype.forEach.call(
            doc.querySelectorAll('[href*="viewAdd"], [onclick*="viewAdd"]'),
            function (trigger) {
                if (trigger.dataset.fieldguardRevealed === '1') {
                    return;
                }
                trigger.dataset.fieldguardRevealed = '1';

                var source = (trigger.getAttribute('href') || '') + ' ' + (trigger.getAttribute('onclick') || '');
                var match = /\b(viewAdd\w+)\s*\(/.exec(source);
                if (!match || typeof win[match[1]] !== 'function') {
                    return;
                }
                try {
                    win[match[1]](trigger);
                } catch (e) {
                    /* the tab stays as GLPI rendered it */
                }
            }
        );
    }

    /**
     * Tag every not-yet-tagged field as a pick target.
     *
     * Runs repeatedly: GLPI renders timelines, tabs and accordions after load,
     * so fields keep appearing well past the iframe's `load` event.
     */
    function markFields(doc) {
        var seen = {};

        Array.prototype.forEach.call(doc.querySelectorAll('[data-fieldguard-name]'), function (node) {
            seen[node.getAttribute('data-fieldguard-itemtype') + '.' + node.getAttribute('data-fieldguard-name')] = true;
        });

        fieldsIn(doc).forEach(function (el) {
            var itemtype = itemtypeForForm(el.closest('form'));
            if (!itemtype) {
                return;
            }
            var name = normalizeField(el.getAttribute('name'));
            var key = itemtype + '.' + name;
            if (seen[key]) {
                return;
            }
            seen[key] = true;

            var target = containerFor(el);
            target.classList.add('fieldguard-pick-target');
            target.setAttribute('data-fieldguard-name', name);
            target.setAttribute('data-fieldguard-itemtype', itemtype);

            var foreign = itemtype !== state.itemtype;
            if (foreign) {
                target.classList.add('fieldguard-pick-foreign');
                target.setAttribute('title', cfg.i18n.foreign.replace('%s', itemtype));
            }

            var badge = doc.createElement('span');
            badge.className = 'fieldguard-pick-badge';
            badge.textContent = foreign ? itemtype + '.' + name : name;
            target.appendChild(badge);
        });

        return Object.keys(seen).length;
    }

    /**
     * Turn the loaded form into a picker: clicking any field toggles its lock
     * instead of editing it. The form's own markup is never modified on disk -
     * everything here is added to the iframe document at runtime.
     */
    function preparePicker() {
        var doc = els.frame.contentDocument;
        if (!doc || !doc.body) {
            return;
        }

        var style = doc.createElement('link');
        style.rel = 'stylesheet';
        style.href = cfg.picker_css + '?v=' + encodeURIComponent(cfg.version || '');
        doc.head.appendChild(style);
        doc.body.classList.add('fieldguard-pick-mode');

        var rescan = function () {
            applyIsolation(doc);
            revealSubForms(doc);
            var count = markFields(doc);
            repaint(doc);
            if (count > 0) {
                if (!state.dirty) {
                    setStatus('');
                }
                return;
            }
            // No lockable field: either GLPI refused the page, or the itemtype
            // renders nothing useful without an item.
            var refused = doc.querySelector('.alert-danger, .error, [data-glpi-error]');
            if (refused) {
                setStatus(refused.textContent.trim().slice(0, 160), true);
            } else if (state.entry && state.entry.needs_id && !state.itemId) {
                setStatus(cfg.i18n.needs_id.replace('%s', state.entry.host_label), true);
            } else {
                setStatus(cfg.i18n.unsupported, true);
            }
        };

        // One delegated listener beats one per field, and it keeps working for
        // fields GLPI renders after load.
        doc.addEventListener('click', function (event) {
            // Only ever act on a real click. GLPI, Bootstrap, select2 and
            // TinyMCE all dispatch synthetic clicks while the form settles, and
            // acting on those would silently create locks nobody asked for.
            if (!event.isTrusted) {
                return;
            }
            var target = event.target.closest('.fieldguard-pick-target');
            if (!target) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            toggleField(doc, target);
        }, true);

        // Never let the preview navigate away or actually create an item.
        doc.addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopPropagation();
        }, true);

        var pending = null;
        new doc.defaultView.MutationObserver(function () {
            doc.defaultView.clearTimeout(pending);
            pending = doc.defaultView.setTimeout(rescan, 150);
        }).observe(doc.body, { childList: true, subtree: true });

        rescan();
    }

    /* ----------------------------------------------------------- wiring --- */

    els.frame.addEventListener('load', function () {
        try {
            preparePicker();
        } catch (err) {
            setStatus(cfg.i18n.error + ' (' + err.message + ')', true);
        }
    });

    Array.prototype.forEach.call(els.groupTabs.querySelectorAll('[data-fieldguard-group]'), function (btn) {
        btn.addEventListener('click', function () {
            Array.prototype.forEach.call(els.groupTabs.querySelectorAll('.nav-link'), function (b) {
                b.classList.remove('active');
            });
            btn.classList.add('active');
            state.group = btn.getAttribute('data-fieldguard-group');
            renderPills();
        });
    });

    els.reload.addEventListener('click', loadPreview);

    els.itemId.addEventListener('change', loadPreview);
    els.itemId.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            loadPreview();
        }
    });

    els.clear.addEventListener('click', function () {
        if (!state.itemtype || !state.locks[state.itemtype]) {
            return;
        }
        if (!window.confirm(cfg.i18n.confirm_wipe)) {
            return;
        }
        delete state.locks[state.itemtype];
        if (els.frame.contentDocument) {
            repaint(els.frame.contentDocument);
        }
        updateTotal();
        markDirty(true);
    });

    els.save.addEventListener('click', save);

    window.addEventListener('beforeunload', function (event) {
        if (!state.dirty) {
            return undefined;
        }
        // Browsers show their own wording; returning a value is what asks.
        event.preventDefault();
        event.returnValue = cfg.i18n.leave;
        return cfg.i18n.leave;
    });

    renderPills();
    refreshAvailability();
    updateCounter();
    updateTotal();
    markDirty(false);
}());
