<?php namespace Helpdesk\Tickets;

use InvalidArgumentException;

/**
 * Ticket numbers are the row id with a prefix: id 123 is HD-000123.
 */
class TicketNumber {

	const PREFIX = 'HD-';

	/**
	 * @param  int  $id
	 * @return string
	 */
	public static function fromId($id)
	{
		if ( ! ctype_digit((string) $id) || (int) $id < 1)
		{
			throw new InvalidArgumentException("Ticket id must be a positive integer, got \"$id\".");
		}

		return sprintf(static::PREFIX.'%06d', $id);
	}

	/**
	 * @param  string  $number
	 * @return int|null the id, or null when $number is not a ticket number
	 */
	public static function toId($number)
	{
		if ( ! preg_match('/^HD-(\d{6,})$/i', (string) $number, $m)) return null;

		$id = (int) $m[1];

		return $id > 0 ? $id : null;
	}

}
