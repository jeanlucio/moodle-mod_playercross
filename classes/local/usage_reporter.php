<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Anonymous usage reporter.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playercross\local;

/**
 * Builds and sends the anonymous usage report.
 *
 * Same pattern as block_playerhud's own usage_reporter (a sibling Player plugin that already
 * has this feature reviewed and running), reporting to the same telemetry service.
 *
 * @package    mod_playercross
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_reporter {
    /** @var string Config key for the timestamp of the last successful send. */
    const CONFIG_LAST_SENT = 'usagereportlast';

    /** @var int Minimum seconds between sends, matching the sibling Player plugins' cadence. */
    const REPORT_INTERVAL = 21 * DAYSECS;

    /** @var string The remote endpoint that receives the report. */
    const REPORT_URL = 'https://plugintelemetry.duckdns.org/report.php';

    /**
     * Whether a report is due right now.
     *
     * @return bool
     */
    public static function is_due(): bool {
        if (!get_config('mod_playercross', 'usagereport')) {
            return false;
        }
        $last = (int) get_config('mod_playercross', self::CONFIG_LAST_SENT);
        return (time() - $last) > self::REPORT_INTERVAL;
    }

    /**
     * Build the report payload.
     *
     * @return array
     */
    public static function build_payload(): array {
        global $CFG, $DB;

        $pluginman = \core_plugin_manager::instance();
        $plugininfo = $pluginman->get_plugin_info('mod_playercross');
        $totalusers = $DB->count_records_select('user', 'deleted = 0 AND id != ?', [$CFG->siteguest]);
        $flavour = self::get_flavour();

        return [
            'plugin' => 'mod_playercross',
            'siteidentifier' => get_site_identifier(),
            'url' => $CFG->wwwroot,
            'moodle_version' => $CFG->version,
            'moodle_release' => $CFG->release,
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            'plugin_version' => $plugininfo ? $plugininfo->versiondisk : null,
            'plugin_release' => $plugininfo ? $plugininfo->release : null,
            // Moodle's own country select stores '0' (not an empty string) for "not set" —
            // treat it the same as unset, or the dashboard would group these sites under a
            // literal "0" bucket instead of alongside the other sites with no country.
            'country' => !empty($CFG->country) && $CFG->country !== '0' ? $CFG->country : null,
            'lang' => $CFG->lang,
            'active_users' => self::get_active_users(),
            'course_count' => self::get_course_count(),
            'site_size_bucket' => self::size_bucket((int) $totalusers),
            'moodle_flavour' => $flavour,
            'moodle_flavour_version' => self::get_flavour_version($flavour),
            'companion_plugins' => self::get_companion_plugins($pluginman),
            'error_counters' => diagnostics::get_all(),
        ];
    }

    /**
     * Students who submitted an attempt in the last 90 days (~4 report cycles) — a proxy for
     * "engaged with the activity recently", distinct from course_count (deployment breadth).
     *
     * @return int
     */
    protected static function get_active_users(): int {
        global $DB;
        $activewindow = time() - (90 * DAYSECS);
        return (int) $DB->count_records_select(
            'playercross_attempts',
            'timecreated > ?',
            [$activewindow],
            'COUNT(DISTINCT userid)'
        );
    }

    /**
     * How many course instances of this activity exist across the whole site.
     *
     * Unlike block_playerhud (a block, needing a JOIN through block_instances/context), an
     * activity module's own instance table already has exactly one row per course placement,
     * so no join is needed here.
     *
     * @return int
     */
    protected static function get_course_count(): int {
        global $DB;
        return (int) $DB->count_records('playercross');
    }

    /**
     * Detect whether this site runs a Moodle fork rather than vanilla Moodle.
     *
     * Same detection block_xp and block_playerhud already use — a plugin bug that only
     * reproduces on Totara/IOMAD/Workplace is otherwise invisible in the aggregate report.
     *
     * @return string|null
     */
    protected static function get_flavour(): ?string {
        if (array_key_exists('totara', \core_component::get_plugin_types())) {
            return 'totara';
        } else if (array_key_exists('iomad', \core_component::get_plugin_list('local'))) {
            return 'iomad';
        } else if (array_key_exists('workplace', \core_component::get_plugin_list('theme'))) {
            return 'workplace';
        }
        return null;
    }

    /**
     * Get the flavour's own version number, when it exposes one.
     *
     * @param string|null $flavour
     * @return string|int|float|null
     */
    protected static function get_flavour_version(?string $flavour) {
        global $CFG;
        if ($flavour !== 'totara') {
            return null;
        }

        // @codingStandardsIgnoreStart
        $TOTARA = new \stdClass();
        include($CFG->dirroot . '/version.php');
        return isset($TOTARA->version) ? $TOTARA->version : null;
        // @codingStandardsIgnoreEnd
    }

    /**
     * Presence and version of this plugin's real sibling dependencies — the AI Hub (optional
     * BYOK backend for word/clue generation) and PlayerHUD (optional economy integration,
     * gated by hud_service::is_installed()). Neither is exposed in a "Companion Plugins" admin
     * section here, unlike block_playerhud, but both are genuine, checkable dependencies.
     *
     * @param \core_plugin_manager $pluginman
     * @return array<string,array{r:string,v:string}>
     */
    protected static function get_companion_plugins(\core_plugin_manager $pluginman): array {
        $components = ['local_aihub', 'block_playerhud'];
        $companions = [];
        foreach ($components as $component) {
            $info = $pluginman->get_plugin_info($component);
            if ($info) {
                $companions[$component] = ['r' => (string) $info->release, 'v' => (string) $info->versiondisk];
            }
        }
        return $companions;
    }

    /**
     * Send the report, if due.
     *
     * Clears error counters only after a confirmed successful send (2xx received), so a
     * failed POST never silently loses counts that were meant to be reported.
     *
     * @return bool Whether a report was sent successfully.
     */
    public static function send(): bool {
        if (!self::is_due()) {
            return false;
        }

        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $payload = self::build_payload();

        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json']);
        $curl->setopt(['CURLOPT_TIMEOUT' => 5, 'CURLOPT_CONNECTTIMEOUT' => 5]);
        $curl->post(self::REPORT_URL, json_encode($payload));

        if ($curl->get_errno()) {
            return false;
        }

        $info = $curl->get_info();
        $code = isset($info['http_code']) ? (int) $info['http_code'] : 0;
        if ($code !== 200) {
            return false;
        }

        set_config(self::CONFIG_LAST_SENT, time(), 'mod_playercross');
        diagnostics::clear();

        return true;
    }

    /**
     * Bucket the site's total user count.
     *
     * @param int $totalusers
     * @return string
     */
    protected static function size_bucket(int $totalusers): string {
        if ($totalusers < 50) {
            return '<50';
        } else if ($totalusers < 500) {
            return '50-500';
        } else if ($totalusers < 5000) {
            return '500-5000';
        }
        return '>5000';
    }
}
