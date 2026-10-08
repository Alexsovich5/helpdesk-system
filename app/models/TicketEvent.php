<?php

use Carbon\Carbon;

/**
 * One row of a ticket's append-only history. Rows are written once and never
 * updated, so the table has created_at but no updated_at.
 */
class TicketEvent extends Eloquent {

	protected $table = 'ticket_events';

	public $timestamps = false;

	protected $fillable = array('type', 'from_value', 'to_value');

	protected $dates = array('created_at');

	/**
	 * Stamps created_at on insert and refuses to update an existing row.
	 * Done here rather than in model event listeners, which are registered
	 * only once per class and so would not survive a fresh application
	 * instance (as in tests).
	 */
	public function save(array $options = array())
	{
		if ($this->exists) return false;

		if (is_null($this->created_at)) $this->created_at = Carbon::now();

		return parent::save($options);
	}

	public function ticket()
	{
		return $this->belongsTo('Ticket');
	}

	public function user()
	{
		return $this->belongsTo('User');
	}

}
