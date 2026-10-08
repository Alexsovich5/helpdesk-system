<?php

require_once __DIR__.'/../../../docker/layout-tree.php';

class ReadmeTest extends TestCase {

	/**
	 * @return string
	 */
	private function readme()
	{
		$path = base_path().'/README.md';

		$this->assertFileExists($path);

		return file_get_contents($path);
	}

	/**
	 * README text with fenced code blocks removed, so only inline code
	 * spans remain between backticks.
	 *
	 * @return string
	 */
	private function prose()
	{
		return preg_replace('/^```.*?^```[ \t]*$/ms', '', $this->readme());
	}

	/**
	 * Inline code spans that look like repository paths: no spaces, no
	 * leading slash (routes), and either a directory separator or a file
	 * extension.
	 *
	 * @return array
	 */
	private function backtickedPaths()
	{
		preg_match_all('/`([^`\n]+)`/', $this->prose(), $matches);

		$paths = array();

		foreach ($matches[1] as $span)
		{
			if ( ! preg_match('#^\.?[A-Za-z0-9_\-]+(?:[./][A-Za-z0-9_\-]+)*/?$#', $span)) continue;

			if (strpos($span, '/') !== false || preg_match('/\.[A-Za-z]{1,5}$/', $span))
			{
				$paths[] = $span;
			}
		}

		return array_unique($paths);
	}

	public function testReadmeNamesRepositoryPaths()
	{
		$paths = $this->backtickedPaths();

		$this->assertContains('docker/ldap', $paths);
		$this->assertContains('docker/smtp-sink', $paths);
		$this->assertContains('composer.json', $paths);
	}

	public function testEveryBacktickedPathExists()
	{
		$missing = array();

		foreach ($this->backtickedPaths() as $path)
		{
			if ( ! file_exists(base_path().'/'.rtrim($path, '/'))) $missing[] = $path;
		}

		$this->assertSame(array(), $missing, 'README names paths that do not exist: '.implode(', ', $missing));
	}

	public function testReadmeHasNoGenericClaims()
	{
		$pattern = '/\*\*Role\*\*|\*\*Timeline\*\*|Status: Complete|uptime|MTTR|developed during|\d+\.\d+\s?%/i';

		$this->assertSame(0, preg_match($pattern, $this->readme(), $match),
			'README contains a generic claim: '.(isset($match[0]) ? $match[0] : ''));
	}

	public function testReadmeHasNoTemplatePlaceholders()
	{
		$readme = $this->readme();

		$this->assertNotContains('{{', $readme);
		$this->assertNotContains('Rules for this README', $readme);
	}

	public function testReadmeStatesTheSimulatedServices()
	{
		$readme = $this->readme();

		$this->assertContains('## Status', $readme);
		$this->assertContains('**Implemented**', $readme);
		$this->assertContains('**Not implemented / known limitations**', $readme);
		$this->assertContains('neither has been run against a real directory or mail server', $readme);
	}

	public function testLayoutTreeRendersNestedPaths()
	{
		$expected = implode("\n", array(
			'.',
			'├── Makefile',
			'├── app',
			'│   ├── a.php',
			'│   └── sub',
			'│       └── b.php',
			'└── z.txt',
		));

		$this->assertSame($expected, layout_tree(array('Makefile', 'app/a.php', 'app/sub/b.php', 'z.txt')));
	}

	public function testLayoutBlockMatchesGitLsFiles()
	{
		$root = base_path();

		if ( ! file_exists($root.'/.git'))
		{
			$this->markTestSkipped('.git is not available in this container');
		}

		exec('git -C '.escapeshellarg($root).' ls-files', $files, $status);

		$this->assertSame(0, $status, 'git ls-files failed');
		$this->assertNotEmpty($files);

		$this->assertSame(1, preg_match('/^## Layout\n.*?^```\n(.*?)\n```/ms', $this->readme(), $match),
			'README has no fenced Layout block');

		$this->assertSame(layout_tree($files), $match[1]);
	}

}
