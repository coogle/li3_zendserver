<?php 

namespace li3_zendserver\core;

use \li3_zendserver\data\Job;
use \MongoDate;
use \ZendJobQueue;

class Queue extends \lithium\core\StaticObject {
	
	static protected $_config = array('jqPort' => 10085);
	
	static public function setConfig(array $config) {
		static::$_config = $config;
	}
	
	static public function getConfig() {
		return static::$_config;
	}
	
	static public function isJobQueueOnline() {
		
		if(class_exists('\ZendJobQueue')) {
			if(@\ZendJobQueue::isJobQueueDaemonRunning()) {
				try {
					$zjq = new \ZendJobQueue("tcp://localhost:10085");
				} catch(\Exception $e) {
					var_dump($e->getMessage());
					return false;
				}
				
				return true;
			}
		}
		
		return false;
	}
	
	static public function resolveJob($job) {
		if($job instanceof \lithium\data\entity\Document) {
			
			if($job->jobClass) {
				$jobClass = $job->jobClass;
				$job = $jobClass::create($job->data());
				return $job;
			}
			
		} elseif($job instanceof \MongoId) {
			$job = Job::first(array(
				'conditions' => array('_id' => $job)
			));
			
			if($job) {
				return static::resolveJob($job);
			}
		} elseif(is_string($job)) {
			$job = Job::first(array(
				'conditions' => array('jobName' => $job)
			));
			
			if($job) {
				return static::resolveJob($job);
			}
		}
		
		return null;
	}
	
	/**
	 * Remove jobs from the database that have expired as of right now.
	 * 
	 * @return integer The number of removed job records, or -1 on error
	 */
	static public function removeExpiredJobs() {
		$conditions = array(
			'expires' => array('$lte' => new MongoDate())
		);
		
		$cleaned = 0;
		$cleaned = Job::count($conditions);
		
		if(!Job::remove($conditions)) {
			return -1;
		}
		
		return $cleaned;
	}
}

