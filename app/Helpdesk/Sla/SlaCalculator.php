<?php namespace Helpdesk\Sla;

use Carbon\Carbon;
use SlaPolicy;
use Ticket;

/**
 * Due dates and SLA state for a ticket. Clocks run on wall-clock time from
 * the ticket's creation and do not pause.
 *
 * A ticket has two clocks: response (stopped by first_responded_at) and
 * resolution (stopped by resolved_at). Each clock is
 *   - running: breached once now is past due, warning from 80 % of the
 *     window, otherwise ok;
 *   - stopped: breached if it stopped after due, otherwise ok;
 *   - ok whenever its due date is null.
 * The ticket's state is the worse of the two.
 */
class SlaCalculator {

	const WARNING_RATIO = 0.8;

	protected static $rank = array('ok' => 0, 'warning' => 1, 'breached' => 2);

	/**
	 * @return array  response_due_at and resolution_due_at (Carbon, or null without a policy)
	 */
	public function dueDates(Ticket $ticket)
	{
		$policy = SlaPolicy::forPriority($ticket->priority);

		if (is_null($policy))
		{
			return array('response_due_at' => null, 'resolution_due_at' => null);
		}

		$start = $ticket->created_at ? $ticket->created_at->copy() : Carbon::now();

		return array(
			'response_due_at'   => $start->copy()->addMinutes((int) $policy->response_minutes),
			'resolution_due_at' => $start->copy()->addMinutes((int) $policy->resolution_minutes),
		);
	}

	/**
	 * @return string  ok, warning or breached
	 */
	public function state(Ticket $ticket, Carbon $now)
	{
		$start = $ticket->created_at;

		$response = $this->clock($start, $ticket->response_due_at, $ticket->first_responded_at, $now);
		$resolution = $this->clock($start, $ticket->resolution_due_at, $ticket->resolved_at, $now);

		return static::$rank[$response] >= static::$rank[$resolution] ? $response : $resolution;
	}

	protected function clock(Carbon $start = null, Carbon $due = null, Carbon $stoppedAt = null, Carbon $now)
	{
		if (is_null($due)) return 'ok';

		if ( ! is_null($stoppedAt))
		{
			return $stoppedAt->gt($due) ? 'breached' : 'ok';
		}

		if ($now->gt($due)) return 'breached';

		if (is_null($start)) return 'ok';

		$window = $due->timestamp - $start->timestamp;
		$elapsed = $now->timestamp - $start->timestamp;

		return $elapsed >= static::WARNING_RATIO * $window ? 'warning' : 'ok';
	}

}
