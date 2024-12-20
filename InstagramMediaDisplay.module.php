<?php

/**
 * Instagram Media Display
 *
 * #pw-var $instagram
 * #pw-order-groups media, profile, instagram-feed, authentication
 * #pw-summary Instagram Media Display, in combination with a Meta app, allows you to get an Instagram user's profile, images, videos, and albums for displaying on your website.
 * #pw-summary-instagram-feed These methods provide some backwards compatibility with Instagram Feed (https://modules.processwire.com/modules/instagram-feed/).
 *
 * @copyright 2024 NB Communication Ltd
 * @license Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 *
 */

class InstagramMediaDisplay extends WireData implements Module, ConfigurableModule {

	/**
	 * getModuleInfo is a module required by all modules to tell ProcessWire about them
	 *
	 * @return array
	 *
	 */
	public static function getModuleInfo() {
		return [
			'title' => 'Instagram Media Display',
			'version' => 110,
			'summary' => "Instagram Media Display, in combination with a Meta app, allows you to get an Instagram user's profile, images, videos, and albums for displaying on your website.",
			'author' => 'nbcommunication',
			'href' => 'https://github.com/nbcommunication/InstagramMediaDisplay',
			'singular' => true,
			'icon' => 'instagram',
		];
	}

	/**
	 * The database table name
	 *
	 * @var string
	 *
	 */
	const dbTableName = 'instagram_media_display';

	/**
	 * The username of the request being made
	 *
	 * @var string
	 *
	 */
	protected $currentUsername = null;

	/**
	 * The number of images to be returned
	 *
	 * @var int
	 *
	 */
	protected $imageCount = 4;

	/**
	 * The maximum number of items to return
	 *
	 * @var int
	 *
	 */
	protected $maxLimit = 100;

	/**
	 * Media Types
	 *
	 * @var string
	 *
	 */
	protected $mediaTypes = [
		'carouselAlbum' => 'CAROUSEL_ALBUM',
		'image' => 'IMAGE',
		'video' => 'VIDEO',
	];

	/**
	 * Initialize the module
	 *
	 */
	public function init() {

		// Reset pagination if request is not AJAX
		if(!$this->isAjax() && $this->wire()->page->template->name !== 'admin') {
			$this->wire()->session->remove($this, $this->getNextKey());
		}

		if(!$this->schemaVersion) {

			// Check if the column exists
			$database = $this->wire()->database;
			$table = self::dbTableName;
			$query = $database->prepare("SHOW COLUMNS FROM `$table` WHERE Field=:column");
			$query->bindValue(':column', 'token_renews', \PDO::PARAM_STR);
			$query->execute();
			$exists = (int) $query->rowCount() > 0;
			$query->closeCursor();

			$exception = null;
			if(!$exists) {

				try {

					// Update database table schema
					$database->exec("ALTER TABLE $table ADD token_renews DATETIME NOT NULL AFTER media_count");

					// Update existing records adding token_renews a day from now
					$query = $database->prepare("UPDATE $table SET token_renews = :renews");
					$query->bindValue(':renews', date('Y-m-d H:i:s', strtotime('+1 day')), \PDO::PARAM_STR);
					$query->execute();

				} catch(\Exception $e) {
					$exception = $e;
				}
			}

			if($exception) {
				$this->error($exception->getMessage());
			} else {
				// Update the schema version
				$this->schemaVersion = 1;
				$this->wire()->modules->saveConfig($this, 'schemaVersion', 1);
			}
		}
	}

	/**
	 * Get the most recent Carousel Album for a user
	 *
	 * ~~~
	 * // Get the most recent album from the default user
	 * $album = $instagram->getCarouselAlbum();
	 *
	 * // Get the most recent album from a specified user
	 * $album = $instagram->getCarouselAlbum('username');
	 *
	 * // Render the album
	 * if(isset($album)) {
	 *     echo '<ul>' .
	 *         $album->children->each('<li>' .
	 *             '<a href="{href}">' .
	 *                 '<img src="{src}" alt="{alt}">' .
	 *             '</a>' .
	 *         '</li>') .
	 *     '</ul>';
	 * }
	 * ~~~
	 *
	 * #pw-group-media
	 *
	 * @param string $username The username of the Instagram user.
	 * @return WireData|null
	 * @see InstagramMediaDisplay::getMedia()
	 *
	 */
	public function getCarouselAlbum($username = null) {
		$album = $this->getCarouselAlbums($username, 1);
		return $album->count() ? $album->first : null;
	}

	/**
	 * Get a list of Carousel Albums for a user
	 *
	 * ~~~
	 * // Get albums from the default user
	 * $albums = $instagram->getCarouselAlbums(); // 4 returned if found
	 *
	 * // Get 2 albums from the default user
	 * $albums = $instagram->getCarouselAlbums(2);
	 *
	 * // Get albums from a specified user
	 * $albums = $instagram->getCarouselAlbums('username'); // 4 returned if found
	 *
	 * // Get 3 albums from a specified user
	 * $albums = $instagram->getCarouselAlbums('username', 3);
	 *
	 * // Render the albums
	 * if($albums->count()) {
	 *     echo '<ul>' .
	 *         $instagram->getCarouselAlbums()->each(function($album) {
	 *             return '<li>' .
	 *                 '<ul>' .
	 *                     $album->children->each('<li>' .
	 *                         '<a href="{href}">' .
	 *                             '<img src="{src}" alt="{alt}">' .
	 *                         '</a>' .
	 *                     '</li>') .
	 *                 '</ul>' .
	 *             '</li>';
	 *         }) .
	 *     '</ul>';
	 * }
	 * ~~~
	 *
	 * #pw-group-media
	 *
	 * @param string $username The username of the Instagram user.
	 * @param int $count The number of items to return (default=4).
	 * @return WireArray
	 * @see InstagramMediaDisplay::getMedia()
	 *
	 */
	public function getCarouselAlbums($username = null, $count = 0) {
		if(!$count) $count = $this->imageCount;
		return $this->getMediaByType($this->mediaTypes['carouselAlbum'], $username, $count);
	}

	/**
	 * Get a list of Images for a user
	 *
	 * ~~~
	 * // Get images from the default user
	 * $images = $instagram->getImages(); // Returns all images found in the first request
	 *
	 * // Get 10 images from the default user
	 * $images = $instagram->getImages(10);
	 *
	 * // Get images from a specified user
	 * $images = $instagram->getImages('username'); // Returns all images found in the first request
	 *
	 * // Get 8 images from a specified user
	 * $images = $instagram->getImages('username', 8);
	 *
	 * // Render the images
	 * echo '<ul>' .
	 *     $images->each('<li>' .
	 *         '<a href="{href}">' .
	 *             '<img src="{src}" alt="{alt}">' .
	 *         '</a>' .
	 *     '</li>') .
	 * '</ul>';
	 * ~~~
	 *
	 * #pw-group-media
	 *
	 * @param string $username The username of the Instagram user.
	 * @param int $count The number of items to return.
	 * @return WireArray
	 * @see InstagramMediaDisplay::getMedia()
	 *
	 */
	public function getImages($username = null, $count = 0) {
		return $this->getMediaByType($this->mediaTypes['image'], $username, $count);
	}

	/**
	 * Get a list of Media for a user
	 *
	 * This method is called by a number of others such as `getImages()`.
	 * Where possible, please use these abstractions instead of calling this method directly.
	 *
	 * ~~~
	 * // Get media from the default user
	 * $items = $instagram->getMedia(); // Returns all media found in the first request
	 *
	 * // Get media from a specified user
	 * $items = $instagram->getMedia('username'); // Returns all media found in the first request
	 *
	 * // Get 10 media items from the default user
	 * $items = $instagram->getMedia(10);
	 *
	 * // Get 10 images from a specified user
	 * $items = $instagram->getMedia(10, 'image');
	 *
	 * // Get the most recent image from the default user with a specified tag
	 * $items = $instagram->getMedia(1, ['type' => 'image', 'tag' => 'tag']);
	 * if($items->count()) $image = $items->first;
	 * ~~~
	 *
	 * #pw-group-media
	 *
	 * @param string $username The username of the Instagram user.
	 * @param array $options Options to modify default behaviour:
	 * - `asArray` (bool): Should the data be returned as an array? (default=false)
	 * - `children` (bool|int|string): Should the children of any carousel albums be returned?
	 * If an integer or string is specified this will be used as the cache expiry for these requests (default=true).
	 * - `json` (bool): Should the data be returned as JSON? (default=false)
	 * - `count` (int): The number of items to return (default=0).
	 * - `tag` (string): An optional tag to filter by (default='').
	 * - `type` (string): The type of media to return (default='').
	 * @return WireArray|array|string
	 *
	 */
	public function getMedia($username = null, $options = []) {

		$session = $this->wire()->session;

		// Get default options
		$options = $this->getMediaOptions($username, $options);

		// Make sure the type is valid
		if($options['type']) {
			$options['type'] = strtoupper($options['type']);
			if(!in_array($options['type'], $this->mediaTypes)) {
				$options['type'] = '';
			}
		}

		// Remove leading hashtag from a given tag
		if($options['tag']) {
			$options['tag'] = ltrim($options['tag'], '#');
			$options['count'] = $options['limit'];
			$options['limit'] = $this->maxLimit;
		}

		// Make sure this is a valid username
		if(isset($username)) {
			if(is_string($username)) {
				if(!$this->isValidUser($username)) {
					return [];
				}
			} else {
				$username = null;
			}
		}

		// Get the IG_ID
		$igId = $this->getUserAccount($username)['user_id'] ?? 0;
		if(!$igId) {
			$this->logError($this->_('Could not get user ID'), ['username' => $username]);
			return [];
		}

		$request = [
			'access_token' => $this->getAccessToken($username),
			'fields' => implode(',', [
				'caption', // The Media's caption text. Not returnable for Media in albums.
				'id', // The Media's ID.
				'media_type', // The Media's type. Can be IMAGE, VIDEO, or CAROUSEL_ALBUM.
				'media_url', // The Media's URL.
				'permalink', // The Media's permanent URL.
				'thumbnail_url', // The Media's thumbnail image URL. Only available on VIDEO Media.
				'timestamp', // The Media's publish date in ISO 8601 format.
				'username', // The Media owner's username.
			]),
			'limit' => $options['limit'],
		];

		$isAjax = $this->isAjax();
		$nextKey = $this->getNextKey();

		$data = [];
		$next = $session->getFor($this, $nextKey); // Lazy pagination
		if($next === false) return $options['json'] ? '' : $this->getBlankArray();

		$response = isset($next) ? $this->apiRequest($next) : $this->apiRequest("$igId/media", $request);
		if(is_array($response)) {

			if($this->hasMedia($response)) {

				$items = $this->filterMedia($response['data'], $options);
				$itemCount = count($items);
				$totalCount = $options['count'] ?: $options['limit'];

				$next = isset($response['paging']['next']) ? $response['paging']['next'] : null;
				if(isset($next)) {

					if($itemCount && $totalCount && $totalCount > $itemCount) {

						do {

							$response = $this->apiRequest($next);

							if($this->hasMedia($response) && isset($response['paging']['next'])) {
								$items = array_merge($items, $this->filterMedia($response['data'], $options));
								$itemCount = count($items);
								$next = $response['paging']['next'];
							} else {
								$itemCount = $totalCount;
							}

						} while($itemCount < $totalCount);

					} else if($isAjax) {

						// Set the next link into session for retrieving on the next call
						$session->setFor($this, $nextKey, $next);
					}

				} else if($isAjax) {

					// Set next to false to prevent further API calls
					$session->setFor($this, $nextKey, false);
				}

				$count = $totalCount && $totalCount <= $itemCount ? $totalCount : $itemCount;
				$i = 0;
				foreach($items as $item) {
					if($i++ >= $count) break;
					$data[] = $item;
				}

				if(count($data)) {

					$json = $options['json'];
					if(!$options['asArray'] || $json) {

						$items = $data;
						// Convert to WireArray/WireData/array
						$data = $this->getBlankArray($json);
						foreach($items as $item) {

							$media = $this->getMediaItem($item, $json);

							// Get Carousel Album children
							$type = $json ? $media['type'] : $media->type;
							if($options['children'] && $type === $this->mediaTypes['carouselAlbum']) {
								if(!is_bool($options['children'])) $this->cacheTime = $options['children'];
								$request['fields'] = str_replace('caption,', '', $request['fields']);
								$response = $this->apiRequest("$item[id]/children", $request);
								if($this->hasMedia($response)) {
									$children = $this->getBlankArray($json);
									foreach($response['data'] as $child) {
										$childMedia = $this->getMediaItem($child, $json);
										if($json) {
											$children[] = $childMedia;
										} else {
											$children->add($childMedia);
										}
									}
									if($json) {
										$media['children'] = $children;
									} else {
										$media->set('children', $children);
									}
								}
							}

							if($json) {
								$data[] = $media;
							} else {
								$data->add($media);
							}
						}
					}

					if($json) $data = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
				}

			} else {

				$this->logError($this->_('Could not process user media'), $response);
			}

		} else {

			$this->logError($this->_('Could not get user media'), ['username' => $username, 'options' => $options]);
		}

		return $data;
	}

	/**
	 * Get a user's profile information
	 *
	 * ~~~
	 * // Get the profile data of the default (first) user
	 * $profile = $instagram->getProfile();
	 *
	 * // Get the profile data of a specified user
	 * $profile = $instagram->getProfile('username');
	 *
	 * // Display the profile information
	 * if(count($profile)) {
	 *     $info = '';
	 *     foreach($profile as $key => $value) {
	 *         $info .= "<li>$key: $value</li>";
	 *     }
	 *     echo "<ul>$info</ul>";
	 * }
	 * // Fields returned: username, id, account_type, media_count, profile_picture_url;
	 * ~~~
	 *
	 * #pw-group-profile
	 *
	 * @param string $username The username of the Instagram user.
	 * @param string $accessToken The access token of the Instagram user.
	 * @return array
	 *
	 */
	public function getProfile($username = null, $accessToken = null) {
		if(is_null($username) || isset($accessToken) || $this->isValidUser($username)) {
			$profile = $this->apiRequest('me', [
				'fields' => implode(',', [
					'user_id', // The User's ID.
					'username', // The User's username.
					'account_type', // The User's account type. Can be BUSINESS, MEDIA_CREATOR, or PERSONAL.
					'media_count', // The number of Media on the User. This field requires the instagram_graph_user_media permission.
					'profile_picture_url',
				]),
				'access_token' => (isset($accessToken) ? $accessToken : $this->getAccessToken($username)),
			]);
			if($profile && isset($profile['username'])) {
				if(is_null($accessToken)) {
					$this->updateUserAccount($profile['username'], $profile['media_count']);
				}
				return $profile;
			}
		}
		return [];
	}

	/**
	 * Get recent comments
	 *
	 * Returns a blank array as comments cannot be accessed by the Instagram Media Display.
	 *
	 * #pw-group-instagram-feed
	 *
	 * @param array $media Unused. Provided for compatibility with InstagramFeed.
	 * @return array An empty array
	 * @see InstagramFeed::getRecentComments()
	 *
	 */
	public function getRecentComments($media = null) {
		$this->logError($this->_('Sorry, comments are not accessible using the Instagram Media Display.'));
		return [];
	}

	/**
	 * Get the most recent media published by a user
	 *
	 * This is probably the most commonly used method from
	 * [InstagramFeed](https://modules.processwire.com/modules/instagram-feed/).
	 * You should not need to change your method call, however some of
	 * the values returned by the deprecated API are no longer present and
	 * are returned as `null` instead.
	 *
	 * ~~~
	 * // $instagram = $modules->get('InstagramFeed');
	 * $instagram = $modules->get('InstagramMediaDisplay');
	 * $images = $instagram->setImageCount(6)->getRecentMedia();
	 * ~~~
	 *
	 * #pw-group-instagram-feed
	 *
	 * @param string $username The username of the Instagram user.
	 * @param array $options Options to modify behaviour:
	 * - `getSize` (bool): Should the image width and height be returned?
	 * This is set to `false` by default as it slows response time.
	 * - `tag` (string): An optional tag to filter by (default='').
	 * @return array
	 * @see InstagramFeed::getRecentMedia()
	 *
	 */
	public function getRecentMedia($username = null, $options = []) {

		// Shortcuts
		if(isset($username) && !is_string($username)) {
			if(is_bool($username)) $options = ['getSize' => $username];
			if(is_array($username)) $options = $username;
			$username = null;
		}
		if(is_bool($options)) $options = ['getSize' => $options];
		if(is_string($options)) $options = ['tag' => $options];

		// Set default options
		$options = array_merge([
			'getSize' => false,
			'limit' => $this->imageCount,
			'tag' => '',
		], $options);

		$items = $this->getMedia($username, array_merge($options, [
			'asArray' => true,
			'type' => $this->mediaTypes['image'],
		]));

		$profile = $this->getProfile($username);

		$data = [];
		if(is_array($items)) {

			$count = count($items);
			$count = $this->imageCount && $this->imageCount <= $count ? $this->imageCount : $count;
			$i = 0;
			foreach($items as $item) {

				if($i >= $count) break;

				$user = [
					'id' => $profile['user_id'] ?? null,
					'full_name' => $profile['username'] ?? null,
					'profile_picture' => $profile['profile_picture_url'] ?? null,
					'username' => $item['username'],
				];

				$image = [
					'url' => $item['media_url'],
					'width' => null,
					'height' => null,
				];

				if($options['getSize']) {
					$size = getimagesize($image['url']);
					$image = array_merge($image, [
						'width' => $size[0],
						'height' => $size[1]
					]);
				}

				$timestamp = strtotime($item['timestamp']);

				$data[] = [
					'id' => $item['id'],
					'user' => $user,
					'images' => [
						'thumbnail' => $image,
						'low_resolution' => $image,
						'standard_resolution' => $image,
					],
					'created_time' => $timestamp,
					'caption' => [
						'id' => null,
						'text' => $item['caption'],
						'created_time' => $timestamp,
						'from' => $user,
					],
					'user_has_liked' => null,
					'likes' => ['count' => null],
					'tags' => $item['tags'],
					'filter' => null,
					'comments' => ['count' => null],
					'type' => strtolower($item['media_type']),
					'link' => $item['permalink'],
					'location' => [
						'latitude' => null,
						'longitude' => null,
						'name' => null,
						'id' => null,
					],
					'attribution' => null,
					'users_in_photo' => null,
				];

				$i++;
			}
		}

		return $data;
	}

	/**
	 * Get a list of recently tagged media
	 *
	 * Instagram Media Display does not provide a way to search media by tag.
	 * This implementation will keep calling the API until it has enough matching items
	 * to return, or all media items have been retrieved.
	 *
	 * Using this method is therefore **not recommended** as it is likely to slow
	 * response times and could possibly exhaust resource limits.
	 *
	 * #pw-group-instagram-feed
	 *
	 * @param string $tag The tag to search for.
	 * @param string $username The username of the Instagram user.
	 * @param array $options Options to modify behaviour.
	 * @return array
	 * @see InstagramFeed::getRecentMediaByTag()
	 * @see InstagramMediaDisplay::getRecentMedia()
	 *
	 */
	public function getRecentMediaByTag($tag, $username = null, $options = []) {
		$options = $this->getMediaOptions($username, $options);
		$options['tag'] = $tag;
		$options['limit'] = $this->imageCount;
		return $this->getRecentMedia($username, $options);
	}

	/**
	 * Get the user's ID from their username
	 *
	 * #pw-group-instagram-feed
	 *
	 * @param string $username The username of the Instagram user.
	 * @return int
	 * @see InstagramFeed::getUserIdByUsername()
	 *
	 */
	public function getUserIdByUsername($username = '') {
		$data = $this->getUserAccount($username);
		return isset($data['user_id']) ? (int) $data['user_id'] : 0;
	}

	/**
	 * Get the most recent Video for a user
	 *
	 * ~~~
	 * // Get the most recent video from the default user
	 * $video = $instagram->getVideo();
	 *
	 * // Get the most recent video from a specified user
	 * $video = $instagram->getVideo('username');
	 *
	 * // Render the video
	 * if(isset($video)) {
	 *     echo '<video ' .
	 *         "src='$video->src' " .
	 *         "poster='$video->poster' " .
	 *         'type="video/mp4" ' .
	 *         'controls ' .
	 *         'playsinline' .
	 *     '></video>';
	 *
	 *     if($video->description) {
	 *         echo "<p>$video->description</p>";
	 *     }
	 * }
	 * ~~~
	 *
	 * #pw-group-media
	 *
	 * @param string $username The username of the Instagram user.
	 * @return WireData|null
	 * @see InstagramMediaDisplay::getMedia()
	 *
	 */
	public function getVideo($username = null) {
		$video = $this->getVideos($username, 1);
		return $video->count() ? $video->first : null;
	}

	/**
	 * Get a list of Videos for a user
	 *
	 * ~~~
	 * // Get videos from the default user
	 * $videos = $instagram->getVideos(); // 4 returned if found
	 *
	 * // Get 2 videos from the default user
	 * $videos = $instagram->getVideos(2);
	 *
	 * // Get videos from a specified user
	 * $videos = $instagram->getVideos('username'); // 4 returned if found
	 *
	 * // Get 3 videos from a specified user
	 * $videos = $instagram->getVideos('username', 3);
	 *
	 * // Render the videos
	 * if($videos->count()) {
	 *     echo '<ul>' .
	 *         $videos->each('<li>' .
	 *             '<video ' .
	 *                 'src="{src}" ' .
	 *                 'poster="{poster}" ' .
	 *                 'type="video/mp4" ' .
	 *                 'controls ' .
	 *                 'playsinline' .
	 *             '></video>' .
	 *         '</li>') .
	 *     '</ul>';
	 * }
	 * ~~~
	 *
	 * #pw-group-media
	 *
	 * @param string $username The username of the Instagram user.
	 * @param int $limit The number of items to return (default=4).
	 * @return WireArray
	 * @see InstagramMediaDisplay::getMedia()
	 *
	 */
	public function getVideos($username = null, $count = 4) {
		if(!$count) $count = $this->imageCount;
		return $this->getMediaByType($this->mediaTypes['video'], $username, $count);
	}

	/**
	 * Set the image count
	 *
	 * #pw-group-instagram-feed
	 *
	 * @param int $imageCount
	 * @return $this
	 *
	 */
	public function setImageCount($imageCount = 4) {
		$this->imageCount = (int) $imageCount;
		return $this;
	}

	/**
	 * Set the max limit
	 *
	 * #pw-group-instagram
	 *
	 * @param int $maxLimit
	 * @return $this
	 *
	 */
	public function setMaxLimit($maxLimit = 100) {
		$this->maxLimit = (int) $maxLimit;
		return $this;
	}

	/**
	 * Add an Instagram user account
	 *
	 * #pw-internal
	 *
	 * @param string $username The username of the Instagram user.
	 * @param string $token The generated long-lived token.
	 * @return bool
	 *
	 */
	public function addUserAccount($username, $token) {

		$profile = $this->getProfile($username, $token);
		if(isset($profile['id'])) {

			$query = $this->wire()->database->prepare(
				'INSERT INTO ' . self::dbTableName . ' SET ' .
					'username=:username, ' .
					'token=:token, ' .
					'user_id=:id, ' .
					'account_type=:type, ' .
					'media_count=:count, ' .
					'token_renews=:date, ' .
					'modified=NOW()'
			);
			$query->bindValue(':username', $username);
			$query->bindValue(':token', $token);
			$query->bindValue(':id', $profile['id']);
			$query->bindValue(':type', $profile['account_type']);
			$query->bindValue(':count', $profile['media_count']);
			$query->bindValue(':date', $this->getRenewalDate());

			return $query->execute();

		} else {
			$this->logError(sprintf($this->_('Could not add user account %s'), $username), $profile);
			return false;
		}
	}

	/**
	 * Get the user account
	 *
	 * #pw-internal
	 *
	 * @param string|int $key
	 * @return array
	 *
	 */
	public function getUserAccount($key) {
		$query = $this->wire()->database->prepare('SELECT * FROM ' . self::dbTableName .
			($key ? ' WHERE user' . (is_numeric($key) ? '_id' : 'name') . '=:key' : ''));
		if($key) $query->bindValue(':key', $key);
		$query->execute();
		return $query->rowCount() ? $query->fetch(PDO::FETCH_ASSOC) : [];
	}

	/**
	 * Get the user accounts
	 *
	 * #pw-internal
	 *
	 * @param bool $request If enabled, the profile data will also
	 * be requested from the API to update media counts (default=false).
	 * @return array
	 *
	 */
	public function getUserAccounts($request = false) {
		$accounts = [];
		$query = $this->wire()->database->prepare('SELECT * FROM ' . self::dbTableName);
		$query->execute();
		if($query->rowCount()) {
			while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
				if($request) {
					$row = array_merge($row, $this->getProfile($row['username']));
				}
				$accounts[$row['username']] = $row;
			}
			$query->closeCursor();
		}
		return $accounts;
	}

	/**
	 * Remove an Instagram user account
	 *
	 * ~~~
	 * // Remove an account
	 * $removed = $instagram->removeUserAccount($username);
	 * ~~~
	 *
	 * #pw-internal
	 *
	 * @param string|int $username The username of the Instagram user.
	 * @return bool
	 *
	 */
	public function removeUserAccount($username) {
		$query = $this->wire()->database->prepare('DELETE FROM ' . self::dbTableName . ' WHERE username=:username');
		$query->bindValue(':username', $username);
		return $query->execute();
	}

	/**
	 * API Request
	 *
	 * #pw-internal
	 *
	 * @param string $endpoint
	 * @param array $data
	 * @param bool $useCache
	 * @return array|false
	 *
	 */
	protected function apiRequest($endpoint, array $data = [], $useCache = true) {

		$cache = $this->wire()->cache;
		$http = $this->wire(new WireHttp());

		// If the long-lived token expires in the next week then refresh it
		if($useCache && count($data) && isset($data['access_token'])) {
			foreach($this->getUserAccounts() as $username => $accessData) {
				if(isset($data['access_token']) && $data['access_token'] === $accessData['token']) {
					if(strtotime($accessData['token_renews'] . '-7 days') < time()) {

						// Refresh a long-lived Instagram User Access Token
						// https://developers.facebook.com/docs/instagram-basic-display-api/reference/refresh_access_token

						$response = $this->apiRequest('refresh_access_token', [
							'grant_type' => 'ig_refresh_token',
							'access_token' => $accessData['token'],
						], false);

						if($response && isset($response['access_token'])) {
							$this->updateUserAccount($username, $response['access_token']);
							$data['access_token'] = $response['access_token'];
							$message = sprintf($this->_('Long-lived access token refreshed for %s'), $username);
						} else {
							$message = $this->_('Could not refresh long-lived access token');
						}

						$this->log($message);

						break;
					}
				}
			}
		}

		// Endpoint URL
		$urlGraph = 'https://graph.instagram.com/v20.0';
		$url = strpos($endpoint, '://') === false ? "$urlGraph/$endpoint" : $endpoint;

		// Cache Name
		$cacheName = str_replace($urlGraph, '', $endpoint);
		if(isset($data['limit'])) $cacheName .= $data['limit'];
		if(isset($this->currentUsername)) $cacheName .= $this->currentUsername;

		// Cache Time
		$defaultTime = 3600;
		$cacheTime = $this->cacheTime ?: $defaultTime;

		$response = $useCache ? $cache->getFor(
			$this,
			md5($cacheName),
			function() use ($http, $url, $data) {
				return $http->getJSON($url, true, $data);
			},
			($cacheTime >= (86400 * 7) ? $defaultTime : $cacheTime)
		) : $http->getJSON($url, true, $data);

		$hasError = isset($response['error']);
		if($response === false || $hasError) {

			// Log error
			$this->logError($this->_('API Request Failed'), [
				'endpoint' => $endpoint,
				'data' => $data,
				'useCache' => $useCache,
				'response' => $response,
				'status' => $http->getHttpCode(),
			]);

			if($hasError && ($response['error']['type'] ?? '') === 'OAuthException') {

				$subject = $this->_('Instagram API Authorisation Error');
				$body = $this->_('The Instagram API has returned an authorisation error. Please check your access token.');

				$cache->getFor($this, 'notifyAdmin', 'daily', function() use ($response) {

					$config = $this->wire()->config;
					$adminEmail = $config->adminEmail;
					if($adminEmail) {
						$this->wire()->mail->new()
							->to($adminEmail)
							->subject(sprintf(
								__('Instagram Media Display authorisation error on %s'),
								$config->httpHost
							))
							->body(implode("\n\n", [
								__('The Instagram API has returned an authorisation error.'),
								__('Please check your access token.'),
								json_encode($response, JSON_PRETTY_PRINT),
							]))
							->send();
						return 2; // logged and email sent
					}

					return 1; // only logged

				});
			}
		}

		return $response;
	}

	/**
	 * Filter a media response
	 *
	 * #pw-internal
	 *
	 * @param array $media
	 * @param array $options
	 * @return array
	 *
	 */
	protected function filterMedia(array $media, array $options = []) {

		$options = array_merge(['type' => '', 'tag' => ''], $options);
		$typeImage = $this->mediaTypes['image'];

		// Set tags and type
		$items = [];
		foreach($media as $item) {

			// Get tags from caption
			$item['tags'] = [];
			if(isset($item['caption'])) {
				preg_match_all('/(#\w+)/u', $item['caption'], $matches);
				if(is_array($matches) && count($matches)) {
					foreach($matches[0] as $tag) {
						$item['tags'][] = strtolower(ltrim($tag, '#'));
					}
				}
			}

			// If the IMAGE type is specified, convert other types to this type
			if($options['type'] === $typeImage && $item['media_type'] !== $typeImage) {
				if($item['media_type'] === $this->mediaTypes['video']) {
					$item['media_url'] = $item['thumbnail_url'];
				}
				$item['media_type'] = $typeImage;
			}

			$items[] = $item;
		}

		// Filter by type
		if($options['type']) {
			$media = $items;
			$items = [];
			foreach($media as $item) {
				if($item['media_type'] === $options['type']) {
					$items[] = $item;
				}
			}
		}

		// Filter by tag
		if($options['tag']) {
			$media = $items;
			$items = [];
			foreach($media as $item) {
				if(in_array(strtolower($options['tag']), $item['tags'])) {
					$items[] = $item;
				}
			}
		}

		// Make sure there are no duplicates and remove invalid items
		$media = $items;
		$items = [];
		foreach($media as $item) {
			// If this isn't a valid item with a media_url, skip it
			if(!isset($item['media_url'])) continue;
			$items[$item['media_url']] = $item;
		}

		return $items;
	}

	/**
	 * Get the user's access token
	 *
	 * #pw-internal
	 *
	 * @param string $username
	 * @return string
	 *
	 */
	protected function getAccessToken($username = null) {
		if(is_null($username)) $username = '';
		$account = $this->getUserAccount($username);
		if(isset($account['username'])) $this->currentUsername = $account['username'];
		return isset($account['token']) ? $account['token'] : '';
	}

	/**
	 * Get a blank array or WireArray
	 *
	 * #pw-internal
	 *
	 * @param bool $array
	 * @return WireArray|array
	 *
	 */
	protected function getBlankArray($array = false) {
		return $array ? [] : (new WireArray());
	}

	/**
	 * Get media items by type
	 *
	 * @param string $type
	 * @param string $username
	 * @param int $count
	 * @return WireArray|array
	 * @see InstagramMediaDisplay::getMedia()
	 *
	 */
	protected function getMediaByType($type, $username = null, $count = 0) {
		if(is_int($username)) {
			$count = $username;
			$username = null;
		}
		if($type === $this->mediaTypes['image'] && $count) {
			$this->limit = $count;
			$count = 0;
		}
		return $this->getMedia($username, [
			'count' => $count,
			'limit' => ($count ? $this->maxLimit : $this->limit),
			'type' => $type,
		]);
	}

	/**
	 * Get media item
	 *
	 * @param array $item
	 * @param bool $array
	 * @return WireArray|array
	 *
	 */
	protected function getMediaItem($item, $array = false) {

		$media = $array ? [] : (new WireData());

		foreach([
			'id' => ['id'],
			'media_type' => ['type'],
			'caption' => ['alt', 'description'],
			'media_url' => ['src', 'url'],
			'tags' => ['tags'],
			'timestamp' => ['created', 'createdStr'],
			'permalink' => ['href', 'link'],
			'thumbnail_url' => ['poster'],
		] as $key => $properties) {
			if(isset($item[$key])) {
				foreach($properties as $property) {
					$value = $item[$key];
					switch($key) {
						case 'caption':
							$value = $this->wire()->sanitizer->entities1($value, true);
							break;
						case 'timestamp':
							if($property === 'created') {
								$value = strtotime($value);
							}
							break;
					}
					if($array) {
						$media[$property] = $value;
					} else {
						$media->set($property, $value);
					}
				}
			}
		}

		return $media;
	}

	/**
	 * Get the default getMedia options
	 *
	 * @param mixed $username
	 * @param mixed $options
	 * @return array
	 * @see InstagramMediaDisplay::getMedia()
	 *
	 */
	protected function getMediaOptions($username, $options) {

		// Shortcuts
		if(is_array($username)) $options = $username;
		if(is_string($options)) $options = ['type' => $options];
		if(is_bool($options)) $options = ['asArray' => $options];
		if(is_int($options)) $options = ['limit' => $options];

		// Set default options
		$options = array_merge([
			'asArray' => false,
			'children' => true,
			'count' => 0,
			'json' => $this->isAjax(),
			'limit' => $this->limit,
			'type' => '',
			'tag' => '',
		], $options);

		if(!is_string($username)) {
			if(is_int($username)) $options['limit'] = $username;
			if(is_bool($username)) $options['asArray'] = $username;
		}

		return $options;
	}

	/**
	 * Get the session key for getting/setting the next link
	 *
	 * #pw-internal
	 *
	 * @return string
	 *
	 */
	protected function getNextKey() {
		return 'next' . $this->wire()->page->id;
	}

	/**
	 * Get the token renewal date
	 *
	 * #pw-internal
	 *
	 * @param int $days
	 * @return string
	 *
	 */
	protected function getRenewalDate($days = 60) {
		return date('Y-m-d H:i:s', strtotime("+$days days"));
	}

	/**
	 * Does the response have media?
	 *
	 * #pw-internal
	 *
	 * @param array $response
	 * @return bool
	 *
	 */
	protected function hasMedia($response) {
		return is_array($response) ? isset($response['data']) && is_array($response['data']) && count($response['data']) : false;
	}

	/**
	 * Is this an AJAX request?
	 *
	 * #pw-internal
	 *
	 * @return bool
	 *
	 */
	protected function isAjax() {
		return $this->wire()->config->ajax;
	}

	/**
	 * Is the given username a valid authorized user?
	 *
	 * #pw-internal
	 *
	 * @param string $username
	 * @return bool
	 *
	 */
	protected function isValidUser($username) {
		$valid = isset($this->getUserAccounts()[$username]);
		if(!$valid) $this->logError(sprintf($this->_('%s is not an authorized user.'), $username));
		return $valid;
	}

	/**
	 * Log an error message
	 *
	 * #pw-internal
	 *
	 * @param string $message
	 * @param array $data
	 * @return bool
	 *
	 */
	protected function logError($message, array $data = []) {
		if(count($data)) $message .= ': ' . json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
		return $this->log($message);
	}

	/**
	 * Update a user account
	 *
	 * #pw-internal
	 *
	 * @param string $username
	 * @param string|int $value
	 * @return bool
	 *
	 */
	protected function updateUserAccount($username, $value) {
		$isToken = is_string($value);
		$query = $this->wire()->database->prepare(
			'UPDATE ' . self::dbTableName . ' SET ' .
				'username=:username, ' .
				($isToken ? 'token=:value, token_renews=:date' : 'media_count=:value') . ', ' .
				'modified=NOW() ' .
				'WHERE username=:username'
		);
		$query->bindValue(':username', $username);
		$query->bindValue(':value', $value);
		if($isToken) $query->bindValue(':date', $this->getRenewalDate());
		return $query->execute();
	}

	/**
	 * Install
	 *
	 */
	public function ___install() {
		$this->wire()->database->exec('CREATE TABLE ' . self::dbTableName . ' (' .
			'username VARCHAR(32) NOT NULL PRIMARY KEY,' .
			'token VARCHAR(256) NOT NULL,' .
			'user_id VARCHAR(32) NOT NULL,' .
			'account_type VARCHAR(32) NOT NULL,' .
			'media_count INT(32) NOT NULL,' .
			'token_renews DATETIME NOT NULL,' .
			'modified TIMESTAMP NOT NULL' .
		')');
	}

	/**
	 * Uninstall
	 *
	 */
	public function ___uninstall() {
		try {
			$this->wire()->database->exec('DROP TABLE ' . self::dbTableName);
		} catch(Exception $e) {
			// Fail
		}
	}
}
