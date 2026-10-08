<?php

use Helpdesk\Assets\AssetCsvImporter;
use Helpdesk\Assets\CsvFormatException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;

class AssetsImportCommand extends Command {

	protected $name = 'assets:import';

	protected $description = 'Create or update assets from an inventory CSV export, matched on asset_tag';

	protected $importer;

	public function __construct(AssetCsvImporter $importer)
	{
		parent::__construct();

		$this->importer = $importer;
	}

	public function fire()
	{
		try
		{
			$result = $this->importer->import($this->argument('file'));
		}
		catch (CsvFormatException $e)
		{
			$this->error($e->getMessage());

			return 1;
		}

		$this->line(sprintf('created %d, updated %d, skipped %d',
			$result['created'], $result['updated'], $result['skipped']));

		foreach ($result['errors'] as $line => $message)
		{
			$this->error("line $line: skipped, $message");
		}

		foreach ($result['warnings'] as $line => $message)
		{
			$this->comment("line $line: $message");
		}

		return 0;
	}

	protected function getArguments()
	{
		return array(
			array('file', InputArgument::REQUIRED, 'Path to the CSV file (header: '.implode(',', AssetCsvImporter::$columns).')'),
		);
	}

}
