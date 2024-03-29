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
 * Called from the block for the replay stage.
 *
 * @package block_behaviour
 * @author Ted Krahn
 * @copyright 2019 Athabasca University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__.'/../../config.php');
require_once("$CFG->dirroot/blocks/behaviour/locallib.php");

defined('MOODLE_INTERNAL') || die();

$id = required_param('id', PARAM_INT);
$shownames = required_param('names', PARAM_INT);
$uselsa = required_param('uselsa', PARAM_INT);

$course = get_course($id);
require_login($course);

$context = context_course::instance($course->id);
require_capability('block/behaviour:view', $context);

// Was script called with course id where plugin is not installed?
if (!block_behaviour_is_installed($course->id)) {

    redirect(new moodle_url('/course/view.php', array('id' => $course->id)));
    die();
}
$installed = $DB->get_record('block_behaviour_installed', array('courseid' => $course->id));

// Trigger a behaviour analytics viewed event.
$event = \block_behaviour\event\behaviour_viewed::create(array('context' => $context));
$event->trigger();

// Some values needed here.
$debugcentroids = false;
$version36 = $CFG->version >= 2018120310 ? true : false;
$version40 = intval($CFG->branch) >= 400 ? true : false;
$panelwidth = 40;
$legendwidth = 180;
$globallogs = null;
$globalclusterid = null;
$globalmembers = null;

// Get modules and node positions.
list($mods, $modids) = block_behaviour_get_course_info($course);

$replay = [];
$manual = [];
$users = [];
$isresearcher = false;

// If user is researcher, get all users for this course.
if (get_config('block_behaviour', 'c_'.$course->id.'_p_'.$USER->id)) {
    $isresearcher = true;

    $userids = $DB->get_records('block_behaviour_clusters', array(
        'courseid' => $course->id
    ), '', 'distinct userid');

    foreach ($userids as $user) {
        $users[] = $user->userid;
    }

} else {
    $users = [ $USER->id ];
}

for ($i = 0; $i < count($users); $i++) {

    $params = array(
        'courseid' => $course->id,
        'userid' => $users[$i],
    );

    // Get all clustering data for this data set.
    $coordids = $DB->get_records('block_behaviour_clusters', $params, '', 'distinct coordsid');

    $dataset = $users[$i].'-'.$course->id;
    $replay[$dataset] = [];
    $manual[$dataset] = [];

    foreach ($coordids as $coords) {

        $coordid = $coords->coordsid;
        list($coordsid, $scale, $nodes, $numnodes, $links, $islsa) =
            block_behaviour_get_graph_data($coordid, $users[$i], $course, $mods, $modids);

        // Not always nodes when using LORD generated graph. This happens
        // because the coord ids are pulled from the clusters table, which
        // may have data from graphs generated by both Behaviour Analytics and LORD graphs.
        if (count($nodes) == 0) {
            continue;
        }

        list($logs, $userinfo) = block_behaviour_get_log_data($nodes, $course, $globallogs);
        block_behaviour_check_got_all_mods($mods, $nodes, $modids);

        $replay[$dataset][$coordid] = array(
            'mods' => $mods,
            'nodes' => $nodes,
            'links' => $links,
            'scale' => $scale,
            'last' => $coordsid,
            'logs' => $logs,
            'users' => $userinfo,
            'islsa' => $islsa,
        );

        // Get all clustering data for this data set.
        $clusters = $DB->get_records('block_behaviour_clusters', array(
            'courseid' => $course->id,
            'userid' => $users[$i],
            'coordsid' => $coordid
        ), 'clusterid, iteration, clusternum');

        // For each clustering run, get the necessary data.
        foreach ($clusters as $run) {

            $members = block_behaviour_get_members($coordid, $run->clusterid, 'block_behaviour_members',
            $users[$i], $course, $globalclusterid, $globalmembers);

            if (! isset($replay[$dataset][$coordid][$run->clusterid])) {
                $replay[$dataset][$coordid][$run->clusterid] = array(
                    'comments' => block_behaviour_get_comment_data($coordsid, $run->clusterid, $users[$i], $course)
                );
            }
            if (! isset($replay[$dataset][$coordid][$run->clusterid][$run->iteration])) {
                $replay[$dataset][$coordid][$run->clusterid][$run->iteration] = [];
            }

            $thesemembers = [];
            if (isset($members[$run->iteration]) && isset($members[$run->iteration][$run->clusternum])) {
                $thesemembers = $members[$run->iteration][$run->clusternum];
            }

            $replay[$dataset][$coordid][$run->clusterid][$run->iteration][$run->clusternum] = array(
                'centroidx' => $run->centroidx,
                'centroidy' => $run->centroidy,
                'members' => $thesemembers
            );
        } // End for each clusters.

        unset($run);
        $globalclusterid = null;
        $globalmembers = [];

        $manual[$dataset][$coordid] = [];

        // Get all clustering data for this data set.
        $clusters = $DB->get_records('block_behaviour_man_clusters', array(
            'courseid' => $course->id,
            'userid' => $users[$i],
            'coordsid' => $coordid
        ), 'clusterid, iteration, clusternum');

        // For each clustering run, get the necessary data.
        foreach ($clusters as $run) {

            $members = block_behaviour_get_members($coordid, $run->clusterid, 'block_behaviour_man_members',
            $users[$i], $course, $globalclusterid, $globalmembers);

            if (! isset($manual[$dataset][$coordid][$run->clusterid])) {
                $manual[$dataset][$coordid][$run->clusterid] = [];
            }
            if (! isset($manual[$dataset][$coordid][$run->clusterid][$run->iteration])) {
                $manual[$dataset][$coordid][$run->clusterid][$run->iteration] = [];
            }

            $thesemembers = [];
            if (isset($members[$run->iteration]) && isset($members[$run->iteration][$run->clusternum])) {
                $thesemembers = $members[$run->iteration][$run->clusternum];
            }

            $manual[$dataset][$coordid][$run->clusterid][$run->iteration][$run->clusternum] = array(
                'centroidx' => $run->centroidx,
                'centroidy' => $run->centroidy,
                'members' => $thesemembers
            );
        } // End for each clusters.
    } // End for each coordsids.
} // End for each user.

if (!get_config('block_behaviour', 'allowshownames')) {
    $shownames = get_config('block_behaviour', 'shownames') ? 1 : 0;
}
// Combine all data and send to client program.
$out = array(
    'logs' => [],
    'users' => [ array('id' => 0) ],
    'mods' => [],
    'panelwidth' => $panelwidth,
    'legendwidth' => $legendwidth,
    'name' => get_string('clusterreplay', 'block_behaviour'),
    'nodecoords' => [],
    'links' => [],
    'userid' => $USER->id,
    'courseid' => $course->id,
    'replaying' => true,
    'replaydata' => $replay,
    'manualdata' => $manual,
    'gotallnodes' => true,
    'isresearcher' => $isresearcher,
    'debugcentroids' => $debugcentroids,
    'strings' => block_behaviour_get_lang_strings(),
    'sesskey' => sesskey(),
    'version36' => $version36,
    'version40' => $version40,
    'iframeurl' => (string) new moodle_url('/'),
    'coordsscript' => (string) new moodle_url('/blocks/behaviour/update-coords.php'),
    'clustersscript' => (string) new moodle_url('/blocks/behaviour/update-clusters.php'),
    'commentsscript' => (string) new moodle_url('/blocks/behaviour/update-comments.php'),
    'manualscript' => (string) new moodle_url('/blocks/behaviour/update-manual-clusters.php'),
    'deletescript' => (string) new moodle_url('/blocks/behaviour/delete-cluster-data.php'),
    'predictionscript' => (string) new moodle_url('/blocks/behaviour/update-prediction.php'),
    'showstudentnames' => $shownames,
    'predictionanalysis' => $installed->prediction,
);

if ($debugcentroids) {
    $out['centroids'] = block_behaviour_get_centroids($course->id, $coordsid);
}

// Set up the page.
$PAGE->set_url('/blocks/behaviour/replay.php', array(
    'id' => $course->id,
    'names' => $shownames,
    'uselsa' => $uselsa
));
$PAGE->set_title(get_string('title', 'block_behaviour'));

// JavaScript.
$PAGE->requires->js_call_amd('block_behaviour/modules', 'init');
$PAGE->requires->js_init_call('waitForModules', array($out), true);
$PAGE->requires->js('/blocks/behaviour/javascript/main.js');

// Finish setting up page.
$PAGE->set_pagelayout('base');
$PAGE->set_heading($course->fullname);

// Output page.
echo $OUTPUT->header();

echo html_writer::table(block_behaviour_get_html_table($panelwidth, $legendwidth, $shownames, $uselsa));

echo $OUTPUT->footer();
