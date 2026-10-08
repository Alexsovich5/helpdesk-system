<?php

class TicketComment extends Eloquent {

	protected $table = 'ticket_comments';

	protected $fillable = array('body', 'is_internal');

	public function ticket()
	{
		return $this->belongsTo('Ticket');
	}

	public function author()
	{
		return $this->belongsTo('User', 'user_id');
	}

	public function scopePublic($query)
	{
		return $query->where('is_internal', false);
	}

}
