<?php

/**
 * Package retrieval for every image stays authenticated: no Dockerfile or
 * apt configuration file in the repository may bypass signature checks or
 * use a plain-HTTP source, the Debian-based images install only the .debs
 * that docker/debs/fetch_verified_debs.sh verified, and that script keeps
 * its HTTPS-only download and gpgv / SHA256 checks.
 */
class BuildSourcesTest extends PHPUnit_Framework_TestCase {

	const BYPASS = '/--allow-unauthenticated|--force-yes|trusted=yes|AllowUnauthenticated|--no-check-gpg|AllowInsecureRepositories|allow-insecure|http:\/\//i';

	protected function root()
	{
		return realpath(__DIR__.'/../../../..');
	}

	/**
	 * Every Dockerfile and apt configuration file below the checkout,
	 * relative to it.
	 *
	 * @return array
	 */
	protected function buildFiles()
	{
		$root = $this->root();
		$files = array();
		$skip = array('vendor', '.git', 'node_modules', 'storage');

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
				function($file) use ($skip)
				{
					return ! ($file->isDir() && in_array($file->getFilename(), $skip, true));
				}
			)
		);

		foreach ($iterator as $file)
		{
			$path = substr($file->getPathname(), strlen($root) + 1);
			$name = $file->getFilename();

			if (preg_match('/^Dockerfile|\.dockerfile$|\.list$|\.sources$|^apt\.conf|^docker\/debs\//', $name)
				|| strpos($path, 'docker/debs/') === 0
				|| strpos($path, 'apt.conf') !== false)
			{
				$files[] = $path;
			}
		}

		sort($files);

		return $files;
	}

	protected function bypassLines($text)
	{
		$lines = array();

		foreach (preg_split('/\r?\n/', $text) as $number => $line)
		{
			if (preg_match(static::BYPASS, $line)) $lines[] = ($number + 1).': '.trim($line);
		}

		return $lines;
	}

	public function testScannerCatchesEveryFormOfTheBypass()
	{
		$bad = implode("\n", array(
			"RUN echo 'deb http://archive.debian.org/debian stretch main' > /etc/apt/sources.list",
			"RUN echo 'APT::Get::AllowUnauthenticated \"true\";' > /etc/apt/apt.conf.d/10archive",
			'RUN apt-get install -y --force-yes slapd',
			'RUN apt-get install -y --allow-unauthenticated slapd',
			"RUN echo 'deb [trusted=yes] https://example.invalid/debian stretch main' > /etc/apt/sources.list",
			'RUN apt-get -o Acquire::AllowInsecureRepositories=true update',
			'RUN apt-get --no-check-gpg update',
		));

		$this->assertCount(7, $this->bypassLines($bad));
		$this->assertSame(array(), $this->bypassLines("RUN curl -fsSL https://example.invalid/x\nFROM php:5.6-apache"));
	}

	public function testScanCoversTheAppAndDirectoryImages()
	{
		$files = $this->buildFiles();

		$this->assertContains('Dockerfile', $files);
		$this->assertContains('docker/ldap/Dockerfile', $files);
		$this->assertContains('docker/smtp-sink/Dockerfile', $files);
		$this->assertContains('docker/debs/fetch_verified_debs.sh', $files);
		$this->assertContains('docker/debs/app.list', $files);
		$this->assertContains('docker/debs/ldap.list', $files);
	}

	public function testNoBuildFileBypassesSignaturesOrUsesPlainHttp()
	{
		$found = array();

		foreach ($this->buildFiles() as $path)
		{
			foreach ($this->bypassLines(file_get_contents($this->root().'/'.$path)) as $line)
			{
				$found[] = $path.':'.$line;
			}
		}

		$this->assertSame(array(), $found, "unauthenticated or plain-HTTP package source:\n".implode("\n", $found));
	}

	public function testManifestsUseHttpsSnapshotRepositories()
	{
		foreach (array('app', 'ldap') as $name)
		{
			$repos = 0;

			foreach (file($this->root().'/docker/debs/'.$name.'.list') as $line)
			{
				$fields = preg_split('/\s+/', trim($line));

				if ($fields[0] !== 'repo') continue;

				$repos++;
				$this->assertRegExp('#^https://snapshot\.debian\.org/archive/#', $fields[2], "$name.list: ".trim($line));
			}

			$this->assertGreaterThan(0, $repos, "$name.list declares no repository");
		}
	}

	public function testFetchScriptVerifiesTheWholeChain()
	{
		$script = file_get_contents($this->root().'/docker/debs/fetch_verified_debs.sh');

		$this->assertContains("--proto '=https' --proto-redir '=https'", $script);
		$this->assertContains('gpgv --status-fd', $script);
		$this->assertContains('/usr/share/keyrings/debian-archive-keyring.gpg', $script);
		$this->assertContains('/usr/share/keyrings/debian-archive-removed-keys.gpg', $script);
		$this->assertContains('VALIDSIG', $script);
		$this->assertRegExp('/BADSIG\|ERRSIG\|NO_PUBKEY\|REVKEYSIG/', $script);
		$this->assertGreaterThanOrEqual(3, preg_match_all('/^\s*check_file "/m', $script));
	}

	/**
	 * @dataProvider periodImages
	 */
	public function testPeriodStageInstallsOnlyTheVerifiedDebs($dockerfile, $from, $manifest)
	{
		$text = file_get_contents($this->root().'/'.$dockerfile);

		$this->assertContains('/build/'.$manifest, $text);

		$start = strpos($text, "\nFROM ".$from);
		$this->assertNotSame(false, $start, "$dockerfile has no $from stage");
		$stage = substr($text, $start);

		$this->assertNotContains('apt-get', $stage, "the $from stage must not run apt-get");
		$this->assertContains('sha256sum -c SHA256SUMS', $stage);
		$this->assertContains('dpkg -i', $stage);
		$this->assertContains('rm -f /etc/apt/sources.list', $stage);
	}

	public function periodImages()
	{
		return array(
			array('Dockerfile', 'php:5.6-apache@sha256:', 'app.list'),
			array('docker/ldap/Dockerfile', 'debian:wheezy@sha256:', 'ldap.list'),
		);
	}

	public function testTheBuiltImageHasNoAptSourcesLeft()
	{
		if ($this->root() !== '/var/www/html')
		{
			$this->markTestSkipped('only meaningful inside the app image');
		}

		$this->assertFileNotExists('/etc/apt/sources.list');
		$this->assertSame(array(), glob('/etc/apt/sources.list.d/*'));
	}

	public function testComposerPharIsCheckedAgainstItsPublishedSha256()
	{
		$text = file_get_contents($this->root().'/Dockerfile');

		$this->assertRegExp('/[0-9a-f]{64}\s+\/usr\/local\/bin\/composer" \| sha256sum -c/', $text);
	}

}
