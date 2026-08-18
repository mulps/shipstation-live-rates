<?php

declare(strict_types=1);

namespace Mulps\ShipStationLiveRates\Console\Command;

use Mulps\ShipStationLiveRates\Cron\RebuildHeuristic;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RebuildHeuristicCommand extends Command
{
    public function __construct(
        private readonly RebuildHeuristic $rebuild
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ssliverates:rebuild-heuristic');
        $this->setDescription('Rebuild local shipping fallback tables from recent ShipStation labels.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Fetching recent ShipStation labels and rebuilding the local fallback table...</info>');
        $count = $this->rebuild->execute();
        $output->writeln(sprintf('<info>Stored %d regional cells.</info>', $count));
        return Command::SUCCESS;
    }
}
