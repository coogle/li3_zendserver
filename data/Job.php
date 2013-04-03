<?php

namespace li3_zendserver\data;

use \ZendJobQueue;

/**
 * The Job model for the Zend Server Job Queue
 * @author John Coggeshall
 *
 */
class Job extends \lithium\data\Model {

	/**
	 * A low priority Job
	 * @var integer
	 */
	const PRIORITY_LOW = 0;

	/**
	 * A normal priority Job
	 * @var integer
	 */
	const PRIORITY_NORMAL = 1;

	/**
	 * A high priority Job
	 * @var integer
	 */
	const PRIORITY_HIGH = 2;

	/**
	 * An urgent priority job
	 * @var integer
	 */
	const PRIORITY_URGENT = 3;

	/**
	 * The name used for a job that is executed inline (no queueing)
	 * @var string
	 */
	const INLINE_SERVER_NAME = "[inline-exec]";

	/**
	 * The job schema
	 *
	 * @var array
	 */
	protected $_schema = array(
		'_id' => array('type' => 'id'),
		'expires' => array('type' => 'date'),
		'job_id' => array('type' => 'integer'),
		'exec_server' => array('type' => 'string'),
		'jobClass' => array('type' => 'string'),
		'jobName' => array('type' => 'string'),
		'schedule' => array('type' => 'string', 'default' => null),
		'schedule_time' => array('type' => 'string', 'default' => null),
		'jobPriority' => array('type' => 'integer', 'default' => self::PRIORITY_NORMAL),
		'accepted' => array('type' => 'date'),
		'executed_time' => array('type' => 'date'),
		'completed_time' => array('type' => 'date'),
		'failed' => array('type' => 'boolean', 'default' => false),
		'jobData' => array('type' => 'object'),
		'options' => array('type' => 'array')
	);

	public $_classes = array(
		'Document' => '\lithium\data\entity\Document',
		'HttpService' => '\lithium\net\http\Service',
		'ZendServer' => '\li3_zendserver\ZendServer',
		'Environment' => '\lithium\core\Environment'
	);

	/**
	 * Lithium metadata
	 * @var array
	 */
	protected $_meta = array(
				'source' => 'zendserver_jobs',
				'locked' => false
			);

	static public function create(array $data = array(), array $options = array()) {
		$retval = parent::create($data, $options);

		if(!is_object($retval->jobData)) {
			$retval->jobData = new \stdClass();
		}

		return $retval;
	}

	/**
	 * Returns if the Job queue is online or not
	 * @return boolean
	 */
	static public function isJobQueueOnline() {
		return (class_exists('\ZendJobQueue') && ZendJobQueue::isJobQueueDaemonRunning());
	}

	/**
	 * Load a specific job model instance based on the jobqueue ID
	 * @param intger $job_id The ID of the job
	 *
	 * @return mixed false on error, or an instance of a specific job
	 */
	static public function loadJob($job_id) {
		$job = static::first(array('conditions' => array('_id' => $job_id)));

		if(!$job) {
			return false;
		}

		if(!class_exists($job->jobClass)) {
			return false;
		}

		$modelClass = $job->jobClass;
		$model = $modelClass::create($job->data());

		return $model;
	}

	/**
	 * Remove a reoccurring job from the queue managing it
	 * @param Document $entity
	 */
	public function removeReoccurringJob(Document $entity) {
		if(!$entity->exec_sever || empty($entity->exec_server)) {
			return false;
		}

		if(!$entity->schedule) {
			return false;
		}

		$rule = $this->getReoccurringRule($entity);

		$queue = new ZendJobQueue("tcp://{$entity->exec_server}:10085");
		return $queue->deleteSchedulingRule($rule['id']);
	}

	/**
	 * Get the rule which controls the job's scheduling from the job queue
	 * @param Document $entity
	 */
	public function getReoccurringRule(Document $entity) {

		if(!$entity->exec_server || empty($entity->exec_server)) {
			return false;
		}

		if(!$entity->schedule) {
			return false;
		}

		$queue = new ZendJobQueue("tcp://{$entity->exec_server}:10085");
		$rules = $queue->getSchedulingRules();

		foreach($rules as $rule) {
			if(isset($rule['vars']) && !empty($rule['vars'])) {
				$vars = json_decode($rule['vars'], true);

				if(!$vars) {
					break;
				}

				if(!isset($vars['id'])) {
					break;
				}

				if($vars['id'] == (string)$entity->_id) {
					return $rule;
				}
			}
		}

		return false;
	}

	/**
	 * Remove a job in the JQ
	 *
	 * @param object $entity
	 */
	public function deleteJob($entity)
	{
		$jobId = $entity->job_id;
		$zjq = new ZendJobQueue("tcp://localhost:10085");
		$zjq->removeJob($jobId);
		$entity->delete();
	}

	/**
	 * Instruct the JQ to execute this job ASAP, even if it was scheduled to run in the future.
	 *
	 * @param object $entity
	 */
	public function runNow($entity)
	{
		$jobId = $entity->job_id;

		$zjq = new ZendJobQueue("tcp://localhost:10085");

		$jobStatus = $zjq->getJobStatus($jobId);
		switch($jobStatus['status']) {
			case ZendJobQueue::STATUS_SCHEDULED:
			case ZendJobQueue::STATUS_SUSPENDED:
				break;
			default:
			case ZendJobQueue::STATUS_PENDING:
			case ZendJobQueue::STATUS_WAITING_PREDECESSOR:
			case ZendJobQueue::STATUS_RUNNING:
			case ZendJobQueue::STATUS_COMPLETED:
			case ZendJobQueue::STATUS_OK:
			case ZendJobQueue::STATUS_FAILED:
			case ZendJobQueue::STATUS_LOGICALLY_FAILED:
			case ZendJobQueue::STATUS_TIMEOUT:
			case ZendJobQueue::STATUS_REMOVED:
				return false;
		}

		$zjq->removeJob($jobId);

		unset($entity->schedule_time);

		if(!$entity->save()) {
			throw new \Exception("Failed to update Job in Database");
		}

		$entity->queue();
	}

	/**
	 * Queue the job into the job queue
	 *
	 * @param Document $entity
	 * @param array $options Options for the queuing. Can be 'forceInline', 'successUrl' or 'failureUrl'
	 * @throws \Exception
	 */
	public function queue($entity, array $options = array()) {

		if(!$entity instanceof \lithium\data\Entity) {
			throw new \InvalidArgumentException("Must provide an entity object");
		}

		$defaults = array(
			'forceInline' => false,
			'successUrl' => null,
			'failureUrl' => null
		);

		$options += $defaults;

		if(!isset($entity->expires)) {
			$entity->expires = new \MongoDate(strtotime("+30 days"));
		}

		if(!isset($entity->schedule)) {
			$entity->schedule = null;
		}

		$entity->accepted = null;
		$entity->job_id = -1;
		$entity->exec_server = null;
		$entity->executed_time = null;
		$entity->completed_time = null;
		$entity->jobClass = get_class($this);
		$entity->options = $options;

		if(!isset($entity->jobName)) {
			$entity->jobName = get_class($this);
		}

		if(!$entity->save(null, array('safe' => true))) {
			throw new \Exception("Failed to store job for queueing");
		}

		if(!isset($entity->_id)) {
			throw new \Exception("Failed to retrieve ID of stored queue job");
		}

		$result = $this->postJob($entity->_id);

		if(!$result || !is_object($result)) {
			throw new \Exception("Failed to contact Job Queue service");
		}

		if(!isset($result->success) || !$result->success) {
			if(isset($result->message)) {
				$eMessage = (string)$result->message;
			} else {
				$eMessage = "Unknown (no message)";
			}

			throw new \Exception("Failed to create Job in Queue (service returned: '$eMessage'");
		}

		return $result;
	}

	protected function decodeResult($id, $resultText) {
		return json_decode($resultText);
	}

	protected function postJob($id) {
		$classes = $this->_classes;
		$config = $classes['ZendServer']::config($classes['Environment']::get());

		$queueConfig = $config['jobQueue'];

		$queueService = new $classes['HttpService']($queueConfig['httpConfig']);

		$resultText = $queueService->post($queueConfig['insertEndpoint'],
											array('id' => (string)$id));

		switch(true) {
			case is_string($resultText):
				$resultObj = $this->decodeResult((string)$id, $resultText);
				break;
			case is_array($resultText):
				$resultObj = (object)$resultText;
				break;
			case is_object($resultText):
				break;
			default:
				return false;
		}

		if(!$resultObj) {
			return false;
		}

		return $resultObj;
	}

	public function fail($message, $context = null) {
		$result = array('success' => false, 'message' => (string)$message);

		if(!is_null($context)) {
			$result['context'] = $context;
		}

		return $result;
	}

	public function success($data = null) {
		$result = array('success' => true, 'resultData' => $data);
		return $result;
	}

	public function executeJob($entity) {}

}
