# 🔐 Security & Compliance

* Capability-based access control (`mod/playercross:view`, `mod/playercross:addinstance`)
* `require_sesskey()` protection on all POST actions; AJAX calls are validated by Moodle's `core/ajax` dispatcher
* Server-side enforcement of round limits and cooldown, always recomputed from current settings
* Round timeout is re-validated against the server's own deadline instead of trusting the client's countdown alone
* Guess charset validation — only Unicode letters accepted
* AI-generated words are treated as untrusted input: only single-token, alphabetic terms within the configured length bounds are saved, and they enter pending teacher approval
* Session round state is isolated per activity instance and per user — a word id or session key from one activity is never accepted by another
* A wrong clue guess or a wrong mystery-phrase guess never leaks the correct word; the theme word is only ever revealed once the round has actually finished
* Moodle External API compliant
* Privacy API fully implemented (GDPR/LGPD)

## 🔒 Third-party Service Disclosure

AI word generation is **optional** and disabled by default. When a teacher uses it, the
activity topic (never student data or attempt records) is sent through `local_aihub` — using
that user's or the site's own BYOK key, if the plugin is installed — or, as a fallback,
through Moodle's own core AI subsystem (`core_ai`), which routes to whatever provider the
site administrator has configured. PlayerCross never contacts an AI provider directly; the
request and its disclosure/consent are entirely owned by `local_aihub` or by `core_ai`. If
neither is installed or configured, the AI word source is unavailable and every other feature
keeps working normally.
