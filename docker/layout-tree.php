<?php
/**
 * Renders a list of repository paths (one per line on stdin, as printed by
 * `git ls-files`) as a tree for the README's Layout section:
 *
 *   git ls-files | php docker/layout-tree.php
 *
 * ReadmeTest includes this file and compares its output for the current
 * `git ls-files` with the README block.
 */

/**
 * @param  array  $paths  relative file paths, in the order to print them
 * @return string
 */
function layout_tree(array $paths)
{
	$tree = array();

	foreach ($paths as $path)
	{
		$path = trim($path);

		if ($path === '') continue;

		$node =& $tree;

		foreach (explode('/', $path) as $part)
		{
			if ( ! isset($node[$part])) $node[$part] = array();

			$node =& $node[$part];
		}

		unset($node);
	}

	$lines = array('.');

	layout_tree_lines($tree, '', $lines);

	return implode("\n", $lines);
}

/**
 * @param  array   $nodes
 * @param  string  $prefix
 * @param  array   $lines
 * @return void
 */
function layout_tree_lines(array $nodes, $prefix, array &$lines)
{
	$names = array_keys($nodes);
	$last = count($names) - 1;

	foreach ($names as $i => $name)
	{
		$lines[] = $prefix.($i === $last ? '└── ' : '├── ').$name;

		if ($nodes[$name])
		{
			layout_tree_lines($nodes[$name], $prefix.($i === $last ? '    ' : '│   '), $lines);
		}
	}
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__)
{
	echo layout_tree(file('php://stdin', FILE_IGNORE_NEW_LINES)), "\n";
}
