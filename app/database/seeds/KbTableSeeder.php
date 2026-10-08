<?php

class KbTableSeeder extends Seeder {

	/**
	 * Knowledge-base categories and the published articles filed under each,
	 * as category => array(title => body). Safe to run again: existing
	 * categories and titles are left alone.
	 */
	public static $articles = array(
		'Accounts & Access' => array(
			'Reset your password' => "Passwords are managed in the directory.\n\n1. Press Ctrl+Alt+Del on a domain PC and choose \"Change a password\".\n2. Enter the old password once and the new one twice.\n3. Sign out of e-mail on your phone and sign back in with the new password.\n\nIf the old password is no longer known, raise a ticket in \"Accounts & Access\" and an agent will set a temporary one.",
			'Account locked out' => "Five wrong passwords in a row lock the account for 30 minutes.\n\nThe usual cause is a phone or laptop still trying an old password. Update the password on every device, then wait for the lock to expire or ask the help desk to unlock it.",
		),
		'E-mail' => array(
			'Set up e-mail on a phone' => "Use the built-in mail app and choose Exchange.\n\nServer: mail.helpdesk.local\nUser name: your directory user name\nPassword: your directory password\n\nAccept the security policy when asked; it requires a screen lock PIN.",
		),
		'Network' => array(
			'Connect to the VPN' => "1. Start the VPN client.\n2. Choose the \"Office\" profile.\n3. Sign in with your directory user name and password.\n\nIf the client reports a certificate error, check that the laptop clock is correct and try again.",
		),
		'Printing' => array(
			'Add a network printer' => "Open Devices and Printers, choose \"Add a printer\", then \"Add a network printer\". Pick the printer whose name matches the label on the device. Printers are named after their floor and room, for example 2F-201.",
		),
	);

	public function run()
	{
		$author = User::whereIn('role', array('admin', 'agent'))->orderBy('id')->first();

		foreach (static::$articles as $categoryName => $articles)
		{
			$category = KbCategory::firstOrCreate(array('name' => $categoryName));

			if (is_null($author)) continue;

			foreach ($articles as $title => $body)
			{
				if (KbArticle::where('title', $title)->exists()) continue;

				$article = new KbArticle(array('title' => $title, 'body' => $body));
				$article->kb_category_id = $category->id;
				$article->author_id = $author->id;
				$article->is_published = true;
				$article->save();
			}
		}
	}

}
