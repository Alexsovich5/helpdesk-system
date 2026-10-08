<?php

/**
 * Response and resolution targets, in minutes, for one ticket priority.
 */
class SlaPolicy extends Eloquent {

	protected $table = 'sla_policies';

	protected $fillable = array('priority', 'response_minutes', 'resolution_minutes');

	/**
	 * @param  string  $priority
	 * @return SlaPolicy|null
	 */
	public static function forPriority($priority)
	{
		return static::where('priority', $priority)->first();
	}

}
