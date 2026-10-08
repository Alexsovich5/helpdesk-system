<?php

use Helpdesk\Tickets\TicketService;

class CommentController extends BaseController {

	protected $tickets;

	public function __construct(TicketService $tickets)
	{
		$this->tickets = $tickets;
	}

	/**
	 * POST /tickets/{number}/comments. The internal flag is honoured for
	 * agents only; TicketService drops it for everyone else.
	 */
	public function store($number)
	{
		$ticket = Ticket::findByNumber($number);

		if (is_null($ticket)) App::abort(404);

		$validator = Validator::make(Input::only('body'), array('body' => 'required'));

		if ($validator->fails())
		{
			return Redirect::to('tickets/'.$ticket->number)->withErrors($validator)->withInput();
		}

		$this->tickets->comment($ticket, Auth::user(), Input::get('body'), (bool) Input::get('is_internal'));

		return Redirect::to('tickets/'.$ticket->number)->with('status', 'Comment added.');
	}

}
