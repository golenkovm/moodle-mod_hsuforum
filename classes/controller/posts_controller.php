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
 * View Posters Controller
 *
 * @package    mod
 * @subpackage hsuforum
 * @copyright  Copyright (c) 2012 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @author     Mark Nielsen
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_hsuforum\controller;

use coding_exception;
use mod_hsuforum\response\json_response;
use mod_hsuforum\service\discussion_service;
use mod_hsuforum\service\post_service;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/controller_abstract.php');

class posts_controller extends controller_abstract {
    /**
     * @var post_service
     */
    protected $postservice;

    /**
     * @var discussion_service
     */
    protected $discussionservice;

    public function init($action) {
        parent::init($action);

        require_once(dirname(__DIR__).'/response/json_response.php');
        require_once(dirname(__DIR__).'/service/post_service.php');
        require_once(dirname(__DIR__).'/service/discussion_service.php');
        require_once(dirname(dirname(__DIR__)).'/lib.php');

        $this->discussionservice = new discussion_service();
        $this->postservice = new post_service($this->discussionservice);
    }

    /**
     * Do any security checks needed for the passed action
     *
     * @param string $action
     */
    public function require_capability($action) {
        global $PAGE;

        switch ($action) {
            case 'discsubscribers':
                if (!has_capability('mod/hsuforum:viewsubscribers', $PAGE->context)) {
                    print_error('nopermissiontosubscribe', 'hsuforum');
                }
                break;
            default:
                require_capability('mod/hsuforum:viewdiscussion', $PAGE->context, null, true, 'noviewdiscussionspermission', 'hsuforum');
        }
    }

    /**
     * Get post nodes
     */
    public function postnodes_action() {
        global $PAGE, $DB, $CFG, $USER;

        if (!AJAX_SCRIPT) {
            throw new coding_exception('This is an AJAX action and you cannot access it directly');
        }
        $discussionid = required_param('discussionid', PARAM_INT);
        $discussion   = $DB->get_record('hsuforum_discussions', array('id' => $discussionid), '*', MUST_EXIST);
        $forum        = $PAGE->activityrecord;
        $cm           = $PAGE->cm;

        if ($forum->type == 'news') {
            if (!($USER->id == $discussion->userid || (($discussion->timestart == 0
                || $discussion->timestart <= time())
                && ($discussion->timeend == 0 || $discussion->timeend > time())))) {
                print_error('invaliddiscussionid', 'hsuforum', "$CFG->wwwroot/mod/hsuforum/view.php?f=$forum->id");
            }
        }
        if (!$post = hsuforum_get_post_full($discussion->firstpost)) {
            print_error("notexists", 'hsuforum', "$CFG->wwwroot/mod/hsuforum/view.php?f=$forum->id");
        }
        if (!hsuforum_user_can_see_post($forum, $discussion, $post, null, $cm)) {
            print_error('nopermissiontoview', 'hsuforum', "$CFG->wwwroot/mod/hsuforum/view.php?f=$forum->id");
        }

        $forumtracked = hsuforum_tp_is_tracked($forum);
        $posts        = hsuforum_get_all_discussion_posts($discussion->id, hsuforum_get_layout_mode_sort(HSUFORUM_MODE_NESTED), $forumtracked);
        $nodes        = array();

        if (!empty($posts[$post->id]) and !empty($posts[$post->id]->children)) {
            foreach ($posts[$post->id]->children as $post) {
                if ($node = $this->renderer->post_to_node($cm, $discussion, $post)) {
                    $nodes[] = $node;
                }
            }
        }
        echo json_encode($nodes);
    }

    /**
     * Gets nested post HTML
     *
     * @throws coding_exception
     */
    public function postsnested_action() {
        if (!AJAX_SCRIPT) {
            throw new coding_exception('This is an AJAX action and you cannot access it directly');
        }
        $discussionid = required_param('discussionid', PARAM_INT);

        list($cm, $discussion, $posts, $canreply) = $this->discussionservice->get_posts($discussionid);

        $output = '';
        if (!empty($posts[$discussion->firstpost]) and !empty($posts[$discussion->firstpost]->children)) {
            foreach ($posts[$discussion->firstpost]->children as $post) {
                if ($postoutput = $this->renderer->nested_post($cm, $discussion, $post, $canreply)) {
                    $output .= $postoutput;
                }
            }
        }
        echo json_encode((object) array(
            'html' => $output,
        ));
    }

    /**
     * @throws coding_exception
     */
    public function posts_html_action() {
        global $PAGE;

        if (!AJAX_SCRIPT) {
            throw new coding_exception('This is an AJAX action and you cannot access it directly');
        }
        $discussionid  = required_param('discussionid', PARAM_INT);
        $displayformat = get_user_preferences('hsuforum_displayformat', 'header');

        /** @var $renderer \mod_hsuforum\render_interface */
        $renderer = $PAGE->get_renderer('mod_hsuforum', $displayformat);

        list($cm, $discussion, $posts, $canreply) = $this->discussionservice->get_posts($discussionid);

        echo json_encode((object) array(
            'html' => $renderer->posts($cm, $discussion, $posts, $canreply),
        ));
    }

    public function discussion_html_action() {
        if (!AJAX_SCRIPT) {
            throw new coding_exception('This is an AJAX action and you cannot access it directly');
        }
        $discussionid = required_param('discussionid', PARAM_INT);

        echo json_encode((object) array(
            'html' => $this->discussionservice->render_discussion($discussionid),
        ));
    }

    /**
     * Load another page of discussions
     *
     * @throws \coding_exception
     */
    public function discussions_html_action() {
        global $PAGE;

        require_once(dirname(dirname(__DIR__)).'/lib/discussion/sort.php');

        if (!AJAX_SCRIPT) {
            throw new coding_exception('This is an AJAX action and you cannot access it directly');
        }
        $page          = required_param('page', PARAM_INT);
        $perpage       = required_param('perpage', PARAM_INT);
        $displayformat = get_user_preferences('hsuforum_displayformat', 'header');

        /** @var $renderer \mod_hsuforum\render_interface */
        $renderer = $PAGE->get_renderer('mod_hsuforum', $displayformat);
        $dsort    = \hsuforum_lib_discussion_sort::get_from_session($PAGE->activityrecord, $PAGE->context);
        $posts    = hsuforum_get_discussions($PAGE->cm, $dsort->get_sort_sql(), true, -1, false, $page, $perpage, false);
        hsuforum_get_ratings_for_posts($PAGE->context, $PAGE->activityrecord, $posts);

        $output = '';
        foreach ($posts as $post) {
            $output .= $renderer->discussion(
                $PAGE->cm, hsuforum_extract_discussion($post, $PAGE->activityrecord), $post
            );
        }

        echo json_encode((object) array(
            'html' => $output,
        ));
    }

    /**
     * Add a reply to a post
     *
     * Since we are uploading files to this action using
     * YUI, then we cannot natively detect it is an AJAX
     * request because it is going through an iframe.  This
     * allows for uploading of files.
     *
     * We must still ensure a JSON response.
     *
     * @return json_response
     */
    public function reply_action() {
        global $DB, $PAGE;

        try {
            require_sesskey();

            $reply         = required_param('reply', PARAM_INT);
            $subject       = trim(optional_param('subject', '', PARAM_TEXT));
            $privatereply  = optional_param('privatereply', 0, PARAM_BOOL);
            $message       = required_param('message', PARAM_RAW_TRIMMED);
            $messageformat = required_param('messageformat', PARAM_INT);

            $forum   = $PAGE->activityrecord;
            $cm      = $PAGE->cm;
            $context = $PAGE->context;
            $course  = $PAGE->course;

            $parent     = $DB->get_record('hsuforum_posts', array('id' => $reply), '*', MUST_EXIST);
            $discussion = $DB->get_record('hsuforum_discussions', array('id' => $parent->discussion, 'forum' => $forum->id), '*', MUST_EXIST);

            // If private reply, then map it to the parent author user ID.
            if (!empty($privatereply)) {
                $privatereply = $parent->userid;
            }
            $data = array(
                'privatereply'  => $privatereply,
                'message'       => $message,
                'messageformat' => $messageformat,
            );
            if (!empty($subject)) {
                $data['subject'] = $subject;
            }
            return $this->postservice->handle_reply($course, $cm, $forum, $context, $discussion, $parent, $data);
        } catch (\Exception $e) {
            return new json_response($e);
        }
    }

    /**
     * Add a discussion
     *
     * Since we are uploading files to this action using
     * YUI, then we cannot natively detect it is an AJAX
     * request because it is going through an iframe.  This
     * allows for uploading of files.
     *
     * We must still ensure a JSON response.
     *
     * @return json_response
     */
    public function add_discussion_action() {
        global $PAGE;

        try {
            require_sesskey();

            $subject       = required_param('subject', PARAM_TEXT);
            $groupid       = optional_param('groupinfo', 0, PARAM_INT);
            $message       = required_param('message', PARAM_RAW_TRIMMED);
            $messageformat = required_param('messageformat', PARAM_INT);

            $forum   = $PAGE->activityrecord;
            $cm      = $PAGE->cm;
            $context = $PAGE->context;
            $course  = $PAGE->course;

            if (empty($groupid)) {
                $groupid = -1;
            }
            return $this->discussionservice->handle_add_discussion($course, $cm, $forum, $context, array(
                'subject'       => $subject,
                'name'          => $subject,
                'groupid'       => $groupid,
                'message'       => $message,
                'messageformat' => $messageformat,
            ));
        } catch (\Exception $e) {
            return new json_response($e);
        }
    }

    /**
     * @return json_response
     * @throws \coding_exception
     */
    public function delete_post_action() {
        global $DB, $PAGE;

        if (!AJAX_SCRIPT) {
            throw new coding_exception('This is an AJAX action and you cannot access it directly');
        }
        require_sesskey();

        $postid = required_param('postid', PARAM_INT);

        $post       = $DB->get_record('hsuforum_posts', array('id' => $postid), '*', MUST_EXIST);
        $discussion = $DB->get_record('hsuforum_discussions', array("id" => $post->discussion), '*', MUST_EXIST);

        $redirect = hsuforum_verify_and_delete_post($PAGE->course, $PAGE->cm,
            $PAGE->activityrecord, $PAGE->context, $discussion, $post);

        $html = '';
        if ($discussion->firstpost != $post->id) {
            $html = $this->discussionservice->render_discussion($discussion->id);
        }
        return new json_response(array(
            'redirecturl' => $redirect,
            'html' => $html,
            'postid' => $post->id,
            'livelog' => get_string('postdeleted', 'hsuforum'),
            'discussionid' => $discussion->id,
        ));
    }

    /**
     * Prints a discussion
     */
    public function postincontext_action() {
        global $PAGE, $DB, $CFG, $USER;

        if (!AJAX_SCRIPT) {
            throw new coding_exception('This is an AJAX action and you cannot access it directly');
        }
        $postid = required_param('postid', PARAM_INT);
        $forum  = $PAGE->activityrecord;
        $cm     = $PAGE->cm;

        if (!$focuspost = hsuforum_get_post_full($postid)) {
            print_error("notexists", 'hsuforum', "$CFG->wwwroot/mod/hsuforum/view.php?f=$forum->id");
        }
        $discussion = $DB->get_record('hsuforum_discussions', array('id' => $focuspost->discussion), '*', MUST_EXIST);

        if ($forum->type == 'news') {
            if (!($USER->id == $discussion->userid || (($discussion->timestart == 0
                || $discussion->timestart <= time())
                && ($discussion->timeend == 0 || $discussion->timeend > time())))) {
                print_error('invaliddiscussionid', 'hsuforum', "$CFG->wwwroot/mod/hsuforum/view.php?f=$forum->id");
            }
        }
        if (!$post = hsuforum_get_post_full($discussion->firstpost)) {
            print_error("notexists", 'hsuforum', "$CFG->wwwroot/mod/hsuforum/view.php?f=$forum->id");
        }
        if (!hsuforum_user_can_see_post($forum, $discussion, $post, null, $cm)) {
            print_error('nopermissiontoview', 'hsuforum', "$CFG->wwwroot/mod/hsuforum/view.php?f=$forum->id");
        }

        $posts  = hsuforum_get_all_discussion_posts($discussion->id, hsuforum_get_layout_mode_sort(HSUFORUM_MODE_NESTED), false);
        $output = $this->renderer->post_in_context($cm, $discussion, $posts[$post->id]);

        echo json_encode((object) array(
            'html' => \html_writer::tag('div', $output, array('class' => 'mod_hsuforum_posts_container')),
        ));
    }

    /**
     * Marks a post as read
     *
     * @throws coding_exception
     */
    public function markread_action() {
        global $PAGE, $DB, $CFG, $USER;

        if (!AJAX_SCRIPT) {
            throw new coding_exception('This is an AJAX action and you cannot access it directly');
        }
        require_once($CFG->dirroot.'/rating/lib.php');

        $postid  = required_param('postid', PARAM_INT);
        $forum   = $PAGE->activityrecord;
        $cm      = $PAGE->cm;

        if (!$post = hsuforum_get_post_full($postid)) {
            print_error("notexists", 'hsuforum', "$CFG->wwwroot/mod/hsuforum/view.php?f=$forum->id");
        }
        $discussion = $DB->get_record('hsuforum_discussions', array('id' => $post->discussion), '*', MUST_EXIST);

        if ($forum->type == 'news') {
            if (!($USER->id == $discussion->userid || (($discussion->timestart == 0
                || $discussion->timestart <= time())
                && ($discussion->timeend == 0 || $discussion->timeend > time())))) {
                print_error('invaliddiscussionid', 'hsuforum', "$CFG->wwwroot/mod/hsuforum/view.php?f=$forum->id");
            }
        }
        if (!hsuforum_user_can_see_post($forum, $discussion, $post, null, $cm)) {
            print_error('nopermissiontoview', 'hsuforum', "$CFG->wwwroot/mod/hsuforum/view.php?f=$forum->id");
        }
        if (hsuforum_tp_is_tracked($forum)) {
            hsuforum_tp_add_read_record($USER->id, $post->id);
        }
        return new json_response(array('postid' => $postid, 'discussionid' => $discussion->id));
    }

    /**
     * Discussion subscription toggle
     */
    public function subscribedisc_action() {
        global $PAGE;

        require_sesskey();

        require_once(dirname(dirname(__DIR__)).'/lib/discussion/subscribe.php');

        $discussionid = required_param('discussionid', PARAM_INT);
        $returnurl    = required_param('returnurl', PARAM_LOCALURL);

        $subscribe = new \hsuforum_lib_discussion_subscribe($PAGE->activityrecord, $PAGE->context);

        if ($subscribe->is_subscribed($discussionid)) {
            $subscribe->unsubscribe($discussionid);
        } else {
            $subscribe->subscribe($discussionid);
        }
        if (!AJAX_SCRIPT) {
            redirect(new moodle_url($returnurl));
        }
    }

    public function discsubscribers_action() {
        global $OUTPUT, $USER, $DB, $COURSE, $PAGE;

        require_once(dirname(dirname(__DIR__)).'/repository/discussion.php');
        require_once(dirname(dirname(__DIR__)).'/lib/userselector/discussion/existing.php');
        require_once(dirname(dirname(__DIR__)).'/lib/userselector/discussion/potential.php');

        $discussionid = required_param('discussionid', PARAM_INT);
        $edit         = optional_param('edit', -1, PARAM_BOOL); // Turn editing on and off.

        $url = $PAGE->url;
        $url->param('discussionid', $discussionid);
        if ($edit !== 0) {
            $url->param('edit', $edit);
        }
        $PAGE->set_url($url);

        $discussion = $DB->get_record('hsuforum_discussions', array('id' => $discussionid), '*', MUST_EXIST);
        $forum      = $PAGE->activityrecord;
        $course     = $COURSE;
        $cm         = $PAGE->cm;
        $context    = $PAGE->context;
        $repo       = new \hsuforum_repository_discussion();

        if (hsuforum_is_forcesubscribed($forum)) {
            throw new coding_exception('Cannot manage discussion subscriptions when subscription is forced');
        }

        $currentgroup = groups_get_activity_group($cm);
        $options = array('forum'=>$forum, 'discussion' => $discussion, 'currentgroup'=>$currentgroup, 'context'=>$context);
        $existingselector = new \hsuforum_userselector_discussion_existing('existingsubscribers', $options);
        $subscriberselector = new \hsuforum_userselector_discussion_potential('potentialsubscribers', $options);

        if (data_submitted()) {
            require_sesskey();
            $subscribe = (bool)optional_param('subscribe', false, PARAM_RAW);
            $unsubscribe = (bool)optional_param('unsubscribe', false, PARAM_RAW);
            /** It has to be one or the other, not both or neither */
            if (!($subscribe xor $unsubscribe)) {
                print_error('invalidaction');
            }
            if ($subscribe) {
                $users = $subscriberselector->get_selected_users();
                foreach ($users as $user) {
                    $repo->subscribe($discussion->id, $user->id);
                }
            } else if ($unsubscribe) {
                $users = $existingselector->get_selected_users();
                foreach ($users as $user) {
                    $repo->unsubscribe($discussion->id, $user->id);
                }
            }
            $subscriberselector->invalidate_selected_users();
            $existingselector->invalidate_selected_users();

            redirect($PAGE->url);
        }

        $strsubscribers = get_string('discussionsubscribers', 'hsuforum');

        // This works but it doesn't make a good navbar, would have to change the settings menu.
        // $PAGE->settingsnav->find('discsubscribers', navigation_node::TYPE_SETTING)->make_active();

        $PAGE->navbar->add(shorten_text(format_string($discussion->name)), new moodle_url('/mod/hsuforum/discuss.php', array('d' => $discussion->id)));
        $PAGE->navbar->add($strsubscribers);
        $PAGE->set_title($strsubscribers);
        $PAGE->set_heading($COURSE->fullname);
        if (has_capability('mod/hsuforum:managesubscriptions', $context)) {
            if ($edit != -1) {
                $USER->subscriptionsediting = $edit;
            }
            if (!empty($USER->subscriptionsediting)) {
                $string = get_string('turneditingoff');
                $edit = "off";
            } else {
                $string = get_string('turneditingon');
                $edit = "on";
            }
            $url = $PAGE->url;
            $url->param('edit', $edit);

            $PAGE->set_button($OUTPUT->single_button($url, $string, 'get'));
        } else {
            unset($USER->subscriptionsediting);
        }
        $output = $OUTPUT->heading($strsubscribers);
        if (empty($USER->subscriptionsediting)) {
            $output .= $this->renderer->subscriber_overview(current($existingselector->find_users('')), $discussion->name, $course);
        } else {
            $output .= $this->renderer->subscriber_selection_form($existingselector, $subscriberselector);
        }
        return $output;
    }
}
