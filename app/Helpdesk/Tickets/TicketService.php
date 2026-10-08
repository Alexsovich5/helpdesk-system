<?php namespace Helpdesk\Tickets;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Ticket;
use TicketComment;
use TicketEvent;
use User;

/**
 * Every change to a ticket goes through here: it updates the ticket, appends
 * a ticket_events row and fires a ticket.* event, all inside one transaction.
 *
 * Events and their payloads:
 *   ticket.created    (Ticket $ticket, User $actor)
 *   ticket.assigned   (Ticket $ticket, User $assignee, User $actor)
 *   ticket.commented  (Ticket $ticket, TicketComment $comment, User $actor)
 *   ticket.status     (Ticket $ticket, string $from, string $to, User $actor)
 */
class TicketService {

	protected $db;

	protected $events;

	public function __construct(DatabaseManager $db, Dispatcher $events)
	{
		$this->db = $db;
		$this->events = $events;
	}

	/**
	 * @param  array  $data  subject, description, category_id, optional priority
	 * @param  User   $requester
	 * @return Ticket
	 */
	public function create(array $data, User $requester)
	{
		$ticket = $this->db->transaction(function() use ($data, $requester)
		{
			$ticket = new Ticket(array_only($data, array('subject', 'description', 'category_id', 'priority')));
			if (empty($ticket->priority)) $ticket->priority = 'normal';
			$ticket->status = 'new';
			$ticket->sla_state = 'ok';
			$ticket->requester_id = $requester->id;
			$ticket->save();

			$ticket->number = TicketNumber::fromId($ticket->id);
			$ticket->save();

			$this->record($ticket, $requester, 'created', null, 'new');

			return $ticket;
		});

		$this->events->fire('ticket.created', array($ticket, $requester));

		return $ticket;
	}

	/**
	 * Assign to an agent; a new ticket moves to open at the same time.
	 */
	public function assign(Ticket $ticket, User $assignee, User $actor)
	{
		if ( ! $assignee->isAgent())
		{
			throw new InvalidArgumentException("Only agents can be assigned tickets; {$assignee->username} is a {$assignee->role}.");
		}

		$opened = $this->db->transaction(function() use ($ticket, $assignee, $actor)
		{
			$previous = $ticket->assignee;

			$ticket->assignee_id = $assignee->id;
			$opened = $ticket->status === 'new';
			if ($opened) $ticket->status = 'open';
			$ticket->save();

			$this->record($ticket, $actor, 'assigned', $previous ? $previous->username : null, $assignee->username);
			if ($opened) $this->record($ticket, $actor, 'status', 'new', 'open');

			return $opened;
		});

		$ticket->setRelation('assignee', $assignee);

		$this->events->fire('ticket.assigned', array($ticket, $assignee, $actor));
		if ($opened) $this->events->fire('ticket.status', array($ticket, 'new', 'open', $actor));

		return $ticket;
	}

	/**
	 * Add a comment. Only agents may write internal comments; the flag is
	 * dropped for anyone else. The first public agent comment is the first response.
	 *
	 * @return TicketComment
	 */
	public function comment(Ticket $ticket, User $author, $body, $internal = false)
	{
		$internal = $internal && $author->isAgent();

		$comment = $this->db->transaction(function() use ($ticket, $author, $body, $internal)
		{
			$comment = new TicketComment(array('body' => $body, 'is_internal' => $internal));
			$comment->ticket_id = $ticket->id;
			$comment->user_id = $author->id;
			$comment->save();

			if ( ! $internal && $author->isAgent() && is_null($ticket->first_responded_at))
			{
				$ticket->first_responded_at = $comment->created_at;
				$ticket->save();
			}

			$this->record($ticket, $author, 'comment', null, $internal ? 'internal' : 'public');

			return $comment;
		});

		$this->events->fire('ticket.commented', array($ticket, $comment, $author));

		return $comment;
	}

	/**
	 * Move a ticket to another status.
	 *
	 * @throws AccessDeniedHttpException  a non-agent tried anything but reopening or closing their own resolved ticket
	 * @throws InvalidTransitionException the workflow does not allow the move
	 */
	public function transition(Ticket $ticket, $to, User $actor)
	{
		$from = $ticket->status;

		if ( ! $actor->isAgent() && ! $this->requesterMayTransition($ticket, $to, $actor))
		{
			throw new AccessDeniedHttpException('Only agents can change the status of this ticket.');
		}

		StatusMachine::check($from, $to);

		$this->db->transaction(function() use ($ticket, $from, $to, $actor)
		{
			$now = Carbon::now();

			$ticket->status = $to;
			if ($to === 'resolved') $ticket->resolved_at = $now;
			if ($from === 'resolved' && $to === 'open') $ticket->resolved_at = null;
			if ($to === 'closed') $ticket->closed_at = $now;
			$ticket->save();

			$this->record($ticket, $actor, 'status', $from, $to);
		});

		$this->events->fire('ticket.status', array($ticket, $from, $to, $actor));

		return $ticket;
	}

	protected function requesterMayTransition(Ticket $ticket, $to, User $actor)
	{
		return $ticket->isOwnedBy($actor)
			&& $ticket->status === 'resolved'
			&& in_array($to, array('open', 'closed'), true);
	}

	protected function record(Ticket $ticket, User $actor = null, $type, $from = null, $to = null)
	{
		$event = new TicketEvent(array('type' => $type, 'from_value' => $from, 'to_value' => $to));
		$event->ticket_id = $ticket->id;
		$event->user_id = $actor ? $actor->id : null;
		$event->save();

		return $event;
	}

}
