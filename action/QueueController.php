<?php
namespace li3_zendserver\action;

use \li3_zendserver\data\Job;
use \li3_zendserver\core\Queue;
use \li3_zendserver\ZendServer;
use \lithium\net\http\Router;
use \lithium\core\Environment;
use \MongoDate;

class QueueController extends \lithium\action\Controller {

	protected $_publicActions = array('insertJob', 'executeJob');
	
	private $_jobResult = array();
	private $_job = null;
	
	private function isInlineJob($model = null) {
		
		$isInline = false;
		
		if(is_object($model)) {
			if(isset($model->options['forceInline'])) {
				$isInline = (bool)$model->options['forceInline'];
			}
		}
		
		$haveJQ = Queue::isJobQueueOnline();
		
		$isInline = !$haveJQ;
		
		return $isInline;
	}
	
	public function insertJob() {
		try {
			
			$input = $this->request->data;
			
			if(!isset($input['id'])) {
				return array('success' => false, 'message' => "Bad Input");
			}
			
			$model = Job::loadJob($input['id']);
			
			if(!$model) {
				return array('success' => false, 'message' => "Could not load Job");
			}
			
			$modelClass = $model->jobClass;
			
			$queueOptions = array(
				'name' => $model->jobName,
				'priority' => $model->jobPriority,
				'persistent' => false,
			);
			
			if(isset($model->schedule) && !empty($model->schedule)) {
				$queueOptions += array('schedule' => (string)$model->schedule);
			}
			
			$requestParams = $this->request->params;
			
			$requestParams['action'] = "executeJob";
			
			if(!$this->isInlineJob($model)) {
				$zjq = new \ZendJobQueue("tcp://localhost:10085");
				
				$config = ZendServer::config(Environment::get());
				
				$executeUrl = "http://{$config['jobQueue']['httpConfig']['host']}{$config['jobQueue']['executeEndpoint']}";
				
				$job_id = $zjq->createHttpJob($executeUrl, array('id' => (string)$model->_id), $queueOptions);
			
				if($job_id > 0) {
					$updateData = array('$set' => array(
						'job_id' => $job_id,
						'exec_server' => php_uname('n'),
						'accepted' => new MongoDate(),
					));
				} else {
					return array('success' => false, 'message' => "Failed to create job in queue");
				}
				
			} else {
				$updateData = array('$set' => array(
						'job_id' => -1,
						'exec_server' => Job::INLINE_SERVER_NAME,
						'accepted' => new MongoDate(),
					));
				
			}
			
			if(!$modelClass::update($updateData, array('_id' => $model->_id))) {
				if(!$zjq->removeJob($job_id)) {
					return array('success' => false, 'message' => "Failed to update job (and failed removing)");
				}
				
				return array('success' => false, 'message' => 'Failed to update job');
			}
			
			$retval = $updateData['$set'];
			
			if($this->isInlineJob($model)) {
				$this->request->query['id'] = (string)$model->_id;
				$jobResult = $this->executeJob(true);
			}
			
		} catch(\Exception $e) {
			return array('success' => false, 'message' => "Exception Caught: {$e->getMessage()} (file: {$e->getFile()}:{$e->getLine()})");
		}	
		
		$retval = array('success' => true, 'job_id' => $retval['job_id'], 'exec_server' => $retval['exec_server']);
		
		if($this->isInlineJob($model)) {
			$retval['job'] = $jobResult;
		}
		
		return $retval; 
	}
	
	protected function failJob($message) {
		$this->_jobResult['failed'] = true;
		$this->_jobResult['message'] = (string)$message;

		if(is_object($this->_job)) {
			Job::update(array('$set' => $this->_jobResult), array('_id' => $this->_job->_id));
		}
		
		if(!$this->isInlineJob()) {
			\ZendJobQueue::setCurrentJobStatus(\ZendJobQueue::FAILED, $this->_jobResult['message']);
		}
		
		trigger_error($this->_jobResult['message'], E_USER_ERROR);
		
		$result = $this->_jobResult;
		
		unset($result['executed_time']);
		unset($result['completed_time']);
		
		return $result;
	}
	
	protected function succeedJob() {
		$this->_jobResult['message'] = '';
		$this->_jobResult['failed'] = false;
		
		if(is_object($this->_job)) {
			Job::update(array('$set' => $this->_jobResult), array('_id' => $this->_job->_id));
		}
		
		if(!$this->isInlineJob()) {
			\ZendJobQueue::setCurrentJobStatus(\ZendJobQueue::OK, $this->_jobResult['message']);
		}
		
		$result = $this->_jobResult;
		
		unset($result['executed_time']);
		unset($result['completed_time']);
		
		return $result;
	}
	
	public function executeJob($inline = false) {
		
		set_time_limit(0);
		
		$this->_jobResult = array('executed_time' => new MongoDate());
		
		if(!$inline) {

			$jqParams = \ZendJobQueue::getCurrentJobParams();
			
			if(!is_array($jqParams) || !isset($jqParams['id'])) {
				return $this->failJob("Was not provided a job database ID");
			}
			
			$this->_job = Job::loadJob($jqParams['id']);
			
		} else {
			$input = $this->request->query;
			
			if(!isset($input['id'])) {
				return array('success' => false, 'message' => "Bad Input");
			}
			
			$this->_job = Job::loadJob($input['id']);
		}
		
		if(!$this->_job) {
			return $this->failJob("Could not load job from database");
		}
		
		try {
			if(!Job::update(array('$set' => $this->_jobResult), array('_id' => $this->_job->_id))) {
				return $this->failJob("Failed to update job");
			}
		
			$jobResult = $this->_job->executeJob();
			
			$this->_jobResult['completed_time'] = new MongoDate();
			$this->_jobResult['failed'] = false;
			$this->_jobResult['message'] = "";
			
			if(is_array($jobResult)) {
				array_walk_recursive($jobResult, function(&$item, $key) {
					if($item instanceof \lithium\data\Entity) {
						$item = $item->data();
					}
				});
			} else {
				$jobResult = array(
					'success' => false,
					'message' => 'Execution of job did not return a valid result'
				);
			}
			
			$this->_jobResult['jobResult'] = $jobResult;
			
		} catch(\Exception $e) {
			return $this->failJob("Job Exception: {$e->getMessage()}"); 
		}
		
		return $this->succeedJob();
	}
}