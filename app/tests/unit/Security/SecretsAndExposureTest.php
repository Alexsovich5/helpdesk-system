<?php

use Helpdesk\Security\AppKey;

/**
 * No encryption key in the repository, debug pages off unless asked for,
 * and the compose stack published on the loopback interface only.
 */
class SecretsAndExposureTest extends TestCase {

	const KEY_VALUE = '/APP_KEY["\']?\s*(?:=>|=|:)\s*["\']?([A-Za-z0-9+\/=_-]{16,})/';

	protected function root()
	{
		return base_path();
	}

	/**
	 * Tracked files (git ls-files when .git is mounted, otherwise every file
	 * in the checkout outside vendor/ and app/storage/).
	 *
	 * @return array
	 */
	protected function trackedFiles()
	{
		$root = $this->root();

		if (file_exists($root.'/.git'))
		{
			exec('git -C '.escapeshellarg($root).' ls-files', $files, $status);
			$this->assertSame(0, $status, 'git ls-files failed');

			return $files;
		}

		$files = array();
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

		foreach ($iterator as $file)
		{
			$path = substr($file->getPathname(), strlen($root) + 1);

			if (preg_match('#^(vendor|app/storage|public/vendor)/#', $path)) continue;

			$files[] = $path;
		}

		return $files;
	}

	public function testScannerRecognisesKeyAssignments()
	{
		// Built at run time so this file does not match its own scan.
		$name = 'APP'.'_KEY';
		$value = 'abcdefghijklmnopqrstuvwxyz012345';

		foreach (array(
			"'$name' => '$value',",
			"      $name: $value",
			"$name=$value",
		) as $line)
		{
			$this->assertSame(1, preg_match(static::KEY_VALUE, $line), $line);
		}

		$this->assertSame(0, preg_match(static::KEY_VALUE, "'key' => getenv('APP_KEY'),"));
	}

	public function testNoTrackedFileContainsAnAppKeyValue()
	{
		$oldKey = 'LocalSampleKey'.'ForHelpdeskStack32';
		$found = array();

		foreach ($this->trackedFiles() as $path)
		{
			$full = $this->root().'/'.$path;
			if ( ! is_file($full)) continue;

			$text = file_get_contents($full);

			if (preg_match(static::KEY_VALUE, $text, $m) || strpos($text, $oldKey) !== false)
			{
				$found[] = $path;
			}
		}

		$this->assertSame(array(), $found, 'files with an APP_KEY value: '.implode(', ', $found));
	}

	public function testEnvironmentFilesAreIgnoredAndOnlyExamplesAreTracked()
	{
		$files = $this->trackedFiles();

		$this->assertNotContains('.env.local.php', $files);
		$this->assertNotContains('.env.testing.php', $files);
		$this->assertContains('.env.local.php.example', $files);

		$ignore = file_get_contents($this->root().'/.gitignore');
		$this->assertRegExp('#^/\.env\.\*\.php$#m', $ignore);
	}

	public function testTestingEnvironmentGeneratesItsOwnKey()
	{
		$key = Config::get('app.key');

		$this->assertSame(32, strlen($key));
		$this->assertNotSame('LocalSampleKey'.'ForHelpdeskStack32', $key);
		$this->assertTrue(AppKey::usable($key));
	}

	public function testAppKeyCheckRejectsMissingShortAndKnownKeys()
	{
		$this->assertFalse(AppKey::usable(''));
		$this->assertFalse(AppKey::usable(false));
		$this->assertFalse(AppKey::usable('short'));
		$this->assertFalse(AppKey::usable('YourSecretKey!!!'));
		$this->assertFalse(AppKey::usable('LocalSampleKey'.'ForHelpdeskStack32'));
		$this->assertTrue(AppKey::usable(str_random(32)));
	}

	public function testKeyFileIsReadWhenTheEnvironmentHasNoKey()
	{
		$file = tempnam(sys_get_temp_dir(), 'appkey');
		file_put_contents($file, str_repeat('k', 32)."\n");

		try
		{
			$this->assertSame(str_repeat('k', 32), AppKey::fromFile($file));
			$this->assertFalse(AppKey::fromFile($file.'.missing'));
			$this->assertFalse(AppKey::fromFile(''));
		}
		finally
		{
			unlink($file);
		}
	}

	public function testAppConfigFallsBackToTheKeyFile()
	{
		$config = file_get_contents(app_path().'/config/app.php');

		$this->assertContains("getenv('APP_KEY') ?: Helpdesk\\Security\\AppKey::fromFile(", $config);
	}

	public function testAppKeyCheckThrowsWithoutShowingTheKey()
	{
		try
		{
			AppKey::assertUsable('S3NTINEL-pw');
			$this->fail('a short key was accepted');
		}
		catch (RuntimeException $e)
		{
			$this->assertContains('APP_KEY', $e->getMessage());
			$this->assertNotContains('S3NTINEL-pw', $e->getMessage());
		}
	}

	public function testLocalDebugIsOffUnlessAppDebugIsSet()
	{
		$previous = getenv('APP_DEBUG');

		try
		{
			putenv('APP_DEBUG');
			$config = require app_path().'/config/local/app.php';
			$this->assertFalse($config['debug']);

			putenv('APP_DEBUG=false');
			$config = require app_path().'/config/local/app.php';
			$this->assertFalse($config['debug']);

			putenv('APP_DEBUG=true');
			$config = require app_path().'/config/local/app.php';
			$this->assertTrue($config['debug']);
		}
		finally
		{
			putenv($previous === false ? 'APP_DEBUG' : 'APP_DEBUG='.$previous);
		}
	}

	public function testComposeDoesNotSetDebugOrAKey()
	{
		$compose = file_get_contents($this->root().'/docker-compose.yml');

		$this->assertNotRegExp('/^\s*APP_DEBUG:\s*["\']?(true|1)/mi', $compose);
		$this->assertNotRegExp('/^\s*APP_KEY:/m', $compose);
	}

	public function testPublishedPortsAreBoundToLoopback()
	{
		$lines = file($this->root().'/docker-compose.yml');
		$ports = array();
		$inPorts = false;

		foreach ($lines as $line)
		{
			if (preg_match('/^(\s*)ports:\s*$/', $line, $m))
			{
				$inPorts = strlen($m[1]);
				continue;
			}

			if ($inPorts !== false)
			{
				if (preg_match('/^(\s*)-\s*(.+?)\s*$/', $line, $m) && strlen($m[1]) >= $inPorts)
				{
					$ports[] = trim($m[2], '"\'');
					continue;
				}

				$inPorts = false;
			}
		}

		$this->assertNotEmpty($ports, 'docker-compose.yml publishes no port');

		foreach ($ports as $port)
		{
			$this->assertStringStartsWith('127.0.0.1:', $port);
		}
	}

}
