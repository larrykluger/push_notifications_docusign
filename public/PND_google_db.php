<?php
# Uses the Google DataStore NoSQL db
#
# 
# The notify url records the information needed to send a notification.
# Since different browsers are using different information, we're encoding the
# info in the notify_url
# 
# Google notification format:  google_notify://notification_service_point:id
#
#
# DB schema
#   cookie_notify_id -- a unique id for an instance of a browser. Stored on the
#       browser as cookie "cookie_notify_id"
#   notify_url -- how to notify the browser instance with a specific cookie_notify_id
#   ds_account -- A DocuSign account number whose member wants to receive notifications
#   ds_email -- A DocuSign email for someone in the ds_account who wants notifications
#
#  Discussion: A specific DS email can work with more than one account. We want notifications
#  for all accounts for a person, so we have an additional row for each account.
#
#  Also, a given person can receive notifications at different browsers. Eg, their 
#  desktop browser and their mobile phone. So we also have additional rows for
#  for each of the person's browsers.
#
#  We are not allowing more than one person to send notifications to a given browser.
#  -- the browser should be private to a person to receive notifications.

	# Definitions for cookies
	define ("COOKIE_NOTIFY", "PushNotifyDocuSign"); # yes or no
	define ("COOKIE_NOTIFY_ID", "PushNotifyDocuSignID"); # unique id
	
class PND_google_db {

	# private variables
	private $gds_client = NULL;
	private $gds_gateway = NULL;
	private $notify_db = NULL;
	private $cookie_notify = NULL;
	private $cookie_notify_id = NULL;
	private $cookie_notify_id_created = NULL; # did we newly create the cookie?
	
	function __construct() {
		// We'll need a Google_Client, use our convenience method
		$this->gds_client = GDS\Gateway::createGoogleClient(GDS_APP_NAME, GDS_SERVICE_ACCOUNT_NAME, GDS_KEY_FILE_PATH);

		// Gateway requires a Google_Client and Dataset ID
		$this->gds_gateway = new GDS\Gateway($this->gds_client, GDS_DATASET_ID);

		$this->notify_db = new NotifyDB($this->gds_gateway);
		setup_id_cookie();
	}

	private function setup_id_cookie() {
		global $pnd_config;
		if (array_key_exists ( COOKIE_NOTIFY_ID , $_COOKIE ) && strlen($_COOKIE[COOKIE_NOTIFY_ID]) > 5) {
			$this->cookie_notify_id = $_COOKIE[COOKIE_NOTIFY_ID];
			$this->cookie_notify_id_created = false;
			return;
		}
		# No cookie: create and set the id cookie
		$this->cookie_notify_id = md5(uniqid($pnd_config['cookie_salt'], true)); # see http://stackoverflow.com/a/1293860/64904
		setcookie(COOKIE_NOTIFY_ID, $this->cookie_notify_id, time()+60*60*24*365); # 1 year
		$this->cookie_notify_id_created = true;
	} 
	private function cookie_on() {
		# Are the cookies telling us that notification is on?
		return (!$this->cookie_notify_id_created && array_key_exists ( COOKIE_NOTIFY, $_COOKIE ) && $_COOKIE[COOKIE_NOTIFY] === 'yes');
	}
	private function set_cookie_notify($on) {
		# sets the cookie to be 'yes' or 'no'
		setcookie(COOKIE_NOTIFY, $on ? 'yes' : 'no', time()+60*60*24*365); # 1 year
	}
	
	public function refresh ($notify_url) {
		# Whenever the page is loaded, if the service worker is already installed,
		# we need to update our db since the notification url may have changed. 
		#
		$notifications = 
			$notify_db->fetchAll("SELECT * FROM Notifications WHERE cookie_notify_id = @id",
			['id' => $this->cookie_notify_id]);

		if (cookie_on()) {
			# If cookie_notify is on and the cookie_notify_id is present,
			# then update the db entries with the new notify_url
			foreach($notifications as $notification) {
				$notification->notify_url = $this->notify_url;
				$this->notify_db->upsert($notification); # see https://github.com/tomwalder/php-gds/blob/master/src/GDS/Store.php
			}
			set_cookie_notify(true);
		} else {
			# If cookie_notify is off or missing, then remove any db entries that
			# use this notify_url
			foreach($notifications as $notification) {
				$this->notify_db->delete($notification); # see https://github.com/tomwalder/php-gds/blob/master/src/GDS/Store.php
			}
			set_cookie_notify(false);
		}		
	}
	
	public function subscribe ($notify_url) {
	}
	
	public function test() {
		$notification = $this->notify_db->createEntity([
			'notify_url' => 'url',
			'cookie_notify_id' => 'cookie id',
			'ds_account_id' => 'account id',
			'ds_account_name' => 'account name',
			'ds_email' => 'email@foo.com',
			'ds_user_name' => 'Joe User',
			'ds_user_id' => 'user id'
		]);
		$bol_result1 = $this->notify_db->upsert($notification);
		echo "Store result: ";
		var_dump($bol_result1);
	
		// Fetch all (client 1)
		$notifications = $this->notify_db->fetchAll("SELECT * FROM Notifications");
		echo "Query found ", count($notifications), " records", PHP_EOL;
		foreach($notifications as $notification) {
			echo "   Notify url: {$notification->notify_url}, email: {$notification->ds_email}", PHP_EOL;
		}
	}
	
	
	public function notifications () {
		# Get the notifications for this id
		$_notifications = 
			$this->notify_db->fetchAll("SELECT * FROM Notifications WHERE cookie_notify_id = @id",
			['id' => $this->cookie_notify_id]);
		
		$results = array();
		foreach($_notifications as $notification) {
			$results[] = array(
				'notify_url' => $notification->notify_url,
				'cookie_notify_id' => $notification->cookie_notify_id,
				'ds_account_id' => $notification->ds_account_id,
				'ds_account_name' => $notification->ds_account_name,
				'ds_email' => $notification->ds_email,
				'ds_user_name' => $notification->ds_user_name,
				'ds_user_id' => $notification->ds_user_id
			);
		}
		return ($results);
	}
}

class Notify extends GDS\Entity {}
class NotifyDB extends GDS\Store {
    /**
     * Build and return a Schema object describing the data model
     *
     * @return \GDS\Schema
     */
    protected function buildSchema()
    {
        $this->setEntityClass('\\Notifications');
        return (new GDS\Schema('Notifications'))
            ->addString('cookie_notify_id')
            ->addString('notify_url')
            ->addString('ds_account_id')
            ->addString('ds_account_name')
			->addString('ds_email')
			->addString('ds_user_name')
			->addString('ds_user_id');
    }
}
	
	
	
	
	
	

