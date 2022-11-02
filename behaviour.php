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
 * Question behaviour where the student have multiple attempts at the question before moving on to the next question
 *
 * @package qbehaviour
 * @subpackage adaptivemoopt
 * @copyright 2009 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Question behaviour for adaptive mode for moopt
 *
 * @copyright 2009 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_adaptivemoopt extends question_behaviour_with_multiple_tries {

    //moopt specific
    public function is_compatible_question(question_definition $question){
        return $question instanceof qtype_moopt_question;
    }

    //not moopt specific
    public function get_expected_data(){
        if ($this->qa->get_state()->is_active()){
            return array('submit' => PARAM_BOOL);
        }
        return parent::get_expected_data();
    }

    //not moopt specific
    public function get_state_string($showcorrectness){
        $laststep = $this->qa->get_last_step();
        if($laststep->has_behaviour_var('_try')) {
            $state = question_state::graded_state_for_fraction(
                $laststep->get_behaviour_var('_rawfraction'));
            return $state->default_string(true);
        }

        $state = $this->qa->get_state();
        if ($state == question_state::$todo) {
            return get_string('notcomplete', 'qbehaviour_adaptivemoopt');
        } else {
            return parent::get_state_string($showcorrectness);
        }
    }

    //not moopt specific
    public function get_right_answer_summary(){
        return $this->question->get_right_answer_summary();
    }

    //moopt specific
    public function adjust_display_options(question_display_options $options) {
        // Save some bits so we can put them back later.
        $save = clone($options);

        // Do the default thing.
        parent::adjust_display_options($options);

        // Then, if they have just Checked an answer, show them the applicable bits of feedback.
        if (!$this->qa->get_state()->is_finished() &&
            $this->qa->get_last_behaviour_var('_try')) {
            $options->feedback        = $save->feedback;
            $options->correctness     = $save->correctness;
            $options->numpartscorrect = $save->numpartscorrect;

        }

        // If the student is waiting for a grading response show feedback to display the "submission queued for grading" bar below the question
        if ($this->qa->get_last_step()->has_behaviour_var("_completeForGrading")) {
            $options->feedback = $save->feedback;
        }
    }

    protected function adjusted_fraction($fraction, $prevtries){
        return $fraction - $this->question->penalty * $prevtries;
    }

    //moopt specific
    public function process_action(question_attempt_pending_step $pendingstep){
        if ($pendingstep->has_behaviour_var('comment')) {
            return $this->process_comment($pendingstep);
        } else if ($pendingstep->has_behaviour_var('finish')) {
            return $this->process_finish($pendingstep);
        } else if ($pendingstep->has_behaviour_var('submit')) {
            return $this->process_submit($pendingstep);
        } elseif($pendingstep->has_behaviour_var('gradingresult')){
            return $this->process_gradingresult($pendingstep);
        } elseif($pendingstep->has_behaviour_var('graderunavailable')) {
            return $this->process_graderunavailable($pendingstep);
        } else {
            return $this->process_save($pendingstep);
        }
    }

    //moopt specific
    public function summarise_action(question_attempt_step $step){
        if ($step->has_behaviour_var('comment')) {
            return $this->summarise_manual_comment($step);
        } else if ($step->has_behaviour_var('finish')) {
            if ($step->get_state()->is_graded()){
                return get_string('finished', 'qbehaviour_adaptivemoopt',
                    get_string('alreadygradedsummary', 'qbehaviour_adaptivemoopt'));
            } else {
              return get_string('finished', 'qbehaviour_adaptivemoopt',
                    get_string('gradingsummary', 'qbehaviour_adaptivemoopt'));
            }
        } else if ($step->has_behaviour_var('submit')) {
            return get_string('submitted', 'question',
                get_string('gradingsummary', 'qbehaviour_adaptivemoopt'));
        } else if ($step->has_behaviour_var('gradingresult')) {
            return get_string('graded', 'qbehaviour_adaptivemoopt',
                get_string('gradedsummary', 'qbehaviour_adaptivemoopt'));
        } else if ($step->has_behaviour_var('graderunavailable')) {
            return get_string('grading', 'qbehaviour_adaptivemoopt',
                get_string('graderunavailable', 'qbehaviour_adaptivemoopt'));
        } else {
            return $this->summarise_save($step);
        }
    }

    //moopt specific
    /**
     * Only differs from parent implementation in that it sets a  flag on the first execution and
     * doesn't keep this step if the flag has already been set. This is important in the face of regrades.
     * When a submission is regraded the comment and the mark refer to the old version of the grading result,
     * therefore we don't include the comment and the mark in the regrading.
     * @global type $DB
     * @param \question_attempt_pending_step $pendingstep
     * @return bool
     */
    public function process_comment(question_attempt_pending_step $pendingstep){
        global $DB;
        if ($DB->record_exists('question_attempt_step_data',
            array('attemptstepid' => $pendingstep->get_id(), 'name' => '-_appliedFlag'))) {
            return question_attempt::DISCARD;
        }

        $parentreturn = parent::process_comment($pendingstep);

        $pendingstep->set_behaviour_var('_appliedFlag', '1');
        return $parentreturn;
    }

    public function process_submit(question_attempt_pending_step $pendingstep){
        global $DB;

        $status = $this->process_save($pendingstep);

        $response = $pendingstep->get_qt_data();
        if (!$this->question->is_complete_response($response)) {
            $pendingstep->set_state(question_state::$invalid);
            if ($this->qa->get_state() != question_state::$invalid) {
                $status = question_attempt::KEEP;
            }
            return $status;
        }

        //get last step with behaviour var "submit", because it contains the answer.
        $prevstep = $this->qa->get_last_step_with_behaviour_var('submit');
        $prevresponse = $prevstep->get_qt_data();

        if ($this->question->is_same_response($prevresponse, $response)) {
            return question_attempt::DISCARD;
        }


        if($this->question->enablefilesubmissions){
            $questionfilesaver = $pendingstep->get_qt_var('answer');
            if ($questionfilesaver instanceof question_file_saver) {
                $responsefiles = $questionfilesaver->get_files();
            } else {
                //We are in regrade
                $record = $DB->get_record('question_usages', array('id' => $this->qa->get_usage_id()), 'contextid');
                $qubacontextid = $record->contextid;
                $responsefiles = $pendingstep->get_qt_files('answer', $qubacontextid);
            }
        }

        $freetextanswers = [];
        if($this->question->enablefreetextsubmissions){
            $autogeneratenames = $this->question->ftsautogeneratefilenames;
            for ($i = 0; $i < $this->question->ftsmaxnumfields; $i++) {
                $text = $response["answertext$i"];
                if ($text == ''){
                    continue;
                }
                $record = $DB->get_record('qtype_moopt_freetexts',
                    ['questionid' => $this->question->id, 'inputindex' => $i]);
                $filename = $response["answerfilename$i"] ?? '';        // By default use submitted filename.
                // Overwrite filename if necessary.
                if ($record) {
                    if ($record->presetfilename) {
                        $filename = $record->filename;
                    } else if ($filename == '') {
                        $tmp = $i + 1;
                        $filename = "File$tmp.txt";
                    }
                } else if ($autogeneratenames || $filename == '') {
                    $tmp = $i + 1;
                    $filename = "File$tmp.txt";
                }
                $freetextanswers[$filename] = $text;
            }
        }

        $state = $this->question->grade_response_asynch($this->qa, $responsefiles ?? [], $freetextanswers);
        if ($state == question_state::$finished){
            $state = question_state::$complete;
            $pendingstep->set_behaviour_var('_completeForGrading', 1);
        }

        $pendingstep->set_state($state);
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));

        return question_attempt::KEEP;
    }

    public function process_finish(question_attempt_pending_step $pendingstep) {
        global $DB;

        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        $laststep = $this->qa->get_last_step();
        $response = $laststep->get_qt_data();

        if($laststep->has_behaviour_var('gradingresult')) {

            // Case 1: last answer was already graded and is still the same (If you press the "Finish attempt ..." button,
            // process_save will be called and checks is_same_response: True = discard step, false = keep step)
            // -> if the answer wasn't already graded or has changed there wouldn't be a gradingresult or a score in the latest step
            // We will not regrade the response here since its already graded (like moodle adaptive does)

            $fraction = $this->qa->get_fraction();

            $pendingstep->set_fraction($fraction);
            $pendingstep->set_state(question_state::graded_state_for_fraction($fraction));

        } else if ($laststep->has_behaviour_var('submit')){
            // Case 2: last answer has been submitted but the grading hasn't finished yet.
            // To tell the gradingresult action a finished state should be set we save the step at this point
            // (gradingresult will check if behaviour vars have 'finish').
            // There wouldn't be a submit var if the answer has changed, cause the process_save() method saved it.

            $pendingstep->set_state(question_state::$finished);
            $pendingstep->set_fraction(0);

        } else if (!$this->question->is_gradable_response($response)){
            // Case 3: $response is a different from the last graded response and unsubmitted but is not gradable

            $pendingstep->set_state(question_state::$gaveup);
            $pendingstep->set_fraction(0);

        } else {
            // Case 4: $response is a different from the last graded response, submitted and gradable

            //get moopt data from response
            if($this->question->enablefilesubmissions) {
                $record = $DB->get_record('question_usages', array('id' => $this->qa->get_usage_id()), 'contextid');
                $qubacontextid = $record->contextid;
                $responsefiles = $this->qa->get_last_qt_files('answer', $qubacontextid);
            }

            if($this->question->enablefreetextsubmissions) {
                $autogeneratenames = $this->question->ftsautogeneratefilenames;
                for ($i = 0; $i < $this->question->ftsmaxnumfields; $i++) {
                    $text = $response["answertext$i"];
                    if ($text == '') {
                        continue;
                    }
                    $record = $DB->get_record('qtype_moopt_freetexts',
                        ['questionid' => $this->question->id, 'inputindex' => $i]);
                    $filename = $response["answerfilename$i"] ?? '';        // By default use submitted filename.
                    // Overwrite filename if necessary.
                    if ($record) {
                        if ($record->presetfilename) {
                            $filename = $record->filename;
                        } else if ($filename == '') {
                            $tmp = $i + 1;
                            $filename = "File$tmp.txt";
                        }
                    } else if ($autogeneratenames || $filename == '') {
                        $tmp = $i + 1;
                        $filename = "File$tmp.txt";
                    }
                    $freetextanswers[$filename] = $text;
                }
            }

            $state = $this->question->grade_response_asynch($this->qa, $responsefiles ?? [], $freetextanswers ?? []);
            $pendingstep->set_state($state);
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        }

        return question_attempt::KEEP;
    }

    public function process_save(question_attempt_pending_step $pendingstep){
        $status = parent::process_save($pendingstep);
        if($status == question_attempt::KEEP){
            $prevresponse = $this->qa->get_last_step_with_behaviour_var('submit')->get_qt_data();
            if (($this->question->enablefilesubmissions && !empty($prevresponse)) ||
                                $this->question->is_same_response($prevresponse, $pendingstep->get_qt_data())){
                $status = question_attempt::DISCARD;
            }
            $prevgrade = $this->qa->get_fraction();
            if (!is_null($prevgrade)) {
                $pendingstep->set_fraction($prevgrade);
            }
            $pendingstep->set_state(question_state::$todo);
        }
        return $status;
    }

    public function process_gradingresult(question_attempt_pending_step $pendingstep){
        global $DB;

        $processdbid = $pendingstep->get_qt_var('gradeprocessdbid');
        $exists = $DB->record_exists('qtype_moopt_gradeprocesses', ['id' => $processdbid]);

        if (!$exists) {
            //It´s a regrade, discard this *old* result
            return question_attempt::DISCARD;
        }

        if ($pendingstep->has_qt_var('score')){
            $score = $pendingstep->get_qt_var('score');
            $maxmark = $this->qa->get_max_mark();
            if($maxmark == 0){
                $fraction = 0;
            } else {
                $fraction = $score / $maxmark;
            }
        } else {
            $fraction = 0;
        }

        $prevstep = $this->qa->get_last_step_with_behaviour_var('_try');
        $prevtries = $this->qa->get_last_behaviour_var('_try', 0);
        $prevbest = $this->qa->get_fraction();
        if(is_null($prevbest)){
            $prevbest = 0;
        }

        $adjustedfraction = max($prevbest, $this->adjusted_fraction($fraction, $prevtries));
        $pendingstep->set_fraction($adjustedfraction);

        $state = question_state::graded_state_for_fraction($fraction);

        // We need to know if a submit or a finish action initiated this grading
        // finish: we need to set a finished state
        // submit: we need to set an active state
        $laststep = $this->qa->get_last_step();
        if ($laststep->has_behaviour_var('finish')){
            if ($prevstep->get_state() == question_state::$complete) {
                $state = question_state::$gradedright;
            }
        } else {
            if ($prevstep->get_state() == question_state::$complete) {
                $state = question_state::$complete;
            } else if ($state == question_state::$gradedright) {
                $state = question_state::$complete;
            } else {
                $state = question_state::$todo;
            }
        }
        $pendingstep->set_state($state);

        $pendingstep->set_behaviour_var('_rawfraction', $fraction);
        $pendingstep->set_behaviour_var('_try', $prevtries + 1);
        $pendingstep->set_behaviour_var('_showGradedFeedback', 1);

        // If this is the real result for a regrade we should update the quiz_overview_regrades table
        // to properly display the new result.
        $regraderecord = $DB->get_record('quiz_overview_regrades',
            ['questionusageid' => $this->qa->get_usage_id(), 'slot' => $this->qa->get_slot()]);
        if ($regraderecord) {
            $regraderecord->newfraction = $adjustedfraction;
            $DB->update_record('quiz_overview_regrades', $regraderecord);
        }

        return question_attempt::KEEP;
    }

    public function process_graderunavailable(question_attempt_pending_step $pendingstep) {
        global $DB;

        $processdbid = $pendingstep->get_qt_var('gradeprocessdbid');
        $exists = $DB->record_exists('qtype_moopt_gradeprocesses', ['id' => $processdbid]);
        if(!$exists){
            //It´s a regrade, discard this old step.
            return question_attempt::DISCARD;
        }

        $pendingstep->set_state(question_state::$needsgrading);

        return question_attempt::KEEP;
    }

    /**
     * Got the most recently graded step. This is mainly intended for use by the
     * renderer.
     * @return question_attempt_step the most recently graded step.
     */
    public function get_graded_step() {
        $step = $this->qa->get_last_step_with_behaviour_var('_try');
        if ($step->has_behaviour_var('_try')) {
            return $step;
        } else {
            return null;
        }
    }

    /**
     * Determine whether a question state represents an "improvable" result,
     * that is, whether the user can still improve their score.
     *
     * @param question_state $state the question state.
     * @return bool whether the state is improvable
     */
    public function is_state_improvable(question_state $state) {
        return $state == question_state::$todo;
    }

    /**
     * @return qbehaviour_adaptivemoopt_mark_details the information about the current state-of-play, scoring-wise,
     * for this adaptive attempt.
     */
    public function get_adaptive_marks() {

        // Try to find the last graded step.
        $gradedstep = $this->get_graded_step();
        if (is_null($gradedstep) || $this->qa->get_max_mark() == 0) {
            // No score yet.
            return new qbehaviour_adaptivemoopt_mark_details(question_state::$todo);
        }

        // Work out the applicable state.
        if ($this->qa->get_state()->is_commented()) {
            $state = $this->qa->get_state();
        } else {
            $state = question_state::graded_state_for_fraction(
                $gradedstep->get_behaviour_var('_rawfraction'));
        }

        // Prepare the grading details.
        $details = $this->adaptive_mark_details_from_step($gradedstep, $state, $this->qa->get_max_mark(), $this->question->penalty);
        $details->improvable = $this->is_state_improvable($this->qa->get_state());
        return $details;
    }

    /**
     * Actually populate the qbehaviour_adaptivemoopt_mark_details object.
     * @param question_attempt_step $gradedstep the step that holds the relevant mark details.
     * @param question_state $state the state corresponding to $gradedstep.
     * @param unknown_type $maxmark the maximum mark for this question_attempt.
     * @param unknown_type $penalty the penalty for this question, as a fraction.
     */
    protected function adaptive_mark_details_from_step(question_attempt_step $gradedstep,
                                                       question_state $state, $maxmark, $penalty) {

        $details = new qbehaviour_adaptivemoopt_mark_details($state);
        $details->maxmark    = $maxmark;
        $details->actualmark = $gradedstep->get_fraction() * $details->maxmark;
        $details->rawmark    = $gradedstep->get_behaviour_var('_rawfraction') * $details->maxmark;

        $details->currentpenalty = $penalty * $details->maxmark;
        $details->totalpenalty   = $details->currentpenalty * $this->qa->get_last_behaviour_var('_try', 0);

        $details->improvable = $this->is_state_improvable($gradedstep->get_state());

        return $details;
    }
}


/**
 * This class encapsulates all the information about the current state-of-play
 * scoring-wise. It is used to communicate between the beahviour and the renderer.
 *
 * @copyright  2012 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_adaptivemoopt_mark_details {
    /** @var question_state the current state of the question. */
    public $state;

    /** @var float the maximum mark for this question. */
    public $maxmark;

    /** @var float the current mark for this question. */
    public $actualmark;

    /** @var float the raw mark for this question before penalties were applied. */
    public $rawmark;

    /** @var float the the amount of additional penalty this attempt attracted. */
    public $currentpenalty;

    /** @var float the total that will apply to future attempts. */
    public $totalpenalty;

    /** @var bool whether it is possible for this mark to be improved in future. */
    public $improvable;

    /**
     * Constructor.
     * @param question_state $state
     */
    public function __construct($state, $maxmark = null, $actualmark = null, $rawmark = null,
                                $currentpenalty = null, $totalpenalty = null, $improvable = null) {
        $this->state          = $state;
        $this->maxmark        = $maxmark;
        $this->actualmark     = $actualmark;
        $this->rawmark        = $rawmark;
        $this->currentpenalty = $currentpenalty;
        $this->totalpenalty   = $totalpenalty;
        $this->improvable     = $improvable;
    }

    /**
     * Get the marks, formatted to a certain number of decimal places, in the
     * form required by calls like get_string('gradingdetails', 'qbehaviour_adaptive', $a).
     * @param int $markdp the number of decimal places required.
     * @return array ready to substitute into language strings.
     */
    public function get_formatted_marks($markdp) {
        return array(
            'max'          => format_float($this->maxmark,        $markdp),
            'cur'          => format_float($this->actualmark,     $markdp),
            'raw'          => format_float($this->rawmark,        $markdp),
            'penalty'      => format_float($this->currentpenalty, $markdp),
            'totalpenalty' => format_float($this->totalpenalty,   $markdp),
        );
    }
}

