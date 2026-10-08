<?php

use Helpdesk\Tickets\TicketNumber;

class TicketNumberTest extends PHPUnit_Framework_TestCase {

	public function testFormatsIdWithSixDigitPadding()
	{
		$this->assertSame('HD-000001', TicketNumber::fromId(1));
		$this->assertSame('HD-000123', TicketNumber::fromId(123));
		$this->assertSame('HD-999999', TicketNumber::fromId(999999));
	}

	public function testLargerIdsAreNotTruncated()
	{
		$this->assertSame('HD-1234567', TicketNumber::fromId(1234567));
	}

	public function testRejectsNonPositiveIds()
	{
		$this->setExpectedException('InvalidArgumentException');

		TicketNumber::fromId(0);
	}

	public function testParsesNumberBackToId()
	{
		$this->assertSame(123, TicketNumber::toId('HD-000123'));
		$this->assertSame(1234567, TicketNumber::toId('hd-1234567'));
	}

	public function testParseReturnsNullForMalformedNumbers()
	{
		$this->assertNull(TicketNumber::toId('123'));
		$this->assertNull(TicketNumber::toId('HD-12a'));
		$this->assertNull(TicketNumber::toId('HD-000000'));
		$this->assertNull(TicketNumber::toId(''));
	}

}
