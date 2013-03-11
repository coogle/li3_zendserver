<?php

namespace li3_zendserver\models\dto;

use \ZendJobQueue;

class JobResult {
	
	public $resultMessage;
	public $resultStatus;
	public $result;
	
	const OK = ZendJobQueue::OK;
	const FAILED = ZendJobQueue::FAILED;
	
	public function __construct() {
		$this->result = array();
	}
}