<?php

use Helpdesk\Tickets\TicketNumber;

class Ticket extends Eloquent {

	/**
	 * Statuses that still need work from the help desk.
	 *
	 * @var array
	 */
	public static $openStatuses = array('new', 'open', 'pending');

	public static $priorities = array('low', 'normal', 'high', 'urgent');

	protected $table = 'tickets';

	protected $fillable = array('subject', 'description', 'category_id', 'priority');

	protected $dates = array('response_due_at', 'resolution_due_at', 'first_responded_at', 'resolved_at', 'closed_at');

	public function requester()
	{
		return $this->belongsTo('User', 'requester_id');
	}

	public function assignee()
	{
		return $this->belongsTo('User', 'assignee_id');
	}

	public function category()
	{
		return $this->belongsTo('Category');
	}

	public function comments()
	{
		return $this->hasMany('TicketComment')->orderBy('id');
	}

	public function events()
	{
		return $this->hasMany('TicketEvent')->orderBy('id');
	}

	/**
	 * Agents see every ticket; anyone else only the tickets they raised.
	 */
	public function scopeVisibleTo($query, User $user)
	{
		if ($user->isAgent()) return $query;

		return $query->where('requester_id', $user->id);
	}

	public function scopeOpen($query)
	{
		return $query->whereIn('status', static::$openStatuses);
	}

	public function isOpen()
	{
		return in_array($this->status, static::$openStatuses, true);
	}

	public function isOwnedBy(User $user)
	{
		return (int) $this->requester_id === (int) $user->id;
	}

	/**
	 * @param  string  $number  e.g. HD-000123
	 * @return Ticket|null
	 */
	public static function findByNumber($number)
	{
		$id = TicketNumber::toId($number);

		return is_null($id) ? null : static::where('number', TicketNumber::fromId($id))->first();
	}

}
