<?php namespace Helpdesk\Reports;

use Carbon\Carbon;
use Ticket;
use User;

/**
 * Ticket volume, SLA compliance and workload over a date range.
 *
 * Rows are fetched with plain Eloquent queries and every duration and
 * percentage is worked out here with Carbon, so the figures are the same on
 * sqlite and MySQL (no TIMESTAMPDIFF or database date functions).
 */
class ReportService {

	/**
	 * Tickets created between the start of $from's day and the end of $to's day.
	 *
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	public function tickets(Carbon $from, Carbon $to)
	{
		list($start, $end) = $this->bounds($from, $to);

		return Ticket::with('category', 'requester', 'assignee')
			->whereBetween('created_at', array($start, $end))
			->orderBy('created_at')
			->orderBy('id')
			->get();
	}

	/**
	 * Keys: from, to (Carbon, whole days), created, resolved, by_status,
	 * by_priority, by_category, response and resolution (measured, met,
	 * percent), mean_response_minutes, mean_resolution_minutes, agents.
	 *
	 * "resolved" counts tickets whose resolved_at falls in the range, whenever
	 * they were created; every other figure covers tickets created in it.
	 *
	 * A clock is measured once it has stopped or its due time has passed
	 * (evaluated at now); it is met when it stopped at or before the due time.
	 * Percentages are null when nothing was measured.
	 *
	 * @return array
	 */
	public function summary(Carbon $from, Carbon $to)
	{
		list($start, $end) = $this->bounds($from, $to);
		$now = Carbon::now();

		$tickets = Ticket::with('category')
			->whereBetween('created_at', array($start, $end))
			->get();

		$byStatus = array_fill_keys(array('new', 'open', 'pending', 'resolved', 'closed'), 0);
		$byPriority = array_fill_keys(Ticket::$priorities, 0);
		$byCategory = array();
		$response = array('measured' => 0, 'met' => 0);
		$resolution = array('measured' => 0, 'met' => 0);
		$responseMinutes = array();
		$resolutionMinutes = array();
		$agents = array();

		foreach ($tickets as $ticket)
		{
			$byStatus[$ticket->status]++;
			$byPriority[$ticket->priority]++;

			$category = $ticket->category ? $ticket->category->name : 'Uncategorised';
			$byCategory[$category] = (isset($byCategory[$category]) ? $byCategory[$category] : 0) + 1;

			$this->measure($response, $ticket->response_due_at, $ticket->first_responded_at, $now);
			$this->measure($resolution, $ticket->resolution_due_at, $ticket->resolved_at, $now);

			if ($ticket->first_responded_at)
			{
				$responseMinutes[] = $this->minutes($ticket->created_at, $ticket->first_responded_at);
			}

			if ($ticket->resolved_at)
			{
				$resolutionMinutes[] = $this->minutes($ticket->created_at, $ticket->resolved_at);
			}

			if ($ticket->assignee_id)
			{
				$id = (int) $ticket->assignee_id;
				if ( ! isset($agents[$id])) $agents[$id] = array('open' => 0, 'resolved' => 0);

				$agents[$id][$ticket->isOpen() ? 'open' : 'resolved']++;
			}
		}

		ksort($byCategory);

		$resolved = Ticket::whereNotNull('resolved_at')
			->whereBetween('resolved_at', array($start, $end))
			->count();

		return array(
			'from'                    => $start,
			'to'                      => $end,
			'created'                 => count($tickets),
			'resolved'                => (int) $resolved,
			'by_status'               => $byStatus,
			'by_priority'             => $byPriority,
			'by_category'             => $byCategory,
			'response'                => $this->compliance($response),
			'resolution'              => $this->compliance($resolution),
			'mean_response_minutes'   => $this->mean($responseMinutes),
			'mean_resolution_minutes' => $this->mean($resolutionMinutes),
			'agents'                  => $this->agentRows($agents),
		);
	}

	/**
	 * @return array  array(Carbon start of day, Carbon end of day), earlier first
	 */
	protected function bounds(Carbon $from, Carbon $to)
	{
		$start = $from->copy()->startOfDay();
		$end = $to->copy()->endOfDay();

		if ($start->gt($end))
		{
			list($start, $end) = array($to->copy()->startOfDay(), $from->copy()->endOfDay());
		}

		return array($start, $end);
	}

	protected function measure(array &$counts, Carbon $due = null, Carbon $stopped = null, Carbon $now)
	{
		if (is_null($due)) return;

		if ($stopped)
		{
			$counts['measured']++;
			if ($stopped->lte($due)) $counts['met']++;
		}
		elseif ($now->gt($due))
		{
			$counts['measured']++;
		}
	}

	protected function compliance(array $counts)
	{
		$counts['percent'] = $counts['measured'] > 0
			? round(100 * $counts['met'] / $counts['measured'], 1)
			: null;

		return $counts;
	}

	protected function minutes(Carbon $start, Carbon $end)
	{
		return ($end->getTimestamp() - $start->getTimestamp()) / 60;
	}

	protected function mean(array $values)
	{
		if (empty($values)) return null;

		return round(array_sum($values) / count($values), 1);
	}

	/**
	 * @param  array  $counts  assignee id => array(open, resolved)
	 * @return array  rows of name, username, open, resolved, ordered by name
	 */
	protected function agentRows(array $counts)
	{
		if (empty($counts)) return array();

		$rows = array();

		foreach (User::whereIn('id', array_keys($counts))->orderBy('name')->orderBy('id')->get() as $user)
		{
			$rows[] = array(
				'name'     => $user->name,
				'username' => $user->username,
				'open'     => $counts[$user->id]['open'],
				'resolved' => $counts[$user->id]['resolved'],
			);
		}

		return $rows;
	}

}
