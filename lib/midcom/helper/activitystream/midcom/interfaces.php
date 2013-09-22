<?php
/**
 * @package midcom.helper.activitystream
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * Activity Log library interface
 *
 * Startup loads main class, which is used for all operations.
 *
 * @package midcom.helper.activitystream
 */
class midcom_helper_activitystream_interface extends midcom_baseclasses_components_interface
{
    public function _on_watched_operation($operation, $object)
    {
        if (!$object->_use_activitystream)
        {
            // Activity Log not used for this object
            return;
        }

        // Create an activity log entry
        if (!midcom::get('auth')->request_sudo('midcom.helper.activitystream'))
        {
            // Not allowed to create activity logs
            return;
        }

        if (is_a($object, 'midcom_db_member'))
        {
            $activity = $this->_process_member($object, $operation);
        }
        else
        {
            $activity = $this->_process_object($object, $operation);
        }

        if (!$activity->verb)
        {
            debug_add('Cannot generate a verb for the activity, skipping');
            midcom::get('auth')->drop_sudo();
            return;
        }

        static $handled_targets = array();
        $cache_key = $activity->target . '_' . $activity->actor;
        if (empty($handled_targets[$cache_key]))
        {
            $activity->application = midcom_core_context::get()->get_key(MIDCOM_CONTEXT_COMPONENT);
            $handled_targets[$cache_key] = $activity->create();
        }

        midcom::get('auth')->drop_sudo();
    }

    private function _process_object(midcom_core_dbaobject $object, $operation)
    {
        $activity = new midcom_helper_activitystream_activity_dba();
        $activity->target = $object->guid;

        if ($object->_activitystream_verb)
        {
            $activity->verb = $object->_activitystream_verb;
        }
        else
        {
            $activity->verb = midcom_helper_activitystream_activity_dba::operation_to_verb($operation);
        }
        if ($object->get_rcs_message())
        {
            $activity->summary = $object->get_rcs_message();
        }

        if (midcom::get('auth')->user)
        {
            $actor = midcom::get('auth')->user->get_storage();
            $activity->actor = $actor->id;
        }

        return $activity;
    }

    private function _process_member(midcom_db_member $member, $operation)
    {
        $activity = new midcom_helper_activitystream_activity_dba();
        if ($operation === MIDCOM_OPERATION_DBA_DELETE)
        {
            $verb = 'http://activitystrea.ms/schema/1.0/leave';
            $msg_self = '%s left group %s';
            $msg_other = '%s was removed from group %s';
        }
        else if ($operation === MIDCOM_OPERATION_DBA_CREATE)
        {
            $verb = 'http://activitystrea.ms/schema/1.0/join';
            $msg_self = '%s joined group %s';
            $msg_other = '%s was added to group %s';
        }
        else
        {
            return $activity;
        }

        try
        {
            $actor = midcom_db_person::get_cached($member->uid);
            $target = midcom_db_group::get_cached($member->gid);
        }
        catch (midcom_error $e)
        {
            $e->log();
            return $activity;
        }
        $activity->target = $target->guid;
        $activity->actor = $actor->id;
        $activity->verb = $verb;

        if (   !empty(midcom::get('auth')->user->guid)
            && $actor->guid == midcom::get('auth')->user->guid)
        {
            $activity->summary = sprintf($this->_l10n->get($msg_self, 'midcom'), $actor->name, $target->official);
        }
        else
        {
            $activity->summary = sprintf($this->_l10n->get($msg_other, 'midcom'), $actor->name, $target->official);
        }
        return $activity;
    }
}
?>