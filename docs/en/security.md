# 🔐 Security & Compliance

* Capability-based access control (`mod/playercross:view`, `mod/playercross:addinstance`, `mod/playercross:managewords`, `mod/playercross:viewreports`)
* `require_sesskey()` protection on all POST actions; AJAX calls are validated by Moodle's `core/ajax` dispatcher
* Server-side enforcement of round limits and cooldown, always recomputed from current settings
* Round timeout is re-validated against the server's own deadline instead of trusting the client's countdown alone
* Guess charset validation — only Unicode letters accepted
* AI-generated words are treated as untrusted input: only single-token, alphabetic terms within the configured length bounds are saved, and they enter pending teacher approval
* Session round state is isolated per activity instance and per user — a word id or session key from one activity is never accepted by another
* A wrong term guess or a wrong mystery-phrase guess never leaks the correct word; the theme word is only ever revealed once the round has actually finished
* Moodle External API compliant
* Privacy API fully implemented (GDPR/LGPD)

## 🔒 Third-party Service Disclosure

AI word generation is **optional** and disabled by default. When a teacher uses it, the
activity topic (never student data or attempt records) is sent through [AI Hub](#aihub)
(`local_aihub`) — using that user's or the site's own BYOK key, if the plugin is installed — or,
as a fallback, through Moodle's own core AI subsystem (`core_ai`), which routes to whatever
provider the site administrator has configured. PlayerCross never contacts an AI provider
directly; the request and its disclosure/consent are entirely owned by `local_aihub` or by
`core_ai`. If neither is installed or configured, the AI word source is unavailable and every
other feature keeps working normally.

* **Cost:** None required by PlayerCross itself. If used, any cost is whatever the underlying
  provider charges through a `local_aihub` BYOK key, or nothing at all via a free/institutional
  `core_ai` provider the site admin may have already configured.
* **API keys / credentials:** Not configured in PlayerCross. Obtain and configure a personal or
  site key inside [AI Hub](#aihub) (`local_aihub`), or ask the site administrator to
  configure a `core_ai` provider instead.
* **Demo credentials:** Not applicable — no credentials are required to install or use
  PlayerCross; AI generation is entirely opt-in.

## 📊 Anonymous Usage Statistics

PlayerCross periodically sends an anonymous, aggregate usage report to the developer's own
telemetry service, to help prioritise fixes and improvements. This is a separate mechanism
from the AI feature above and does not require it to be configured.

### Is it required?

No. It is **on by default** but fully opt-out: disable it at any time from
**Site administration > Plugins > Activity modules > PlayerCross** ("Send anonymous usage
statistics"). Nothing about the activity's own features changes if it is disabled.

### What is sent

A single JSON payload, at most once every 21 days:

- **Site info:** Moodle version/release, PHP version, site country and language, whether the
  site runs a non-vanilla Moodle fork (Totara/IOMAD/Workplace) and its version.
- **Plugin usage:** PlayerCross' own installed version/release, an approximate active-student
  count (students with a round attempt in the last 90 days), how many course instances of the
  activity exist across the site, and whether the companion plugins ([AI Hub](#aihub) and
  PlayerHUD) are installed and their versions.
- **Internal error counters:** aggregate counts of two known-fragile code paths in AI term
  generation — every provider attempt failing, or the AI answering with fewer usable terms than
  requested (including every candidate failing the activity's own clue-validity check) — never
  the error content itself, only how many times each happened.
- **Site identifier:** Moodle's own anonymous site identifier (`get_site_identifier()`) and
  the site's URL, so repeated reports from the same site can be recognised as one deployment
  over time rather than counted as new installs.

### What is never sent

No student data, no personal data of any user (name, email, ID), no word/clue pool content, no
AI prompts or responses, and no data from courses that do not have the activity added.

### Where reports are sent

Reports are sent to `https://plugintelemetry.duckdns.org/report.php`, a service operated by
the plugin's own developer — not a third-party analytics vendor.

Declared in the plugin's own Privacy Provider (`classes/privacy/provider.php`) as an external
location, per standard Moodle Privacy API practice for any plugin that transmits data off-site.
