<?php namespace Helpdesk\Sla;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;
use Ticket;
use TicketEvent;

/**
 * Re-evaluates the SLA state of every open ticket (new, open, pending).
 *
 * When a ticket's state changes, sla_state is updated and, if the new state
 * is warning or breached, a sla_warning / sla_breached event row is written
 * (no user). Every change fires
 *   ticket.sla  (Ticket $ticket, string $from, string $to)
 * Unchanged tickets are left alone, so running twice at the same time
 * records nothing the second time. Resolved and closed tickets keep the
 * state stored when they were resolved.
 */
class SlaMonitor {

	protected $db;

	protected $events;

	protected $sla;

	protected static $eventTypes = array('warning' => 'sla_warning', 'breached' => 'sla_breached');

	public function __construct(DatabaseManager $db, Dispatcher $events, SlaCalculator $sla)
	{
		$this->db = $db;
		$this->events = $events;
		$this->sla = $sla;
	}

	/**
	 * @param  Carbon  $now
	 * @param  bool    $dryRun  evaluate and count only; change nothing
	 * @return array   checked, warning, breached (tickets in that state at $now)
	 */
	public function run(Carbon $now, $dryRun = false)
	{
		$counts = array('checked' => 0, 'warning' => 0, 'breached' => 0);

		foreach (Ticket::open()->orderBy('id')->get() as $ticket)
		{
			$state = $this->sla->state($ticket, $now);

			$counts['checked']++;
			if (isset($counts[$state])) $counts[$state]++;

			if ($dryRun || $state === $ticket->sla_state) continue;

			$this->change($ticket, $state, $now);
		}

		return $counts;
	}

	protected function change(Ticket $ticket, $to, Carbon $now)
	{
		$from = $ticket->sla_state;

		$this->db->connection()->transaction(function() use ($ticket, $from, $to, $now)
		{
			$ticket->sla_state = $to;
			$ticket->save();

			if (isset(static::$eventTypes[$to]))
			{
				$event = new TicketEvent(array('type' => static::$eventTypes[$to], 'from_value' => $from, 'to_value' => $to));
				$event->ticket_id = $ticket->id;
				$event->user_id = null;
				$event->created_at = $now;
				$event->save();
			}
		});

		$this->events->fire('ticket.sla', array($ticket, $from, $to));
	}

}
