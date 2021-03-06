<?php

namespace MiamBundle\Services;

use MiamBundle\Entity\Feed;
use MiamBundle\Entity\Subscription;
use MiamBundle\Entity\User;

class FeedManager extends MainService {
	protected $em;
	private $container;

	public function __construct($em, $container) {
		$this->em = $em;
		$this->container = $container;
	}

	private function formatUrl($url) {
		$parsed = parse_url($url);

		$url = strtolower($parsed["scheme"])."://";

		if(isset($parsed["user"]) && isset($parsed["pass"])) {
			$url .= $parsed["user"].":".$parsed["pass"]."@";
		}

		$url .= strtolower($parsed["host"]);

		if(isset($parsed["path"])) {
			$url .= $parsed["path"];
		}

		if(isset($parsed["query"])) {
			$url .= "?".$parsed["query"];
		}

		if(isset($parsed["fragment"])) {
			$url .= "#".$parsed["fragment"];
		}

		return $url;
	}

	private function hashUrl($url) {
		return hash('sha1', $url);
	}

	// Find a feed, create if not exists
	public function getFeedForUrl($url, $fast = false) {
		$feed = null;

		if(filter_var($url, FILTER_VALIDATE_URL) !== false) {
			$url = $this->formatUrl($url);
			$hash = $this->hashUrl($url);

			$feed = $this->getRepo("Feed")->findOneByHash($hash);
			if(!$feed) {
				$feed = new Feed();
				$feed->setUrl($url);
				$feed->setHash($hash);

				$this->em->persist($feed);
				$this->em->flush();

				if(!$fast) {
					$this->container->get('data_parsing')->parseFeed($feed);
				}
			}
		}

		return $feed;
	}

	// Find a feed
	public function findFeedForUrl($url) {
		$feed = null;

		if(filter_var($url, FILTER_VALIDATE_URL) !== false) {
			$url = $this->formatUrl($url);
			$hash = $this->hashUrl($url);

			$feed = $this->getRepo("Feed")->findOneByHash($hash);
		}

		return $feed;
	}

	// Find feeds from the argument (for commands)
	public function getFeeds($arg = null) {
        if($arg == 'all' || is_null($arg) || $arg == '') {
            return $this->getRepo("Feed")->findAll();
        } elseif($arg == 'catalog') {
            return $this->getRepo("Feed")->findCatalog();
        } elseif($arg == 'subscribed') {
            return $this->getRepo("Feed")->findSubscribed();
        } elseif($arg == 'used') {
            return $this->getRepo("Feed")->findUsed();
        } elseif($arg == 'unused') {
            return $this->getRepo("Feed")->findUnused();
        } elseif(filter_var($arg, FILTER_VALIDATE_URL) !== false) {
            $feed = $this->findFeedForUrl($arg);
            if($feed) {
            	return array($feed);
            }
        } elseif(intval($arg) > 0) {
            $feed = $this->getRepo("Feed")->find(intval($arg));
            if($feed) {
            	return array($feed);
            }
        }

        return null;
    }

	public function deleteSubscription(Subscription $subscription) {
		$this->em->remove($subscription);
		$this->em->flush();
	}

	public function deleteFeed(Feed $feed) {
		$this->em->remove($feed);
		$this->em->flush();
	}
}