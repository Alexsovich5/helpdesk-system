<?php

use Helpdesk\Tickets\InvalidTransitionException;
use Helpdesk\Tickets\StatusMachine;
use Helpdesk\Tickets\TicketService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TicketController extends BaseController {

	const PER_PAGE = 20;

	protected $tickets;

	public function __construct(TicketService $tickets)
	{
		$this->tickets = $tickets;
	}

	/**
	 * GET /tickets?status=&priority=&category=&assignee=&q=&page=
	 *
	 * "assignee" takes a user id, or "none" for unassigned tickets.
	 */
	public function index()
	{
		$user = Auth::user();
		$filters = array_map('trim', array_merge(
			array('status' => '', 'priority' => '', 'category' => '', 'assignee' => '', 'q' => ''),
			array_filter(Input::only('status', 'priority', 'category', 'assignee', 'q'), 'is_string')
		));

		$query = Ticket::visibleTo($user)->with('category', 'requester', 'assignee');

		if (in_array($filters['status'], StatusMachine::statuses(), true))
		{
			$query->where('status', $filters['status']);
		}

		if (in_array($filters['priority'], Ticket::$priorities, true))
		{
			$query->where('priority', $filters['priority']);
		}

		if (ctype_digit($filters['category']))
		{
			$query->where('category_id', (int) $filters['category']);
		}

		if ($filters['assignee'] === 'none')
		{
			$query->whereNull('assignee_id');
		}
		elseif (ctype_digit($filters['assignee']))
		{
			$query->where('assignee_id', (int) $filters['assignee']);
		}

		if ($filters['q'] !== '')
		{
			$term = '%'.$filters['q'].'%';

			$query->where(function($q) use ($term)
			{
				$q->where('number', 'like', $term)->orWhere('subject', 'like', $term);
			});
		}

		$tickets = $query->orderBy('id', 'desc')->paginate(static::PER_PAGE);
		$tickets->appends(array_filter($filters, 'strlen'));

		return View::make('tickets.index', array(
			'tickets'    => $tickets,
			'filters'    => $filters,
			'statuses'   => StatusMachine::statuses(),
			'priorities' => Ticket::$priorities,
			'categories' => Category::orderBy('name')->lists('name', 'id'),
			'agents'     => $user->isAgent() ? $this->agentOptions() : array(),
			'user'       => $user,
		));
	}

	public function create()
	{
		return View::make('tickets.create', array(
			'categories' => Category::orderBy('name')->lists('name', 'id'),
			'priorities' => Ticket::$priorities,
		));
	}

	public function store()
	{
		$data = Input::only('subject', 'description', 'category_id', 'priority');

		$validator = Validator::make($data, array(
			'subject'     => 'required|max:255',
			'description' => 'required',
			'category_id' => 'required|exists:categories,id',
			'priority'    => 'required|in:'.implode(',', Ticket::$priorities),
		));

		if ($validator->fails())
		{
			return Redirect::to('tickets/create')->withErrors($validator)->withInput();
		}

		$ticket = $this->tickets->create($data, Auth::user());

		return Redirect::to('tickets/'.$ticket->number)->with('status', "Ticket {$ticket->number} created.");
	}

	public function show($number)
	{
		$ticket = $this->findOrFail($number);
		$user = Auth::user();

		$ticket->load('category', 'requester', 'assignee', 'events.user', 'comments.author');

		return View::make('tickets.show', array(
			'ticket'     => $ticket,
			'user'       => $user,
			'timeline'   => $this->timeline($ticket, $user),
			'agents'     => $user->isAgent() ? $this->agentOptions() : array(),
			'targets'    => StatusMachine::targets($ticket->status),
			'priorities' => Ticket::$priorities,
		));
	}

	public function assign($number)
	{
		$ticket = $this->findOrFail($number);
		$assignee = User::find(Input::get('assignee_id'));

		if (is_null($assignee) || ! $assignee->isAgent())
		{
			return Redirect::to('tickets/'.$ticket->number)
				->withErrors(array('assignee_id' => 'Choose an agent to assign the ticket to.'));
		}

		$this->tickets->assign($ticket, $assignee, Auth::user());

		return Redirect::to('tickets/'.$ticket->number)->with('status', "Assigned to {$assignee->name}.");
	}

	public function status($number)
	{
		$ticket = $this->findOrFail($number);
		$to = (string) Input::get('status');

		try
		{
			$this->tickets->transition($ticket, $to, Auth::user());
		}
		catch (AccessDeniedHttpException $e)
		{
			return Response::make('Forbidden', 403);
		}
		catch (InvalidTransitionException $e)
		{
			return Redirect::to('tickets/'.$ticket->number)->withErrors(array('status' => $e->getMessage()));
		}

		return Redirect::to('tickets/'.$ticket->number)->with('status', "Status changed to $to.");
	}

	public function priority($number)
	{
		$ticket = $this->findOrFail($number);
		$priority = (string) Input::get('priority');

		if ( ! in_array($priority, Ticket::$priorities, true))
		{
			return Redirect::to('tickets/'.$ticket->number)
				->withErrors(array('priority' => 'Choose one of: '.implode(', ', Ticket::$priorities).'.'));
		}

		$this->tickets->changePriority($ticket, $priority, Auth::user());

		return Redirect::to('tickets/'.$ticket->number)->with('status', "Priority changed to $priority.");
	}

	/**
	 * @return Ticket
	 */
	protected function findOrFail($number)
	{
		$ticket = Ticket::findByNumber($number);

		if (is_null($ticket)) App::abort(404);

		return $ticket;
	}

	/**
	 * @return array user id => name for everyone who can be assigned a ticket
	 */
	protected function agentOptions()
	{
		return User::whereIn('role', array('agent', 'admin'))->orderBy('name')->lists('name', 'id');
	}

	/**
	 * Events and comments merged in the order they happened. Comment events
	 * are left out because the comment itself is shown, and internal
	 * comments are only listed for agents.
	 *
	 * @return array of array('kind' => 'event'|'comment', 'at' => Carbon, 'item' => model)
	 */
	protected function timeline(Ticket $ticket, User $user)
	{
		$entries = array();

		foreach ($ticket->events as $event)
		{
			if ($event->type === 'comment') continue;

			$entries[] = array('kind' => 'event', 'at' => $event->created_at, 'item' => $event, 'order' => array($event->created_at->timestamp, 0, $event->id));
		}

		foreach ($ticket->comments as $comment)
		{
			if ($comment->is_internal && ! $user->isAgent()) continue;

			$entries[] = array('kind' => 'comment', 'at' => $comment->created_at, 'item' => $comment, 'order' => array($comment->created_at->timestamp, 1, $comment->id));
		}

		usort($entries, function($a, $b)
		{
			return $a['order'] < $b['order'] ? -1 : ($a['order'] > $b['order'] ? 1 : 0);
		});

		return $entries;
	}

}
