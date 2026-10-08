<?php

use Carbon\Carbon;
use Helpdesk\Sla\SlaMonitor;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

class SlaCheckCommand extends Command {

	protected $name = 'sla:check';

	protected $description = 'Evaluate the SLA state of open tickets and record warnings and breaches';

	protected $monitor;

	public function __construct(SlaMonitor $monitor)
	{
		parent::__construct();

		$this->monitor = $monitor;
	}

	public function fire()
	{
		$counts = $this->monitor->run(Carbon::now(), (bool) $this->option('dry-run'));

		$this->line(sprintf('checked %d, warning %d, breached %d',
			$counts['checked'], $counts['warning'], $counts['breached']));

		return 0;
	}

	protected function getOptions()
	{
		return array(
			array('dry-run', null, InputOption::VALUE_NONE, 'Count states without updating tickets'),
		);
	}

}
