<?php

/**
 * An extension of the previous SexactuBridge to cover the whole GQMagazine.
 * This one taks a page (as an example sexe/news or journaliste/maia-mazaurette) which is to be configured,
 * reads all the articles visible on that page, and make a stream out of it.
 * @author nicolas-delsaux
 *
 */
class GQMagazineBridge extends BridgeAbstract
{
	const MAINTAINER = 'Riduidel';

	const NAME = 'GQMagazine';

	// URI is no more valid, since we can address the whole gq galaxy
	const URI = 'https://www.gqmagazine.fr';

	const CACHE_TIMEOUT = 7200; // 2h
	const DESCRIPTION = 'GQMagazine section extractor bridge. This bridge allows you get only a specific section.';

	const DEFAULT_DOMAIN = 'www.gqmagazine.fr';

	const PARAMETERS = array( array(
	    'headerSize' => array(
	        'name' => 'Header "minimal size"',
	        'required' => false,
	        'defaultValue' => 300
	    ),
	    'domain' => array(
			'name' => 'Domain to use',
			'required' => true,
			'defaultValue' => self::DEFAULT_DOMAIN
		),
		'page' => array(
			'name' => 'Initial page to load',
			'required' => true,
			'exampleValue' => 'sexe/news'
		),
	));

	const REPLACED_ATTRIBUTES = array(
		'href' => 'href',
		'src' => 'src',
		'data-original' => 'src'
	);

	const POSSIBLE_TITLES = array(
		'h2',
		'h3'
	);

	private function getDomain() {
		$domain = $this->getInput('domain');
		if (empty($domain))
			$domain = self::DEFAULT_DOMAIN;
		if (strpos($domain, '://') === false)
			$domain = 'https://' . $domain;
		return $domain;
	}

	public function getURI()
	{
		return $this->getDomain() . '/' . $this->getInput('page');
	}

	private function findTitleOf($link) {
		foreach (self::POSSIBLE_TITLES as $tag) {
			$title = $link->parent()->find($tag, 0);
			if($title !== null) {
				if($title->plaintext !== null) {
					return $title->plaintext;
				}
			}
		}
	}

	public function collectData()
	{
		$html = getSimpleHTMLDOM($this->getURI()) or returnServerError('Could not request ' . $this->getURI());

		// Since GQ don't want simple class scrapping, let's do it the hard way and ... discover content !
		$main = $html->find('main', 0);
		foreach ($main->find('a') as $link) {
			if(strpos($link, $this->getInput('page')))
				continue;
			$uri = $link->href;
			$date = $link->parent()->find('time', 0);

			$item = array();
			$author = $link->parent()->find('span[itemprop=name]', 0);
			if($author !== null) {
				$item['author'] = $author->plaintext;
				$item['title'] = $this->findTitleOf($link);
				switch(substr($uri, 0, 1)) {
					case 'h': // absolute uri
						$item['uri'] = $uri;
						break;
					case '/': // domain relative uri
						$item['uri'] = $this->getDomain() . $uri;
						break;
					default:
						$item['uri'] = $this->getDomain() . '/' . $uri;
				}
				$article = $this->loadFullArticle($item);
				if($article) {
					$item['content'] = $this->replaceUriInHtmlElement($article);
				} else {
					$item['content'] = "<strong>Article body couldn't be loaded</strong>. It must be a bug!";
				}
				$short_date = $date->datetime;
				$item['timestamp'] = strtotime($short_date);
				$this->items[] = $item;
			}
		}
	}

	/**
	 * Loads the full article and returns the contents
	 * @param $item the full item, with title, uri, and so on
	 * @return The article content as TEXT
	 */
	private function loadFullArticle($item){
	    $uri = $item['uri'];
	    $title = $item['title'];
		$html = getSimpleHTMLDOMCached($uri);
		$HEADER_SIZE = (int) $this->getInput('headerSize');
		// First, lcoate element having title as text
		$selector = sprintf('*[plaintext=%s]', $title);
		foreach ($html->find($selector) as $titleElement) {
		    $parent = $titleElement;
		    do {
		        // Now we have the title element, let's go on parent element and find all the remaining text
		        $parent = $parent->parent();
		    } while(strlen($parent->plaintext)<strlen($title)+$HEADER_SIZE);
		    // Now we have some parent element which doesn't contain only the title.
		    // I hope the remaining content is the interesting one 
		    return $this->filter($titleElement, $parent);
		}
	}
	
	private function filter($title, $element) {
	    // Let's try to remove those script tags
	    foreach($element->find('script') as $script) {
	        $script_parent = $script->parent();
	        if($script_parent!=null) {
	            $script_parent->removeChild($script);
	        }
	    }
	    // Let's try to remove those iframe tags (which are googletagmanager inclusions)
	    foreach($element->find('iframe') as $iframe) {
	        $iframe_parent = $iframe->parent();
	        if($iframe_parent!=null) {
	            $iframe_parent->removeChild($iframe);
	        }
	    }
	    // Remove all svg which are in "a" tags (they're social network images)
	    foreach($element->find('svg') as $svg) {
	        $svg_parent = $svg->parent();
	        if($svg_parent->tag=='a') {
	            $container = $svg_parent->parent();
	            $container->removeChild($svg_parent);
	        }
	    }
	    // Remove date (because it is already extracted)
	    $date = $element->find('time', 0);
	    if($date!=null) {
	        $date->parent()->removeChild($date);
	    }
	    // And remove everything after footer (if found)
	    $footer = $element->find('[data-test-id="ArticleFooter"]', 0);
	    if ($footer!=null) {
	        if($footer->parent()==$element) {
	            while($footer->next_sibling()!=null) {
	                $element->removeChild($footer->nextSibling());
	            }
	        }
	    }
	    return str_replace($title->outertext, '', $element->innertext);
	}

	/**
	 * Replaces all relative URIs with absolute ones
	 * @param $returned the text of the element
	 * @return The text with all URIs replaced
	 */
	private function replaceUriInHtmlElement($returned){
		foreach (self::REPLACED_ATTRIBUTES as $initial => $final) {
			$returned = str_replace($initial . '="/', $final . '="' . self::URI . '/', $returned);
		}
		return $returned;
	}
}
