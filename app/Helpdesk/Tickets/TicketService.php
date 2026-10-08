<?php namespace Helpdesk\Tickets;

use Asset;
use Carbon\Carbon;
use Helpdesk\Sla\SlaCalculator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;
use InvalidArgumentException;
use KbArticle;
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
 *   ticket.priority   (Ticket $ticket, string $from, string $to, User $actor)
 *   ticket.article_linked (Ticket $ticket, KbArticle $article, User $actor)
 *   ticket.asset_linked   (Ticket $ticket, Asset $asset, User $actor)
 */
class TicketService {

	protected $db;

	protected $events;

	protected $sla;

	public function __construct(DatabaseManager $db, Dispatcher $events, SlaCalculator $sla)
	{
		$this->db = $db;
		$this->events = $events;
		$this->sla = $sla;
	}

	/**
	 * @param  array  $data  subject, description, category_id, optional priority and asset_id
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
			if ( ! empty($data['asset_id'])) $ticket->asset_id = (int) $data['asset_id'];
			$ticket->save();

			$ticket->number = TicketNumber::fromId($ticket->id);
			foreach ($this->sla->dueDates($ticket) as $column => $due)
			{
				$ticket->$column = $due;
			}
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
			if ($to === 'resolved')
			{
				$ticket->resolved_at = $now;
				$ticket->sla_state = $this->sla->state($ticket, $now);
			}
			if ($from === 'resolved' && $to === 'open') $ticket->resolved_at = null;
			if ($to === 'closed') $ticket->closed_at = $now;
			$ticket->save();

			$this->record($ticket, $actor, 'status', $from, $to);
		});

		$this->events->fire('ticket.status', array($ticket, $from, $to, $actor));

		return $ticket;
	}

	/**
	 * Change the priority and recompute both due dates from the creation
	 * time. Setting the current priority again changes nothing.
	 *
	 * @throws InvalidArgumentException  unknown priority
	 */
	public function changePriority(Ticket $ticket, $priority, User $actor)
	{
		if ( ! in_array($priority, Ticket::$priorities, true))
		{
			throw new InvalidArgumentException("Unknown priority [$priority].");
		}

		$from = $ticket->priority;
		if ($from === $priority) return $ticket;

		$this->db->transaction(function() use ($ticket, $from, $priority, $actor)
		{
			$ticket->priority = $priority;
			foreach ($this->sla->dueDates($ticket) as $column => $due)
			{
				$ticket->$column = $due;
			}
			$ticket->save();

			$this->record($ticket, $actor, 'priority', $from, $priority);
		});

		$this->events->fire('ticket.priority', array($ticket, $from, $priority, $actor));

		return $ticket;
	}

	/**
	 * Link a published knowledge-base article to the ticket. Linking an
	 * article that is already linked changes nothing.
	 *
	 * @throws AccessDeniedHttpException  the actor is not an agent
	 * @throws InvalidArgumentException   the article is a draft
	 */
	public function linkArticle(Ticket $ticket, KbArticle $article, User $actor)
	{
		if ( ! $actor->isAgent())
		{
			throw new AccessDeniedHttpException('Only agents can link articles to tickets.');
		}

		if ( ! $article->is_published)
		{
			throw new InvalidArgumentException('Only published articles can be linked to a ticket.');
		}

		$linked = $this->db->transaction(function() use ($ticket, $article, $actor)
		{
			if ($ticket->articles()->where('kb_articles.id', $article->id)->exists()) return false;

			$ticket->articles()->attach($article->id);

			$this->record($ticket, $actor, 'article_linked', null, $article->title);

			return true;
		});

		if ($linked) $this->events->fire('ticket.article_linked', array($ticket, $article, $actor));

		return $ticket;
	}

	/**
	 * Point the ticket at an asset, replacing any asset linked before.
	 * Linking the asset that is already linked changes nothing.
	 *
	 * @throws AccessDeniedHttpException  the actor is not an agent
	 */
	public function linkAsset(Ticket $ticket, Asset $asset, User $actor)
	{
		if ( ! $actor->isAgent())
		{
			throw new AccessDeniedHttpException('Only agents can link assets to tickets.');
		}

		if ((int) $ticket->asset_id === (int) $asset->id) return $ticket;

		$this->db->transaction(function() use ($ticket, $asset, $actor)
		{
			$previous = $ticket->asset;

			$ticket->asset_id = $asset->id;
			$ticket->save();

			$this->record($ticket, $actor, 'asset_linked', $previous ? $previous->asset_tag : null, $asset->asset_tag);
		});

		$ticket->setRelation('asset', $asset);

		$this->events->fire('ticket.asset_linked', array($ticket, $asset, $actor));

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
