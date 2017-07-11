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
 * Course world collection strategy.
 *
 * @package    block_xp
 * @copyright  2017 Branch Up Pty Ltd
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_xp\local\strategy;
defined('MOODLE_INTERNAL') || die();

use context;
use block_xp\local\config\config;
use block_xp\local\logger\course_user_event_collection_logger;
use block_xp\local\notification\course_level_up_notification_service;
use block_xp\local\xp\course_filter_manager;
use block_xp\local\xp\levels_info;
use block_xp\local\xp\user_state_course_store;

/**
 * Course world collection strategy.
 *
 * @package    block_xp
 * @copyright  2017 Branch Up Pty Ltd
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_world_collection_strategy implements event_collection_strategy {

    /** @var context The context. */
    protected $context;
    /** @var config The config. */
    protected $config;
    /** @var user_state_course_store The store. */
    protected $store;
    /** @var course_filter_manager The filter manager. */
    protected $filtermanager;
    /** @var course_user_event_collection_logger The logger. */
    protected $logger;
    /** @var course_level_up_notification_service The notification service. */
    protected $levelupnotifificationservice;

    /**
     * Constructor.
     *
     * @param context $context The context.
     * @param config $config The config.
     * @param user_state_course_store $store The store.
     * @param course_filter_manager $filtermanager The filter manager.
     * @param course_user_event_collection_logger $logger The logger.
     * @param course_level_up_notification_service $levelupnotifificationservice The notification service.
     */
    public function __construct(
            context $context,
            config $config,
            user_state_course_store $store,
            course_filter_manager $filtermanager,
            course_user_event_collection_logger $logger,
            course_level_up_notification_service $levelupnotifificationservice
        ) {
        $this->context = $context;
        $this->config = $config;
        $this->store = $store;
        $this->filtermanager = $filtermanager;
        $this->logger = $logger;
        $this->levelupnotifificationservice = $levelupnotifificationservice;
    }

    /**
     * Handle an event.
     *
     * @param \core\event\base $event The event.
     * @return void
     */
    public function collect_event(\core\event\base $event) {
        $userid = $event->userid;

        // Get course config.
        $config = $this->config;
        if (!$config->get('enabled')) {
            return;
        }

        // Cheatguard.
        if ($config->get('enablecheatguard') && !$this->can_capture_event($event, $config)) {
            return;
        }

        // Get XP to reward with.
        $points = $this->filtermanager->get_points_for_event($event);

        // Collect.
        // No need to go through the following if the user did not gain XP.
        if ($points > 0) {

            // TODO Implement this differently.
            $initiallevel = $this->store->get_state($userid)->get_level()->get_level();
            $this->store->increase($userid, $points);
            $level = $this->store->get_state($userid)->get_level()->get_level();

            if ($initiallevel != $level) {
                $params = array(
                    'context' => $this->context,
                    'relateduserid' => $userid,
                    'other' => array(
                        'level' => $level
                    )
                );
                $lupevent = \block_xp\event\user_leveledup::create($params);
                $lupevent->trigger();
            }

            if ($level > $initiallevel && $config->get('enablelevelupnotif')) {
                $this->levelupnotifificationservice->notify($userid);
            }
        }

        // Log collection.
        if ($config->get('enablelog')) {
            $this->logger->log_event($event, $userid, $points);
        }
    }

    /**
     * Check wether or not the user can capture this event.
     *
     * This method is there to prevent a user from refreshing a page
     * 200x times to get more experience points. For simplicity, and performance
     * reason, this does not handle multiple sessions at the same time.
     *
     * It also prevents a user from opening too many pages at the same time
     * by limiting the number of events for a given time. This might potentially lead
     * to ignoring some events in legit situations if the user is quick.
     *
     * This method has not been designed to check if the user has capabilities
     * to capture the event or not, those checks should be done in the observer
     * for performance reasons.
     *
     * @param \core\event\base $event The event.
     * @param \block_xp\local\config\config $config The config.
     * @return bool True when the event is OK.
     */
    protected function can_capture_event(\core\event\base $event, \block_xp\local\config\config $config) {
        global $SESSION;

        $now = time();
        $maxcount = 64;
        $maxactions = $config->get('maxactionspertime');
        $maxtime = $config->get('timeformaxactions');

        $actiontime = $config->get('timebetweensameactions');
        $actionkey = $event->eventname . ':' . $event->contextid . ':' . $event->objectid . ':' . $event->relateduserid;

        // Init the session variable.
        if (!isset($SESSION->block_xp_cheatguard)) {
            $SESSION->block_xp_cheatguard = array();
        }

        // Actions per time.
        if (count($SESSION->block_xp_cheatguard) > $maxactions) {
            $actions = array_reverse($SESSION->block_xp_cheatguard, true);
            $count = 0;
            foreach ($actions as $action => $time) {
                $count++;
                if ($count > $maxactions && $time > $now - $actiontime) {
                    // Too many actions within $actiontime.
                    return false;
                }
            }
        }

        if (isset($SESSION->block_xp_cheatguard[$actionkey])) {
            if ($SESSION->block_xp_cheatguard[$actionkey] > $now - $actiontime) {
                // The key was found and the time has not expired, cheater spotted.
                return false;
            }
        }

        // Unset the value to re-add it at the end of the array.
        unset($SESSION->block_xp_cheatguard[$actionkey]);

        // Log the time at which this event happened.
        $SESSION->block_xp_cheatguard[$actionkey] = time();

        // Limit the array of events to $maxcount, we do not want to flood the session for no reason.
        $SESSION->block_xp_cheatguard = array_slice($SESSION->block_xp_cheatguard, -$maxcount, null, true);
        return true;
    }

}
