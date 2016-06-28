<?php

namespace MiamBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ParseSubscribedCommand extends ContainerAwareCommand {
	protected function configure() {
        $this
            ->setName('miam:parse:subscribed')
            ->setDescription('Parse all feeds with subscribers')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        error_reporting(0);

        $output->writeln('Fetching and parsing...');

        $time_begin = time();

        $em = $this->getContainer()->get('doctrine')->getEntityManager();
        
        $feeds = $em->getRepository('MiamBundle:Feed')
            ->createQueryBuilder('f')
            ->innerJoin('f.subscriptions', 's')
            ->getQuery()->getResult();

        /*$feeds = $em->getRepository('MiamBundle:Feed')
            ->createQueryBuilder('f')
            ->select('f, COUNT(s.id) AS countSubs')
            ->leftJoin('f.subscriptions', 's')
            ->groupBy('f')
            ->having('countSubs > 0')
            ->getQuery()->getResult();*/

        $nb = 0;
        foreach($feeds as $feed) {
            $feed = $em->getRepository('MiamBundle:Feed')->find($feed->getId());

            if($feed) {
                $this->getContainer()->get('data_parsing')->parseFeed($feed, array('verbose' => true));

                $nb++;
            }

            if($nb%20 == 0) {
                $em->clear();
            }
        }

        $duration = time() - $time_begin;

        $output->writeln('');
        $output->writeln('End - Duration: '.$duration.'s');
    }
}