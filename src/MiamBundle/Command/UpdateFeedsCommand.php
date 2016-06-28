<?php

namespace MiamBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateFeedsCommand extends ContainerAwareCommand {
	protected function configure() {
        $this
            ->setName('miam:update:feeds')
            ->setDescription('Update data about feeds')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        error_reporting(0);

        $output->write('Update... ');

        $this->getContainer()->get('feed_manager')->updateFeeds();

        $output->writeln('Done.');
    }
}