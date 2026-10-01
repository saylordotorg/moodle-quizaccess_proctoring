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

/**
 * Privacy for the quizaccess_proctoring plugin.
 *
 * @package    quizaccess_proctoring
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\privacy;

use coding_exception;
use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;
use dml_exception;


/**
 * Implements privacy API for the quizaccess_proctoring plugin.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    core_userlist_provider {
    /**
     * Provides metadata about the user data stored by quizaccess_proctoring.
     *
     * @param collection $collection The metadata collection object.
     * @return collection The updated metadata collection.
     */
    public static function get_metadata(collection $collection): collection {
        $quizaccessproctoringlogs = [
            'capturedat' => 'privacy:metadata:capturedat',
            'requestid' => 'privacy:metadata:requestid',
            'courseid' => 'privacy:metadata:courseid',
            'quizid' => 'privacy:metadata:quizid',
            'userid' => 'privacy:metadata:userid',
            'webcampicture' => 'privacy:metadata:webcampicture',
            'status' => 'privacy:metadata:status',
            'timemodified' => 'timemodified',
        ];

        $collection->add_database_table(
            'quizaccess_proctoring_logs',
            $quizaccessproctoringlogs,
            'privacy:metadata:quizaccess_proctoring_logs'
        );
        $collection->add_database_table('quizaccess_proctoring_fm_warnings', [
            'courseid' => 'privacy:metadata:courseid',
            'quizid' => 'privacy:metadata:quizid',
            'userid' => 'privacy:metadata:userid',
            'reportid' => 'privacy:metadata:reportid',
        ], 'privacy:metadata:quizaccess_proctoring_logs');
        $collection->add_database_table('quizaccess_proctoring_facematch_task', [
            'reportid' => 'privacy:metadata:reportid',
            'refimageurl' => 'privacy:metadata:webcampicture',
            'targetimageurl' => 'privacy:metadata:webcampicture',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:quizaccess_proctoring_logs');

        $quizaccessproctoringevents = [
            'capturedat' => 'privacy:metadata:capturedat',
            'requestid' => 'privacy:metadata:requestid',
            'courseid' => 'privacy:metadata:courseid',
            'quizid' => 'privacy:metadata:quizid',
            'userid' => 'privacy:metadata:userid',
            'attemptid' => 'privacy:metadata:attemptid',
            'reportid' => 'privacy:metadata:reportid',
            'eventtype' => 'privacy:metadata:eventtype',
            'eventdetail' => 'privacy:metadata:eventdetail',
            'pagevisibility' => 'privacy:metadata:pagevisibility',
            'currenturl' => 'privacy:metadata:currenturl',
            'screenshoturl' => 'privacy:metadata:screenshoturl',
            'timemodified' => 'timemodified',
        ];

        $collection->add_database_table(
            'quizaccess_proctoring_events',
            $quizaccessproctoringevents,
            'privacy:metadata:quizaccess_proctoring_events'
        );

        $quizaccessproctoringriskholds = [
            'courseid' => 'privacy:metadata:courseid',
            'quizid' => 'privacy:metadata:quizid',
            'userid' => 'privacy:metadata:userid',
            'attemptid' => 'privacy:metadata:attemptid',
            'reportid' => 'privacy:metadata:reportid',
            'riskscore' => 'privacy:metadata:riskscore',
            'threshold' => 'privacy:metadata:riskthreshold',
            'originalgrade' => 'privacy:metadata:originalgrade',
            'status' => 'privacy:metadata:status',
            'reviewerid' => 'privacy:metadata:reviewerid',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'timemodified',
            'timereviewed' => 'privacy:metadata:timereviewed',
        ];

        $collection->add_database_table(
            'quizaccess_proctoring_risk_holds',
            $quizaccessproctoringriskholds,
            'privacy:metadata:quizaccess_proctoring_risk_holds'
        );

        $quizaccessproctoringfindingreviews = [
            'courseid' => 'privacy:metadata:courseid',
            'quizid' => 'privacy:metadata:quizid',
            'userid' => 'privacy:metadata:userid',
            'attemptid' => 'privacy:metadata:attemptid',
            'reportid' => 'privacy:metadata:reportid',
            'factorkey' => 'privacy:metadata:factorkey',
            'verdict' => 'privacy:metadata:findingverdict',
            'note' => 'privacy:metadata:findingnote',
            'reviewerid' => 'privacy:metadata:reviewerid',
            'timecreated' => 'privacy:metadata:timecreated',
        ];

        $collection->add_database_table(
            'quizaccess_proctoring_finding_reviews',
            $quizaccessproctoringfindingreviews,
            'privacy:metadata:quizaccess_proctoring_finding_reviews'
        );

        $quizaccessproctoringnotes = [
            'courseid' => 'privacy:metadata:courseid',
            'quizid' => 'privacy:metadata:quizid',
            'userid' => 'privacy:metadata:userid',
            'attemptid' => 'privacy:metadata:attemptid',
            'reportid' => 'privacy:metadata:reportid',
            'notetext' => 'privacy:metadata:notetext',
            'authorid' => 'privacy:metadata:noteauthorid',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ];

        $collection->add_database_table(
            'quizaccess_proctoring_notes',
            $quizaccessproctoringnotes,
            'privacy:metadata:quizaccess_proctoring_notes'
        );

        $quizaccessproctoringaireviews = [
            'courseid' => 'privacy:metadata:courseid',
            'quizid' => 'privacy:metadata:quizid',
            'userid' => 'privacy:metadata:userid',
            'attemptid' => 'privacy:metadata:attemptid',
            'reportid' => 'privacy:metadata:reportid',
            'eventid' => 'privacy:metadata:airevieweventid',
            'reviewtype' => 'privacy:metadata:aireviewreviewtype',
            'holdid' => 'privacy:metadata:holdid',
            'riskscore' => 'privacy:metadata:riskscore',
            'triggerthreshold' => 'privacy:metadata:aireviewtriggerthreshold',
            'provider' => 'privacy:metadata:aireviewprovider',
            'model' => 'privacy:metadata:aireviewmodel',
            'reviewscore' => 'privacy:metadata:aireviewscore',
            'decision' => 'privacy:metadata:aireviewdecision',
            'summary' => 'privacy:metadata:aireviewsummary',
            'evidence' => 'privacy:metadata:aireviewevidence',
            'rawresponse' => 'privacy:metadata:aireviewrawresponse',
            'errormessage' => 'privacy:metadata:errormessage',
            'status' => 'privacy:metadata:status',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'timemodified',
            'timereviewed' => 'privacy:metadata:timereviewed',
        ];

        $collection->add_database_table(
            'quizaccess_proctoring_ai_reviews',
            $quizaccessproctoringaireviews,
            'privacy:metadata:quizaccess_proctoring_ai_reviews'
        );

        $quizaccessproctoringidv = [
            'verifiedat' => 'privacy:metadata:verifiedat',
            'profilehash' => 'privacy:metadata:profilehash',
            'policyhash' => 'privacy:metadata:policyhash',
            'courseid' => 'privacy:metadata:courseid',
            'quizid' => 'privacy:metadata:quizid',
            'userid' => 'privacy:metadata:userid',
            'attemptid' => 'privacy:metadata:attemptid',
            'status' => 'privacy:metadata:status',
            'facescore' => 'privacy:metadata:facescore',
            'namescore' => 'privacy:metadata:namescore',
            'extractedname' => 'privacy:metadata:extractedname',
            'romanizedname' => 'privacy:metadata:romanizedname',
            'matchedprofilename' => 'privacy:metadata:matchedprofilename',
            'namematchreason' => 'privacy:metadata:namematchreason',
            'profilename' => 'privacy:metadata:profilename',
            'idimageurl' => 'privacy:metadata:idimageurl',
            'idbackimageurl' => 'privacy:metadata:idbackimageurl',
            'liveimageurl' => 'privacy:metadata:liveimageurl',
            'errormessage' => 'privacy:metadata:errormessage',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'timemodified',
        ];

        $collection->add_database_table(
            'quizaccess_proctoring_idv',
            $quizaccessproctoringidv,
            'privacy:metadata:quizaccess_proctoring_idv'
        );

        $quizaccessproctoringuserimages = [
            'user_id' => 'privacy:metadata:userid',
            'photo_draft_id' => 'privacy:metadata:photo_draft_id',
        ];

        $collection->add_database_table(
            'quizaccess_proctoring_user_images',
            $quizaccessproctoringuserimages,
            'privacy:metadata:quizaccess_proctoring_user_images'
        );

        $quizaccessproctoringfaceimages = [
            'parent_type' => 'privacy:metadata:parent_type',
            'parentid' => 'privacy:metadata:parentid',
            'faceimage' => 'privacy:metadata:faceimage',
            'facefound' => 'privacy:metadata:facefound',
            'timemodified' => 'timemodified',
        ];

        $collection->add_database_table(
            'quizaccess_proctoring_face_images',
            $quizaccessproctoringfaceimages,
            'privacy:metadata:quizaccess_proctoring_face_images'
        );

        $overridefields = [
            'courseid' => 'privacy:metadata:courseid',
            'quizid' => 'privacy:metadata:quizid',
            'userid' => 'privacy:metadata:userid',
            'justification' => 'privacy:metadata:overridejustification',
            'expiry' => 'privacy:metadata:overrideexpiry',
            'grantedby' => 'privacy:metadata:overrideactor',
            'revokedby' => 'privacy:metadata:overrideactor',
            'revoked' => 'privacy:metadata:overriderevoked',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
            'timerevoked' => 'privacy:metadata:timereviewed',
        ];
        foreach (\quizaccess_proctoring\local\override_resolver::STATE_COLUMNS as $column) {
            $overridefields[$column] = 'privacy:metadata:overridestate';
        }
        $collection->add_database_table(
            'quizaccess_proctoring_overrides',
            $overridefields,
            'privacy:metadata:overrides'
        );
        $collection->add_database_table('quizaccess_proctoring_override_audit', [
            'overrideid' => 'privacy:metadata:overrideid',
            'actorid' => 'privacy:metadata:overrideactor',
            'action' => 'privacy:metadata:overridechange',
            'fieldname' => 'privacy:metadata:overridechange',
            'oldvalue' => 'privacy:metadata:overridechange',
            'newvalue' => 'privacy:metadata:overridechange',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:overrideaudit');

        $collection->add_external_location_link(
            'openai',
            [
                'images' => 'privacy:metadata:openai:images',
                'prompt' => 'privacy:metadata:openai:prompt',
            ],
            'privacy:metadata:openai'
        );
        $collection->add_external_location_link(
            'anthropic',
            [
                'images' => 'privacy:metadata:anthropic:images',
                'prompt' => 'privacy:metadata:anthropic:prompt',
            ],
            'privacy:metadata:anthropic'
        );
        $collection->add_external_location_link(
            'compatibleai',
            [
                'images' => 'privacy:metadata:compatibleai:images',
                'prompt' => 'privacy:metadata:compatibleai:prompt',
            ],
            'privacy:metadata:compatibleai'
        );
        $collection->add_external_location_link(
            'sayloridverification',
            [
                'images' => 'privacy:metadata:sayloridverification:images',
                'profile' => 'privacy:metadata:sayloridverification:profile',
            ],
            'privacy:metadata:sayloridverification'
        );

        $collection->add_subsystem_link(
            'core_files',
            [],
            'privacy:metadata:core_files'
        );

        return $collection;
    }

    /**
     * Retrieves a list of contexts that contain user information for the specified user.
     *
     * @param int $userid The ID of the user.
     * @return contextlist The list of contexts containing user data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $params = ['context' => CONTEXT_MODULE, 'userid' => $userid];

        // Context in Quizaccess proctoring logs.
        $sql = "SELECT DISTINCT c.id
                  FROM {quizaccess_proctoring_logs} qpl
                  JOIN {context} c ON c.instanceid = qpl.quizid AND c.contextlevel = :context
                  WHERE qpl.userid = :userid
              GROUP BY c.id";
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);
        $contextlist->add_from_sql("SELECT DISTINCT c.id
                  FROM {quizaccess_proctoring_fm_warnings} w
                  JOIN {context} c ON c.instanceid = w.quizid AND c.contextlevel = :context
                 WHERE w.userid = :userid", $params);
        $sql = "SELECT DISTINCT c.id
                  FROM {quizaccess_proctoring_events} qpe
                  JOIN {context} c ON c.instanceid = qpe.quizid AND c.contextlevel = :context
                 WHERE qpe.userid = :userid
              GROUP BY c.id";
        $contextlist->add_from_sql($sql, $params);
        $sql = "SELECT DISTINCT c.id
                  FROM {quizaccess_proctoring_risk_holds} qprh
                  JOIN {context} c ON c.instanceid = qprh.quizid AND c.contextlevel = :context
                 WHERE qprh.userid = :userid OR qprh.reviewerid = :reviewerid
              GROUP BY c.id";
        $riskholdparams = $params;
        $riskholdparams['reviewerid'] = $userid;
        $contextlist->add_from_sql($sql, $riskholdparams);
        $sql = "SELECT DISTINCT c.id
                  FROM {quizaccess_proctoring_finding_reviews} qpfr
                  JOIN {context} c ON c.instanceid = qpfr.quizid AND c.contextlevel = :context
                 WHERE qpfr.userid = :userid OR qpfr.reviewerid = :reviewerid OR qpfr.revokedby = :revokerid
              GROUP BY c.id";
        $findingreviewparams = $params;
        $findingreviewparams['reviewerid'] = $userid;
        $findingreviewparams['revokerid'] = $userid;
        $contextlist->add_from_sql($sql, $findingreviewparams);
        $sql = "SELECT DISTINCT c.id
                  FROM {quizaccess_proctoring_notes} qpn
                  JOIN {context} c ON c.instanceid = qpn.quizid AND c.contextlevel = :context
                 WHERE qpn.userid = :userid OR qpn.authorid = :authorid
              GROUP BY c.id";
        $noteparams = $params;
        $noteparams['authorid'] = $userid;
        $contextlist->add_from_sql($sql, $noteparams);
        $sql = "SELECT DISTINCT c.id
                  FROM {quizaccess_proctoring_ai_reviews} qpar
                  JOIN {context} c ON c.instanceid = qpar.quizid AND c.contextlevel = :context
                 WHERE qpar.userid = :userid
              GROUP BY c.id";
        $contextlist->add_from_sql($sql, $params);
        $sql = "SELECT DISTINCT c.id
                  FROM {quizaccess_proctoring_idv} qpidv
                  JOIN {context} c ON c.instanceid = qpidv.quizid AND c.contextlevel = :context
                 WHERE qpidv.userid = :userid
              GROUP BY c.id";
        $contextlist->add_from_sql($sql, $params);

        $systemparams = [
            'systemcontext' => CONTEXT_SYSTEM,
            'userid' => $userid,
        ];
        $sql = "SELECT DISTINCT c.id
                  FROM {context} c
                  JOIN {quizaccess_proctoring_user_images} qui ON qui.user_id = :userid
                 WHERE c.contextlevel = :systemcontext";
        $contextlist->add_from_sql($sql, $systemparams);

        $fileparams = ['component' => 'quizaccess_proctoring', 'userid' => $userid];

        $sqlfile = "SELECT DISTINCT contextid as id
                    FROM {files}
                    WHERE component = :component
                    AND userid= :userid";
        $contextlist->add_from_sql($sqlfile, $fileparams);

        $fileparams = [
            'component' => 'quizaccess_proctoring',
            'userid' => $userid,
            'userphoto' => 'user_photo',
            'faceimage' => 'face_image',
        ];
        $sqlfile = "SELECT DISTINCT contextid AS id
                      FROM {files}
                     WHERE component = :component
                       AND filearea IN (:userphoto, :faceimage)
                       AND itemid = :userid";
        $contextlist->add_from_sql($sqlfile, $fileparams);
        self::add_override_contexts($contextlist, $userid);
        return $contextlist;
    }

    /**
     * Retrieves the list of users who have data in a specific context.
     *
     * @param userlist $userlist The userlist object to populate with user data.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        self::add_override_users($userlist);

        if ($context->contextlevel === CONTEXT_SYSTEM) {
            $sql = "SELECT DISTINCT qui.user_id AS userid
                      FROM {quizaccess_proctoring_user_images} qui";
            $userlist->add_from_sql('userid', $sql, []);

            $sql = "SELECT DISTINCT f.itemid AS userid
                      FROM {files} f
                     WHERE f.contextid = :contextid
                       AND f.component = :component
                       AND f.filearea IN (:userphoto, :faceimage)
                       AND f.itemid > 0";
            $userlist->add_from_sql('userid', $sql, [
                'contextid' => $context->id,
                'component' => 'quizaccess_proctoring',
                'userphoto' => 'user_photo',
                'faceimage' => 'face_image',
            ]);
            return;
        }

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        // The data is associated at the quiz module context level, so retrieve the user's context id.
        $sql = "SELECT DISTINCT qpl.userid AS userid
                  FROM {quizaccess_proctoring_logs} qpl
                  JOIN {course_modules} cm ON cm.id = qpl.quizid
                 WHERE cm.id = ?";
        $params = [$context->instanceid];
        $userlist->add_from_sql('userid', $sql, $params);
        $userlist->add_from_sql('userid', "SELECT DISTINCT userid
                  FROM {quizaccess_proctoring_fm_warnings} WHERE quizid = ?", $params);

        $sql = "SELECT DISTINCT qpe.userid AS userid
                  FROM {quizaccess_proctoring_events} qpe
                 WHERE qpe.quizid = ?";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT DISTINCT qprh.userid AS userid
                  FROM {quizaccess_proctoring_risk_holds} qprh
                 WHERE qprh.quizid = ?";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT DISTINCT qprh.reviewerid AS userid
                  FROM {quizaccess_proctoring_risk_holds} qprh
                 WHERE qprh.quizid = ? AND qprh.reviewerid <> 0";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT DISTINCT qpfr.userid AS userid
                  FROM {quizaccess_proctoring_finding_reviews} qpfr
                 WHERE qpfr.quizid = ?";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT DISTINCT qpfr.reviewerid AS userid
                  FROM {quizaccess_proctoring_finding_reviews} qpfr
                 WHERE qpfr.quizid = ? AND qpfr.reviewerid <> 0";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT DISTINCT qpfr.revokedby AS userid
                  FROM {quizaccess_proctoring_finding_reviews} qpfr
                 WHERE qpfr.quizid = ? AND qpfr.revokedby <> 0";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT DISTINCT qpn.userid AS userid
                  FROM {quizaccess_proctoring_notes} qpn
                 WHERE qpn.quizid = ?";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT DISTINCT qpn.authorid AS userid
                  FROM {quizaccess_proctoring_notes} qpn
                 WHERE qpn.quizid = ? AND qpn.authorid <> 0";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT DISTINCT qpar.userid AS userid
                  FROM {quizaccess_proctoring_ai_reviews} qpar
                 WHERE qpar.quizid = ?";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT DISTINCT qpidv.userid AS userid
                  FROM {quizaccess_proctoring_idv} qpidv
                 WHERE qpidv.quizid = ?";
        $userlist->add_from_sql('userid', $sql, $params);

        $fileparams = ['component' => 'quizaccess_proctoring', 'contextid' => $context->id];
        $sqlfile = "SELECT DISTINCT userid
                    FROM {files}
                    WHERE component = :component
                    AND contextid= :contextid";
        $userlist->add_from_sql('userid', $sqlfile, $fileparams);
    }

    /**
     * Exports user data for the given approved context list.
     *
     * @param approved_contextlist $contextlist The list of contexts to export data for.
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        // Get all cmids that correspond to the contexts for a user.
        foreach ($contextlist->get_contexts() as $context) {
            self::export_override_data($context, (int)$contextlist->get_user()->id);
            if ($context->contextlevel === CONTEXT_MODULE && $context->instanceid) {
                [$insql, $inparams] = $DB->get_in_or_equal([$context->instanceid], SQL_PARAMS_NAMED);

                $select = "quizid $insql AND userid = :userid";
                $params = $inparams;
                $params['userid'] = $contextlist->get_user()->id;

                $fields = 'id, courseid, quizid, userid, webcampicture, status, timemodified, capturedat, requestid';

                $qaplogs = $DB->get_records_select('quizaccess_proctoring_logs', $select, $params, '', $fields);

                $index = 0;
                foreach ($qaplogs as $qaplog) {
                    // Data export is organised in: {Context}/{Plugin Name}/{Table name}/{index}/data.json.
                    $index++;
                    $subcontext = [
                        get_string('quizaccess_proctoring', 'quizaccess_proctoring'),
                        'proctoring_logs',
                        $index,
                    ];

                    $data = (object)[
                        'id' => $qaplog->id,
                        'courseid' => $qaplog->courseid,
                        'quizid' => $qaplog->quizid,
                        'userid' => $qaplog->userid,
                        'webcampicture' => $qaplog->webcampicture,
                        'capturedat' => $qaplog->capturedat ? transform::datetime($qaplog->capturedat) : null,
                        'requestid' => $qaplog->requestid,
                        'status' => $qaplog->status,
                        'timemodified' => transform::datetime($qaplog->timemodified),
                    ];
                    $webcamepic = explode("/", "$qaplog->webcampicture");
                    $webcamepiclast = end($webcamepic);

                    $paramfile = [
                        'userid' => $qaplog->userid,
                        'filename' => $webcamepiclast,
                        'contextid' => $context->id,
                        'component' => 'quizaccess_proctoring',
                        'filearea' => 'picture',
                    ];
                    if (!empty($webcamepiclast)) {
                        $userfiles = $DB->get_record('files', $paramfile);
                        writer::with_context($context)
                            ->export_area_files(
                                [get_string('privacy:core_files', 'quizaccess_proctoring')],
                                'quizaccess_proctoring',
                                'picture',
                                $userfiles ? $userfiles->itemid : $qaplog->id
                            )->export_data($subcontext, $data);
                    } else {
                        writer::with_context($context)
                            ->export_data($subcontext, $data);
                    }
                }

                $logids = array_map('intval', array_keys($qaplogs));
                $warnings = array_values($DB->get_records_select('quizaccess_proctoring_fm_warnings', $select, $params));
                if ($warnings) {
                    writer::with_context($context)->export_data([
                        get_string('quizaccess_proctoring', 'quizaccess_proctoring'), 'proctoring_face_warnings',
                    ], (object)['warnings' => $warnings]);
                }
                if ($logids) {
                    [$loginsql, $loginparams] = $DB->get_in_or_equal($logids, SQL_PARAMS_NAMED, 'logid');
                    $jobs = array_values($DB->get_records_select(
                        'quizaccess_proctoring_facematch_task',
                        "reportid {$loginsql}",
                        $loginparams
                    ));
                    if ($jobs) {
                        writer::with_context($context)->export_data([
                            get_string('quizaccess_proctoring', 'quizaccess_proctoring'), 'proctoring_face_jobs',
                        ], (object)['jobs' => $jobs]);
                    }
                    $faceparams = $loginparams;
                    $faceparams['adminparent'] = 'admin_image';
                    $faceimages = $DB->get_records_select(
                        'quizaccess_proctoring_face_images',
                        "parentid {$loginsql} AND parent_type <> :adminparent",
                        $faceparams,
                        '',
                        'id, parent_type, parentid, faceimage, facefound, timemodified'
                    );

                    $index = 0;
                    foreach ($faceimages as $faceimage) {
                        $index++;
                        $subcontext = [
                            get_string('quizaccess_proctoring', 'quizaccess_proctoring'),
                            'proctoring_face_images',
                            $index,
                        ];

                        $data = (object)[
                            'id' => $faceimage->id,
                            'parent_type' => $faceimage->parent_type,
                            'parentid' => $faceimage->parentid,
                            'faceimage' => $faceimage->faceimage,
                            'facefound' => $faceimage->facefound,
                            'timemodified' => transform::datetime($faceimage->timemodified),
                        ];

                        writer::with_context($context)
                            ->export_area_files($subcontext, 'quizaccess_proctoring', 'face_image', $faceimage->parentid)
                            ->export_data($subcontext, $data);
                    }
                }

                $eventfields = 'id, courseid, quizid, userid, attemptid, reportid, eventtype, eventdetail, ' .
                    'pagevisibility, currenturl, screenshoturl, timemodified, capturedat, requestid';
                $events = $DB->get_records_select(
                    'quizaccess_proctoring_events',
                    $select,
                    $params,
                    '',
                    $eventfields
                );

                $index = 0;
                foreach ($events as $event) {
                    $index++;
                    $subcontext = [
                        get_string('quizaccess_proctoring', 'quizaccess_proctoring'),
                        'proctoring_events',
                        $index,
                    ];

                    $data = (object)[
                        'id' => $event->id,
                        'courseid' => $event->courseid,
                        'quizid' => $event->quizid,
                        'userid' => $event->userid,
                        'attemptid' => $event->attemptid,
                        'reportid' => $event->reportid,
                        'eventtype' => $event->eventtype,
                        'capturedat' => $event->capturedat ? transform::datetime($event->capturedat) : null,
                        'requestid' => $event->requestid,
                        'eventdetail' => $event->eventdetail,
                        'pagevisibility' => $event->pagevisibility,
                        'currenturl' => $event->currenturl,
                        'screenshoturl' => $event->screenshoturl,
                        'timemodified' => transform::datetime($event->timemodified),
                    ];

                    if (!empty($event->screenshoturl)) {
                        writer::with_context($context)
                            ->export_area_files($subcontext, 'quizaccess_proctoring', 'violation_screenshot', $event->id)
                            ->export_data($subcontext, $data);
                    } else {
                        writer::with_context($context)->export_data($subcontext, $data);
                    }
                }

                $riskholdfields = 'id, courseid, quizid, quizinstance, userid, attemptid, reportid, riskscore, ' .
                    'threshold, originalgrade, status, reviewerid, timecreated, timemodified, timereviewed';
                $participantparams = $inparams;
                $participantparams['participantid'] = $contextlist->get_user()->id;
                $participantparams['reviewerid'] = $contextlist->get_user()->id;
                $riskholds = $DB->get_records_select(
                    'quizaccess_proctoring_risk_holds',
                    "quizid $insql AND (userid = :participantid OR reviewerid = :reviewerid)",
                    $participantparams,
                    '',
                    $riskholdfields
                );

                $index = 0;
                foreach ($riskholds as $riskhold) {
                    $index++;
                    $subcontext = [
                        get_string('quizaccess_proctoring', 'quizaccess_proctoring'),
                        'proctoring_risk_holds',
                        $index,
                    ];

                    $data = (object)[
                        'id' => $riskhold->id,
                        'courseid' => $riskhold->courseid,
                        'quizid' => $riskhold->quizid,
                        'quizinstance' => $riskhold->quizinstance,
                        'userid' => $riskhold->userid,
                        'attemptid' => $riskhold->attemptid,
                        'reportid' => $riskhold->reportid,
                        'riskscore' => $riskhold->riskscore,
                        'threshold' => $riskhold->threshold,
                        'originalgrade' => $riskhold->originalgrade,
                        'status' => $riskhold->status,
                        'reviewerid' => $riskhold->reviewerid,
                        'timecreated' => transform::datetime($riskhold->timecreated),
                        'timemodified' => transform::datetime($riskhold->timemodified),
                        'timereviewed' => transform::datetime($riskhold->timereviewed),
                    ];

                    writer::with_context($context)->export_data($subcontext, $data);
                }

                $findingreviewfields = 'id, courseid, quizid, userid, attemptid, reportid, factorkey, verdict, ' .
                    'note, reviewerid, revokedby, revoked, timerevoked, timecreated';
                $findingreviews = $DB->get_records_select(
                    'quizaccess_proctoring_finding_reviews',
                    "quizid $insql AND (userid = :participantid OR reviewerid = :reviewerid OR revokedby = :revokerid)",
                    $participantparams + ['revokerid' => $contextlist->get_user()->id],
                    '',
                    $findingreviewfields
                );

                $index = 0;
                foreach ($findingreviews as $findingreview) {
                    $index++;
                    $subcontext = [
                        get_string('quizaccess_proctoring', 'quizaccess_proctoring'),
                        'proctoring_finding_reviews',
                        $index,
                    ];

                    $data = (object)[
                        'id' => $findingreview->id,
                        'courseid' => $findingreview->courseid,
                        'quizid' => $findingreview->quizid,
                        'userid' => $findingreview->userid,
                        'attemptid' => $findingreview->attemptid,
                        'reportid' => $findingreview->reportid,
                        'factorkey' => $findingreview->factorkey,
                        'verdict' => $findingreview->verdict,
                        'note' => $findingreview->note,
                        'reviewerid' => $findingreview->reviewerid,
                        'revokedby' => $findingreview->revokedby,
                        'revoked' => $findingreview->revoked,
                        'timerevoked' => $findingreview->timerevoked ? transform::datetime($findingreview->timerevoked) : null,
                        'timecreated' => transform::datetime($findingreview->timecreated),
                    ];

                    writer::with_context($context)->export_data($subcontext, $data);
                }

                $notefields = 'id, courseid, quizid, userid, attemptid, reportid, notetext, authorid, ' .
                    'timecreated, timemodified';
                $notes = $DB->get_records_select(
                    'quizaccess_proctoring_notes',
                    "quizid $insql AND (userid = :participantid OR authorid = :noteauthorid)",
                    $participantparams + ['noteauthorid' => $contextlist->get_user()->id],
                    '',
                    $notefields
                );

                $index = 0;
                foreach ($notes as $note) {
                    $index++;
                    $subcontext = [
                        get_string('quizaccess_proctoring', 'quizaccess_proctoring'),
                        'proctoring_notes',
                        $index,
                    ];

                    $data = (object)[
                        'id' => $note->id,
                        'courseid' => $note->courseid,
                        'quizid' => $note->quizid,
                        'userid' => $note->userid,
                        'attemptid' => $note->attemptid,
                        'reportid' => $note->reportid,
                        'notetext' => $note->notetext,
                        'authorid' => $note->authorid,
                        'timecreated' => transform::datetime($note->timecreated),
                        'timemodified' => transform::datetime($note->timemodified),
                    ];

                    writer::with_context($context)->export_data($subcontext, $data);
                }

                $aireviewfields = 'id, courseid, quizid, userid, attemptid, reportid, eventid, reviewtype, holdid, riskscore, ' .
                    'triggerthreshold, provider, model, reviewscore, decision, status, summary, evidence, ' .
                    'rawresponse, errormessage, timecreated, timemodified, timereviewed';
                $aireviews = $DB->get_records_select(
                    'quizaccess_proctoring_ai_reviews',
                    $select,
                    $params,
                    '',
                    $aireviewfields
                );

                $index = 0;
                foreach ($aireviews as $aireview) {
                    $index++;
                    $subcontext = [
                        get_string('quizaccess_proctoring', 'quizaccess_proctoring'),
                        'proctoring_ai_reviews',
                        $index,
                    ];

                    $data = (object)[
                        'id' => $aireview->id,
                        'courseid' => $aireview->courseid,
                        'quizid' => $aireview->quizid,
                        'userid' => $aireview->userid,
                        'attemptid' => $aireview->attemptid,
                        'reportid' => $aireview->reportid,
                        'eventid' => $aireview->eventid,
                        'reviewtype' => $aireview->reviewtype,
                        'holdid' => $aireview->holdid,
                        'riskscore' => $aireview->riskscore,
                        'triggerthreshold' => $aireview->triggerthreshold,
                        'provider' => $aireview->provider,
                        'model' => $aireview->model,
                        'reviewscore' => $aireview->reviewscore,
                        'decision' => $aireview->decision,
                        'status' => $aireview->status,
                        'summary' => $aireview->summary,
                        'evidence' => $aireview->evidence,
                        'rawresponse' => $aireview->rawresponse,
                        'errormessage' => $aireview->errormessage,
                        'timecreated' => transform::datetime($aireview->timecreated),
                        'timemodified' => transform::datetime($aireview->timemodified),
                        'timereviewed' => transform::datetime($aireview->timereviewed),
                    ];

                    writer::with_context($context)->export_data($subcontext, $data);
                }

                $idvfields = 'id, courseid, quizid, userid, attemptid, status, facescore, namescore, extractedname, ' .
                    'romanizedname, matchedprofilename, namematchreason, profilename, idimageurl, idbackimageurl, ' .
                    'liveimageurl, errormessage, timecreated, timemodified, verifiedat, profilehash, policyhash';
                $idverifications = $DB->get_records_select(
                    'quizaccess_proctoring_idv',
                    $select,
                    $params,
                    '',
                    $idvfields
                );

                $index = 0;
                foreach ($idverifications as $idverification) {
                    $index++;
                    $subcontext = [
                        get_string('quizaccess_proctoring', 'quizaccess_proctoring'),
                        'proctoring_id_verifications',
                        $index,
                    ];

                    $data = (object)[
                        'id' => $idverification->id,
                        'courseid' => $idverification->courseid,
                        'quizid' => $idverification->quizid,
                        'userid' => $idverification->userid,
                        'attemptid' => $idverification->attemptid,
                        'status' => $idverification->status,
                        'facescore' => $idverification->facescore,
                        'verifiedat' => $idverification->verifiedat ? transform::datetime($idverification->verifiedat) : null,
                        'profilehash' => $idverification->profilehash,
                        'policyhash' => $idverification->policyhash,
                        'namescore' => $idverification->namescore,
                        'extractedname' => $idverification->extractedname,
                        'romanizedname' => $idverification->romanizedname,
                        'matchedprofilename' => $idverification->matchedprofilename,
                        'namematchreason' => $idverification->namematchreason,
                        'profilename' => $idverification->profilename,
                        'idimageurl' => $idverification->idimageurl,
                        'idbackimageurl' => $idverification->idbackimageurl,
                        'liveimageurl' => $idverification->liveimageurl,
                        'errormessage' => $idverification->errormessage,
                        'timecreated' => transform::datetime($idverification->timecreated),
                        'timemodified' => transform::datetime($idverification->timemodified),
                    ];

                    writer::with_context($context)
                        ->export_area_files($subcontext, 'quizaccess_proctoring', 'id_document', $idverification->id)
                        ->export_area_files($subcontext, 'quizaccess_proctoring', 'id_back_document', $idverification->id)
                        ->export_area_files($subcontext, 'quizaccess_proctoring', 'id_live_image', $idverification->id)
                        ->export_data($subcontext, $data);
                }
            } else if ($context->contextlevel === CONTEXT_SYSTEM) {
                $userid = $contextlist->get_user()->id;
                $userimage = $DB->get_record(
                    'quizaccess_proctoring_user_images',
                    ['user_id' => $userid],
                    'id, user_id, photo_draft_id'
                );
                if ($userimage) {
                    $subcontext = [
                        get_string('quizaccess_proctoring', 'quizaccess_proctoring'),
                        'proctoring_reference_image',
                    ];
                    $data = (object)[
                        'id' => $userimage->id,
                        'user_id' => $userimage->user_id,
                        'photo_draft_id' => $userimage->photo_draft_id,
                    ];

                    writer::with_context($context)
                        ->export_area_files($subcontext, 'quizaccess_proctoring', 'user_photo', $userid)
                        ->export_area_files($subcontext, 'quizaccess_proctoring', 'face_image', $userid)
                        ->export_data($subcontext, $data);

                    $faceimages = $DB->get_records(
                        'quizaccess_proctoring_face_images',
                        [
                            'parentid' => $userimage->id,
                            'parent_type' => 'admin_image',
                        ],
                        '',
                        'id, parent_type, parentid, faceimage, facefound, timemodified'
                    );

                    $index = 0;
                    foreach ($faceimages as $faceimage) {
                        $index++;
                        $subcontext = [
                            get_string('quizaccess_proctoring', 'quizaccess_proctoring'),
                            'proctoring_reference_face_image',
                            $index,
                        ];
                        $data = (object)[
                            'id' => $faceimage->id,
                            'parent_type' => $faceimage->parent_type,
                            'parentid' => $faceimage->parentid,
                            'faceimage' => $faceimage->faceimage,
                            'facefound' => $faceimage->facefound,
                            'timemodified' => transform::datetime($faceimage->timemodified),
                        ];

                        writer::with_context($context)->export_data($subcontext, $data);
                    }
                }
            }
        }
    }

    /**
     * Deletes all user data within a specified context.
     *
     * @param context $context The context to delete data from.
     * @throws dml_exception
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        self::delete_override_data($context);

        // Sanity check that context is at the module context level.
        if ($context->contextlevel === CONTEXT_MODULE) {
            $cmid = $context->instanceid;
            $logids = $DB->get_fieldset_select(
                'quizaccess_proctoring_logs',
                'id',
                'quizid = :cmid',
                ['cmid' => $cmid]
            );
            self::delete_face_image_records_for_logids($logids);

            if ($logids) {
                $DB->delete_records_list('quizaccess_proctoring_facematch_task', 'reportid', $logids);
            }
            // Free text, URLs and attempt IDs remain personal data after userid is cleared.
            foreach (self::module_data_tables() as $table) {
                $DB->delete_records($table, ['quizid' => $cmid]);
            }

            $fs = get_file_storage();
            $fs->delete_area_files($context->id, 'quizaccess_proctoring', 'picture');
            $fs->delete_area_files($context->id, 'quizaccess_proctoring', 'face_image');
            $fs->delete_area_files($context->id, 'quizaccess_proctoring', 'violation_screenshot');
            $fs->delete_area_files($context->id, 'quizaccess_proctoring', 'id_document');
            $fs->delete_area_files($context->id, 'quizaccess_proctoring', 'id_back_document');
            $fs->delete_area_files($context->id, 'quizaccess_proctoring', 'id_live_image');
        } else if ($context->contextlevel === CONTEXT_SYSTEM) {
            $DB->delete_records('quizaccess_proctoring_face_images', ['parent_type' => 'admin_image']);
            $DB->delete_records('quizaccess_proctoring_user_images');

            $fs = get_file_storage();
            $fs->delete_area_files($context->id, 'quizaccess_proctoring', 'user_photo');
            $fs->delete_area_files($context->id, 'quizaccess_proctoring', 'face_image');
        }
    }

    /**
     * Deletes user data for specified users in a given context.
     *
     * @param approved_userlist $userlist The list of users to delete data for.
     * @throws dml_exception
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        self::delete_override_data($context, $userids);

        if ($context->contextlevel === CONTEXT_SYSTEM) {
            self::delete_reference_image_data_for_userids($userids);
            return;
        }

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        self::delete_module_data_for_userids($context, $userids);
    }

    /**
     * Deletes user data for a given user within the specified context.
     *
     * @param approved_contextlist $contextlist The list of contexts containing the user's data.
     * @throws dml_exception If there is an issue with the database operation.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $contexts = $contextlist->get_contexts();
        if (count($contexts) == 0) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        foreach ($contexts as $context) {
            self::delete_override_data($context, [$userid]);
            if ($context->contextlevel === CONTEXT_MODULE) {
                self::delete_module_data_for_userids($context, [$userid]);
            } else if ($context->contextlevel === CONTEXT_SYSTEM) {
                self::delete_reference_image_data_for_userids([$userid]);
            }
        }
    }

    /**
     * Deletes module evidence and user-owned proctoring files for selected users.
     *
     * @param context $context Module context.
     * @param array $userids User IDs.
     */
    private static function delete_module_data_for_userids(context $context, array $userids): void {
        global $DB;

        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$userids || $context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cmid = $context->instanceid;
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'userid');
        $params = array_merge(['cmid' => $cmid], $inparams);

        $logids = $DB->get_fieldset_select(
            'quizaccess_proctoring_logs',
            'id',
            "quizid = :cmid AND userid {$insql}",
            $params
        );
        self::delete_face_image_records_for_logids($logids);
        if ($logids) {
            $DB->delete_records_list('quizaccess_proctoring_facematch_task', 'reportid', $logids);
        }
        // Remove the complete evidence, including embedded names, raw AI responses and links
        // back to quiz attempts. Zeroing the user ID alone does not anonymize these records.
        foreach (self::module_data_tables() as $table) {
            $DB->delete_records_select($table, "quizid = :cmid AND userid {$insql}", $params);
        }
        // Keep other students' records while removing the erased user's staff attribution.
        foreach (
            [
            'quizaccess_proctoring_risk_holds' => ['reviewerid'],
            'quizaccess_proctoring_finding_reviews' => ['reviewerid', 'revokedby'],
            'quizaccess_proctoring_notes' => ['authorid'],
            ] as $table => $fields
        ) {
            foreach ($fields as $field) {
                $DB->set_field_select($table, $field, 0, "quizid = :cmid AND {$field} {$insql}", $params);
            }
        }

        self::delete_files_for_userids($context, $userids, [
            'picture',
            'face_image',
            'violation_screenshot',
            'id_document',
            'id_back_document',
            'id_live_image',
        ]);
    }

    /**
     * Add contexts containing overrides or override actions involving this user.
     *
     * @param contextlist $contextlist Discovered contexts.
     * @param int $userid User being discovered.
     */
    private static function add_override_contexts(contextlist $contextlist, int $userid): void {
        $participant = "(o.userid = :subject OR o.grantedby = :grantor OR o.revokedby = :revoker
                        OR EXISTS (SELECT 1 FROM {quizaccess_proctoring_override_audit} a
                                    WHERE a.overrideid = o.id AND a.actorid = :actor))";
        $params = ['subject' => $userid, 'grantor' => $userid, 'revoker' => $userid, 'actor' => $userid];
        $contextlist->add_from_sql("SELECT DISTINCT c.id
                  FROM {quizaccess_proctoring_overrides} o
                  JOIN {context} c ON c.instanceid = o.courseid AND c.contextlevel = :level
                 WHERE o.quizid = 0 AND {$participant}", $params + ['level' => CONTEXT_COURSE]);
        $contextlist->add_from_sql("SELECT DISTINCT c.id
                  FROM {quizaccess_proctoring_overrides} o
                  JOIN {course_modules} cm ON cm.instance = o.quizid AND cm.course = o.courseid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :quizmodule
                  JOIN {context} c ON c.instanceid = cm.id AND c.contextlevel = :level
                 WHERE {$participant}", $params + ['level' => CONTEXT_MODULE, 'quizmodule' => 'quiz']);
    }

    /**
     * Restrict overrides to their actual course or quiz context.
     *
     * @param context $context Approved context.
     * @return array SQL condition and parameters, or an empty array for unrelated contexts.
     */
    private static function override_scope(context $context): array {
        if ($context->contextlevel === CONTEXT_COURSE) {
            $courseid = (int)$context->instanceid;
            $quizid = 0;
        } else if ($context->contextlevel === CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id('quiz', (int)$context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return [];
            }
            $courseid = (int)$cm->course;
            $quizid = (int)$cm->instance;
        } else {
            return [];
        }
        return [
            'courseid = :overridecourse AND quizid = :overridequiz',
            ['overridecourse' => $courseid, 'overridequiz' => $quizid],
        ];
    }

    /**
     * Add students and staff referenced by overrides in one context.
     *
     * @param userlist $userlist Users being discovered.
     */
    private static function add_override_users(userlist $userlist): void {
        $scope = self::override_scope($userlist->get_context());
        if (!$scope) {
            return;
        }
        [$select, $params] = $scope;
        foreach (['userid', 'grantedby', 'revokedby'] as $field) {
            $userlist->add_from_sql('userid', "SELECT DISTINCT {$field} AS userid
                  FROM {quizaccess_proctoring_overrides} WHERE {$select} AND {$field} > 0", $params);
        }
        $userlist->add_from_sql('userid', "SELECT DISTINCT a.actorid AS userid
                  FROM {quizaccess_proctoring_override_audit} a
                 WHERE a.actorid > 0 AND a.overrideid IN
                       (SELECT id FROM {quizaccess_proctoring_overrides} WHERE {$select})", $params);
    }

    /**
     * Export override reasons, states and audit history for a data subject.
     *
     * @param context $context Approved context.
     * @param int $userid Data subject.
     */
    private static function export_override_data(context $context, int $userid): void {
        global $DB;

        $scope = self::override_scope($context);
        if (!$scope) {
            return;
        }
        [$select, $params] = $scope;
        $params += ['subject' => $userid, 'grantor' => $userid, 'revoker' => $userid, 'actor' => $userid];
        $records = $DB->get_records_select('quizaccess_proctoring_overrides', "{$select} AND
                (userid = :subject OR grantedby = :grantor OR revokedby = :revoker OR id IN
                    (SELECT overrideid FROM {quizaccess_proctoring_override_audit} WHERE actorid = :actor))", $params);
        foreach ($records as $record) {
            $path = [get_string('quizaccess_proctoring', 'quizaccess_proctoring'), 'proctoring_overrides', $record->id];
            if ((int)$record->userid === $userid) {
                $audit = array_values($DB->get_records('quizaccess_proctoring_override_audit', ['overrideid' => $record->id]));
                $data = (object)['override' => $record, 'audit' => $audit];
            } else {
                // Staff attribution is personal to the requester; another student's accommodation
                // reasons and historical values are not part of that staff member's export.
                $audit = array_values($DB->get_records(
                    'quizaccess_proctoring_override_audit',
                    ['overrideid' => $record->id, 'actorid' => $userid],
                    '',
                    'id, overrideid, actorid, action, fieldname, timecreated'
                ));
                $data = (object)[
                    'overrideid' => $record->id,
                    'granted' => (int)$record->grantedby === $userid,
                    'revoked' => (int)$record->revokedby === $userid,
                    'audit' => $audit,
                ];
            }
            writer::with_context($context)->export_data($path, $data);
        }
    }

    /**
     * Delete override reasons and audit history, and anonymize staff actions on other users' records.
     *
     * @param context $context Approved context.
     * @param array|null $userids Selected users, or null for all users in the context.
     */
    private static function delete_override_data(context $context, ?array $userids = null): void {
        global $DB;

        $scope = self::override_scope($context);
        if (!$scope || $userids === []) {
            return;
        }
        [$select, $params] = $scope;
        $subjectselect = $select;
        $subjectparams = $params;
        if ($userids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'overrideuser');
            $subjectselect .= " AND userid {$insql}";
            $subjectparams += $inparams;
        }
        $ids = $DB->get_fieldset_select('quizaccess_proctoring_overrides', 'id', $subjectselect, $subjectparams);
        if ($ids) {
            $DB->delete_records_list('quizaccess_proctoring_override_audit', 'overrideid', $ids);
            $DB->delete_records_list('quizaccess_proctoring_overrides', 'id', $ids);
        }
        if ($userids !== null) {
            foreach (['grantedby', 'revokedby'] as $field) {
                $DB->set_field_select(
                    'quizaccess_proctoring_overrides',
                    $field,
                    0,
                    "{$select} AND {$field} {$insql}",
                    $params + $inparams
                );
            }
            $DB->set_field_select(
                'quizaccess_proctoring_override_audit',
                'actorid',
                0,
                "actorid {$insql} AND overrideid IN
                    (SELECT id FROM {quizaccess_proctoring_overrides} WHERE {$select})",
                $params + $inparams
            );
        }
    }

    /**
     * Tables containing evidence directly associated with a student and course module.
     *
     * @return string[] Table names.
     */
    private static function module_data_tables(): array {
        return [
            'quizaccess_proctoring_logs',
            'quizaccess_proctoring_events',
            'quizaccess_proctoring_risk_holds',
            'quizaccess_proctoring_ai_reviews',
            'quizaccess_proctoring_finding_reviews',
            'quizaccess_proctoring_idv',
            'quizaccess_proctoring_notes',
            'quizaccess_proctoring_fm_warnings',
        ];
    }

    /**
     * Deletes face-image database rows for module webcam log records.
     *
     * @param array $logids Proctoring log IDs.
     */
    private static function delete_face_image_records_for_logids(array $logids): void {
        global $DB;

        $logids = array_values(array_filter(array_map('intval', $logids)));
        if (!$logids) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($logids, SQL_PARAMS_NAMED, 'logid');
        $params = array_merge($inparams, ['adminparent' => 'admin_image']);
        $DB->delete_records_select(
            'quizaccess_proctoring_face_images',
            "parentid {$insql} AND parent_type <> :adminparent",
            $params
        );
    }

    /**
     * Deletes stored files for selected users from selected file areas.
     *
     * @param context $context File context.
     * @param array $userids User IDs.
     * @param array $fileareas File areas.
     */
    private static function delete_files_for_userids(context $context, array $userids, array $fileareas): void {
        global $DB;

        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$userids || !$fileareas) {
            return;
        }

        [$userinsql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'userid');
        [$areainsql, $areaparams] = $DB->get_in_or_equal($fileareas, SQL_PARAMS_NAMED, 'filearea');
        $params = array_merge([
            'contextid' => $context->id,
            'component' => 'quizaccess_proctoring',
        ], $userparams, $areaparams);

        $sql = "SELECT *
                  FROM {files}
                 WHERE contextid = :contextid
                   AND component = :component
                   AND filearea {$areainsql}
                   AND userid {$userinsql}";

        $fs = get_file_storage();
        $files = $DB->get_records_sql($sql, $params);
        foreach ($files as $file) {
            $storedfile = $fs->get_file_instance($file);
            if ($storedfile) {
                $storedfile->delete();
            }
        }
    }

    /**
     * Deletes system-context reference images and related face crops for selected users.
     *
     * @param array $userids User IDs.
     */
    private static function delete_reference_image_data_for_userids(array $userids): void {
        global $DB;

        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$userids) {
            return;
        }

        [$userinsql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'userid');
        $parentids = $DB->get_fieldset_select(
            'quizaccess_proctoring_user_images',
            'id',
            "user_id {$userinsql}",
            $userparams
        );
        if ($parentids) {
            [$parentinsql, $parentparams] = $DB->get_in_or_equal($parentids, SQL_PARAMS_NAMED, 'parentid');
            $params = array_merge($parentparams, ['adminparent' => 'admin_image']);
            $DB->delete_records_select(
                'quizaccess_proctoring_face_images',
                "parentid {$parentinsql} AND parent_type = :adminparent",
                $params
            );
        }
        $DB->delete_records_select('quizaccess_proctoring_user_images', "user_id {$userinsql}", $userparams);

        $context = \context_system::instance();
        [$iteminsql, $itemparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'itemid');
        $params = array_merge([
            'contextid' => $context->id,
            'component' => 'quizaccess_proctoring',
            'userphoto' => 'user_photo',
            'faceimage' => 'face_image',
        ], $itemparams);

        $sql = "SELECT *
                  FROM {files}
                 WHERE contextid = :contextid
                   AND component = :component
                   AND filearea IN (:userphoto, :faceimage)
                   AND itemid {$iteminsql}";

        $fs = get_file_storage();
        $files = $DB->get_records_sql($sql, $params);
        foreach ($files as $file) {
            $storedfile = $fs->get_file_instance($file);
            if ($storedfile) {
                $storedfile->delete();
            }
        }
    }
}
