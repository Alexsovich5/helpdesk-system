<?php namespace Helpdesk\Tickets;

use RuntimeException;

class InvalidTransitionException extends RuntimeException {

	public $from;

	public $to;

	public function __construct($from, $to)
	{
		$this->from = $from;
		$this->to = $to;

		parent::__construct("A ticket cannot move from \"$from\" to \"$to\".");
	}

}
