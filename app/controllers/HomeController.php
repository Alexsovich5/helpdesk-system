<?php

class HomeController extends BaseController {

	/**
	 * Requesters see their open tickets; agents see the open tickets
	 * assigned to them and the unassigned queue.
	 */
	public function getIndex()
	{
		$user = Auth::user();

		$data = array(
			'user' => $user,
			'mine' => Ticket::open()->where('requester_id', $user->id)->orderBy('id', 'desc')->get(),
		);

		if ($user->isAgent())
		{
			$data['assigned'] = Ticket::open()->where('assignee_id', $user->id)->with('requester')->orderBy('id', 'desc')->get();
			$data['queue'] = Ticket::open()->whereNull('assignee_id')->with('requester')->orderBy('id', 'desc')->get();
		}

		return View::make('home.index', $data);
	}

}
