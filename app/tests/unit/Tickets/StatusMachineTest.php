<?php

use Helpdesk\Tickets\InvalidTransitionException;
use Helpdesk\Tickets\StatusMachine;

class StatusMachineTest extends PHPUnit_Framework_TestCase {

	public function allowedTransitions()
	{
		return array(
			array('new', 'open'),
			array('new', 'resolved'),
			array('new', 'closed'),
			array('open', 'pending'),
			array('open', 'resolved'),
			array('pending', 'open'),
			array('pending', 'resolved'),
			array('resolved', 'open'),
			array('resolved', 'closed'),
		);
	}

	public function disallowedTransitions()
	{
		return array(
			array('new', 'pending'),
			array('new', 'new'),
			array('open', 'new'),
			array('open', 'closed'),
			array('open', 'open'),
			array('pending', 'closed'),
			array('pending', 'new'),
			array('resolved', 'pending'),
			array('resolved', 'new'),
			array('closed', 'open'),
			array('closed', 'resolved'),
			array('closed', 'new'),
			array('open', 'archived'),
			array('unknown', 'open'),
		);
	}

	/**
	 * @dataProvider allowedTransitions
	 */
	public function testAllowedTransition($from, $to)
	{
		$this->assertTrue(StatusMachine::can($from, $to));

		StatusMachine::check($from, $to);
	}

	/**
	 * @dataProvider disallowedTransitions
	 */
	public function testDisallowedTransition($from, $to)
	{
		$this->assertFalse(StatusMachine::can($from, $to));
	}

	/**
	 * @dataProvider disallowedTransitions
	 */
	public function testCheckThrowsOnDisallowedTransition($from, $to)
	{
		try
		{
			StatusMachine::check($from, $to);
		}
		catch (InvalidTransitionException $e)
		{
			$this->assertSame($from, $e->from);
			$this->assertSame($to, $e->to);
			return;
		}

		$this->fail("Expected InvalidTransitionException for $from -> $to");
	}

	public function testClosedIsTerminal()
	{
		$this->assertSame(array(), StatusMachine::targets('closed'));
	}

	public function testTargetsListsNextStatuses()
	{
		$this->assertSame(array('open', 'closed'), StatusMachine::targets('resolved'));
		$this->assertSame(array('pending', 'resolved'), StatusMachine::targets('open'));
	}

	public function testStatusesInWorkflowOrder()
	{
		$this->assertSame(array('new', 'open', 'pending', 'resolved', 'closed'), StatusMachine::statuses());
	}

}
