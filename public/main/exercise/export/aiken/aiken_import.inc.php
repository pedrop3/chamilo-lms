<?php

/* For licensing terms, see /license.txt */

/**
 * Library for the import of Aiken format.
 *
 * @author claro team <cvs@claroline.net>
 * @author Guillaume Lederer <guillaume@claroline.net>
 * @author César Perales <cesar.perales@gmail.com> Parse function for Aiken format
 */

use Chamilo\CoreBundle\Component\Utils\ActionIcon;

/**
 * This function displays the form for import of the zip file with qti2.
 *
 * @param   string  Report message to show in case of error
 */
function aiken_display_form()
{
    $name_tools = get_lang('Import Aiken quiz');
    $form = '<div class="actions">';
    $form .= '<a href="exercise.php?show=test&'.api_get_cidreq().'">'.
        Display::getMdiIcon(ActionIcon::BACK, 'ch-tool-icon', null, ICON_SIZE_MEDIUM, get_lang('Back to Tests tool')).'</a>';
    $form .= '</div>';
    $form_validator = new FormValidator(
        'aiken_upload',
        'post',
        api_get_self().'?'.api_get_cidreq(),
        null,
        ['enctype' => 'multipart/form-data']
    );
    $form_validator->addElement('header', $name_tools);
    $form_validator->addElement('text', 'total_weight', get_lang('Total weight'));
    $form_validator->addElement('file', 'userFile', get_lang('File'));
    $form_validator->addButtonUpload(get_lang('Upload'), 'submit');
    $form .= $form_validator->returnForm();
    $form .= '<blockquote>'.get_lang('Import Aiken quizExplanation').'<br /><pre>'.get_lang('Import Aiken quizExplanationExample').'</pre></blockquote>';
    echo $form;
}

/**
 * Main function to import the Aiken exercise.
 *
 * @param string $file
 *
 * @return mixed True on success, error message on failure
 */
function aiken_import_exercise($file)
{
    // set some default values for the new exercise
    $exercise_info = [];
    $exercise_info['name'] = preg_replace('/.(txt)$/i', '', $file);
    $exercise_info['question'] = [];

    // if file is not a .zip, then we cancel all
    if (!preg_match('/.(zip)$/i', $file)) {
        return 'You must upload a .txt file';
    }

    $file_found = false;
    $operation = false;
    $result = aiken_parse_file($exercise_info, $file);

    if (true !== $result) {
        return $result;
    }

    // 1. Create exercise
    $exercise = new Exercise();
    $exercise->exercise = $exercise_info['name'];
    $exercise->save();
    $last_exercise_id = $exercise->getId();
    $tableQuestion = Database::get_course_table(TABLE_QUIZ_QUESTION);
    $tableAnswer = Database::get_course_table(TABLE_QUIZ_ANSWER);
    if (!empty($last_exercise_id)) {
        $courseId = api_get_course_int_id();
        foreach ($exercise_info['question'] as $key => $question_array) {
            // 2. Create question.
            $question = new Aiken2Question();
            $question->type = $question_array['type'];
            $question->setAnswer();
            $question->updateTitle($question_array['title']);

            if (isset($question_array['description'])) {
                $question->updateDescription($question_array['description']);
            }
            $type = $question->selectType();
            $question->type = constant($type);
            $question->save($exercise);
            $last_question_id = $question->getId();

            // 3. Create answer
            $answer = new Answer($last_question_id, $courseId, $exercise, false);
            $answer->new_nbrAnswers = count($question_array['answer']);
            $max_score = 0;

            $scoreFromFile = 0;
            if (isset($question_array['score']) && !empty($question_array['score'])) {
                $scoreFromFile = $question_array['score'];
            }

            foreach ($question_array['answer'] as $key => $answers) {
                $key++;
                $answer->new_answer[$key] = $answers['value'];
                $answer->new_position[$key] = $key;
                $answer->new_comment[$key] = '';
                // Correct answers ...
                if (isset($question_array['correct_answers']) &&
                    in_array($key, $question_array['correct_answers'])
                ) {
                    $answer->new_correct[$key] = 1;
                    if (isset($question_array['feedback'])) {
                        $answer->new_comment[$key] = $question_array['feedback'];
                    }
                } else {
                    $answer->new_correct[$key] = 0;
                }

                if (isset($question_array['weighting'][$key - 1])) {
                    $answer->new_weighting[$key] = $question_array['weighting'][$key - 1];
                    $max_score += $question_array['weighting'][$key - 1];
                }

                if (!empty($scoreFromFile) && $answer->new_correct[$key]) {
                    $answer->new_weighting[$key] = $scoreFromFile;
                }

                $params = [
                    'c_id' => $courseId,
                    'question_id' => $last_question_id,
                    'answer' => $answer->new_answer[$key],
                    'correct' => $answer->new_correct[$key],
                    'comment' => $answer->new_comment[$key],
                    'ponderation' => isset($answer->new_weighting[$key]) ? $answer->new_weighting[$key] : '',
                    'position' => $answer->new_position[$key],
                    'hotspot_coordinates' => '',
                    'hotspot_type' => '',
                ];

                $answerId = Database::insert($tableAnswer, $params);
                if ($answerId) {
                    $params = [
                        'iid' => $answerId,
                    ];
                    Database::update($tableAnswer, $params, ['iid = ?' => [$answerId]]);
                }
            }

            if (!empty($scoreFromFile)) {
                $max_score = $scoreFromFile;
            }

            $params = ['ponderation' => $max_score];
            Database::update(
                $tableQuestion,
                $params,
                ['iid = ?' => [$last_question_id]]
            );
        }

        $operation = $last_exercise_id;
    }

    return $operation;
}

/**
 * Parses an Aiken file and builds an array of exercise + questions to be
 * imported by the import_exercise() function.
 *
 * @param array The reference to the array in which to store the questions
 * @param string Path to the directory with the file to be parsed (without final /)
 * @param string Name of the last directory part for the file (without /)
 * @param string Name of the file to be parsed (including extension)
 * @param string $exercisePath
 * @param string $file
 * @param string $questionFile
 *
 * @return string|bool True on success, error message on error
 * @assert ('','','') === false
 */
function aiken_parse_file(&$exercise_info, $file)
{
    if (!is_file($file)) {
        return 'FileNotFound';
    }

    $text = file_get_contents($file);
    $detect = mb_detect_encoding($text, 'ASCII', true);
    if ('ASCII' === $detect) {
        $data = explode("\n", $text);
    } else {
        $text = str_ireplace(["\x0D", "\r\n"], "\n", $text); // Removes ^M char from win files.
        $data = explode("\n\n", $text);
    }

    $question_index = 0;
    $answers_array = [];
    foreach ($data as $line => $info) {
        $info = trim($info);
        if (empty($info)) {
            continue;
        }
        //make sure it is transformed from iso-8859-1 to utf-8 if in that form
        if (!mb_check_encoding($info, 'utf-8') && mb_check_encoding($info, 'iso-8859-1')) {
            $info = utf8_encode($info);
        }
        $exercise_info['question'][$question_index]['type'] = 'MCUA';
        if (preg_match('/^([A-Za-z])(\)|\.)\s(.*)/', $info, $matches)) {
            //adding one of the possible answers
            $exercise_info['question'][$question_index]['answer'][]['value'] = $matches[3];
            $answers_array[] = $matches[1];
        } elseif (preg_match('/^ANSWER:\s?([A-Z])\s?/', $info, $matches)) {
            //the correct answers
            $correct_answer_index = array_search($matches[1], $answers_array);
            $exercise_info['question'][$question_index]['correct_answers'][] = $correct_answer_index + 1;
            //weight for correct answer
            $exercise_info['question'][$question_index]['weighting'][$correct_answer_index] = 1;
            $next = $line + 1;

            if (false !== strpos($data[$next], 'ANSWER_EXPLANATION:')) {
                continue;
            }

            if (false !== strpos($data[$next], 'DESCRIPTION:')) {
                continue;
            }
            // Check if next has score, otherwise loop too next question.
            if (false === strpos($data[$next], 'SCORE:')) {
                $answers_array = [];
                $question_index++;
                continue;
            }
        } elseif (preg_match('/^SCORE:\s?(.*)/', $info, $matches)) {
            $exercise_info['question'][$question_index]['score'] = (float) $matches[1];
            $answers_array = [];
            $question_index++;
            continue;
        } elseif (preg_match('/^DESCRIPTION:\s?(.*)/', $info, $matches)) {
            $exercise_info['question'][$question_index]['description'] = $matches[1];
            $next = $line + 1;

            if (false !== strpos($data[$next], 'ANSWER_EXPLANATION:')) {
                continue;
            }
            // Check if next has score, otherwise loop too next question.
            if (false === strpos($data[$next], 'SCORE:')) {
                $answers_array = [];
                $question_index++;
                continue;
            }
        } elseif (preg_match('/^ANSWER_EXPLANATION:\s?(.*)/', $info, $matches)) {
            //Comment of correct answer
            $correct_answer_index = array_search($matches[1], $answers_array);
            $exercise_info['question'][$question_index]['feedback'] = $matches[1];
            $next = $line + 1;
            // Check if next has score, otherwise loop too next question.
            if (false === strpos($data[$next], 'SCORE:')) {
                $answers_array = [];
                $question_index++;
                continue;
            }
        } elseif (preg_match('/^TEXTO_CORRECTA:\s?(.*)/', $info, $matches)) {
            //Comment of correct answer (Spanish e-ducativa format)
            $correct_answer_index = array_search($matches[1], $answers_array);
            $exercise_info['question'][$question_index]['feedback'] = $matches[1];
        } elseif (preg_match('/^T:\s?(.*)/', $info, $matches)) {
            //Question Title
            $correct_answer_index = array_search($matches[1], $answers_array);
            $exercise_info['question'][$question_index]['title'] = $matches[1];
        } elseif (preg_match('/^TAGS:\s?([A-Z])\s?/', $info, $matches)) {
            //TAGS for chamilo >= 1.10
            $exercise_info['question'][$question_index]['answer_tags'] = explode(',', $matches[1]);
        } elseif (preg_match('/^ETIQUETAS:\s?([A-Z])\s?/', $info, $matches)) {
            //TAGS for chamilo >= 1.10 (Spanish e-ducativa format)
            $exercise_info['question'][$question_index]['answer_tags'] = explode(',', $matches[1]);
        } elseif (empty($info)) {
            /*if (empty($exercise_info['question'][$question_index]['title'])) {
                $exercise_info['question'][$question_index]['title'] = $info;
            }
            //moving to next question (tolerate \r\n or just \n)
            if (empty($exercise_info['question'][$question_index]['correct_answers'])) {
                error_log('Aiken: Error in question index '.$question_index.': no correct answer defined');

                return 'ExerciseAikenErrorNoCorrectAnswerDefined';
            }
            if (empty($exercise_info['question'][$question_index]['answer'])) {
                error_log('Aiken: Error in question index '.$question_index.': no answer option given');

                return 'ExerciseAikenErrorNoAnswerOptionGiven';
            }
            $question_index++;
            //emptying answers array when moving to next question
            $answers_array = [];
        } else {
            if (empty($exercise_info['question'][$question_index]['title'])) {
                $exercise_info['question'][$question_index]['title'] = $info;
            }
            /*$question_index++;
            //emptying answers array when moving to next question
            $answers_array = [];
            $new_question = true;*/
        }
    }
    $total_questions = count($exercise_info['question']);
    $total_weight = !empty($_POST['total_weight']) ? (int) ($_POST['total_weight']) : 20;
    foreach ($exercise_info['question'] as $key => $question) {
        if (!isset($exercise_info['question'][$key]['weighting'])) {
            continue;
        }
        $exercise_info['question'][$key]['weighting'][current(array_keys($exercise_info['question'][$key]['weighting']))] = $total_weight / $total_questions;
    }

    return true;
}

/**
 * Imports the zip file.
 *
 * @param array $array_file ($_FILES)
 *
 * @return bool
 */
function aiken_import_file($array_file)
{
    $unzip = 0;
    $process = process_uploaded_file($array_file, false);
    if (preg_match('/\.(zip|txt)$/i', $array_file['name'])) {
        // if it's a zip, allow zip upload
        $unzip = 1;
    }

    if ($process && 1 == $unzip) {
        $imported = aiken_import_exercise($array_file['name']);
        if (is_numeric($imported) && !empty($imported)) {
            Display::addFlash(Display::return_message(get_lang('Uploaded.')));

            return $imported;
        } else {
            Display::addFlash(Display::return_message(get_lang($imported), 'error'));

            return false;
        }
    }
}
