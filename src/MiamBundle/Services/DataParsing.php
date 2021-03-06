<?php

namespace MiamBundle\Services;

use MiamBundle\Entity\Enclosure;
use MiamBundle\Entity\Feed;
use MiamBundle\Entity\Item;
use MiamBundle\Entity\Tag;

class DataParsing extends MainService {
	protected $em;
	private $container;

	public function __construct($em, $container) {
		$this->em = $em;
		$this->container = $container;
		$this->rootDir = $this->container->get('kernel')->getRootDir().'/..';
	}

	public function parseFeed(Feed $feed, $options = array()) {
		$pie = new \SimplePie();
		$now = new \DateTime("now");

		if(isset($options['data']) && !empty($options['data'])) {
			$pie->set_raw_data($options['data']);
		} else {
			$pie->set_feed_url($feed->getUrl());
			$pie->force_feed(true);
			$pie->set_autodiscovery_level(SIMPLEPIE_LOCATOR_NONE);

			$timeout = isset($options['timeout']) ? intval($options['timeout']) : 10;
			if($timeout > 0) {
				$pie->set_timeout($timeout);
			} else {
				$pie->set_timeout(10);
			}

			if(isset($options['cache']) && !$options['cache']) {
				$pie->enable_cache(false);
			} else {
				$pie->enable_cache(true);
				$pie->set_cache_duration(300);
				$pie->set_cache_location($this->rootDir.'/var/cache/simplepie');
			}
		}

		$firstParsing = false;
		if(!$feed->getDateParsed()) {
			$firstParsing = true;
		}
		$feed->setDateParsed($now);

		$countNewItems = 0;
		$error = null;

		$pie_init = $pie->init();
		if($pie_init) {
			$feed->setDateSuccess($now);
			
			// Name
			$feed_name = $this->sanitizeText($pie->get_title(), 255);
			if($feed_name) {
				$feed->setOriginalName($feed_name);
			}

			$feed_description = $this->sanitizeText($pie->get_description());
			if($feed_description) {
				$feed->setOriginalDescription($feed_description);
			}

			// Website
			$feed_website = $this->sanitizeUrl($pie->get_link());
			if(filter_var($feed_website, FILTER_VALIDATE_URL) !== false) {
				$feed->setWebsite($feed_website);
			}

			// Icon Url
			$feed_icon = $this->sanitizeUrl($pie->get_image_url());
			if(filter_var($feed_icon, FILTER_VALIDATE_URL) !== false) {
				$feed->setIconUrl($feed_icon);
			}

			// Author(s)
			$fas = $pie->get_authors();
			if(count($fas) > 0) {
				$feed_authors = array();

				foreach($fas as $fa) {
					$feed_author_name = $this->sanitizeText($fa->get_name());
					$feed_author_email = $this->sanitizeText($fa->get_email());

					if($feed_author_name) {
						$feed_authors[] = $feed_author_name;
					} elseif($feed_author_email) {
						$feed_authors[] = $feed_author_email;
					}
				}

				$feed_authors = implode(', ', $feed_authors);
				$feed->setAuthor($feed_authors);
			}

			// Language
			$feed_language = $this->sanitizeText($pie->get_language(), 255);
			if($feed_language) {
				$feed->setLanguage($feed_language);
			}

			// Data length
			$dataLength = mb_strlen($pie->get_raw_data());
			if($dataLength > 0) {
				$feed->setDataLength($dataLength);
			}

			$items = $pie->get_items();

			$countItems = count($items);
			if($countItems > 0) {
				$feed->setNbItems($countItems);
			}

			// Find and create new tags
			$cache_tags = array();
			foreach($items as $i) {
				$tags = $i->get_categories() ?: array();
				foreach($tags as $t) {
					$tag_name = $this->sanitizeText($t->get_label(), 255);
					if($tag_name) {
						$tag_hash = hash('sha1', $tag_name);
						if(!array_key_exists($tag_hash, $cache_tags)) {
							$tag = $this->getRepo('Tag')->findOneByHash($tag_hash);
							if(!$tag) {
								$tag = new Tag();
								$tag->setName($tag_name);
								$tag->setHash($tag_hash);

								$this->em->persist($tag);
							}

							$cache_tags[$tag_hash] = $tag;
						}
					}
				}
			}
			$this->em->flush();
			
			$identifiers = array();
			foreach($items as $i) {
				$item_identifier = hash('sha1', $i->get_id());
				$item_hash = $i->get_id(true); // md5(serialize($this->data))
				
				// Ignore duplicates (Not happy? Deal with it!)
				if(in_array($item_identifier, $identifiers)) {
					continue;
				} else {
					$identifiers[] = $item_identifier;
				}

				$is_new_item = false;
				
				// Check if item exists
				$item = $this->getRepo('Item')->findOneBy(array(
					'feed' => $feed,
					'identifier' => $item_identifier
				));
				
				// Creation if new item
				if(!$item) {
					$item = new Item();
					$item->setFeed($feed);
					$item->setIdentifier($item_identifier);

					// Published date
					$date_published = $i->get_date(DATE_ATOM);
                    if($date_published) {
                    	$date_published = new \DateTime($date_published);
                    }

                    if(!$date_published || $date_published > $now) {
                    	$date_published = $now;
                    }
                    $item->setDatePublished($date_published);

                    $countNewItems++;
                    $is_new_item = true;
				}

                if($is_new_item || $item->getHash() != $item_hash) {
	                $item->setHash($item_hash);

	                // Title
					$item_title = $this->sanitizeText($i->get_title(), 255);
					if($item_title) {
						$item->setTitle($item_title);
					}
					
					// HTML content
					$htmlContent = (string) $i->get_content();
					if(extension_loaded('tidy')) {
						// To avoid unclosed tags
						$htmlContent = tidy_repair_string($htmlContent, array(
			                "output-html" => true
			            ), "utf8");
			            
			            // Seriously, Tidy, why do you add html and body tags...
			            if(preg_match('#<body>(.*)</body>#is', $htmlContent, $matches)) {
			                $htmlContent = $matches[1];
			            } else {
			                $htmlContent = "";
			            }
					}
					$item->setHtmlContent($htmlContent);

					// Text content
					$textContent = $this->sanitizeText(strip_tags($htmlContent));
					$item->setTextContent($textContent);

					// Link
					$link = $this->sanitizeUrl($i->get_link());
					if(filter_var($link, FILTER_VALIDATE_URL) !== false) {
						$item->setLink($link);
					}

					// Updated date
					$date_updated = $i->get_updated_date(DATE_ATOM);
	                if($date_updated) {
	                	$date_updated = new \DateTime($date_updated);
	                } else {
	                	$date_updated = $item->getDatePublished();
	                }
	                $item->setDateUpdated($date_updated);

	                // Modified date
	        		$item->setDateModified($now);

					// Author(s)
					$as = $i->get_authors();
					if(count($as) > 0) {
						$authors = array();

						foreach($as as $a) {
							$author_name = $this->sanitizeText($a->get_name());
							$author_email = $this->sanitizeText($a->get_email());

							if($author_name) {
								$authors[] = $author_name;
							} elseif($author_email) {
								$authors[] = $author_email;
							}
						}

						$authors = implode(', ', $authors);
						$item->setAuthor($authors);
					}

					// Contributor(s)
					$cs = $i->get_contributors();
					if(count($cs) > 0) {
						$contributors = array();

						foreach($cs as $c) {
							$contributor_name = $this->sanitizeText($c->get_name());
							$contributor_email = $this->sanitizeText($c->get_email());

							if($contributor_name) {
								$contributors[] = $contributor_name;
							} elseif($contributor_email) {
								$contributors[] = $contributor_email;
							}
						}

						$contributors = implode(', ', $contributors);
						$item->setContributor($contributors);
					}

					$all_tags = array();
					$new_tags = array();

					// New tags
					$tags = $i->get_categories() ?: array();
					foreach($tags as $t) {
						$tag_name = $this->sanitizeText($t->get_label(), 255);
						if($tag_name) {
							$tag_hash = hash('sha1', $tag_name);
							if(array_key_exists($tag_hash, $cache_tags)) {
								$tag = $cache_tags[$tag_hash];

								if(($is_new_item || !$item->getTags()->contains($tag)) && !in_array($tag_hash, $new_tags)) {
									$item->addTag($tag);

									$new_tags[] = $tag_hash;
								}
							}

							$all_tags[] = $tag_hash;
						}
					}

					// Remove obsolete tags
					foreach($item->getTags() as $t) {
						if(!in_array($t->getHash(), $all_tags)) {
							$item->removeTag($t);
						}
					}
					
					// Enclosure(s)
					$enclosures = $i->get_enclosures();
					$enclosure_hashes = array();

					foreach($enclosures as $e) {
						$enclosure_url = $this->sanitizeUrl($e->get_link());
						if(filter_var($enclosure_url, FILTER_VALIDATE_URL) !== false) {
							$enclosure = null;

							$enclosure_hash = hash('sha1', $enclosure_url);
							if(!$is_new_item) {
								$enclosure = $this->getRepo('Enclosure')->findOneBy(array(
									'item' => $item,
									'hash' => $enclosure_hash
								));
							}

							if(!$enclosure && !in_array($enclosure_hash, $enclosure_hashes)) {
								$enclosure = new Enclosure();
								$enclosure->setItem($item);
								$enclosure->setUrl($enclosure_url);
								$enclosure->setHash($enclosure_hash);

								$enclosure_type = $this->sanitizeText($e->get_type(), 255);
								$enclosure->setType($enclosure_type);

								$enclosure_length = intval($e->get_length());
								$enclosure->setLength($enclosure_length);

								$enclosure_title = $this->sanitizeText($e->get_title(), 255);
								$enclosure->setTitle($enclosure_title);

								$enclosure_description = $this->sanitizeText($e->get_description());
								$enclosure->setDescription($enclosure_description);

								$this->em->persist($enclosure);

								$enclosure_hashes[] = $enclosure_hash;
							}
						}
					}

					$this->em->persist($item);
				}
			}
			
			$feed->setErrorCount(0);
		} else {
			$error = $pie->error();

			$feed->setErrorCount($feed->getErrorCount() + 1);
			$feed->setErrorMessage($error);
		}

		if($countNewItems > 0) {
			$feed->setDateNewItem($now);
		}

		$this->em->persist($feed);
		
		$this->em->flush();

		// Get icon every 7 days
		if(!$feed->getDateIcon() || $feed->getDateIcon() < new \DateTime("now - 7 days")) {
			$this->parseIcon($feed);
		}

		return array(
			'success' => $pie_init,
			'countNewItems' => $countNewItems,
			'error' => $error
		);
	}

	private function sanitizeText($text, $maxLength = 0) {
		$text = html_entity_decode(trim($text), ENT_COMPAT | ENT_HTML5, 'utf-8');

		if($maxLength > 0) {
			$text = mb_substr($text, 0, $maxLength);
		}

		return $text;
	}

	private function sanitizeUrl($url) {
		return trim($url);
	}

    public function parseIcon(Feed $feed) {
    	$success = false;

    	$feedDir = $this->rootDir.'/web/images/feeds';

    	$tmpPath = $feedDir.'/icon-'.$feed->getId().'.tmp';
    	$iconPath = $feedDir.'/icon-'.$feed->getId().'.png';

    	$tmpIcon = false;

    	// Get the icon
    	$iconUrl = $feed->getIconUrl();
    	if($iconUrl) {
    		if($this->getUrlContentTo($iconUrl, $tmpPath)) {
	    		$iconData = getimagesize($tmpPath);

				// Non-square icons are bad (2px tolerance)
				if($iconData && abs($iconData[0] - $iconData[1]) <= 2) {
					$tmpIcon = true;
				}
			}
    	}
    	
    	// Get the favicon if no icon
    	if(!$tmpIcon) {
    		$faviconUrl = $this->getFaviconUrl($feed);
    		if($faviconUrl) {
    			$this->getUrlContentTo($faviconUrl, $tmpPath);
	    	}
    	}
    	
    	$iconSize = 16;
    	
    	// Convert and store
    	if(is_file($tmpPath)) {
    		try {
    			$iconData = getimagesize($tmpPath);
    		} catch(\Exception $e) {
    			$iconData = false;
    		}
    		
    		if($iconData) {
	    		$iconSrcWidth = $iconData[0];
	    		$iconSrcHeight = $iconData[1];
	    		$iconSrcType = $iconData[2];

	    		if(extension_loaded('imagick')) {
	    			if($iconSrcType == IMAGETYPE_ICO) {
		    			// Must edit the extension for ICO icons or it fails
						$icoPath = $feedDir.'/icon-'.$feed->getId().'.ico';
						rename($tmpPath, $icoPath);
						$tmpPath = $icoPath;
					}

					try {
	    				$icon = new \Imagick($tmpPath);
						$icon->thumbnailImage($iconSize, $iconSize);
						$icon->setImageFormat('png');
						$icon->writeImage($iconPath);
						$icon->clear();
						$icon->destroy();

		    			$success = true;
	    			} catch(\Exception $e) {
	    				$success = false;
	    			}
	    		}

	    		if(!$success && extension_loaded('gd')) {
	    			if($iconSrcType == IMAGETYPE_GIF) {
		    			$iconDst = imagecreatetruecolor($iconSize, $iconSize);
						
						$blackBg = imagecolorallocate($iconDst, 0, 0, 0);
		    			imagecolortransparent($iconDst, $blackBg);

						$iconSrc = imagecreatefromgif($tmpPath);
						imagecopyresampled($iconDst, $iconSrc, 0, 0, 0, 0, $iconSize, $iconSize, $iconSrcWidth, $iconSrcHeight);
						
						imagepng($iconDst, $iconPath);
		            	imagedestroy($iconDst);

		            	$success = true;
		    		} elseif($iconSrcType == IMAGETYPE_JPEG) {
		    			$iconDst = imagecreatetruecolor($iconSize, $iconSize);

						$iconSrc = imagecreatefromjpeg($tmpPath);
						imagecopyresampled($iconDst, $iconSrc, 0, 0, 0, 0, $iconSize, $iconSize, $iconSrcWidth, $iconSrcHeight);
						
						imagepng($iconDst, $iconPath);
		            	imagedestroy($iconDst);

		            	$success = true;
		    		} elseif($iconSrcType == IMAGETYPE_PNG) {
		    			$iconDst = imagecreatetruecolor($iconSize, $iconSize);

		    			$blackBg = imagecolorallocate($iconDst, 0, 0, 0);
		    			imagecolortransparent($iconDst, $blackBg);
		            	imagealphablending($iconDst, false);
		            	imagesavealpha($iconDst, true);

		            	$iconSrc = imagecreatefrompng($tmpPath);
		            	imagecopyresampled($iconDst, $iconSrc, 0, 0, 0, 0, $iconSize, $iconSize, $iconSrcWidth, $iconSrcHeight);

		            	imagepng($iconDst, $iconPath);
		            	imagedestroy($iconDst);

		            	$success = true;
		    		}
	    		}
	    	}

    		@unlink($tmpPath);
    	}

    	// Feed update
    	if($success) {
    		$feed->setHasIcon(true);
    	}
    	$feed->setDateIcon(new \DateTime("now"));

    	$this->em->persist($feed);
    	$this->em->flush();

    	return array(
    		'success' => $success
    	);
    }

    private function getUrlContentTo($url, $dst) {
    	$content = null;

    	if(extension_loaded('curl')) {
	    	$ch = curl_init();

	    	curl_setopt_array($ch, array(
	    		CURLOPT_URL => $url,
	    		CURLOPT_RETURNTRANSFER => 1,
	    		CURLOPT_CONNECTTIMEOUT => 5,
	    		CURLOPT_TIMEOUT => 10
	    	));

	    	$content = curl_exec($ch);

	    	curl_close($ch);
	    } else {
	    	try {
	    		$content = file_get_contents($url);
	    	} catch(\Exception $e) {
	    		$content = null;
	    	}
	    }

	    if(!is_null($content) && $content !== false && $content !== "") {
			if(file_put_contents($dst, $content) !== false) {
				return true;
			}
		}

	    return false;
    }

    // May need improvements
    private function getFaviconUrl(Feed $feed) {
    	$favicon = null;
    	
    	$url = $feed->getWebsite();
    	if(empty($url)) {
    		$item = $this->getRepo('Item')->findLastOneForFeed($feed);
    		if($item) {
    			$url = $item->getLink();
    		}
    	}

    	$rootUrl = null;
    	if(filter_var($url, FILTER_VALIDATE_URL) !== false) {
    		$parsed = parse_url($url);

    		$rootUrl = $parsed["scheme"]."://";

    		if(isset($parsed["user"]) && isset($parsed["pass"])) {
    			$rootUrl .= $parsed["user"].":".$parsed["pass"]."@";
    		}

    		$rootUrl .= $parsed["host"];

    		if(isset($parsed["port"])) {
    			$rootUrl .= ":".$parsed["port"];
    		}
    	}

    	if($rootUrl) {
    		// https://stackoverflow.com/questions/5701593
    		$doc = new \DOMDocument();
    		$doc->strictErrorChecking = false;

    		// Get favicon path
    		libxml_use_internal_errors(true);
    		try {
	    		$html = file_get_contents($rootUrl);
	    		if($html && $doc->loadHTML($html) !== false) {
		    		$xml = simplexml_import_dom($doc);
		    		if($xml instanceof \SimpleXmlElement) {
		    			$rels = array("shortcut icon", "icon", "SHORTCUT ICON", "ICON");
		    			foreach($rels as $rel) {
		    				$arr = $xml->xpath('//link[@rel="'.$rel.'"]');
		    				if(isset($arr[0]['href'])) {
					    		$favicon = $arr[0]['href'];
					    		break;
					    	}
		    			}
			    	}
			    }
			} catch(\Exception $e) {}
			libxml_clear_errors();

		    // Default otherwise
		    if(!$favicon) {
		    	$favicon = "/favicon.ico";
		    }
		    
		    // Fix if relative url
		    if(!filter_var($favicon, FILTER_VALIDATE_URL) !== false) {
		    	$parsedFav = parse_url($favicon);

		    	if(!isset($parsedFav["host"])) {
			    	$favicon = $rootUrl;
			    } elseif(!isset($parsedFav["scheme"])) {
			    	$favicon = $parsedFav["host"];

			    	if(isset($parsedFav["port"])) {
		    			$favicon .= ":".$parsedFav["port"];
		    		}

		    		if(isset($parsedFav["user"]) && isset($parsedFav["pass"])) {
		    			$favicon = $parsedFav["user"].":".$parsedFav["pass"]."@".$favicon;
		    		}

		    		$favicon = "http://".$favicon;
			    }

		    	$path = $parsedFav['path'] ?: "/favicon.ico";
		    	if(mb_substr($path, 0, 1) != "/") {
		    		$path = "/".$path;
		    	}
		    	$favicon .= $path;

		    	if(isset($parsedFav["query"])) {
		    		$favicon .= "?".$parsedFav["query"];
		    	}
		    }
    	}
    	
    	return $favicon;
    }
}
