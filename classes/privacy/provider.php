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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Flexquiz privacy provider.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace mod_flexquiz\privacy;

use core_privacy\manager;
use \core_privacy\local\request\userlist;
use \core_privacy\local\request\approved_contextlist;
use \core_privacy\local\request\approved_userlist;
use \core_privacy\local\request\deletion_criteria;
use \core_privacy\local\request\writer;
use \core_privacy\local\request\helper as request_helper;
use \core_privacy\local\metadata\collection;
use \core_privacy\local\request\transform;
use core_privacy\local\request\contextlist;

/**
 * The privacy provider for the flexquiz plugin.
 */
class provider implements
    // This plugin has data.
    \core_privacy\local\metadata\provider,
    // This plugin currently implements the original plugin_provider interface.
    \core_privacy\local\request\plugin\provider,
    // This plugin is capable of determining which users have data within it.
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Get the list of metadata.
     *
     * @param collection $collection The collection containing the list of metadata for this plugins.
     */
    public static function get_metadata(collection $collection) : collection {

        /* Database tables */

        $collection->add_database_table(
            'flexquiz_student',
            [
                'instances' => 'privacy:metadata:flexquiz_student:instances',
                'cyclenumber' => 'privacy:metadata:flexquiz_student:cyclenumber',
                'instances_this_cycle' => 'privacy:metadata:flexquiz_student:instances_this_cycle',
                'graded' => 'privacy:metadata:flexquiz_student:graded'
            ],
            'privacy:metadata:flexquiz_student'
        );

        $collection->add_database_table(
            'flexquiz_grades_question',
            [
                'attempts' => 'privacy:metadata:flexquiz_grades_question:attempts',
                'rating' => 'privacy:metadata:flexquiz_grades_question:rating'
            ],
            'privacy:metadata:flexquiz_grades_question'
        );

        $collection->add_database_table(
            'flexquiz_stats',
            [
                'fraction' => 'privacy:metadata:flexquiz_stats:fraction',
                'attempts' => 'privacy:metadata:flexquiz_stats:attempts'
            ],
            'privacy:metadata:flexquiz_stats'
        );

        /* External locations */

        $collection->add_external_location_link(
            'danube.education',
            [
                'student' => 'privacy:metadata:danube.education_data:student',
                'cycle' => 'privacy:metadata:danube.education_data:cycle',
                'question_scores' => 'privacy:metadata:danube.education_data:question_scores'
            ],
            'privacy:metadata:danube.education_data'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist $contextlist The list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid) : contextlist {
        $contextlist = new \core_privacy\local\request\contextlist();

        $params = [
            'modulename' => 'flexquiz',
            'contextlevel' => CONTEXT_MODULE,
            'userid'  => $userid
        ];

        $sql = "SELECT c.id
                FROM {context} c
                     INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                     INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                     INNER JOIN {flexquiz} f ON f.id = cm.instance
                     LEFT JOIN {flexquiz_student} fs ON fs.flexquiz = f.id
                WHERE fs.student = :userid";

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!is_a($context, \context_module::class)) {
            return;
        }

        $params = [
            'modulename' => 'flexquiz',
            'instanceid' => $context->instanceid
        ];

        $sql = "SELECT fs.student AS userid
                FROM {course_modules} cm
                    JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                    JOIN {flexquiz} f ON f.id = cm.instance
                    JOIN {flexquiz_student} fs ON fs.flexquiz = f.id
                WHERE cm.id = :instanceid";

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Delete all personal data for all users in the specified context.
     *
     * @param context $context Context to delete data from.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('flexquiz', $context->instanceid);
        if (!$cm) {
            return;
        }

        $flexquizid = $cm->instance;

        // Delete all child quizzes (actual quiz modules) of this flexquiz.
        $sql = 'SELECT cm.id, cm.instance, cm.section AS sectionid,
                FROM {course_modules} cm
                     JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                     JOIN {flexquiz_children} fc ON fc.quizid = cm.instance
                     JOIN {flexquiz_student} fs ON fs.id = fc.flexquiz_student_item
                WHERE fs.flexquiz = :flexquiz';
        $params = [
            'modulename' => 'quiz',
            'flexquiz' => $flexquizid
        ];
        $quizcoursemodules = $DB->get_records_sql($sql, $params);

        foreach ($quizcoursemodules as $cm) {
            $transaction = $DB->start_delegated_transaction();

            delete_mod_from_section($cm->id, $cm->sectionid);
            $DB->delete_records('course_modules', array('id' => $cm->id));
            quiz_delete_instance($cm->instance);

            $transaction->allow_commit();
        }

        // Delete all child quizzes of this flexquiz.
        $DB->delete_records_select(
            'flexquiz_children',
            "flexquiz_student_item IN (
                SELECT id
                FROM {flexquiz_student}
                WHERE flexquiz = :flexquiz
            )",
            ['flexquiz' => $flexquizid]
        );

        // Delete all flexquiz grades of this flexquiz.
        $DB->delete_records_select(
            'flexquiz_grades_question',
            "flexquiz_student_item IN (
                SELECT id
                FROM {flexquiz_student}
                WHERE flexquiz = :flexquiz
            )",
            ['flexquiz' => $flexquizid]
        );

        // Delete all flexquiz stash questions of this flexquiz.
        $DB->delete_records_select(
            'flexquiz_stash_questions',
            "stashid IN (
                SELECT s.id
                FROM {flexquiz_stash} s
                     JOIN {flexquiz_student} fs ON s.flexquiz_student_item = fs.id
                WHERE fs.flexquiz = :flexquiz
            )",
            ['flexquiz' => $flexquizid]
        );

        // Delete all flexquiz stashes of this flexquiz.
        $DB->delete_records_select(
            'flexquiz_stash',
            "flexquiz_student_item IN (
                SELECT id
                FROM {flexquiz_student}
                WHERE flexquiz = :flexquiz
            )",
            ['flexquiz' => $flexquizid]
        );

        // Note: Do not delete flexquiz_stats, since they are over all flexquizzes per user.

        // Delete all flexquiz students of this flexquiz.
        $DB->delete_records('flexquiz_student', ['flexquiz' => $flexquizid]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();
        $userid = $user->id;

        foreach ($contextlist as $context) {
            // Get the course module.
            $cm = $DB->get_record('course_modules', ['id' => $context->instanceid]);
            $flexquiz = $DB->get_record('flexquiz', ['id' => $cm->instance]);
            $flexquizid = $flexquiz->id;

            // Delete all child quizzes (actual quiz modules) of this flexquiz for this user.
            $sql = 'SELECT cm.id, cm.instance, cm.section AS sectionid
                    FROM {course_modules} cm
                        JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                        JOIN {flexquiz_children} fc ON fc.quizid = cm.instance
                        JOIN {flexquiz_student} fs ON fs.id = fc.flexquiz_student_item
                    WHERE fs.flexquiz = :flexquiz
                          AND fs.student = :student';
            $params = [
                'modulename' => 'quiz',
                'flexquiz' => $flexquizid,
                'student' => $userid
            ];
            $quizcoursemodules = $DB->get_records_sql($sql, $params);

            foreach ($quizcoursemodules as $cm) {
                $test = 1;

                $transaction = $DB->start_delegated_transaction();

                delete_mod_from_section($cm->id, $cm->sectionid);
                $DB->delete_records('course_modules', array('id' => $cm->id));
                quiz_delete_instance($cm->instance);

                $transaction->allow_commit();
            }

            // Delete all child quizzes of this flexquiz for this user.
            $DB->delete_records_select(
                'flexquiz_children',
                "flexquiz_student_item IN (
                    SELECT id
                    FROM {flexquiz_student}
                    WHERE flexquiz = :flexquiz
                          AND student = :student
                )",
                [
                    'flexquiz' => $flexquizid,
                    'student' => $userid
                ]
            );

            // Delete all flexquiz grades of this flexquiz for this user.
            $DB->delete_records_select(
                'flexquiz_grades_question',
                "flexquiz_student_item IN (
                    SELECT id
                    FROM {flexquiz_student}
                    WHERE flexquiz = :flexquiz
                          AND student = :student
                )",
                [
                    'flexquiz' => $flexquizid,
                    'student' => $userid
                ]
            );

            // Delete all flexquiz stash questions of this flexquiz for this user.
            $DB->delete_records_select(
                'flexquiz_stash_questions',
                "stashid IN (
                    SELECT s.id
                    FROM {flexquiz_stash} s
                         JOIN {flexquiz_student} fs ON s.flexquiz_student_item = fs.id
                    WHERE fs.flexquiz = :flexquiz
                          AND fs.student = :student
                )",
                [
                    'flexquiz' => $flexquizid,
                    'student' => $userid
                ]
            );

            // Delete all flexquiz stashes of this flexquiz for this user.
            $DB->delete_records_select(
                'flexquiz_stash',
                "flexquiz_student_item IN (
                    SELECT id
                    FROM {flexquiz_student}
                    WHERE flexquiz = :flexquiz
                          AND student = :student
                )",
                [
                    'flexquiz' => $flexquizid,
                    'student' => $userid
                ]
            );

            // Note: Do not delete flexquiz_stats, since they are over all flexquizzes per user.

            // Delete the flexquiz student of this flexquiz for this user.
            $DB->delete_records('flexquiz_student', ['flexquiz' => $flexquizid, 'student' => $userid]);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $cm = $DB->get_record('course_modules', ['id' => $context->instanceid]);
        $flexquiz = $DB->get_record('flexquiz', ['id' => $cm->instance]);
        $flexquizid = $flexquiz->id;

        list($userinsql, $userinparams) = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params = array_merge(['flexquiz' => $flexquizid], $userinparams);

        // Delete all child quizzes (actual quiz modules) of this flexquiz for all specified users.
        $sql = 'SELECT cm.id, cm.instance, cm.section AS sectionid
                FROM {course_modules} cm
                    JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                    JOIN {flexquiz_children} fc ON fc.quizid = cm.instance
                    JOIN {flexquiz_student} fs ON fs.id = fc.flexquiz_student_item
                WHERE fs.flexquiz = :flexquiz
                        AND fs.student = {$userinsql}';
        $quizparams = array_merge(
            $params,
            ['modulename' => 'quiz']
        );
        $quizcoursemodules = $DB->get_records_sql($sql, $quizparams);

        foreach ($quizcoursemodules as $cm) {
            $test = 1;

            $transaction = $DB->start_delegated_transaction();

            delete_mod_from_section($cm->id, $cm->sectionid);
            $DB->delete_records('course_modules', array('id' => $cm->id));
            quiz_delete_instance($cm->instance);

            $transaction->allow_commit();
        }

        // Delete all child quizzes of this flexquiz for all specified users.
        $DB->delete_records_select(
            'flexquiz_children',
            "flexquiz_student_item IN (
                SELECT id
                FROM {flexquiz_student}
                WHERE flexquiz = :flexquiz
                      AND student {$userinsql}
            )",
            $params
        );

        // Delete all flexquiz grades of this flexquiz for all specified users.
        $DB->delete_records_select(
            'flexquiz_grades_question',
            "flexquiz_student_item IN (
                SELECT id
                FROM {flexquiz_student}
                WHERE flexquiz = :flexquiz
                      AND student {$userinsql}
            )",
            $params
        );

        // Delete all flexquiz stash questions of this flexquiz for all specified users.
        $DB->delete_records_select(
            'flexquiz_stash_questions',
            "stashid IN (
                SELECT s.id
                FROM {flexquiz_stash} s
                     JOIN {flexquiz_student} fs ON s.flexquiz_student_item = fs.id
                WHERE fs.flexquiz = :flexquiz
                      AND fs.student {$userinsql}
            )",
            $params
        );

        // Delete all flexquiz stashes of this flexquiz for all specified users.
        $DB->delete_records_select(
            'flexquiz_stash',
            "flexquiz_student_item IN (
                SELECT id
                FROM {flexquiz_student}
                WHERE flexquiz = :flexquiz
                      AND student {$userinsql}
            )",
            $params
        );

        // Note: Do not delete flexquiz_stats, since they are over all flexquizzes per user.

        // Delete all flexquiz students of this flexquiz for all specified users.
        $DB->delete_records_select(
            'flexquiz_student',
            "flexquiz = :flexquiz AND sudent {$userinsql}",
            $params
        );
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();
        $userid = $user->id;
        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        // Get all flexquiz students for given contexts and user.
        $sql = "SELECT c.id AS contextid, fs.*, cm.id AS cmid
                FROM {context} c
                     JOIN {course_modules} cm ON cm.id = c.instanceid
                     JOIN {flexquiz} f ON f.id = cm.instance
                     JOIN {flexquiz_student} fs ON fs.flexquiz = f.id
                WHERE c.id {$contextsql}
                     AND fs.student = :student";
        $flexquizstudents = $DB->get_records_sql(
            $sql,
            array_merge(
                $contextparams,
                ['student' => $userid]
            )
        );

        // Get all flexquizzes for given contexts.
        $sql = "SELECT c.id AS contextid, f.*, cm.id AS cmid
                FROM {context} c
                     JOIN {course_modules} cm ON cm.id = c.instanceid
                     JOIN {flexquiz} f ON f.id = cm.instance
                WHERE c.id {$contextsql}";
        $flexquizzes = $DB->get_recordset_sql($sql, $contextparams);

        foreach ($flexquizzes as $flexquiz) {
            $context = \context::instance_by_id($flexquiz->contextid);

            $data = request_helper::get_context_data($context, $user);
            writer::with_context($context)
                ->export_data([], $data);
            request_helper::export_context_files($context, $user);

            if (isset($flexquizstudents[$flexquiz->contextid])) {
                static::export_flexquiz_student($userid, $flexquiz, $flexquizstudents[$flexquiz->contextid]);
                static::export_flexquiz_children($userid, $flexquiz);
                static::export_flexquiz_grades_question($userid, $flexquiz);
            }

            static::export_flexquiz_stats($userid, $flexquiz);
        }

        $flexquizzes->close();
    }

    /**
     * Exports flexquiz students.
     *
     * @param int userid the user's id.
     * @param stdClass flexquiz flexquiz db object.
     * @param stdClass flexquizstudent the flexquiz student db object.
     */
    protected static function export_flexquiz_student(int $userid, \stdClass $flexquiz, \stdClass $flexquizstudent) {
        global $DB;

        if (null !== $flexquizstudent) {
            $data = (object) [
                'instances' => format_string($flexquizstudent->instances, true),
                'cyclenumber' => format_string($flexquizstudent->cyclenumber, true),
                'instances_this_cycle' => format_string($flexquizstudent->instances_this_cycle, true),
                'graded' => format_string($flexquizstudent->graded, true)
            ];

            $subcontext = array('Flexquiz Statistics');

            writer::with_context(\context_module::instance($flexquiz->cmid))
                ->export_data($subcontext, $data);

            return true;
        }

        return false;
    }

    /**
     * Exports flexquiz child quizzes.
     *
     * @param int userid the user's id.
     * @param stdClass flexquiz flexquiz db object.
     */
    protected static function export_flexquiz_children(int $userid, \stdClass $flexquiz) {
        global $DB;

        $sql = "SELECT fc.*
                FROM {flexquiz} f
                     JOIN {flexquiz_student} fs ON fs.flexquiz = f.id
                     JOIN {flexquiz_children} fc ON fc.flexquiz_student_item = fs.id
                WHERE f.id = :flexquiz
                     AND fs.student = :student";
        $flexquizchildren = $DB->get_records_sql(
            $sql,
            array('student' => $userid, 'flexquiz' => $flexquiz->id)
        );

        $count = 1;
        foreach ($flexquizchildren as $flexquizchild) {
            $data = (object) [
                'active' => format_string($flexquizchild->active, true)
            ];

            $quizlabel = 'Quiz '.$count;
            $subcontext = array('Child Quizzes', $quizlabel);

            writer::with_context(\context_module::instance($flexquiz->cmid))
                ->export_data($subcontext, $data);

            $count++;
        }

        return true;
    }

    /**
     * Exports flexquiz question grades.
     *
     * @param int userid the user's id.
     * @param stdClass flexquiz flexquiz db object.
     */
    protected static function export_flexquiz_grades_question(int $userid, \stdClass $flexquiz) {
        global $DB;

        $sql = "SELECT fgq.*, q.name AS question_name, q.questiontext AS question_text
                FROM {flexquiz} f
                     JOIN {flexquiz_student} fs ON fs.flexquiz = f.id
                     JOIN {flexquiz_grades_question} fgq ON fgq.flexquiz_student_item = fs.id
                     JOIN {question} q ON q.id = fgq.question
                WHERE f.id = :flexquiz
                     AND fs.student = :student";
        $flexquizgradesquestions = $DB->get_records_sql(
            $sql,
            array('student' => $userid, 'flexquiz' => $flexquiz->id)
        );

        $count = 1;
        foreach ($flexquizgradesquestions as $flexquizgradesquestion) {
            $data = (object) [
                'name' => format_string($flexquizgradesquestion->question_name, true),
                'question' => format_string($flexquizgradesquestion->question_text, true),
                'attempts' => format_string($flexquizgradesquestion->attempts, true),
                'rating' => format_string($flexquizgradesquestion->rating, true)
            ];

            $subcontext = array('Question Grades', '_'.$count);

            writer::with_context(\context_module::instance($flexquiz->cmid))
                ->export_data($subcontext, $data);

            $count++;
        }

        return true;
    }

    /**
     * Exports flexquiz stats.
     *
     * @param int userid the user's id.
     * @param stdClass flexquiz flexquiz db object.
     */
    protected static function export_flexquiz_stats(int $userid, \stdClass $flexquiz) {
        global $DB;

        $sql = "SELECT fs.*
                FROM {flexquiz_stats} fs
                WHERE fs.student = :student";
        $flexquizstats = $DB->get_record_sql(
            $sql,
            array('student' => $userid)
        );

        if (null !== $flexquizstats) {
            $data = (object) [
                'fraction' => format_string($flexquizstats->fraction, true),
                'attempts' => format_string($flexquizstats->attempts, true),
            ];

            $subcontext = array('Global User Statistics');

            writer::with_context(\context_module::instance($flexquiz->cmid))
                ->export_data($subcontext, $data);

            return true;
        }

        return false;
    }
}
