<?php namespace Helpdesk\Reports;

use Carbon\Carbon;

/**
 * Builds CSV text with fputcsv, so commas, quotes and line breaks in values
 * are quoted the standard way.
 *
 * Spreadsheet programs run a cell that starts with = + - @ (or a tab or
 * carriage return before one) as a formula, so a ticket subject such as
 * =HYPERLINK(...) would execute when an agent opens the export. Every string
 * cell, header included, that starts with one of those characters gets a
 * leading single quote. Integers and floats are written as numbers.
 */
class CsvExporter {

	public static $ticketHeader = array(
		'number', 'subject', 'status', 'priority', 'category', 'requester', 'assignee',
		'created_at', 'first_responded_at', 'resolved_at', 'sla_state',
	);

	/**
	 * @param  array  $header
	 * @param  array  $rows  arrays of scalar values
	 * @return string
	 */
	public function toCsv(array $header, array $rows)
	{
		$handle = fopen('php://temp', 'r+');

		fputcsv($handle, array_map(array($this, 'cell'), $header));

		foreach ($rows as $row)
		{
			fputcsv($handle, array_map(array($this, 'cell'), $row));
		}

		rewind($handle);
		$csv = stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}

	/**
	 * One row per ticket; load category, requester and assignee first.
	 *
	 * @param  \Traversable|array  $tickets
	 * @return string
	 */
	public function tickets($tickets)
	{
		$rows = array();

		foreach ($tickets as $ticket)
		{
			$rows[] = array(
				$ticket->number,
				$ticket->subject,
				$ticket->status,
				$ticket->priority,
				$ticket->category ? $ticket->category->name : '',
				$ticket->requester ? $ticket->requester->name : '',
				$ticket->assignee ? $ticket->assignee->name : '',
				$this->time($ticket->created_at),
				$this->time($ticket->first_responded_at),
				$this->time($ticket->resolved_at),
				$ticket->sla_state,
			);
		}

		return $this->toCsv(static::$ticketHeader, $rows);
	}

	/**
	 * @param  mixed  $value
	 * @return mixed
	 */
	public function cell($value)
	{
		if ( ! is_string($value) || $value === '') return $value;

		return strpbrk($value[0], "=+-@\t\r") === false ? $value : "'".$value;
	}

	protected function time(Carbon $value = null)
	{
		return $value ? $value->toDateTimeString() : '';
	}

}
