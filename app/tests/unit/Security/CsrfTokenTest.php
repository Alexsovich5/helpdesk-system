<?php

use Helpdesk\Security\CsrfToken;

class CsrfTokenTest extends PHPUnit_Framework_TestCase {

	public function testTheSessionTokenMatchesItself()
	{
		$token = str_repeat('aB3', 13).'x';

		$this->assertTrue(CsrfToken::matches($token, $token));
	}

	public function testDifferentTokensDoNotMatch()
	{
		$this->assertFalse(CsrfToken::matches('abcdef', 'abcdeg'));
		$this->assertFalse(CsrfToken::matches('abcdef', 'ABCDEF'));
		$this->assertFalse(CsrfToken::matches('abcdef', 'abcdef '));
	}

	public function testMissingTokensNeverMatch()
	{
		$this->assertFalse(CsrfToken::matches(null, null));
		$this->assertFalse(CsrfToken::matches('', ''));
		$this->assertFalse(CsrfToken::matches(null, ''));
		$this->assertFalse(CsrfToken::matches('abcdef', null));
	}

	public function testNonStringInputNeverMatches()
	{
		$this->assertFalse(CsrfToken::matches('abcdef', array('abcdef')));
		$this->assertFalse(CsrfToken::matches('100', 100));
		$this->assertFalse(CsrfToken::matches('1e2', '100'));
		$this->assertFalse(CsrfToken::matches('0', false));
	}

}
