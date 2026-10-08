<?php

use Carbon\Carbon;
use Helpdesk\Reports\CsvExporter;

class CsvExporterTest extends TestCase {

	public function testWritesHeaderThenRows()
	{
		$csv = (new CsvExporter)->toCsv(array('a', 'b'), array(array('1', '2'), array('3', '4')));

		$this->assertSame("a,b\n1,2\n3,4\n", $csv);
	}

	public function testQuotesCommasAndDoublesQuotes()
	{
		$csv = (new CsvExporter)->toCsv(array('subject'), array(array('Printer, 2nd floor'), array('Says "paper jam"')));

		$this->assertSame("subject\n\"Printer, 2nd floor\"\n\"Says \"\"paper jam\"\"\"\n", $csv);

		$lines = array_map('str_getcsv', explode("\n", trim($csv)));
		$this->assertSame(array('Says "paper jam"'), $lines[2]);
	}

	public function testHeaderOnlyWhenThereAreNoRows()
	{
		$this->assertSame("a,b\n", (new CsvExporter)->toCsv(array('a', 'b'), array()));
	}

	public function testTicketRows()
	{
		Artisan::call('migrate');

		$requester = User::create(array('username' => 'carol', 'name' => 'Carol', 'email' => 'carol@helpdesk.local', 'role' => 'requester', 'source' => 'local'));
		$category = Category::create(array('name' => 'Printing'));

		$ticket = new Ticket;
		$ticket->number = 'HD-000042';
		$ticket->subject = 'Toner, black';
		$ticket->description = 'x';
		$ticket->status = 'open';
		$ticket->priority = 'high';
		$ticket->category_id = $category->id;
		$ticket->requester_id = $requester->id;
		$ticket->sla_state = 'warning';
		$ticket->created_at = Carbon::create(2014, 1, 6, 9, 0, 0);
		$ticket->first_responded_at = Carbon::create(2014, 1, 6, 9, 10, 0);
		$ticket->save();

		$csv = (new CsvExporter)->tickets(Ticket::with('category', 'requester', 'assignee')->get());
		$lines = explode("\n", trim($csv));

		$this->assertSame(CsvExporter::$ticketHeader, str_getcsv($lines[0]));
		$this->assertSame(
			'HD-000042,"Toner, black",open,high,Printing,Carol,,"2014-01-06 09:00:00","2014-01-06 09:10:00",,warning',
			$lines[1]
		);
	}

}
