<?php

namespace MiamBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ParseFeedCommand extends ContainerAwareCommand {
	protected function configure() {
        $this
            ->setName('miam:parse:feed')
            ->setDescription('Parse a feed')
            ->addArgument('feed', InputArgument::REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        error_reporting(0);

        $feedArgument = $input->getArgument('feed');

        $em = $this->getContainer()->get('doctrine')->getEntityManager();

        $feed = null;
        if(filter_var($feedArgument, FILTER_VALIDATE_URL) !== false) {
            $feed = $em->getRepository('MiamBundle:Feed')->findOneByUrl($feedArgument);
        } else {
            $feed = $em->getRepository('MiamBundle:Feed')->find(intval($feedArgument));
        }

        if($feed) {
            $output->write('Parsing... ');

            $result = $this->getContainer()->get('data_parsing')->parseFeed($feed);
            if($result['success']) {
                if($result['countNewItems'] > 0) {
                    $output->writeln('Success. '.$result['countNewItems'].' new item'.($result['countNewItems'] > 1 ? 's' : ''));
                } else {
                    $output->writeln('Success.');
                }
            } else {
                $output->writeln('Failure.');
            }
        } else {
            $output->writeln('Feed unknown...');
        }
    }
}