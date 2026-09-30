# Field Guard

Lock GLPI form fields per profile and per group. An administrator creates a
**rule**, names the profiles and groups it applies to, opens the real form for
any itemtype in a live preview, and clicks the fields to lock. Locked fields are
greyed out (or hidden) for every user the rule matches.

Supports **GLPI 10.0 and GLPI 11.0**, on PHP 7.4 or later.

Everything version-dependent lives in `PluginFieldguardCompat`
(`inc/compat.class.php`); the rest of the plugin is written once. See
*Supporting both GLPI 10 and 11* below.

## How it works

Two independent layers, and the distinction matters:

| Layer | File | What it does |
| --- | --- | --- |
| Presentation | `public/js/fieldguard.js` + `public/css/fieldguard.css` | Greys out / hides the field in the browser |
| Enforcement | `PluginFieldguardLock::filterInput()` via `pre_item_add` / `pre_item_update` | Drops the locked keys from the input before GLPI writes them |

Who a lock applies to is decided by its rule, never by the lock itself, so the
same set of fields can serve several audiences without being duplicated.

**The CSS layer is cosmetic and can be undone from the browser console in a few
seconds.** It exists so users see what they may not change. The PHP layer is what
actually protects the data: even a request crafted by hand, with no browser form
involved, has its locked fields stripped before the write. Do not treat the
greyed-out field as the security boundary.

Verified end to end: a crafted `POST` to `front/ticket.form.php` setting a locked
`urgency=5` stores the default `3`, while an unlocked `impact=5` is kept.

### Who a rule applies to

A rule carries two independent lists, profiles and groups:

| Profiles | Groups | Applies to |
| --- | --- | --- |
| empty | empty | everyone |
| Technician | empty | everyone holding the Technician profile |
| empty | Support N1 | every member of Support N1 |
| Technician | Support N1 | someone holding Technician **and** in Support N1 |

Within a list the entries are alternatives; the two lists are combined with AND.
Every active rule matching the session contributes its locks, and a field locked
by more than one keeps the strictest mode — `hidden` beats `readonly`, since a
hidden field is unwritable too.

Group membership is read from `$_SESSION['glpigroups']`, which GLPI fills at
login: a user added to a group has to sign in again for a group-scoped rule to
start applying to them.

### Where the locks come from

Locks matching the session are serialised into a
`<meta name="fieldguard:locks">` tag in `<head>` via the `add_header_tag` hook,
rather than fetched over AJAX. Fields are therefore already greyed out on first
paint, with no flash of an editable form and no extra request per page.

## Configuration

**Setup → Plugins → Field Guard**, or `front/config.form.php`.

The list page holds the rules; clicking one opens its editor.

1. Create a **rule** and give it a name.
2. In the editor, pick the **profiles** and **groups** it applies to, then save
   that half of the form. Leave a list empty to mean "any".
3. Pick a **lock mode**: `Read-only (greyed out)` or `Hidden`.
4. Pick a **tab** (Assistance, Assets, Management, Tools, Administration) and an
   **itemtype**. The real GLPI form loads in the preview below.
5. Optionally fill **Item ID**. Empty previews the creation form; filling it
   previews that saved item instead.
6. **Click any field** to toggle its lock, then press **Save locks**.

Picks are held in the browser until you save, for the whole rule at once: moving
between itemtypes keeps them, and each pill shows how many fields that itemtype
has locked. Saving writes the rule's complete set, which is also what makes a
removal expressible — leaving the page with unsaved picks warns first.

In the preview, a field is:

- **solid grey** — locked, read-only
- **pink dashed outline** — locked, hidden
- **blue dashed outline** — not locked
- **purple dotted outline** — belongs to a different itemtype than the selected
  one (see *Forms inside forms* below); still clickable, and saved under its own
  itemtype

Hovering shows the field's HTML `name`, which is the key the lock is stored
under, prefixed with the itemtype when it differs from the selected one.

### Item ID

Many fields do not exist on a creation form. A ticket has no `status`, no SLA
and no approvals until it is saved; an asset has no attached-items tabs. Enter
an existing item's ID to load that item's form and reach those fields. The lock
is still stored per itemtype, not per item — the ID only decides which form
gets rendered for you to click on.

### Sub-tabs: navigate, do not enumerate

A ticket is not only its own form: costs, linked items, contracts, projects and
knowledge base entries each live in a tab of their own, and problems and changes
have their equivalents — plus whatever another plugin adds.

Those get **no pill**. The preview keeps GLPI's own tab rail on the left, so the
admin walks to a tab exactly as they would in the real interface, and the picker
marks whatever turns up. A pill list could never be complete, and one button per
tab would fill the screen long before it was.

The pills are therefore only the entry points: an ITIL object and the sub-items
its own form renders (followup, task, solution, approval).

The preview URL always names the tab to start on (`<Itemtype>$main`), because
GLPI stores the last tab opened per itemtype in the session — and navigating the
rail is exactly what changes it.

Costs and approvals keep their form behind a button: the tab renders a table
plus *Add a new cost* / *Send an approval request*, whose handler ajax-loads the
real form. The picker calls GLPI's own handler once per button, so the fields
are on screen without depending on a click reaching it.

**What is lockable is wider than what is shown.** `getManagedItemtypes()` is the
list a field's itemtype must be in to be clickable, and it covers the core tabs
above. Forms a plugin renders are handled by a resolver instead — see below.

### Forms inside forms

Some itemtypes have no form page of their own. `front/itilfollowup.form.php`
and `front/itilsolution.form.php` are POST-only action handlers: they answer 400
and 500 to a GET and never render anything. Their fields live in the parent ITIL
object's timeline instead.

Those pills are marked `↳` and preview through their host, which is why they
require an Item ID. Open a saved ticket and the timeline renders, in one page,
the forms for `Ticket`, `ITILFollowup`, `TicketTask`, `ITILSolution`,
`TicketValidation` and `Document`; a problem hosts `ProblemTask`, a change hosts
`ChangeTask` and `ChangeValidation`.

Selecting one of those pills switches the preview to **isolate mode**: GLPI
keeps each child form in a collapsed `#new-<Itemtype>-block`, so the picker
expands that one and hides the timeline, the parent's own form and the other
children. You get just the form you are configuring. These compact timeline
forms label their fields with icons rather than text, so in isolate mode the
field-name badges stay on instead of only appearing on hover.

This is also why **every field is attributed to the form that owns it**, not to
the selected pill. Four of those forms have a `content` field; matching on the
field name alone would collapse a followup's body onto the ticket's description.
Both the picker and the runtime resolve the itemtype from each form's `action`
attribute, and only fall back to the page URL for a form that declares no action
at all.

### Custom fields

A field's itemtype is normally read off its form's `action`, because GLPI posts
an item to `front/<itemtype>.form.php`. Plugins are free to break that. The
Additional Fields plugin posts **every** custom field tab to a single
`container.form.php`, and the class the values land in is generated per
container (`PluginFields<host><container>`), so nothing in the URL names it.

`PluginFieldguardLock::getFormResolvers()` closes that: a resolver says *read
these hidden inputs, join them with a pipe, look the result up here*. For the
Additional Fields plugin the key is the host itemtype plus the container id,
built from `glpi_plugin_fields_containers`:

```php
['keys' => ['itemtype', 'plugin_fields_containers_id'],
 'map'  => ['Ticket|1' => 'PluginFieldsTicketteste']]
```

Both layers use it — the picker gets it in its config payload, the runtime in a
`fieldguard:resolvers` meta tag limited to the itemtypes the session holds locks
on. Supporting another plugin is one more entry in that PHP method, with nothing
to change in either JavaScript file.

Those generated classes are ordinary `CommonDBTM`s whose `add()`/`update()` run
through GLPI, so `pre_item_add` / `pre_item_update` enforce their locks exactly
as they do a core field's. `isLockableItemtype()` is what lets a lock be stored
for one, since it is in no hand-written list.

The resolver is built without calling into the other plugin: at plugin-init time
another plugin's autoloader is not necessarily registered yet, so the class name
falls back to the documented naming rule and the container's own table is what
proves the class is real.

## Data model

| Table | Holds |
| --- | --- |
| `glpi_plugin_fieldguard_rules` | name, active flag, comment |
| `glpi_plugin_fieldguard_ruleprofiles` | the profiles a rule is restricted to (none = any) |
| `glpi_plugin_fieldguard_rulegroups` | the groups a rule is restricted to (none = any) |
| `glpi_plugin_fieldguard_locks` | one row per (rule, itemtype, field), unique on that triplet |

`field` holds the input's HTML `name`, normalised so that `foo[]`, `foo[0]` and
`foo` collapse onto the same key.

Before 1.3.0 a lock hung off a profile directly. Installing over such a database
turns every profile that held locks into one rule targeting that profile, so
existing locks keep applying exactly as they did; `profiles_id` is then dropped
from the lock table.

A short list of keys is never filtered whatever the configuration says
(`PluginFieldguardLock::NEVER_FILTERED`): `id`, `_glpi_csrf_token`, `entities_id`,
`is_recursive`. These are GLPI plumbing, and dropping them would break the write
rather than protect it. Other underscore-prefixed keys *are* filtered, because
real form fields such as `_actors` live in that namespace and are exactly what
administrators want to lock. The same list is mirrored in both JS files; change
all three together.

## Supporting both GLPI 10 and 11

| What differs | GLPI 10.0 | GLPI 11.0 |
| --- | --- | --- |
| Static assets | URL maps straight onto the file, so the path must include `public/` | `/plugins/<key>/css/x.css` resolves to `<plugin>/public/css/x.css` |
| Bootstrapping | The script must `include('../../../inc/includes.php')` itself | The kernel boots first; including it again is an error |
| CSRF on writes | Left to the script, and the token is **consumed** on use | Checked by a kernel listener, reading `X-Glpi-Csrf-Token` on XHR |
| `htmlescape()` | Does not exist | Available globally |
| `DBmysql::doQuery()` | Added partway through the 10.0 series | Present; `query()` deprecated |
| Plugin web path | `Plugin::getWebDir()` — may be `plugins/` or `marketplace/` | same |
| `$_SERVER['PHP_SELF']` | The plugin script itself | `/index.php`, the Symfony front controller |

**Never use `$_SERVER['PHP_SELF']` as a form action.** On GLPI 11 it points at
the front controller, so the browser posts to a path info of `/` — and GLPI
routes every POST to `/` to the inventory agent endpoint, which answers
*"Inventory is disabled"* instead of reaching the plugin. Build the URL with
`PluginFieldguardCompat::scriptUrl('front/x.php')`, which is correct on both.

Assets are stored once, under `public/`, and only the registered path differs.
Entry points under `front/` and `ajax/` start with:

```php
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}
```

Because GLPI 10 burns a CSRF token on every use, `ajax/savelocks.php` returns a
fresh token with each response and the picker sends it with the next save. The token goes in both the request body
(GLPI 10 reads `$_POST`) and the `X-Glpi-Csrf-Token` header (GLPI 11 reads the
header on XHR). Verified on GLPI 10 that a bogus token is rejected and the
write does not happen.

`add_header_tag` — how the locks reach the page — exists in both, so there is
no AJAX fallback to maintain.

The code avoids PHP 8-only syntax (`match`, `str_starts_with`, …) because GLPI
10.0 still supports PHP 7.4, even though its Docker images ship PHP 8.

### Do not use Bootstrap's `.small` on a GLPI page

GLPI 10's palette stylesheets ship:

```css
#page .small, .qtip .small, .modal .modal-body .small { width: 1%; }
```

Any element carrying `.small` inside `#page` therefore collapses to a few
pixels wide — a hint line rendered at 7px instead of 489px. The config screen
uses its own `.fieldguard-hint` / `.fieldguard-status` classes instead. GLPI 11
does not have this rule, so the symptom only shows on 10.

Worth keeping in mind before reaching for any other Bootstrap utility class:
GLPI's palettes override some of them in ways Bootstrap does not imply.

## Implementation notes

Things GLPI 11 does differently from GLPI 10, all of which this plugin handles:

- **Static assets must live under `public/`.** A request for
  `/plugins/fieldguard/css/picker.css` resolves to
  `<plugin>/public/css/picker.css`. Files outside `public/` are not served.
  GLPI 10 maps the URL straight onto the file instead.
- **No `include('../../../inc/includes.php')`.** The kernel boots before the
  legacy file is required. GLPI 10 does need it.
- **Legacy scripts are `require`d inside a controller method**, so globals are in
  function scope: `global $CFG_GLPI;` is mandatory in `front/` and `ajax/` files
  that use them.
- **CSRF is checked by the kernel**, not by the script. XHR requests must send
  the token in the `X-Glpi-Csrf-Token` header; plain POSTs send
  `_glpi_csrf_token` in the body.
- **Item hooks dispatch by exact class name** — `Plugin::doHook()` does an
  `isset($tab[$itemtype])`, so there is no wildcard. Every managed itemtype is
  registered explicitly in `plugin_init_fieldguard()`.
- **Assets are cached for a month** and `Html::script()`/`Html::css()` default to
  GLPI's *core* version string, which does not change when the plugin does. The
  config page stamps each asset with its own `filemtime` instead.

The preview hides GLPI's chrome — the nav sidebar, the page header, the left
tab rail (`#tabspanel`) and the form's action buttons — so only the fields are
left to click. `.card-footer` is deliberately *not* hidden: GLPI puts that class
on `.itil-right-side`, the panel holding a ticket's status, urgency, priority
and SLA fields, which are among the most useful things to lock.

Clicks are only acted on when `event.isTrusted` is true. GLPI, Bootstrap,
select2 and TinyMCE all dispatch synthetic clicks while a form settles, and
treating one of those as a pick would silently create a lock nobody asked for.

The picker never modifies the target form on disk. It loads the form in a
same-origin iframe with `_fieldguard_pick=1`, and everything the administrator
sees is added to the iframe document at runtime from the parent page. That query
parameter also tells `fieldguard.js` to skip enforcement, so the preview always
shows the form unlocked.

GLPI renders timelines, tabs and accordions after `load`, so the picker rescans
on a debounced `MutationObserver` rather than once.

## Translations

Gettext catalogs live in `locales/`, under the `fieldguard` domain:

| File | What it is |
| --- | --- |
| `fieldguard.pot` | Template, regenerated from the source — never translated directly |
| `pt_BR.po` | Brazilian Portuguese, the editable catalog |
| `pt_BR.mo` | Compiled; **this is the file GLPI loads** |

GLPI resolves the catalog through `Plugin::loadLang()`, which looks for
`locales/<lang>.mo` using the filename in `$CFG_GLPI['languages']`, falling
back to `en_GB.mo` and then to the untranslated msgid. English therefore needs
no catalog of its own.

To regenerate after changing or adding strings:

```bash
./tools/update-locales.sh
```

It extracts with `xgettext`, merges into every existing `.po` with `msgmerge`
(keeping current translations and flagging changed ones as fuzzy), and compiles
the `.mo` files. Adding a language is one command plus a translation pass:

```bash
msginit --input=locales/fieldguard.pot --locale=es_ES --output=locales/es_ES.po
./tools/update-locales.sh
```

### What is deliberately not translated

- **Tab group labels** (`Assistance`, `Assets`, `Management`, `Tools`,
  `Administration`) are called as `__('Assets')` with **no domain**, so they
  resolve against GLPI's own catalog and are already translated in every
  language GLPI ships. `update-locales.sh` filters them out of the template;
  the `CORE_STRINGS` pattern there must stay in sync with
  `PluginFieldguardLock::getItemtypeGroups()`.
- **Itemtype names on the pills** (Ticket, Change, …) come from
  `$itemtype::getTypeName()`, i.e. from core.
- **The plugin name in `setup.php`.** GLPI writes it into `glpi_plugins.name`
  at install time, so a translated value would freeze whatever language the
  installing administrator happened to be using and show it to everyone. The
  configuration page translates its own title instead, per request.

Strings shown by JavaScript are passed from PHP in the `i18n` block of the
config payload, so there is nothing to extract from the `.js` files — and
nothing that can drift out of the catalog.

## Caveats

- **Do not write a rule that matches the session you administer with.** Locks
  apply to whoever the rule matches, including you.
- Self-Service users in GLPI 11 are routed to the Service Catalog, which is built
  on the new form engine rather than `ticket.form.php`. Locks on `Ticket` apply
  to the standard interface; they do not rewrite service-catalog forms.
- `Unmanaged` is not offered: its form answers 403 even to a super-admin, so it
  can never be previewed. Any other form GLPI refuses to render reports the
  error in the toolbar rather than leaving a blank frame.
- GLPI renders a checkbox as a hidden `0` plus a checkbox `1`. Locking one
  disables the checkbox, so the browser would submit the hidden `0` — the
  server-side filter drops the key entirely, which is what keeps the stored
  value unchanged. Another reason not to rely on the CSS layer alone.
- Saving the rule's profiles and groups reloads the page, which discards any
  lock picks not yet saved. The browser warns before leaving; save the locks
  first.
- Group membership is cached in the session at login, so a user added to a group
  keeps their old locks until they sign in again.
- Locking a field only prevents *writes* to it. It does not hide the value from
  the item's history, search results or the API.
- Navigating the preview's tab rail sets GLPI's remembered active tab for that
  itemtype, the same way its own links do, so the next ticket you open lands on
  the tab you last looked at in the picker.
- A tab whose itemtype is neither in `getManagedItemtypes()` nor named by a
  resolver shows its fields but cannot be picked. Adding either is a one-line
  change; there is no UI for it.
- `Notepad` is shared by every itemtype that has notes, so a lock on it is not
  limited to the object it was picked from.

## Development

The GLPI version comes from `GLPI_VERSION` in `.env` (`.env.example` pins
11.0.8). To test the other branch, point it at `10` and recreate the stack, or
bring up a second project on another port:

```bash
GLPI_VERSION=11.0.8 docker compose -p glpi11 up -d --build   # + a ports override
```

Note that `plugin:install` takes `-u <user>` on GLPI 10 and `plugin:enable`
differs slightly between branches.

```bash
make up                              # build and start GLPI + MySQL
make install plugin=fieldguard        # install and enable
make update-files plugin=fieldguard   # push local changes into the container
make plugin-reset plugin=fieldguard   # uninstall, re-copy, reinstall
```

Bumping `PLUGIN_FIELDGUARD_VERSION` makes GLPI deactivate the plugin on next
boot until its update runs, so after a version bump re-run `plugin:install`
followed by `plugin:enable` (`plugin:activate` on GLPI 10).

`make update-files` copies the files but the browser may still hold a cached
asset; the config page busts its own cache by mtime, while the globally injected
`fieldguard.js` / `fieldguard.css` are stamped with `PLUGIN_FIELDGUARD_VERSION`, so
bump it when releasing.
