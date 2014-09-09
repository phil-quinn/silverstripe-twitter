<?php
// Require third party lib
require_once __DIR__ . "/../../thirdparty/twitteroauth/twitteroauth/twitteroauth.php";

/**
 * JSON powered twitter service
 * 
 * @link http://www.webdevdoor.com/javascript-ajax/custom-twitter-feed-integration-jquery/
 * @link http://www.webdevdoor.com/php/authenticating-twitter-feed-timeline-oauth/
 * 
 * @author Damian Mooyman
 * 
 * @package twitter
 */
class TwitterService implements ITwitterService {

	/**
	 * Generate a new TwitterOAuth connection
	 * 
	 * @return TwitterOAuth
	 */
	protected function getConnection() {
		$consumerKey = SiteConfig::current_site_config()->TwitterAppConsumerKey;
		$consumerSecret = SiteConfig::current_site_config()->TwitterAppConsumerSecret;
		$accessToken = SiteConfig::current_site_config()->TwitterAppAccessToken;
		$accessSecret = SiteConfig::current_site_config()->TwitterAppAccessSecret;

		return new TwitterOAuth($consumerKey, $consumerSecret, $accessToken, $accessSecret);
	}

	function getTweets($user, $count) {

		// Check user
		if (empty($user)) return null;

		// Call rest api
		$arguments = http_build_query(array(
			'screen_name' => $user,
			'count' => $count,
			'include_rts' => true
		));
		$connection = $this->getConnection();
		$response = $connection->get("https://api.twitter.com/1.1/statuses/user_timeline.json?$arguments");

		// Parse all tweets
		$tweets = array();
		if ($response && is_array($response)) {
			foreach ($response as $tweet) {
				$tweets[] = $this->parseTweet($tweet);
			}
		}

		return $tweets;
	}

	function searchTweets($query, $count) {
	
		$tweets = array();
		if (!empty($query)) {
			// Call rest api
			$arguments = http_build_query(array(
				'q' => (string)$query,
				'count' => $count,
				'include_rts' => true
			));
			$connection = $this->getConnection();
			$response = $connection->get("https://api.twitter.com/1.1/search/tweets.json?$arguments");
		
			// Parse all tweets
			if ($response) {
			 	foreach ($response->statuses as $tweet) {
					$tweets[] = $this->parseTweet($tweet);
				}
			}
		}
	
		return $tweets;
	}

	/**
	 * Calculate the time ago in days, hours, whichever is the most significant
	 * 
	 * @param string $time Input time as a string
	 * @param integer $detail Number of time periods to display. Increasing provides greater time detail.
	 * @return string
	 */
	public static function determine_time_ago($time, $detail = 1) {
		$difference = time() - strtotime($time);

		if ($difference < 1) {
			return _t('Date.LessThanMinuteAgo', 'less than a minute');
		}

		$periods = array(
			365 * 24 * 60 * 60 => 'year',
			30 * 24 * 60 * 60 => 'month',
			24 * 60 * 60 => 'day',
			60 * 60 => 'hour',
			60 => 'min',
			1 => 'sec'
		);
		
		$items = array();

		foreach ($periods as $seconds => $description) {
			// Break if reached the sufficient level of detail
			if(count($items) >= $detail) break;
			
			// If this is the last element in the chain, round the value.
			// Otherwise, take the floor of the time difference
			$quantity = $difference / $seconds;
			if(count($items) === $detail - 1) {
				$quantity = round($quantity);
			} else  {
				$quantity = intval($quantity);
			}
			
			// Check that the current period is smaller than the current time difference
			if($quantity <= 0) continue;
			
			// Append period to total items and continue calculation with remainder
			if($quantity !== 1) $description .= 's';
			$items[] = $quantity.' '. _t("Date.".strtoupper($description), $description);
			$difference -= $quantity * $seconds;
		}
		$time = implode(' ', $items);
		return _t(
			'Date.TIMEDIFFAGO',
			'{difference} ago',
			'Time since tweet',
			array('difference' => $time)
		);
	}

	/**
	 * Converts a tweet response into a simple associative array of fields
	 * 
	 * @param stdObject $tweet Tweet object
	 * @return array Array of fields with Date, User, and Content as keys
	 */
	public function parseTweet($tweet) {

		$profileLink = "https://twitter.com/" . Convert::raw2url($tweet->user->screen_name);
		$tweetID = $tweet->id_str;

		return array(
			'ID' => $tweetID,
			'Date' => $tweet->created_at,
			'TimeAgo' => self::determine_time_ago($tweet->created_at),
			'Name' => $tweet->user->name,
			'User' => $tweet->user->screen_name,
			'AvatarUrl' => $tweet->user->profile_image_url,
			'Content' => $this->parseText($tweet),
			'Media'	=> $this->parseMedia($tweet),
			'URLs'	=> $this->parseURLs($tweet),
			'Videos'	=> $this->parseVideos($tweet),
			'Link' => "{$profileLink}/status/{$tweetID}",
			'ProfileLink' => $profileLink,
			'ReplyLink' => "https://twitter.com/intent/tweet?in_reply_to={$tweetID}",
			'RetweetLink' => "https://twitter.com/intent/retweet?tweet_id={$tweetID}",
			'FavouriteLink' => "https://twitter.com/intent/favorite?tweet_id={$tweetID}"
		);
	}

	/**
	 * Look for urls in the entities of a tweet and convert it 
	 * to usable ArrayData in an ArrayList
	 * 
	 * @param stdObject $tweet Tweet object
	 * @return ArrayList 
	 */
	public function parseURLs($tweet) {
		$urls = new ArrayList();
		if (isset($tweet->entities->urls)) {
			foreach($tweet->entities->urls as $item) {
				$urls->push( new ArrayData(array(
					'URL' => $item->url,
					'ExpandedURL' => $item->expanded_url,
					'DisplayURL' => $item->display_url,
				)));
			}
			return $urls;
		}
	}

	/**
	 * Look for videos (YouTube or Vimeo) in the urls in the 
	 * entities of a tweet and convert it 
	 * to usable ArrayData in an ArrayList
	 * 
	 * @param stdObject $tweet Tweet object
	 * @return ArrayList 
	 */
	public function parseVideos($tweet) {
		$videos = new ArrayList();
		if (isset($tweet->entities->urls)) {
			foreach($tweet->entities->urls as $item) {
				$url = $item->expanded_url;

				if (self::is_youtube($url)) {
					$type = 'youtube';
					$id = self::youtube_video_id($url);
					$thumbs = new ArrayData( array(
						'Small' => self::youtube_thumb_url($id, $size = 's'),
						'Medium' => self::youtube_thumb_url($id, $size = 'm'),
						'Large' => self::youtube_thumb_url($id, $size = 'l'),
					));
				}
				else if (self::is_vimeo($url)) {
					$type = 'vimeo';
					$id = self::vimeo_video_id($url);
					$thumbs = new ArrayData( array(
						'Small' => self::vimeo_thumb_url($id, $size = 's'),
						'Medium' => self::vimeo_thumb_url($id, $size = 'm'),
						'Large' => self::vimeo_thumb_url($id, $size = 'l'),
					));
				}
				else {
					// if not YouTube or Vimeo, skip
					continue;
				}
				$videos->push( new ArrayData(array(
					'URL' => $item->url,
					'ExpandedURL' => $item->expanded_url,
					'DisplayURL' => $item->display_url,
					'Type' => $type,
					'Thumbs' => $thumbs,
					'IFrameURL' => self::video_iframe_url($url),
					'IFrameCode' => self::video_iframe_code($url),
				)));
			}
			return $videos;
		}
	}


	/**
	 * Look for media in the entities of a tweet and convert it 
	 * to usable ArrayData in an ArrayList
	 * 
	 * @param stdObject $tweet Tweet object
	 * @return ArrayList 
	 */
	public function parseMedia($tweet) {
		$media = new ArrayList();
		if (isset($tweet->entities->media)) {
			foreach($tweet->entities->media as $item) {
				$media->push( new ArrayData(array(
					'ID' => $item->id,
					'MediaURLNoProtocol' => preg_replace('/^https?:/i','',$item->media_url),
					'MediaURL' => $item->media_url,
					'MediaURLHTTPS' => $item->media_url_https,
					'URL' => $item->url,
					'DisplayURL' => $item->display_url,
					'ExpandedURL' => $item->expanded_url,
					'Type' => $item->type,
					'Sizes' => $this->parseSizes($item->sizes),
				)));
			}
			return $media;
		}
	}

	/**
	 * Convert entity media sizes to an ArrayList
	 * 
	 * @param stdObject $sizes the sizes object
	 * @return ArrayData
	 */
	public function parseSizes($sizes) {
		if ($sizes) {
			$size_labels = array(
				'small' 	=> 'Small',
				'medium' 	=> 'Medium',
				'large' 	=> 'Large',
				'thumb' 	=> 'Thumb',
			);
			$size_array = array();
			foreach ($size_labels as $key => $label) {
				$size_array[$label] = new ArrayData(array(
					'Width'=>$sizes->$key->w,
					'Height'=>$sizes->$key->h,
					'Resize'=>$sizes->$key->resize,
				));
			}
			return new ArrayData( $size_array );
		}
		return false;
	}




	/**
	 * Is this YouTube?
	 *
	 * @param $url Video URL
	 * @return boolean
	*/
	public static function is_youtube($url) {
		return (preg_match('/youtu\.be/i', $url) || preg_match('/youtube\.com\/watch/i', $url));
	}

	/**
	 * Is this Vimeo?
	 *
	 * @param $url Video URL
	 * @return boolean
	*/
	public static function is_vimeo($url = false) {
		return (preg_match('/vimeo\.com/i', $url));
	}

	/**
	 * Parse the ID from a YouTube URL
	 *
	 * @param $url Video URL
	 * @return string
	*/
	public static function youtube_video_id($url) {
		if(self::is_youtube($url)) {
			$pattern = '/^.*((youtu.be\/)|(v\/)|(\/u\/\w\/)|(embed\/)|(watch\?))\??v?=?([^#\&\?]*).*/';
			preg_match($pattern, $url, $matches);
			if (count($matches) && strlen($matches[7]) == 11) {
				return $matches[7];
			}
		}
		return false;
	}

	/**
	 * Parse the ID from a Vimeo URL
	 *
	 * @param $url Video URL
	 * @return string
	*/
	public static function  vimeo_video_id($url) {
		if(self::is_vimeo($url)) {
			$pattern = '/\/\/(www\.)?vimeo.com\/(\d+)($|\/)/';
			preg_match($pattern, $url, $matches);
			if (count($matches)) {
				return $matches[2];
			}
		}
		return false;
	}


	/**
	 * Returns the iframe code for this video
	 *
	 * @param $url Video URL
	 * @param $width iframe width (defaults to '640')
	 * @param $height iframe height (defaults to '360')
	 * @param $fixed Do not resize to fit with JS (defaults to false)
	 * @return string
	*/
	public static function video_iframe_code($url, $width = '640', $height = '360', $fixed = false) {
		$class = $fixed ? 'video-iframe fixed' : 'video-iframe resize';
		$video_iframe_url = self::video_iframe_url($url);
		if (self::is_vimeo($url)) {
			$code = self::vimeo_video_id($url);
			return "<iframe class=\"$class\" src=\"$video_iframe_url\" width=\"$width\" height=\"$height\" frameborder=\"0\" webkitAllowFullScreen mozallowfullscreen allowFullScreen></iframe>";
		}
		else if (self::is_youtube($url)) {
			$code = self::youtube_video_id($url);
			return "<iframe class=\"$class\" width=\"$width\" height=\"$height\" src=\"$video_iframe_url\" frameborder=\"0\" allowfullscreen></iframe>";
		}
	}
	/**
	 * Returns the iframe url for this video
	 *
	 * @param $url Video URL
	 * @return string
	*/
	public static function video_iframe_url($url) {
		if ($url) {
			if (self::is_vimeo($url)) {
				$code = self::vimeo_video_id($url);
				return "//player.vimeo.com/video/$code";
			}
			else if (self::is_youtube($url)) {
				$code = self::youtube_video_id($url);
				return "//www.youtube.com/embed/$code";
			}
		}
	}

	/**
	 * Gets a Vimeo thumbnail url
	 *
	 * @param mixed $id A vimeo id (ie. 1185346)
	 * @param mixed $size 's' small, 'm' medium, 'l' large
	 * @return thumbnail's url
	*/
	static function vimeo_thumb_url($id, $size = 'm') {
		$data = @file_get_contents("http://vimeo.com/api/v2/video/$id.json");
		if ($data) {
			$data = json_decode($data);
			switch($size) {
				case 's':
					return $data[0]->thumbnail_small;
				case 'm':
					return $data[0]->thumbnail_medium;
				case 'l':
					return $data[0]->thumbnail_large;
			}
		}
		return false;
	}

	/**
	 * Gets a YouTube thumbnail url
	 *
	 * @param mixed $id A YouTube id (ie. mXWSkBp0z8Y)
	 * @param mixed $size 's' small, 'm' medium, 'l' large
	 * @return string
	*/
	public static function youtube_thumb_url($id, $size = 'm') {
		switch($size) {
			case 's':
				return "http://img.youtube.com/vi/$id/default.jpg";
			case 'm':
				return "http://img.youtube.com/vi/$id/0.jpg";
			case 'l':
				return "http://img.youtube.com/vi/$id/maxresdefault.jpg";
		}
		return false;
	}




	/**
	 * Inject a hyperlink into the body of a tweet
	 * 
	 * @param array $tokens List of characters/words that make up the tweet body,
	 * with each index representing the visible character position of the body text
	 * (excluding markup).
	 * @param stdObject $entity The link object 
	 * @param string $link 'href' tag for the link
	 * @param string $title 'title' tag for the link
	 */
	protected function injectLink(&$tokens, $entity, $link, $title) {
		$startPos = $entity->indices[0];
		$endPos = $entity->indices[1];

		// Inject <a tag at the start
		$tokens[$startPos] = sprintf(
			"<a href='%s' title='%s' target='_blank'>%s",
			Convert::raw2att($link),
			Convert::raw2att($title),
			$tokens[$startPos]
		);
		$tokens[$endPos - 1] = sprintf("%s</a>", $tokens[$endPos - 1]);
	}

	/**
	 * Parse the tweet object into a HTML block
	 * 
	 * @param stdObject $tweet Tweet object
	 * @return string HTML text
	 */
	protected function parseText($tweet) {
		$rawText = $tweet->text;

		// tokenise into words for parsing (multibyte safe)
		$tokens = preg_split('/(?<!^)(?!$)/u', $rawText);

		// Inject links
		foreach ($tweet->entities->urls as $url) {
			$this->injectLink($tokens, $url, $url->url, $url->expanded_url);
		}

		// Inject hashtags
		foreach ($tweet->entities->hashtags as $hashtag) {
			$link = 'https://twitter.com/search?src=hash&q=' . Convert::raw2url('#' . $hashtag->text);
			$text = "#" . $hashtag->text;

			$this->injectLink($tokens, $hashtag, $link, $text);
		}

		// Inject mentions
		foreach ($tweet->entities->user_mentions as $mention) {
			$link = 'https://twitter.com/' . Convert::raw2url($mention->screen_name);
			$this->injectLink($tokens, $mention, $link, $mention->name);
		}

		// Re-combine tokens
		return implode('', $tokens);
	}

}
