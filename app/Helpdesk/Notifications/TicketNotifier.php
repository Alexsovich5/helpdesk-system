<?php namespace Helpdesk\Notifications;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Ticket;
use TicketComment;
use User;

/**
 * Sends e-mail for ticket.* events. One message per recipient.
 *
 *   ticket.created    requester (acknowledgement) and every agent except the actor
 *   ticket.assigned   the assignee, unless they assigned themselves
 *   ticket.commented  public, by an agent: the requester
 *                     public, by the requester: the assignee, or every agent if unassigned
 *                     internal: the assignee only (never the requester)
 *   ticket.status     the requester
 *   ticket.sla        to warning/breached: the assignee, or every agent if unassigned
 *
 * Apart from the creation acknowledgement, the user who caused the event
 * is never mailed about it.
 *
 * The mailer is looked up from the container on every send so a mailer
 * bound after boot (tests) is the one used.
 */
class TicketNotifier {

	protected $app;

	public function __construct(Container $app)
	{
		$this->app = $app;
	}

	public function subscribe(Dispatcher $events)
	{
		$events->listen('ticket.created', 'Helpdesk\Notifications\TicketNotifier@onCreated');
		$events->listen('ticket.assigned', 'Helpdesk\Notifications\TicketNotifier@onAssigned');
		$events->listen('ticket.commented', 'Helpdesk\Notifications\TicketNotifier@onCommented');
		$events->listen('ticket.status', 'Helpdesk\Notifications\TicketNotifier@onStatus');
		$events->listen('ticket.sla', 'Helpdesk\Notifications\TicketNotifier@onSla');
	}

	public function onCreated(Ticket $ticket, User $actor)
	{
		$recipients = $this->without($this->agents(), $actor);
		$recipients[] = $ticket->requester;

		$this->send('emails.ticket_created', $ticket, $recipients, 'New ticket', array());
	}

	public function onAssigned(Ticket $ticket, User $assignee, User $actor)
	{
		$recipients = $this->without(array($assignee), $actor);

		$this->send('emails.ticket_assigned', $ticket, $recipients, 'Assigned to you', array(
			'actor' => $actor,
		));
	}

	public function onCommented(Ticket $ticket, TicketComment $comment, User $actor)
	{
		if ($comment->is_internal)
		{
			$recipients = $ticket->assignee ? array($ticket->assignee) : array();
		}
		elseif ($actor->isAgent() && ! $ticket->isOwnedBy($actor))
		{
			$recipients = array($ticket->requester);
		}
		else
		{
			$recipients = $this->assigneeOrAgents($ticket);
		}

		$this->send('emails.ticket_commented', $ticket, $this->without($recipients, $actor), 'New comment', array(
			'comment' => $comment,
			'actor'   => $actor,
		));
	}

	public function onStatus(Ticket $ticket, $from, $to, User $actor)
	{
		$recipients = $this->without(array($ticket->requester), $actor);

		$this->send('emails.ticket_status', $ticket, $recipients, 'Status changed to '.$to, array(
			'from'  => $from,
			'to'    => $to,
			'actor' => $actor,
		));
	}

	public function onSla(Ticket $ticket, $from, $to)
	{
		if ( ! in_array($to, array('warning', 'breached'), true)) return;

		$label = $to === 'breached' ? 'SLA breached' : 'SLA warning';

		$this->send('emails.ticket_sla', $ticket, $this->assigneeOrAgents($ticket), $label, array(
			'state' => $to,
		));
	}

	/**
	 * @param  string  $view
	 * @param  Ticket  $ticket
	 * @param  User[]  $recipients
	 * @param  string  $what    subject text after the ticket number
	 * @param  array   $data    extra view data
	 */
	protected function send($view, Ticket $ticket, array $recipients, $what, array $data)
	{
		$subject = '['.$ticket->number.'] '.$what.': '.$ticket->subject;
		$mailer = $this->app->make('mailer');

		foreach ($this->unique($recipients) as $recipient)
		{
			$mailer->send($view, array_merge($data, array('ticket' => $ticket, 'recipient' => $recipient)),
				function($message) use ($recipient, $subject)
				{
					$message->to($recipient->email, $recipient->name)->subject($subject);
				});
		}
	}

	protected function agents()
	{
		return User::whereIn('role', array('agent', 'admin'))->orderBy('id')->get()->all();
	}

	protected function assigneeOrAgents(Ticket $ticket)
	{
		return $ticket->assignee ? array($ticket->assignee) : $this->agents();
	}

	protected function without(array $users, User $actor)
	{
		return array_values(array_filter($users, function($user) use ($actor)
		{
			return $user && (int) $user->id !== (int) $actor->id;
		}));
	}

	/**
	 * Drop nulls, users without an address and duplicates (by id).
	 */
	protected function unique(array $users)
	{
		$unique = array();
		foreach ($users as $user)
		{
			if ($user && $user->email) $unique[$user->id] = $user;
		}

		return array_values($unique);
	}

}
