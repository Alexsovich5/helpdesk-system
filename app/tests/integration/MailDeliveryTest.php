<?php

/**
 * Notifications leave the application over SMTP. The compose service
 * "smtp-sink" accepts every message and writes it as a .eml file to the
 * shared "mail-sink" volume, mounted at /var/mail-sink in the test runs.
 *
 * @group integration
 */
class MailDeliveryTest extends IntegrationTestCase {

	protected $sinkDir;

	public function setUp()
	{
		parent::setUp();

		$this->sinkDir = getenv('MAIL_SINK_DIR') ?: '/var/mail-sink';

		$this->assertTrue(is_dir($this->sinkDir), 'mail sink directory '.$this->sinkDir.' is not mounted');

		foreach (glob($this->sinkDir.'/*.eml') as $file)
		{
			unlink($file);
		}
	}

	public function testIntegrationEnvironmentSendsOverSmtp()
	{
		$this->assertFalse(Config::get('mail.pretend'));
		$this->assertSame('smtp', Config::get('mail.driver'));
		$this->assertSame('smtp-sink', Config::get('mail.host'));
	}

	public function testCreatingATicketDeliversTheAcknowledgementToTheSink()
	{
		$carol = User::firstOrNew(array('username' => 'carol'));
		$carol->name = 'Carol Requester';
		$carol->email = 'carol@helpdesk.local';
		$carol->role = 'requester';
		$carol->source = 'ldap';
		$carol->save();

		$category = Category::firstOrCreate(array('name' => 'Hardware'));

		$ticket = App::make('Helpdesk\Tickets\TicketService')->create(array(
			'subject'     => 'Docking station does not charge laptop',
			'description' => 'The laptop battery drains while docked.',
			'category_id' => $category->id,
			'priority'    => 'normal',
		), $carol);

		$message = $this->waitForMessage('carol@helpdesk.local', $ticket->number, 5);

		$this->assertNotNull($message, 'no .eml for carol mentioning '.$ticket->number.' within 5 s');
		$this->assertContains('carol@helpdesk.local', $message['to']);
		$this->assertContains($ticket->number, $message['subject']);
	}

	/**
	 * Polls the sink directory until a message addressed to $to with
	 * $subjectPart in its subject appears, or $seconds pass.
	 *
	 * @return array|null  array('to' => .., 'subject' => ..)
	 */
	protected function waitForMessage($to, $subjectPart, $seconds)
	{
		$deadline = microtime(true) + $seconds;

		do
		{
			foreach (glob($this->sinkDir.'/*.eml') as $file)
			{
				$headers = $this->headers(file_get_contents($file));

				if (isset($headers['to'], $headers['subject'])
					&& strpos($headers['to'], $to) !== false
					&& strpos($headers['subject'], $subjectPart) !== false)
				{
					return $headers;
				}
			}

			usleep(200000);
		}
		while (microtime(true) < $deadline);

		return null;
	}

	/**
	 * Parses the header block of a raw message, unfolding continuation
	 * lines. Keys are lower-cased header names.
	 */
	protected function headers($raw)
	{
		$parts = preg_split("/\r?\n\r?\n/", $raw, 2);
		$block = preg_replace("/\r?\n[ \t]+/", ' ', $parts[0]);

		$headers = array();
		foreach (preg_split("/\r?\n/", $block) as $line)
		{
			if (strpos($line, ':') === false) continue;

			list($name, $value) = explode(':', $line, 2);
			$headers[strtolower(trim($name))] = trim($value);
		}

		return $headers;
	}

}
