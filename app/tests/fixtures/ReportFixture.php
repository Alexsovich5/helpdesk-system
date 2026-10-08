<?php

use Carbon\Carbon;

/**
 * Six tickets created in January 2014 plus one either side of the month,
 * with fixed timestamps, shared by the sqlite and MySQL report tests.
 *
 * Expected summary for 2014-01-01..2014-01-31 evaluated at 2014-02-03 09:00:
 *   created 6, resolved 4 (one resolved ticket was created in December)
 *   response: 6 measured, 4 met -> 66.7 %; mean 39.0 minutes over 5 responses
 *   resolution: 5 measured, 2 met -> 40.0 %; mean 660.0 minutes over 3 resolutions
 */
class ReportFixture {

	/**
	 * Ticket ids by fixture key (t1..t6, before, after).
	 *
	 * @var array
	 */
	public $ids = array();

	public $agentA;
	public $agentB;
	public $requester;

	/**
	 * Evaluation time for the summary.
	 *
	 * @return Carbon
	 */
	public static function now()
	{
		return Carbon::create(2014, 2, 3, 9, 0, 0);
	}

	/**
	 * @param  string  $prefix  username prefix so integration rows do not clash
	 */
	public function build($prefix = '')
	{
		$this->agentA = $this->user($prefix.'bob', 'Bob Agent', 'agent');
		$this->agentB = $this->user($prefix.'dave', 'Dave Agent', 'agent');
		$this->requester = $this->user($prefix.'carol', 'Carol Requester', 'requester');

		$hardware = Category::firstOrCreate(array('name' => 'Hardware'))->id;
		$software = Category::firstOrCreate(array('name' => 'Software'))->id;
		$network = Category::firstOrCreate(array('name' => 'Network'))->id;

		$a = $this->agentA->id;
		$b = $this->agentB->id;

		// key => created, priority, category, status, assignee,
		//        response due, first response, resolution due, resolved, closed
		$rows = array(
			't1' => array('2014-01-06 09:00', 'urgent', $hardware, 'closed', $a,
				'2014-01-06 09:30', '2014-01-06 09:10', '2014-01-06 13:00', '2014-01-06 11:00', '2014-01-06 12:00'),
			't2' => array('2014-01-07 10:00', 'high', $software, 'resolved', $a,
				'2014-01-07 11:00', '2014-01-07 11:30', '2014-01-07 18:00', '2014-01-07 16:00', null),
			't3' => array('2014-01-08 08:00', 'normal', $software, 'open', $a,
				'2014-01-08 12:00', '2014-01-08 09:00', '2014-01-09 08:00', null, null),
			't4' => array('2014-01-09 14:00', 'normal', $network, 'resolved', $b,
				'2014-01-09 18:00', '2014-01-09 14:20', '2014-01-10 14:00', '2014-01-10 15:00', null),
			't5' => array('2014-01-31 10:00', 'low', $hardware, 'pending', $b,
				'2014-01-31 18:00', '2014-01-31 10:15', '2014-02-03 10:00', null, null),
			't6' => array('2014-01-20 11:00', 'normal', $software, 'new', null,
				'2014-01-20 15:00', null, '2014-01-21 11:00', null, null),
			'before' => array('2013-12-31 23:30', 'normal', $hardware, 'resolved', $a,
				'2014-01-01 03:30', '2014-01-01 08:00', '2014-01-01 23:30', '2014-01-02 10:00', null),
			'after' => array('2014-02-01 00:00', 'urgent', $network, 'open', $b,
				'2014-02-01 00:30', null, '2014-02-01 04:00', null, null),
		);

		foreach ($rows as $key => $row)
		{
			$this->ids[$key] = $this->ticket($key, $row);
		}

		return $this;
	}

	/**
	 * Ids of every ticket the fixture created.
	 *
	 * @return array
	 */
	public function allIds()
	{
		return array_values($this->ids);
	}

	protected function user($username, $name, $role)
	{
		return User::firstOrCreate(array(
			'username' => $username,
			'name'     => $name,
			'email'    => $username.'@helpdesk.local',
			'role'     => $role,
			'source'   => 'local',
		));
	}

	protected function ticket($key, array $row)
	{
		$time = function($value) { return is_null($value) ? null : Carbon::createFromFormat('Y-m-d H:i', $value); };

		$ticket = new Ticket;
		$ticket->subject = 'Report fixture '.$key;
		$ticket->description = 'Fixture ticket for the report tests.';
		$ticket->priority = $row[1];
		$ticket->category_id = $row[2];
		$ticket->status = $row[3];
		$ticket->assignee_id = $row[4];
		$ticket->requester_id = $this->requester->id;
		$ticket->response_due_at = $time($row[5]);
		$ticket->first_responded_at = $time($row[6]);
		$ticket->resolution_due_at = $time($row[7]);
		$ticket->resolved_at = $time($row[8]);
		$ticket->closed_at = $time($row[9]);
		$ticket->created_at = $time($row[0]);
		$ticket->updated_at = $time($row[0]);
		$ticket->save();

		$ticket->number = sprintf('HD-%06d', $ticket->id);
		$ticket->save();

		return $ticket->id;
	}

}
