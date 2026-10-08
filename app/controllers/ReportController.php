<?php

use Carbon\Carbon;
use Helpdesk\Reports\CsvExporter;
use Helpdesk\Reports\ReportService;

class ReportController extends BaseController {

	protected $reports;

	protected $exporter;

	public function __construct(ReportService $reports, CsvExporter $exporter)
	{
		$this->reports = $reports;
		$this->exporter = $exporter;
	}

	/**
	 * GET /reports?from=YYYY-MM-DD&to=YYYY-MM-DD
	 */
	public function index()
	{
		list($from, $to) = $this->range();

		return View::make('reports.index', array(
			'summary' => $this->reports->summary($from, $to),
			'query'   => array('from' => $from->toDateString(), 'to' => $to->toDateString()),
		));
	}

	/**
	 * GET /reports/export.csv?from=YYYY-MM-DD&to=YYYY-MM-DD
	 */
	public function export()
	{
		list($from, $to) = $this->range();

		$csv = $this->exporter->tickets($this->reports->tickets($from, $to));
		$filename = sprintf('tickets-%s-to-%s.csv', $from->toDateString(), $to->toDateString());

		return Response::make($csv, 200, array(
			'Content-Type'        => 'text/csv; charset=UTF-8',
			'Content-Disposition' => 'attachment; filename="'.$filename.'"',
		));
	}

	/**
	 * The requested range, or the current month to date when a date is
	 * missing or invalid. A reversed range is put in order.
	 *
	 * @return array  array(Carbon from, Carbon to)
	 */
	protected function range()
	{
		$from = $this->date(Input::get('from'));
		$to = $this->date(Input::get('to'));

		if (is_null($from) || is_null($to))
		{
			$today = Carbon::today();

			return array($today->copy()->startOfMonth(), $today);
		}

		return $from->gt($to) ? array($to, $from) : array($from, $to);
	}

	/**
	 * @param  mixed  $value
	 * @return Carbon|null
	 */
	protected function date($value)
	{
		if ( ! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return null;

		if ( ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;

		return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0);
	}

}
