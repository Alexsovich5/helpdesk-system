<?php namespace Helpdesk\Assets;

use Asset;
use User;

/**
 * Upserts assets from an inventory CSV export.
 *
 * The first line is a header naming the columns (any order):
 * asset_tag,name,type,serial,location,status,assigned_username
 *
 * Rows are matched on asset_tag: a known tag updates the asset, a new one
 * creates it. A row with an empty tag, name or type, or a status outside
 * Asset::$statuses, is skipped and reported against its line number. An
 * assigned_username that matches no user leaves the asset unassigned and
 * adds a warning; the row is still imported. Blank lines are ignored.
 */
class AssetCsvImporter {

	public static $columns = array('asset_tag', 'name', 'type', 'serial', 'location', 'status', 'assigned_username');

	/**
	 * @param  string  $path
	 * @return array  created, updated, skipped (ints); errors and warnings (line number => message)
	 *
	 * @throws CsvFormatException
	 */
	public function import($path)
	{
		$handle = is_file($path) && is_readable($path) ? fopen($path, 'r') : false;

		if ($handle === false)
		{
			throw new CsvFormatException("File [$path] is not readable.");
		}

		try
		{
			return $this->importHandle($handle);
		}
		catch (\Exception $e)
		{
			fclose($handle);

			throw $e;
		}
	}

	protected function importHandle($handle)
	{
		$result = array('created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array(), 'warnings' => array());

		$header = $this->readHeader($handle);
		$users = array();
		$line = 1;

		while (($fields = fgetcsv($handle)) !== false)
		{
			$line++;

			if ($fields === array(null)) continue;

			$row = array();
			foreach ($header as $index => $column)
			{
				$row[$column] = isset($fields[$index]) ? trim($fields[$index]) : '';
			}

			$error = $this->validate($row);
			if ( ! is_null($error))
			{
				$result['skipped']++;
				$result['errors'][$line] = $error;
				continue;
			}

			$userId = null;
			$username = $row['assigned_username'];
			if ($username !== '')
			{
				if ( ! array_key_exists($username, $users))
				{
					$user = User::where('username', $username)->first();
					$users[$username] = $user ? $user->id : null;
				}

				$userId = $users[$username];
				if (is_null($userId))
				{
					$result['warnings'][$line] = "unknown user \"$username\"; asset {$row['asset_tag']} left unassigned";
				}
			}

			$asset = Asset::where('asset_tag', $row['asset_tag'])->first();
			$exists = ! is_null($asset);
			if ( ! $exists)
			{
				$asset = new Asset;
				$asset->asset_tag = $row['asset_tag'];
			}

			$asset->name = $row['name'];
			$asset->type = $row['type'];
			$asset->serial = $row['serial'] === '' ? null : $row['serial'];
			$asset->location = $row['location'] === '' ? null : $row['location'];
			$asset->status = $row['status'];
			$asset->assigned_user_id = $userId;
			$asset->save();

			$result[$exists ? 'updated' : 'created']++;
		}

		fclose($handle);

		return $result;
	}

	/**
	 * @return array column index => column name
	 *
	 * @throws CsvFormatException
	 */
	protected function readHeader($handle)
	{
		$fields = fgetcsv($handle);

		if ($fields === false || $fields === array(null))
		{
			throw new CsvFormatException('The file is empty; expected a header line: '.implode(',', static::$columns));
		}

		$fields[0] = preg_replace('/^\xEF\xBB\xBF/', '', $fields[0]);
		$header = array_map(function($field) { return strtolower(trim($field)); }, $fields);

		$missing = array_diff(static::$columns, $header);
		if (count($missing))
		{
			throw new CsvFormatException('The header is missing the column(s): '.implode(', ', $missing));
		}

		return array_intersect($header, static::$columns);
	}

	/**
	 * @return string|null  why the row cannot be imported
	 */
	protected function validate(array $row)
	{
		foreach (array('asset_tag', 'name', 'type') as $column)
		{
			if ($row[$column] === '') return "$column is empty";
		}

		foreach (Asset::$maxLengths as $column => $max)
		{
			if (mb_strlen($row[$column], 'UTF-8') > $max) return "$column is longer than $max characters";
		}

		if ( ! in_array($row['status'], Asset::$statuses, true))
		{
			return "unknown status \"{$row['status']}\" (expected one of: ".implode(', ', Asset::$statuses).')';
		}

		return null;
	}

}
