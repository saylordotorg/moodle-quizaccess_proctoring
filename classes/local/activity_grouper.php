<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace quizaccess_proctoring\local;

/**
 * Groups raw suspicious-activity events into reviewer-friendly episodes.
 *
 * The attempt page logs low-level browser telemetry (focus/blur, tab visibility,
 * clipboard, captures). Reviewers think in terms of "the student left the exam
 * N times"; this class folds the raw stream into away-from-exam episodes, keeps
 * standalone flagged events as their own entries, and separates routine noise
 * (mouse wiggles at the window edge) so the report can collapse it.
 *
 * Pure data transformation: no database access, no output formatting.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_grouper {
    /** @var string[] Event types that start an away-from-exam gap. */
    public const AWAY_START = ['focus_lost', 'tab_hidden', 'page_exit'];

    /** @var string[] Event types that end an away-from-exam gap. */
    public const AWAY_END = ['focus_returned', 'tab_visible'];

    /** @var string[] Informational noise kept out of the episode list. */
    public const ROUTINE = ['mouse_left_window', 'mouse_returned_window', 'monitor_detection_unavailable'];

    /** @var string[] Events that attach to a just-closed gap (e.g. pasting right after returning). */
    public const RETURN_FOLLOWUP = ['clipboard_paste', 'clipboard_copy', 'clipboard_cut', 'contextmenu'];

    /** @var int Seconds after a return during which follow-up events still belong to the gap. */
    public const RETURN_GRACE = 10;

    /**
     * Groups raw event records into episodes and a routine bucket.
     *
     * Each event record needs id, eventtype and timemodified properties; other
     * properties (screenshoturl, eventdetail, ...) ride along untouched.
     *
     * @param \stdClass[] $events Raw event records in any order.
     * @return array{episodes: array[], routine: \stdClass[], awaycount: int,
     *     awayseconds: int, capturecount: int, rawcount: int} Grouped result. Each
     *     episode has type ('away'|'signal'), start, end (0 while unclosed),
     *     duration (null when the student never returned), events, captures and
     *     hascapture keys.
     */
    public static function group(array $events): array {
        $sorted = array_values($events);
        usort($sorted, static function (\stdClass $a, \stdClass $b): int {
            return [(int)$a->timemodified, (int)$a->id] <=> [(int)$b->timemodified, (int)$b->id];
        });

        $episodes = [];
        $routine = [];
        $open = null;
        foreach ($sorted as $event) {
            $type = (string)$event->eventtype;
            $time = (int)$event->timemodified;
            if (in_array($type, self::ROUTINE, true)) {
                $routine[] = $event;
                continue;
            }

            if ($open !== null) {
                $episodes[$open]['events'][] = $event;
                if (in_array($type, self::AWAY_END, true)) {
                    $episodes[$open]['end'] = $time;
                    $open = null;
                }
                continue;
            }

            if (in_array($type, self::AWAY_START, true)) {
                $episodes[] = ['type' => 'away', 'start' => $time, 'end' => 0, 'events' => [$event]];
                $open = count($episodes) - 1;
                continue;
            }

            // The most recent away gap keeps collecting echoes (a second visibility
            // event, a paste right after returning) for a short grace period.
            $last = count($episodes) - 1;
            $withingrace = $last >= 0 && $episodes[$last]['type'] === 'away' && $episodes[$last]['end'] > 0 &&
                ($time - (int)$episodes[$last]['end']) <= self::RETURN_GRACE;

            if (in_array($type, self::AWAY_END, true)) {
                if ($withingrace) {
                    $episodes[$last]['events'][] = $event;
                } else {
                    $routine[] = $event;
                }
                continue;
            }

            if ($withingrace && in_array($type, self::RETURN_FOLLOWUP, true)) {
                $episodes[$last]['events'][] = $event;
                continue;
            }

            $episodes[] = ['type' => 'signal', 'start' => $time, 'end' => $time, 'events' => [$event]];
        }

        $awaycount = 0;
        $awayseconds = 0;
        $capturecount = 0;
        $rawcount = 0;
        foreach ($episodes as &$episode) {
            $episode['duration'] = ($episode['type'] === 'away')
                ? ($episode['end'] > 0 ? max(0, $episode['end'] - $episode['start']) : null)
                : 0;
            $episode['captures'] = array_values(array_filter($episode['events'], static function (\stdClass $event): bool {
                return !empty($event->screenshoturl);
            }));
            $episode['hascapture'] = !empty($episode['captures']);
            if ($episode['type'] === 'away') {
                $awaycount++;
                $awayseconds += (int)($episode['duration'] ?? 0);
            }
            if ($episode['hascapture']) {
                $capturecount++;
            }
            $rawcount += count($episode['events']);
        }
        unset($episode);

        return [
            'episodes' => $episodes,
            'routine' => $routine,
            'awaycount' => $awaycount,
            'awayseconds' => $awayseconds,
            'capturecount' => $capturecount,
            'rawcount' => $rawcount,
        ];
    }
}
