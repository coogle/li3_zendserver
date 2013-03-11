<?php

namespace li3_zendserver\extensions\helper;

use \ZendJobQueue;
use \ReflectionObject;

class Zendserver extends \lithium\template\helper\Html {

	/**
	 * Returns all of the Zend Job Queue statuses and their
	 * value for use on the javascript side when checking on
	 * a status of a job
	 *
	 * @param object $context Request context object
	 * @return array An array of status constants
	 */
	public function queueStatuses($context = null) {
		$reflect = new ReflectionObject(new ZendJobQueue());

		$result = array();

		foreach($reflect->getConstants() as $key => $val) {
			switch($key) {
				case ZendJobQueue::STATUS_PENDING:
				case ZendJobQueue::STATUS_WAITING_PREDECESSOR:
				case ZendJobQueue::STATUS_RUNNING:
				case ZendJobQueue::STATUS_COMPLETED:
				case ZendJobQueue::STATUS_OK:
				case ZendJobQueue::STATUS_FAILED:
				case ZendJobQueue::STATUS_LOGICALLY_FAILED:
				case ZendJobQueue::STATUS_TIMEOUT:
				case ZendJobQueue::STATUS_REMOVED:
				case ZendJobQueue::STATUS_SCHEDULED:
				case ZendJobQueue::STATUS_SUSPENDED:
					$result[$key] = $val;
					break;
			}
		}

		return $result;
	}
}

?>