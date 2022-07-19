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
 * Default/english language file used by the flexquiz module.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

defined('MOODLE_INTERNAL') || die;

$string['activitycompleted'] = 'You have completed this activity.';
$string['flexquiz:addinstance'] = 'Add a new Flex Quiz';
$string['flexquiz:view'] = 'View Flex Quiz information';
$string['flexquizname'] = 'Flex Quiz name';
$string['aiapikeyfield'] = 'API key';
$string['aiapikeydescription'] = 'The key required to communicate with the API.';
$string['aiheader'] = 'AI Options';
$string['ainotset'] = "The 'Use AI' setting needs to be enabled.";
$string['aierror'] = 'AI not configured properly (This is done via plugin settings by an administrator.): {$a}';
$string['ainourl'] = 'A URL must be specified.';
$string['ainoapikey'] = 'An API key must be specified.';
$string['aiurlfield'] = 'API base URL';
$string['aiurldescription'] = 'Base URL of the API to send the requests to.';
$string['alertactive'] = 'Active!';
$string['approximately'] = 'Consecutive correct answers required: About {$a} more correct answers needed this cycle to meet the requirement.';
$string['availablefrom'] = 'Becomes available at {$a}.';
$string['backtocourse'] = 'Back to the course';
$string['ccar'] = 'Consecutive correct answers required';
$string['ccar_help'] = 'The number of consecutive correct answers required to consider a question mastered. Only when the given number is reached will a question not reappear before the next cycle transition.';
$string['ccasleft'] = 'Consecutive correct answers required: At least {$a} more correct answers needed this cycle to meet the requirement.';
$string['cleanupchildren'] = 'Flexquiz children cleanup';
$string['closesat'] = 'Closes at {$a}.';
$string['createcyclegrades'] = 'Add grades for completed cycles';
$string['createcyclegrades_help'] = "Set this to yes, if you want to add grades for each completed cycle. Cycle grades will be implemented as 'assignment' activities which will be set to complete and graded on cycle completion.";
$string['currentcycle'] = 'Current cycle: {$a}';
$string['currentlyactivequiz'] = 'Currently active quiz: {$a}';
$string['currentstanding'] = 'Current average: {$a}';
$string['customtimelimit'] = 'Quiz time limit';
$string['customtimelimit_help'] = 'Sets the timelimit applied to each quiz created.';
$string['cycleduration'] = 'Cycle duration';
$string['cyclegradesvisible'] = 'Make cycle grades visible for everyone';
$string['cyclegradesvisible_help'] = "Set this to 'yes' in order to make the assignment activities containing the cycle grades visible for everyone.";
$string['cyclegradingheader'] = 'Cycle grading';
$string['cyclesheader'] = 'Cycles';
$string['dateformat'] = 'F jS Y, H:i';
$string['emptyquiz'] = 'Empty questions array returned from AI. No quiz created.';
$string['enddate'] = 'Activity ends at';
$string['enddate_help'] = 'No more quizzes are created for this Flex Quiz after the date given here.';
$string['endsat'] = 'Ends at: {$a}';
$string['faultyairesponse'] = 'AI response could not be parsed. Quiz creation skipped.';
$string['fewccasleft'] = 'There are still a few more correct question attempts required this cycle.';
$string['generalheader'] = 'General';
$string['generaltab'] = 'General';
$string['gradecategoryid'] = 'Category';
$string['gradecategoryid_help'] = 'The category in which the cycle grades will appear. Setting this to \'<Course\>\' or deleting the selected category will make cycle grades appear in the course root category.';
$string['gradingactivitydescription'] = 'Grading for {$a}';
$string['gradingactivityname'] = '{$a->name} - cycle {$a->number} (cycle ended on {$a->date})';
$string['gradingactivityshortname'] = '{$a->name} - cycle {$a->number}';
$string['headeraverage'] = 'Average performance';
$string['headercycleattempts'] = 'Quiz attempts this cycle';
$string['headername'] = 'Name';
$string['headerpercentage'] = 'Current standing';
$string['headerquestion'] = 'Question';
$string['headerquestionattempts'] = 'Consecutive correct answers';
$string['headerquestions'] = 'Performance by question';
$string['headerstatus'] = 'Status';
$string['headertotalattempts'] = 'Quizzes attempted';
$string['iteration'] = 'Iteration';
$string['lastcycle'] = 'Last cycle reached.';
$string['maxcountreached'] = 'Maximum quiz count reached for this cycle.';
$string['maxquizcount'] = 'Maximum number of quizzes per cycle';
$string['maxquestions'] = 'Maximum number of questions per quiz';
$string['minquestions'] = 'Minimum number of questions per quiz';
$string['minquestions_help'] = 'Only use this setting in non-AI mode. In AI mode this is determined by the AI.';
$string['minquestionsnotallowed'] = 'Minimum cannot be set in AI mode.';
$string['modulename'] = 'Flex Quiz';
$string['modulenameplural'] = 'Flex Quizzes';
$string['nextcyclestart'] = 'Next cycle starts: {$a}';
$string['noaccess'] = 'You do not have access to this activity';
$string['noactivequizzes'] = 'No active quizzes.';
$string['noaierror'] = 'AI failed. Contact your administrator.';
$string['noccasleft'] = 'Consecutive correct answers requirement met for this cycle.';
$string['nodeadline'] = 'No deadline set.';
$string['noparent'] = 'No parent quiz specified';
$string['norandomquestionswithai'] = "The questions pool upon which your flex quiz is based contains questions of type 'random'. Questions of type 'random' are not yet supported by our AI.";
$string['nostats'] = 'No stats yet.';
$string['numberofstudents'] = 'Number of students: {$a->numberofstudents}.';
$string['numbersfieldinvalid'] = 'Input must be a number';
$string['parentquiz'] = 'Parent quiz';
$string['parentquiz_help'] = 'The quiz which provides the pool of questions used by each quiz created.';
$string['pauseduration'] = 'Mandatory pause between quizzes';
$string['pauseduration_help'] = 'Indicates how long a student must wait after completing a quiz until he can start the next one.';
$string['performancetab'] = 'Performance';
$string['pluginadministration'] = 'Flex Quiz';
$string['pluginname'] = 'Flex Quiz';
$string['questionsheader'] = 'Questions';
$string['quizclosed'] = 'Closed.';
$string['randomquestion'] = 'Random question from category:';
$string['requirementsmet'] = 'All requirements met.';
$string['rootcategory'] = '&ltCourse&gt';
$string['roundupcycle'] = 'Roundup cycle (Advanced)';
$string['roundupcycle_help'] = "Selecting this option will cause the last cycle to be handled differently: The 'consecutive correct answers required' setting will be set to 1 for this one cycle. Be aware that this setting will also effect flex quizzes with no cycles set, since those will be treated as having just one cycle.";
$string['roundupstatsvalue'] = 'Average roundup cycle performance: {$a->roundupavg}%.';
$string['sectionname'] = 'Quiz section';
$string['startdate'] = 'Start activity at';
$string['startdate_help'] = 'Sets the start time for the first quiz.';
$string['startedat'] = 'Started at: {$a}';
$string['startsat'] = 'Starts at: {$a}';
$string['statistics'] = 'Statistics';
$string['statsvalue'] = 'Average performance: {$a->average}%.';
$string['toofewquestions'] = 'The product [maximum of questions] * [maximum of quizzes] cannot be lower than the total number of questions available.';
$string['useai'] = 'Use AI';
$string['useaidescription'] = 'Use an AI to choose questions for child quizzes. Requires a connection to an AI to be present and correctly configured.';
$string['usesai'] = 'Use AI';
$string['usesai_help'] = "Requires a connection to an AI. Your 'flex quiz' plugin needs to be configured accordingly.";

$string['privacy:metadata:flexquiz_student'] = 'Information about the student for one Flexquiz.';
$string['privacy:metadata:flexquiz_student:instances'] = 'The number of child quizzes the student got for this Flexquiz.';
$string['privacy:metadata:flexquiz_student:cyclenumber'] = 'The current cycle the student is in.';
$string['privacy:metadata:flexquiz_student:instances_this_cycle'] = 'The number of child quizzes the student got for this Flexquiz during the current cycle.';
$string['privacy:metadata:flexquiz_student:graded'] = 'Flag that tells if the student has been graded.';

$string['privacy:metadata:flexquiz_grades_question'] = 'Information about the grades of a student for a Flexquiz question.';
$string['privacy:metadata:flexquiz_grades_question:attempts'] = 'The number of attempts on the question.';
$string['privacy:metadata:flexquiz_grades_question:rating'] = 'The student\'s rating for the question.';

$string['privacy:metadata:flexquiz_stats'] = 'Global information about a student\'s performance over all Flexquizzes.';
$string['privacy:metadata:flexquiz_stats:fraction'] = 'A value representing the user\'s overall progress/grade.';
$string['privacy:metadata:flexquiz_stats:attempts'] = 'The number of attempts on all Flexquizzes.';

$string['privacy:metadata:danube.education_data'] = 'The data sent to the danube.education API.';
$string['privacy:metadata:danube.education_data:student'] = 'The moodle ID of the student.';
$string['privacy:metadata:danube.education_data:cycle'] = 'The current cycle the student is in.';
$string['privacy:metadata:danube.education_data:question_scores'] = 'Individual scores of question from child quizzes.';
