<?php namespace Helpdesk\Tickets;

/**
 * Allowed ticket status transitions (SPEC section 5.2).
 */
class StatusMachine {

	protected static $transitions = array(
		'new'      => array('open', 'resolved', 'closed'),
		'open'     => array('pending', 'resolved'),
		'pending'  => array('open', 'resolved'),
		'resolved' => array('open', 'closed'),
		'closed'   => array(),
	);

	/**
	 * @return array every status, in workflow order
	 */
	public static function statuses()
	{
		return array_keys(static::$transitions);
	}

	/**
	 * @param  string  $from
	 * @return array statuses reachable from $from in one step
	 */
	public static function targets($from)
	{
		return isset(static::$transitions[$from]) ? static::$transitions[$from] : array();
	}

	public static function can($from, $to)
	{
		return in_array($to, static::targets($from), true);
	}

	/**
	 * @throws InvalidTransitionException
	 */
	public static function check($from, $to)
	{
		if ( ! static::can($from, $to))
		{
			throw new InvalidTransitionException($from, $to);
		}
	}

}
