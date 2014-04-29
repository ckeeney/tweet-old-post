<?php
// Basic configuration 
require_once("config.php");
// twitteroauth class 
require_once("oAuth/twitteroauth.php");

if (!class_exists('CWP_TOP_Core')) {
	class CWP_TOP_Core {

		// All fields
		public static $fields;
		// Number of fields
		public static $noFields;

		// Consumer key, Consumer Secret key, oAuth Callback Key
		public $consumer;
		public $consumerSecret;
		public $oAuthCallback;
		public $bitly_key;
		public $bitly_user;
		// Access token, oAuth Token, oAuth Token Secret and User Information
		private $cwp_top_access_token;
		private $cwp_top_oauth_token;
		private $cwp_top_oauth_token_secret;


		private $users;
		private $user_info;

		// Plugin Status
		public $pluginStatus;
		// Interval Set
		public $intervalSet;
		public $cwp_twitter;

		public function __construct() {
			// Get all fields
			global $cwp_top_fields;

			// Set all authentication settings
			$this->setAlloAuthSettings();

			// Load all hooks
			$this->loadAllHooks();

			// Check if the user added any account
			$this->afterAddAccountCheck();

			// Save all fields in static var
			self::$fields = $cwp_top_fields;

			// Save all number of fields in static var
			self::$noFields = count(self::$fields);
		}

		public function startTweetOldPost()
		{
			// If the plugin is deactivated
			if($this->pluginStatus !== 'true') {
				// Set it to active status
				update_option('cwp_topnew_active_status', 'true');
				// Schedule the next tweet
				//$timeNow = date("Y-m-d H:i:s", time());
				//$timeNow = get_date_from_gmt($timeNow);
				//$timeNow= strtotime($timeNow);
				$timeNow = time();
				$interval = floatval($this->intervalSet) * 60 * 60;
				$timeNow = $timeNow+$interval;
				wp_schedule_event($timeNow, 'cwp_top_schedule', 'cwp_top_tweet_cron');
			} else { 
				
				// Report that is already started
				_e("Tweet Old Post is already active!", CWP_TEXTDOMAIN);
			}

			die(); // Required for AJAX
		}

		public function stopTweetOldPost()
		{
			//echo $this->pluginStatus;
			// If the plugin is active
			if($this->pluginStatus !== 'false') {
				// Set it to inactive status
				update_option('cwp_topnew_active_status', 'false');
				// Clear all scheduled tweets
				$this->clearScheduledTweets();
			} else {
				// Report that is already inactive
				_e("Tweet Old Post is already inactive!", CWP_TEXTDOMAIN);
			}

			die(); // Required for AJAX
		}

		public function getTweetsFromDB()
		{
			// Global WordPress $wpdb object.
			global $wpdb;

			// Generate the Tweet Post Date Range
			$dateQuery = $this->getTweetPostDateRange();

			// Get the number of tweets to be tweeted each interval.
			$tweetCount = intval(get_option('top_opt_no_of_tweet'));

			// Get post categories set.
//			$postQueryCategories =  $this->getTweetCategories();

			// Get excluded categories.
			$postQueryExcludedCategories = $this->getExcludedCategories();			
			//echo $postQueryExcludedCategories;

			// Get post type set.
			$somePostType = $this->getTweetPostType();

			// Generate dynamic query.
			$query = "
				SELECT *
				FROM {$wpdb->prefix}posts
				INNER JOIN {$wpdb->prefix}term_relationships ON ({$wpdb->prefix}posts.ID = {$wpdb->prefix}term_relationships.object_id)
				WHERE 1=1
				  AND ((post_date >= '{$dateQuery['before']}'
				        AND post_date <= '{$dateQuery['after']}')) ";

			// If there are no categories set, select the post from all.
			//if(!empty($postQueryCategories)) {
		//		$query .= "AND (wp_term_relationships.term_taxonomy_id IN ({$postQueryCategories})) ";
		//	}

			if(!empty($postQueryExcludedCategories)) {
				$query .= "AND ( {$wpdb->prefix}posts.ID NOT IN (
					SELECT object_id
					FROM {$wpdb->prefix}term_relationships
					WHERE term_taxonomy_id IN ({$postQueryExcludedCategories})))";
			}
						  
			$query .= "AND {$wpdb->prefix}posts.post_type IN ({$somePostType})
					  AND ({$wpdb->prefix}posts.post_status = 'publish')
					GROUP BY {$wpdb->prefix}posts.ID
					ORDER BY RAND() DESC LIMIT 0,{$tweetCount}
			";

			// Save the result in a var for future use.
			$returnedPost = $wpdb->get_results($query);

			return $returnedPost;
		}

		public function isPostWithImageEnabled () {
			return get_option("top_opt_post_with_image");
		}

		public function tweetOldPost()
		
		{
			$returnedPost = $this->getTweetsFromDB();


			$k = 0; // Iterator
			
			// Get the number of tweets to be tweeted each interval.
			$tweetCount = intval(get_option('top_opt_no_of_tweet'));

			// While haven't reached the limit
			while($k != $tweetCount) {
				// If the post is not already tweeted
				$isNotAlreadyTweeted = $this->isNotAlreadyTweeted($returnedPost[$k]->ID);

				if($isNotAlreadyTweeted) {

					// Foreach returned post
					foreach ($returnedPost as $post) {
						// Generate a tweet from it based on user settings.
						$finalTweet = $this->generateTweetFromPost($post);
						// Tweet the post
						if ($this->isPostWithImageEnabled()=="on")
							$this->tweetPostwithImage($finalTweet, $returnedPost[$k]->ID);
						else
							$this->tweetPost($finalTweet);
						// Get already tweeted posts array.
						$tweetedPosts = get_option("top_opt_already_tweeted_posts");
						// Push the tweeted post at the end of the array.
						array_push($tweetedPosts, $post->ID);
						// Update the new already tweeted posts array.
						update_option("top_opt_already_tweeted_posts", $tweetedPosts);
						// Increase
						$k = $k + 1;
					}
				}
			}

		}

		public function viewSampleTweet()
		{

			$returnedTweets = $this->getTweetsFromDB();
			//var_dump($returnedTweets);
			$finalTweetsPreview = $this->generateTweetFromPost($returnedTweets[0]);
			echo $finalTweetsPreview;
			
			die(); // required
		}

		/**
		 * Returns if the post is already tweeted
		 * @param  [type]  $postId Post ID
		 * @return boolean         True if not already tweeted / False if already tweeted
		 */
		
		public function isNotAlreadyTweeted($postId) {
			// Get all already tweeted posts
			$tweetedPosts = get_option("top_opt_already_tweeted_posts");
			// If the new post ID is in the array, which means that is already tweeted
			if(in_array($postId, $tweetedPosts)) {
				return false;
			} else {
				return true;
			}
		}

		/**
		 * Generates the tweet based on the user settings
		 * @param  [type] $postQuery Returned post from database
		 * @return [type]            Generated Tweet
		 */
		
		public function generateTweetFromPost($postQuery)
		{

			// Save all user settings in variables.
			$tweetedPosts 					= get_option("top_opt_already_tweeted_posts");
			$tweet_content 					= get_option('top_opt_tweet_type');
			$tweet_content_custom_field 	= get_option('top_opt_tweet_type_custom_field');
			$additional_text 				= get_option('top_opt_add_text');
			$additional_text_at 			= get_option('top_opt_add_text_at');
			$include_link 					= get_option('top_opt_include_link');
			$fetch_url_from_custom_field 	= get_option('top_opt_custom_url_option');
			$custom_field_url 				= get_option('top_opt_custom_url_field');
			$use_url_shortner 				= get_option('top_opt_use_url_shortner');
			$url_shortner_service 			= get_option('top_opt_url_shortner');
			$hashtags 						= get_option('top_opt_custom_hashtag_option');
			$common_hashtags				= get_option('top_opt_hashtags');
			$maximum_hashtag_length 		= get_option('top_opt_hashtag_length');
			$hashtag_custom_field 			= get_option('top_opt_custom_hashtag_field');
			$bitly_key 						= get_option('top_opt_bitly_key');
            $bitly_user 					= get_option('top_opt_bitly_user');
			$additionalTextBeginning 		= "";
			$additionalTextEnd 				= "";

			// If the user set to not use hashtags, set it to empty variable.
			if($hashtags == 'nohashtag') { 
				$newHashtags = "";
			}

			// Generate the tweet content.
			switch ($tweet_content) {
				case 'title':
					$tweetContent = $postQuery->post_title . " ";
					break;
				
				case 'body':
					$tweetContent = get_post_field('post_content', $postQuery->ID) . " ";
					break;

				case 'titlenbody':
					$tweetContent = $postQuery->post_title . " " . get_post_field('post_content', $postQuery->ID) . " ";
					break;

				case 'custom-field':
					$tweetContent = get_post_meta($postQuery->ID, $tweet_content_custom_field,true);
					break;
				default:
					$tweetContent = $finalTweet;
					break;
			}

			// Trim new empty lines.
			$tweetContent = trim(preg_replace('/\s+/', ' ', $tweetContent));

			// Remove html entinies.
			$tweetContent = preg_replace("/&#?[a-z0-9]+;/i","", $tweetContent);

			// Strip all shortcodes from content.
			$tweetContent = strip_shortcodes($tweetContent);

			// Generate the post link.
			if($include_link == 'true') {
				if($fetch_url_from_custom_field == 'on') {
					$post_url = " " . get_post_meta($postQuery->ID, $custom_field_url,true) . " ";
				} else { 
					$post_url = " " . get_permalink($postQuery->ID);
				}

				if($use_url_shortner == 'on') {
					$post_url = " " . $this->shortenURL($post_url, $url_shortner_service, $postQuery->ID, $bitly_key, $bitly_user);
				}
				$post_url = $post_url . " ";
			} else { $post_url = ""; }

			// Generate the hashtags
			$newHashtags = "";
			if($hashtags != 'nohashtag') {
				
				switch ($hashtags) {
					case 'common':
						$newHashtags = $common_hashtags;
						break;
					
					case 'categories':
						$postCategories = get_the_category($postQuery->ID);
						
						foreach ($postCategories as $category) {
							if(strlen($category->cat_name.$newHashtags) <= $maximum_hashtag_length || $maximum_hashtag_length == 0) { 
						 		$newHashtags = $newHashtags . " #" . strtolower($category->cat_name); 
						 	}
						} 

						break;

					case 'tags':
						$postTags = wp_get_post_tags($postQuery->ID);
						
						foreach ($postTags as $postTag) {
							if(strlen($postTag->slug.$newHashtags) <= $maximum_hashtag_length || $maximum_hashtag_length == 0) {
								$newHashtags = $newHashtags . " #" . strtolower($postTag->slug);
							}
						}
						break;

					case 'custom':
						$newHashtags = get_post_meta($postQuery->ID, $hashtag_custom_field);
						break;	
					default:
						break;
				}
			}


			// Generate the additional text
			if($additional_text_at == 'beginning') {
				$additionalTextBeginning = $additional_text . " ";
			}

			if($additional_text_at == 'end') {
				$additionalTextEnd = " " . $additional_text;
			}

			// Calculate the final tweet length
			$finalTweetLength = 0;

			if(!empty($additional_text)) {
				$additionalTextLength = strlen($additional_text); $finalTweetLength += intval($additionalTextLength);
			}

			if(!empty($post_url)) {
				$postURLLength = strlen($post_url); $finalTweetLength += intval($postURLLength);
			}

			if(!empty($newHashtags)) {
				$hashtagsLength = strlen($newHashtags); $finalTweetLength += intval($hashtagsLength);
			}

			$finalTweetLength = 139 - $finalTweetLength - 3;

			$tweetContent = substr($tweetContent,0, $finalTweetLength) . "...";

			$finalTweet = $additionalTextBeginning . $tweetContent . $post_url . $newHashtags . $additionalTextEnd;
			$finalTweet = substr($finalTweet,0, 140);

			// Strip any tags and return the final tweet
			return strip_tags($finalTweet); 
		}

		/**
		 * Tweets the returned post from generateTweetFromPost()
		 * @param  [type] $finalTweet Generated tweet
		 */
		
		public function tweetPost($finalTweet)
		{	
			foreach ($this->users as $user) {
				// Create a new twitter connection using the stored user credentials.
				$connection = new TwitterOAuth($this->consumer, $this->consumerSecret, $user['oauth_token'], $user['oauth_token_secret']);
				// Post the new tweet
				$status = $connection->post('statuses/update', array('status' => $finalTweet));				
			}
		}

		public function tweetPostwithImage($finalTweet, $id)
		{	
			foreach ($this->users as $user) {
				// Create a new twitter connection using the stored user credentials.
				$connection = new TwitterOAuth($this->consumer, $this->consumerSecret, $user['oauth_token'], $user['oauth_token_secret']);
				// Post the new tweet
				if (function_exists(topProImage)) 
				topProImage($connection, $finalTweet, $id);				
			}
		}
		
		// Generates the tweet date range based on the user input.
		public function getTweetPostDateRange()
		{
			$minAgeLimit = "-" . get_option('top_opt_age_limit') . " days";
			if (get_option('top_opt_max_age_limit')==0) $maxAgeLimit = "- 9999 days";
			else
			$maxAgeLimit = "-" . get_option('top_opt_max_age_limit') . " days";

			$minAgeLimit = date("Y-m-d H:i:s", strtotime($minAgeLimit));
			$maxAgeLimit = date("Y-m-d H:i:s", strtotime($maxAgeLimit));
	
			if(isset($minAgeLimit) || isset($maxAgeLimit)) {

				$dateQuery = array();

				if(isset($minAgeLimit)) {
					$dateQuery['before'] = $maxAgeLimit;
				}

				if(isset($maxAgeLimit)) {
					$dateQuery['after'] = $minAgeLimit;
				}

				$dateQuery['inclusive'] = true;

			}

			if(!empty($dateQuery)) {
				return $dateQuery;
			}

		}

		// Gets the tweet categories.
/*		public function getTweetCategories()
		{
			$postQueryCategories = "";
			$postsCategories = get_option('top_opt_tweet_specific_category');

			if(!empty($postCategories)) {
				$lastPostCategory = end($postsCategories);
				foreach ($postsCategories as $key => $cat) {
					if($cat == $lastPostCategory) {
						$postQueryCategories .= $cat;
					} else { 
						$postQueryCategories .= $cat . ", ";
					}
				}
			}

			return $postQueryCategories;
		}*/

		// Gets the omited tweet categories
		
		public function getExcludedCategories()
		{
			$postQueryCategories = "";
			$postCategories = get_option('top_opt_omit_cats');

			if(!empty($postCategories) && is_array($postCategories)) {
				$lastPostCategory = end($postCategories);
				foreach ($postCategories as $key => $cat) {
					if($cat == $lastPostCategory) {
						$postQueryCategories .= $cat;
					} else { 
						$postQueryCategories .= $cat . ", ";
					}
				}
			}
			else
				$postQueryCategories = get_option('top_opt_omit_cats');

			return $postQueryCategories;
		}

		// Gets the tweet post type.
		public function getTweetPostType()
		{
			$top_opt_tweet_type = get_option('top_opt_post_type');

			switch ($top_opt_tweet_type) {
				case 'post':
					return "'post'";
					break;
				
				case 'page':
					return "'page'";
					break;

				case 'custom-post-type':
					return "'" . get_option('top_opt_post_type_value') . "'";
					break;

				case 'both':
					return "'post', 'page'";
					break;

				default:
					break;
			}

		}


		// Creates a custom Tweet Old Post schedule
		public function createCustomSchedule($schedules)
		{
			$schedules['cwp_top_schedule'] = array(
					'interval'	=> floatval($this->intervalSet) * 60 * 60,
					'display'	=> __("Custom Tweet User Interval", CWP_TEXTDOMAIN)
				);

			return $schedules;
		}

		// Clears the custom Tweet Old Post cron job.
		public function clearScheduledTweets()
		{
			wp_clear_scheduled_hook('cwp_top_tweet_cron');
		}

		// Deactivation hook
		public function deactivationHook()
		{
			delete_option('activation_hook_test_motherfucker');
			$this->clearScheduledTweets();
			$this->deleteAllOptions();
		}

		// Sets all authentication settings
		public function setAlloAuthSettings() 
		{
			global $cwp_top_settings;

			$this->consumer = $cwp_top_settings['oAuth_settings']['consumer_key'];
			$this->consumerSecret = $cwp_top_settings['oAuth_settings']['consumer_secret'];
			$this->oAuthCallback = CURRENTURL;

			$this->cwp_top_access_token = get_option('cwp_top_access_token');			
			$this->cwp_top_oauth_token = get_option('cwp_top_oauth_token');
			$this->cwp_top_oauth_token_secret = get_option('cwp_top_oauth_token_secret');

			$this->user_info = get_option('cwp_top_oauth_user_details');			
			$this->users = get_option('cwp_top_logged_in_users');

			$this->pluginStatus = get_option('cwp_topnew_active_status');
			$this->intervalSet = get_option('top_opt_interval');

			//update_option('cwp_top_logged_in_users', '');
		}

		// Checks if twitter returned any temporary credentials to log in the user.
		public function afterAddAccountCheck()
		{
			if(isset($_REQUEST['oauth_token'])) {
				if($_REQUEST['oauth_token'] == $this->cwp_top_oauth_token) {

					$twitter = new TwitterOAuth($this->consumer, $this->consumerSecret, $this->cwp_top_oauth_token, $this->cwp_top_oauth_token_secret );
					$access_token = $twitter->getAccessToken($_REQUEST['oauth_verifier']);
					$user_details = $twitter->get('account/verify_credentials');

					$newUser = array(
						'user_id'				=> $user_details->id,
						'oauth_token'			=> $access_token['oauth_token'],
						'oauth_token_secret'	=> $access_token['oauth_token_secret'],
						'oauth_user_details'	=> $user_details
					);

					$loggedInUsers = get_option('cwp_top_logged_in_users');
					if(empty($loggedInUsers)) { $loggedInUsers = array(); }


					if(in_array($newUser, $loggedInUsers)) {
						echo "You already added that user! no can do !";
					} else { 
						array_push($loggedInUsers, $newUser);
						update_option('cwp_top_logged_in_users', $loggedInUsers);
					}

					header("Location: " . SETTINGSURL);
					exit;
				}
			}
		}

		// Used to display the twitter login button
		public function displayTwitterLoginButton()
		{
			// display the twitter login button
			if($this->userIsLoggedIn()) {
				$this->setAlloAuthSettings();
				return true;
			} else {
				return false;
			}
		}

		// Adds new twitter account
		public function addNewTwitterAccount()
		{
			$this->oAuthCallback = $_POST['currentURL'];
			$twitter = new TwitterOAuth($this->consumer, $this->consumerSecret);
			$requestToken = $twitter->getRequestToken($this->oAuthCallback);

			update_option('cwp_top_oauth_token', $requestToken['oauth_token']);
			update_option('cwp_top_oauth_token_secret', $requestToken['oauth_token_secret']);



			switch ($twitter->http_code) {
				case 200:
					$url = $twitter->getAuthorizeURL($requestToken['oauth_token']);
					echo $url;
					break;
				
				default:
					return __("Could not connect to Twitter!", CWP_TEXTDOMAIN);
					break;
			}
			die(); // Required
		}

				// Adds new twitter account
		public function addNewTwitterAccountPro()
		{
			if (function_exists('topProAddNewAccount')) {
				topProAddNewAccount();
			}
			else{
				_e("You need to <a target='_blank' href='http://themeisle.com/plugins/tweet-old-post-pro/?utm_source=topplusacc&utm_medium=announce&utm_campaign=top&upgrade=true'>upgrade to the PRO version</a> in order to add more accounts, fellow pirate!", CWP_TEXTDOMAIN);

			}
			die(); // Required
		}

		// Gets the next tweet interval.
		public function getNextTweetInterval()
		{
			$timestamp = wp_next_scheduled( 'cwp_top_tweet_cron' );
			//echo $timestamp;
			$timestamp = date("Y-m-d H:i:s", $timestamp);
			$timeLeft = get_date_from_gmt($timestamp);
			$timeLeft = strtotime($timeLeft);
			echo $timeLeft;
		}

		// Checks if the user is logged in/
		public function userIsLoggedIn()
		{
			if(!empty($this->cwp_top_oauth_token) && !empty($this->cwp_top_oauth_token_secret)) {
				return true;
			} else { 
				return false;
			}
		}

		// Clears all Twitter user credentials.
		public function logOutTwitterUser()
		{
			$userID = $_POST['user_id'];

			$users = get_option('cwp_top_logged_in_users');

			foreach ($users as $id => $user) {
				foreach ($user as $key => $value) {
					if($userID == $value) {
						$user_id = array_search($user, $users);
						unset($users[$user_id]);
					}
				}
			}

			update_option('cwp_top_logged_in_users', $users);

			$this->setAlloAuthSettings();
			die();
		}

		// Updates all options.
		public function updateAllOptions()
		{
			$dataSent = $_POST['dataSent']['dataSent'];

			$options = array();
			parse_str($dataSent, $options);

			

			foreach ($options as $option => $newValue) {
				//$newValue = sanitize_text_field($newValue);
				update_option($option, $newValue);
			}

			if(!array_key_exists('top_opt_custom_url_option', $options)) {
				update_option('top_opt_custom_url_option', 'off');
			}

			if(!array_key_exists('top_opt_use_url_shortner', $options)) {
				update_option('top_opt_use_url_shortner', 'off');
			}

			if(!array_key_exists('top_opt_tweet_specific_category', $options)) {
				update_option('top_opt_tweet_specific_category', '');
			}

			if(!array_key_exists('top_opt_omit_cats', $options)) {
				update_option('top_opt_omit_cats', '');
			}

			//update_option("top_opt_already_tweeted_posts", array());

			die();
		}

		public function top_admin_notice() {
			global $current_user ;
		        $user_id = $current_user->ID;
		        /* Check that the user hasn't already clicked to ignore the message */
			if ( ! get_user_meta($user_id, 'top_ignore_notice') ) {
		        echo '<div class="error"><p>';
		        printf(__('If you just updated TOP plugin, please make sure you <a href="'.SETTINGSURL.'">re-authentificate</a> your twitter account and make sure that all the <a href="'.SETTINGSURL.'">settings</a> are correct. To be able to maintain it, we rewrote TOP from scratch and some changes were required. | <a href="'.SETTINGSURL.'&top_nag_ignore=0">Hide Notice</a>'));
		        echo "</p></div>";
			}
		}
		public function top_nag_ignore() {
			global $current_user;
		        $user_id = $current_user->ID;
		        /* If user clicks to ignore the notice, add that to their user meta */
		        if ( isset($_GET['top_nag_ignore']) && '0' == $_GET['top_nag_ignore'] ) {
		             add_user_meta($user_id, 'top_ignore_notice', 'true', true);
			}
		}

		public function resetAllOptions()
		{
			update_option('activation_hook_test_motherfucker', "Well, the plugin was activated!");

			$defaultOptions = array(
				'top_opt_tweet_type'				=> 'title',
				'top_opt_post_with_image'			=> 'off',
				'top_opt_bitly_user'				=>'',
				'top_opt_bitly_key'					=>'',
				'top_opt_tweet_type_custom_field'	=> '',
				'top_opt_add_text'					=> '',
				'top_opt_add_text_at'				=> 'beginning',
				'top_opt_include_link'				=> 'false',
				'top_opt_custom_url_option'			=> 'off',
				'top_opt_use_url_shortner'			=> 'off',
				'top_opt_url_shortner'				=> 'is.gd',
				'top_opt_custom_hashtag_option'		=> 'nohashtag',
				'top_opt_hashtags'			=> '',
				'top_opt_hashtag_length'			=> '0',
				'top_opt_custom_hashtag_field'		=> '',
				'top_opt_interval'					=> '4',
				'top_opt_age_limit'					=> '30',
				'top_opt_max_age_limit'				=> '0',
				'top_opt_no_of_tweet'				=> '1',
				'top_opt_post_type'					=> 'post',
				'top_opt_post_type_value'			=> 'post',
				'top_opt_custom_url_field'			=> '',
				'top_opt_tweet_specific_category'	=> '',
				'top_opt_omit_cats'					=> '',
				'cwp_topnew_active_status'			=> 'false'
			);

			foreach ($defaultOptions as $option => $defaultValue) {
				update_option($option, $defaultValue);
			}
			//die();
		}

		public function deleteAllOptions()
		{
			global $defaultOptions;
			foreach ($defaultOptions as $option => $defaultValue) {
				delete_option($option);
			}
		}

		// Generate all fields based on settings
		public static function generateFieldType($field)
		{	
			$disabled = "";
			$pro = "";
			switch ($field['type']) {

				case 'text':
					print "<input type='text' placeholder='".$field['description']."' value='".$field['option_value']."' name='".$field['option']."' id='".$field['option']."'>";
					break;
			
				case 'select':
					$noFieldOptions = intval(count($field['options']));
					$fieldOptions = array_keys($field['options']);
					
					if ($field['option']=='top_opt_post_type') $disabled = "disabled";
					print "<select id='".$field['option']."' name='".$field['option']."'".$disabled.">";
						for ($i=0; $i < $noFieldOptions; $i++) { 
							print "<option value=".$fieldOptions[$i];
							if($field['option_value'] == $fieldOptions[$i]) { echo " selected='selected'"; }
							print ">".$field['options'][$fieldOptions[$i]]."</option>";
						}
					print "</select>";
					break;

				case 'checkbox':
					if ($field['option']=='top_opt_post_with_image'&& !function_exists(topProImage)) {
						$disabled = "disabled='disabled'";
						$pro = "This is only available in the PRO option";
					}
					print "<input id='".$field['option']."' type='checkbox' ".$disabled." name='".$field['option']."'";
					if($field['option_value'] == 'on') { echo "checked=checked"; }
					print " />".$pro;
					break;

				case 'custom-post-type':
					print "<select id='".$field['option']."' name='".$field['option']."' >";
						$post_types = get_post_types(array('_builtin' => false));
						foreach ($post_types as $post_type) {
							print "<option value='".$post_type."'";
							if($field['option_value'] == $post_type) { print "selected=selected"; }
							print ">" . $post_type . "</option>";
						}
					print "</select>";
					break;

				case 'categories-list':
					print "<div class='categories-list'>";
						$categories = get_categories();

						foreach ($categories as $category) {

							$top_opt_tweet_specific_category = get_option('top_opt_tweet_specific_category');

							if (!is_array(get_option('top_opt_omit_cats')))
								$top_opt_omit_specific_cats = explode(',',get_option('top_opt_omit_cats'));
							else
								$top_opt_omit_specific_cats = get_option('top_opt_omit_cats');

						print "<div class='cwp-cat'>";
								print "<input type='checkbox' name='".$field['option']."[]' value='".$category->cat_ID."' id='".$field['option']."_cat_".$category->cat_ID."'";
						/*	
								if($field['option'] == 'top_opt_tweet_specific_category' ) {
									if(is_array($top_opt_tweet_specific_category)) {
										if(in_array($category->cat_ID, $top_opt_tweet_specific_category)) {
											print "checked=checked";
										}
									}
								}
						*/
								if($field['option'] == 'top_opt_omit_cats') {
									if(is_array($top_opt_omit_specific_cats)) {
										if(in_array($category->cat_ID, $top_opt_omit_specific_cats)) {
											print "checked=checked";
										}
									}					
								}


								print ">";
								print "<label for='".$field['option']."_cat_".$category->cat_ID."'>".$category->name."</label>";							
							print "</div>";
						}
					print "</div>";
					break;

			}

		}

		public function top_plugin_action_links($links, $file) {
		    static $this_plugin;

		    if (!$this_plugin) {
		        $this_plugin = plugin_basename(__FILE__);
		    }

		    if ($file == $this_plugin) {
		        // The "page" query string value must be equal to the slug
		        // of the Settings admin page we defined earlier, which in
		        // this case equals "myplugin-settings".
		        $settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=TweetOldPost">Settings</a>';
		        array_unshift($links, $settings_link);
		    }

		    return $links;
		}

		public function loadAllHooks() 
		{
			// loading all actions and filters
			add_action('admin_menu', array($this, 'addAdminMenuPage'));
			add_action('admin_enqueue_scripts', array($this, 'loadAllScriptsAndStyles'));

			// Update all options ajax action.
			add_action('wp_ajax_nopriv_update_response', array($this, 'updateAllOptions'));
			add_action('wp_ajax_update_response', array($this, 'updateAllOptions'));

			// Reset all options ajax action.
			add_action('wp_ajax_nopriv_reset_options', array($this, 'resetAllOptions'));
			add_action('wp_ajax_reset_options', array($this, 'resetAllOptions'));

			// Add new twitter account ajax action
			add_action('wp_ajax_nopriv_add_new_twitter_account', array($this, 'addNewTwitterAccount'));
			add_action('wp_ajax_add_new_twitter_account', array($this, 'addNewTwitterAccount'));

			// Add more than one twitter account ajax action
			add_action('wp_ajax_nopriv_add_new_twitter_account_pro', array($this, 'addNewTwitterAccountPro'));
			add_action('wp_ajax_add_new_twitter_account_pro', array($this, 'addNewTwitterAccountPro'));

			// Log Out Twitter user ajax action
			add_action('wp_ajax_nopriv_log_out_twitter_user', array($this, 'logOutTwitterUser'));
			add_action('wp_ajax_log_out_twitter_user', array($this, 'logOutTwitterUser'));

			// Tweet Old Post ajax action.
			add_action('wp_ajax_nopriv_tweet_old_post_action', array($this, 'startTweetOldPost'));
			add_action('wp_ajax_tweet_old_post_action', array($this, 'startTweetOldPost'));

			// Tweet Old Post view sample tweet action.
			add_action('wp_ajax_nopriv_view_sample_tweet_action', array($this, 'viewSampleTweet'));
			add_action('wp_ajax_view_sample_tweet_action', array($this, 'viewSampleTweet'));

			// Tweet Old Post ajax action
			add_action('wp_ajax_nopriv_stop_tweet_old_post', array($this, 'stopTweetOldPost'));
			add_action('wp_ajax_stop_tweet_old_post', array($this, 'stopTweetOldPost'));

			//Tweet Old Post initial notice

			add_action('admin_notices', array($this,'top_admin_notice'));

			add_action('admin_init', array($this,'top_nag_ignore'));

			//Settings link

			add_filter('plugin_action_links', array($this,'top_plugin_action_links'), 10, 2);



			// Filter to add new custom schedule based on user input
			add_filter('cron_schedules', array($this, 'createCustomSchedule'));

			add_action('cwp_top_tweet_cron', array($this, 'tweetOldPost'));

		}

		public function loadAllScriptsAndStyles()
		{
			global $cwp_top_settings; // Global Tweet Old Post Settings

			// Enqueue and register all scripts on plugin's page
			if(isset($_GET['page'])) {
				if ($_GET['page'] == $cwp_top_settings['slug']) {

					// Enqueue and Register Main CSS File
					wp_register_style( 'cwp_top_stylesheet', CSSFILE, false, '1.0.0' );
			        wp_enqueue_style( 'cwp_top_stylesheet' );

			        // Register Main JS File
			        wp_enqueue_script( 'cwp_top_js_countdown', JSCOUNTDOWN, array(), '1.0.0', true );
			        wp_enqueue_script( 'cwp_top_javascript', JSFILE, array(), '1.0.0', true );
			        wp_localize_script( 'cwp_top_javascript', 'cwp_top_ajaxload', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
				 }				
			}
 	
		}

		public function addAdminMenuPage()
		{
			global $cwp_top_settings; // Global Tweet Old Post Settings
			add_menu_page($cwp_top_settings['name'], $cwp_top_settings['name'], "edit_dashboard", $cwp_top_settings['slug'], array($this, 'loadMainView'));
		}

		public function loadMainView()
		{
			global $cwp_top_fields;
			foreach ($cwp_top_fields as $field => $value) {
				$cwp_top_fields[$field]['option_value'] = get_option($cwp_top_fields[$field]['option']); 
			}
			require_once("view.php");
		}

		// Sends a request to the passed URL
		public function sendRequest($url, $method='GET', $data='', $auth_user='', $auth_pass='') {

		    $ch = curl_init($url);

		    if (strtoupper($method) == "POST") {
		        curl_setopt($ch, CURLOPT_POST, 1);
		        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		    }

		    if (ini_get('open_basedir') == '' && ini_get('safe_mode') == 'Off') {
		        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		    }

		    curl_setopt($ch, CURLOPT_HEADER, 0);
		    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

		    if ($auth_user != '' && $auth_pass != '') {
		        curl_setopt($ch, CURLOPT_USERPWD, "{$auth_user}:{$auth_pass}");
		    }

		    $response = curl_exec($ch);
		    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		    curl_close($ch);

		    if ($httpcode != 200) {
		        return $httpcode;
		    }

		    return $response;
		}

		// Shortens the url.
		public function shortenURL($url, $service, $id, $bitly_key, $bitly_user) {
			
			if ($service == "bit.ly") {
				//$shortURL = $url;
				$url = trim($url);
				$bitly_key = trim($bitly_key);
				$bitly_user = trim($bitly_user);
		        $shortURL = "http://api.bit.ly/v3/shorten?format=txt&login=".$bitly_user."&apiKey=".$bitly_key."&longUrl={$url}";
		        $shortURL = $this->sendRequest($shortURL, 'GET');
		    } elseif ($service == "tr.im") {
		        $shortURL = "http://api.tr.im/api/trim_simple?url={$url}";
		        $shortURL = $this->sendRequest($shortURL, 'GET');
		    } elseif ($service == "3.ly") {
		        $shortURL = "http://3.ly/?api=em5893833&u={$url}";
		        $shortURL = $this->sendRequest($shortURL, 'GET');
		    } elseif ($service == "tinyurl") {
		        $shortURL = "http://tinyurl.com/api-create.php?url=" . $url;
		        $shortURL = $this->sendRequest($shortURL, 'GET');
		    } elseif ($service == "u.nu") {
		        $shortURL = "http://u.nu/unu-api-simple?url={$url}";
		        $shortURL = $this->sendRequest($shortURL, 'GET');
		    } elseif ($service == "1click.at") {
		        $shortURL = "http://1click.at/api.php?action=shorturl&url={$url}&format=simple";
		        $shortURL = $this->sendRequest($shortURL, 'GET');
		    } elseif ($service == "is.gd") {
		        $shortURL = "http://is.gd/api.php?longurl={$url}";
		        $shortURL = $this->sendRequest($shortURL, 'GET');
		    } elseif ($service == "t.co") {
		        $shortURL = "http://twitter.com/share?url={$url}";
		        $shortURL = $this->sendRequest($shortURL, 'GET');
		    } else {
		    	$shortURL = wp_get_shortlink($id);
		    }

		    if($shortURL != ' 400 ') {
		    	return $shortURL;
		    }
		}

	}
}

if(class_exists('CWP_TOP_Core')) {
	$CWP_TOP_Core = new CWP_TOP_Core;
}