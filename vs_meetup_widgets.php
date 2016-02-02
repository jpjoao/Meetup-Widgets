<?php 
/**
 * VsMeetWidget
 * All widget info for VsMeet (including widgets themselves)
 */

class VsMeetWidget extends VsMeet{
	private $req_url = 'http://api.meetup.com/oauth/request/';
	private $authurl = 'http://www.meetup.com/authorize/';
	private $acc_url = 'http://api.meetup.com/oauth/access/';
	private $api_url = 'http://api.meetup.com/';
	private $callback_url = '';
	private $base_url = 'http://api.meetup.com/2/events/';
		
	private $key = '';
	private $secret = '';	
	protected $api_key = "";
	
	public function __construct() {
		$options = get_option('vs_meet_options');
		$this->key = $options['vs_meetup_key'];
		$this->secret = $options['vs_meetup_secret'];
		$this->api_key = $options['vs_meetup_api_key'];
		$this->callback_url = admin_url( 'admin-ajax.php' ) .'?action=meetup_event';
		
		parent::__construct();
		
		// add login function to ajax requests
		add_action( 'wp_ajax_nopriv_meetup_event', array($this, 'meetup_event_popup') );
		add_action( 'wp_ajax_meetup_event', array($this, 'meetup_event_popup') );
	}
	
	/**
	 * Given arguments & a transient name, grab data from the events API
	 * 
	 * @param  $args       array   Query params to send to events.json call
	 * @param  $transient  string  The transient name (if empty, no transient stored)
	 * 
	 * @return  array  Event data (single event or list)
	 */
	public function get_data( $args, $transient = '' ){
		if ( $transient )
			$event = get_transient( $transient );

		$defaults = array(
			'key' => $this->api_key,
		);

		if ( false === $event ) {
			$args = wp_parse_args( $args, $defaults );
			$url = add_query_arg( $args, $this->base_url );
			$event_response = wp_remote_get( $url );
			if( is_wp_error( $event_response )) {
				if ( WP_DEBUG ){
					echo 'Something went wrong!';
					var_dump($event_response);
				}
				return false;
			}
			$event = json_decode( $event_response['body'] );
			// Single events only return first result
			if ( ! isset( $event->results ) || ! isset( $event->results[0] ) )
				return false;

			if ( isset( $args['event_id'] ) )
				$event = $event->results[0];
			else
				$event = $event->results;

			if ( $transient )
				set_transient( $transient, $event, 60*60*2 );
		}
		
		return $event;
	}

	/**
	 * Get a single event, with a link to RSVP (OAuth, new tiny window).
	 * 
	 * @param string $id            Event ID
     * @param string  $date_format  Format to display the event date
	 * 
	 * @return string Event details formatted for display in widget
	 */
	public function get_single_event( $id, $date_format = 'F d, Y @ g:i a' ){
		global $event;
		$options = get_option( 'vs_meet_options' );
		$this->api_key = $options['vs_meetup_api_key'];
		$out = '';

		if ( ! empty( $this->api_key ) ) {
			$event = $this->get_data( array( 'event_id' => $id ), 'vsm_single_event_'.$id );
			if ( ! $event )
				return;

			ob_start();

			// We want the callback URL in the template, passing it in via the global $event is easiest.
			$event->callback_url = $this->callback_url;
			$template = '';
			if ( isset( $event->group ) && isset( $event->group->urlname ) ) 
				$template = $event->group->urlname;
            set_query_var('date_format', $date_format);
			get_template_part( 'meetup-single', apply_filters( 'vsm_single_template', $template, $event ) );
			$out = ob_get_contents();

			if ( empty( $out ) ) {
				// grab the template included in plugin
				if ( file_exists( dirname(__FILE__).'/meetup-single.php' ) )
					load_template( dirname(__FILE__).'/meetup-single.php', false ); 
				$out = ob_get_contents();
			}

			ob_end_clean();

		} else {
			if ( is_user_logged_in() )
				$out = '<p><a href="'.admin_url('options-general.php').'">Please enter an API key</a></p>';
		}
		return $out;
	}
	
	/**
	 * Get the HTML for a group's events via Meetup API
	 * 
	 * @param string  $id           Meetup ID or URL name
	 * @param int     $limit        Number of events to display, default 5.
     * @param int     $hide_first   Display or not the first item in the list
     * @param string  $date_format  Format to display the event date
	 * 
 	 * @return string Event list formatted for display in widget
	 */
	public function get_group_events( $id, $limit = 5, $hide_first = 0, $date_format = 'M d, g:ia' ){
		global $events;
		$options = get_option('vs_meet_options');
		$this->api_key = $options['vs_meetup_api_key'];

        if ($hide_first)
            ++$limit;

		if ( ! empty( $this->api_key ) ) {
			$args = array(
				'status' => 'upcoming',
				'page' => $limit,
			);
			if ( preg_match('/[a-zA-Z]/', $id ) )
				$args['group_urlname'] = $id;
			else
				$args['group_id'] = $id;
				
			$events = $this->get_data( $args, 'vsm_group_events_'.$id.'_'.$limit );
			if ( ! $events )
				return;
			
			ob_start();
            set_query_var('date_format', $date_format);
            set_query_var('hide_first', $hide_first);
			get_template_part( 'meetup-list', 'group' );
			$out = ob_get_contents();

			if ( empty( $out ) ) {
				// grab the template included in plugin
				if ( file_exists( dirname(__FILE__).'/meetup-list.php' ) )
					load_template( dirname(__FILE__).'/meetup-list.php', false ); 
				$out = ob_get_contents();
			}

			ob_end_clean();

		} else {
			if ( is_user_logged_in() )
				$out = '<p><a href="'.admin_url('options-general.php').'">Please enter an API key</a></p>';
		}
		return $out;
	}
	// Function name was changed in 2.1, leave this for backwards compatibilty
	function get_list_events( $id, $limit = 5, $deprecated = '' ){
		$this->get_group_events( $id, $limit );
	}
	
	/**
	 * Get user's list of events
	 * 
	 * @param string  $id     User ID
	 * @param string  $limit  Number of events to display, default 5.
	 * @param string  $rsvp   Only return events with this RSVP status (can only be set to 'yes' in UI)
	 * 
	 * @return string Event list formatted for display in widget
	 */
	public function get_user_events($limit = 5 ){
		global $events;
		$options = get_option('vs_meet_options');
		$this->api_key = $options['vs_meetup_api_key'];

		if ( ! empty( $this->api_key ) ) {
			$args = array(
				'rsvp' => 'yes',
				'page' => $limit,
			);
			
			$events = $this->get_data( $args, 'vsm_user_events_'.$limit );
			if ( ! $events )
				return;
			
			ob_start();
			get_template_part( 'meetup-list', 'group' );
			$out = ob_get_contents();
	
			if ( empty( $out ) ) {
				// grab the template included in plugin
				if ( file_exists( dirname(__FILE__).'/meetup-list.php' ) )
					load_template( dirname(__FILE__).'/meetup-list.php', false ); 
				$out = ob_get_contents();
			}
	
			ob_end_clean();

		} else {
			$out = '<p><a href="'.admin_url('options-general.php').'">Please enter an API key</a></p>';
		}
		return $out;
	}

    /**
     * Get the HTML for a the next group's events via Meetup API
     *
     * @param string  $id           Meetup ID or URL name
     * @param string  $date_format  Format to display the event date
     *
     * @return string Event item formatted for display in widget
     */
    public function get_group_next_event( $id, $date_format = 'F d, Y @ g:i a' ){
        global $event;
        $options = get_option('vs_meet_options');
        $this->api_key = $options['vs_meetup_api_key'];

        if ( ! empty( $this->api_key ) ) {
            $args = array(
                'status' => 'upcoming',
                'page' => 1,
            );
            if ( preg_match('/[a-zA-Z]/', $id ) )
                $args['group_urlname'] = $id;
            else
                $args['group_id'] = $id;

            $events = $this->get_data( $args, 'vsm_group_next_event_'.$id.'_1' );
            if ( ! $events )
                return;

            $event = array_pop($events);

            ob_start();
            $template = '';
            if ( isset( $event->group ) && isset( $event->group->urlname ) )
                $template = $event->group->urlname;
            set_query_var('date_format', $date_format);
            get_template_part( 'meetup-single', apply_filters( 'vsm_single_template', $template, $event ) );
            $out = ob_get_contents();

            if ( empty( $out ) ) {
                // grab the template included in plugin
                if ( file_exists( dirname(__FILE__).'/meetup-single.php' ) )
                    load_template( dirname(__FILE__).'/meetup-single.php', false );
                $out = ob_get_contents();
            }

            ob_end_clean();

        } else {
            if ( is_user_logged_in() )
                $out = '<p><a href="'.admin_url('options-general.php').'">Please enter an API key</a></p>';
        }
        return $out;
    }


    /**
     * Get the HTML for groups events via Meetup API
     *
     * @param string  $ids               Meetup ID or URL name
     * @param int     $limit            Number of events to display, default 5.
     * @param bool     $highlight_first  Highlight or not the first item in the list
     * @param string     $highlight_groups  Highlight or not the first item in the list
     * @param string  $date_format      Format to display the event date
     *
     * @return string Event list formatted for display in widget
     */
    public function get_groups_events( $ids, $limit = 10, $highlight_first = true, $highlight_groups = '', $date_format = 'd/m/Y @ g:i a' ){
        global $events;

        $events = array();

        $options = get_option('vs_meet_options');
        $this->api_key = $options['vs_meetup_api_key'];

        if ( ! empty( $this->api_key ) ) {
            $args = array(
                'status' => 'upcoming',
                'page' => $limit,
            );

            if (strpos($ids, ',') !== false)
            {
                $ids = explode(',', $ids);
            }
            else
            {
                $ids = array($ids);
            }

            foreach ($ids as $id)
            {
                if ( preg_match('/[a-zA-Z]/', $id ) )
                    $args['group_urlname'] = $id;
                else
                    $args['group_id'] = $id;

                $group_events = $this->get_data( $args, 'vsm_group_events_'.$id.'_'.$limit );
                if ( ! $group_events )
                {
                    continue;
                }

                $events = array_merge($events, $group_events);
            }

            if ( empty($events) )
                return;

            //order the events by date
            usort($events, function ($a, $b) {
                if ($a->time == $b->time) return 0;
                return $a->time < $b->time ? -1 : 1;
            });

            if (strpos($highlight_groups, ',') !== false)
            {
                $highlight_groups = explode(',', $highlight_groups);
            }
            else
            {
                $highlight_groups = array($highlight_groups);
            }

            ob_start();
            set_query_var('date_format', $date_format);
            set_query_var('highlight_first', $highlight_first);
            set_query_var('limit', $limit);
            set_query_var('highlight_groups', $highlight_groups);
            get_template_part( 'meetup-list', 'group' );
            $out = ob_get_contents();

            if ( empty( $out ) ) {
                // grab the template included in plugin
                if ( file_exists( dirname(__FILE__).'/meetup-groups-list.php' ) )
                    load_template( dirname(__FILE__).'/meetup-groups-list.php', false );
                $out = ob_get_contents();
            }

            ob_end_clean();

        } else {
            if ( is_user_logged_in() )
                $out = '<p><a href="'.admin_url('options-general.php').'">Please enter an API key</a></p>';
        }
        return $out;
    }

	/**
	 * Create the event RSVP popup
	 */
	function meetup_event_popup() {
		session_start();
		$header = '<html dir="ltr" lang="en-US">
			<head>
				<meta charset="UTF-8" />
				<meta name="viewport" content="width=device-width" />
				<title>RSVP to a Meetup</title>
				<link rel="stylesheet" type="text/css" media="all" href="'.get_bloginfo( 'stylesheet_url' ).'" />
				<style>
					.button {
						padding:3%;
						color:white;
						background-color:#B03C2D;
						border-radius:3px;
						display:block;
						font-weight:bold;
						width:40%;
						float:left;
						text-align:center;
					}
					.button.no {
						margin-left:8%;
					}
				</style>
			</head>
			<body>
				<div id="page" class="hfeed meetup event" style="padding:15px;">';
		if (array_key_exists('event',$_GET)) $_SESSION['event'] = $_GET['event'];
		if (!array_key_exists('state',$_SESSION)) $_SESSION['state'] = 0;
		// In state=1 the next request should include an oauth_token.
		// If it doesn't go back to 0
		if(!isset($_GET['oauth_token']) && $_SESSION['state']==1) $_SESSION['state'] = 0;
		try {
			$oauth = new OAuth($this->key, $this->secret, OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_AUTHORIZATION );
			$oauth->enableDebug();
			if(!isset($_GET['oauth_token']) && !$_SESSION['state']) {
				$request_token_info = $oauth->getRequestToken($this->req_url);
				$_SESSION['secret'] = $request_token_info['oauth_token_secret'];
				$_SESSION['state'] = 1;
				header('Location: '.$this->authurl.'?oauth_token='.$request_token_info['oauth_token'].'&oauth_callback='.$this->callback_url);
				exit;
			} else if($_SESSION['state']==1) {
				$oauth->setToken($_GET['oauth_token'],$_SESSION['secret']);
				$verifier = (array_key_exists('verifier',$_GET)) ? $_GET['verifier'] : null; 
				$access_token_info = $oauth->getAccessToken($this->acc_url,null,$verifier);
				$_SESSION['state'] = 2;
				$_SESSION['token'] = $access_token_info['oauth_token'];
				$_SESSION['secret'] = $access_token_info['oauth_token_secret'];
			} 
			$oauth->setToken($_SESSION['token'],$_SESSION['secret']);
			if (array_key_exists('rsvp',$_GET)) { // button has been pressed.
				//send the RSVP.
				if ('yes' == $_GET['rsvp'])
					$oauth->fetch("{$this->api_url}/rsvp", array('event_id'=>$_SESSION['event'], 'rsvp'=>'yes'), OAUTH_HTTP_METHOD_POST);	
				else
					$response = $oauth->fetch("{$this->api_url}/rsvp", array('event_id'=>$_SESSION['event'], 'rsvp'=>'no'), OAUTH_HTTP_METHOD_POST);	
				$rsvp = json_decode($oauth->getLastResponse());
				
				echo $header;
				echo '<h1 style="padding:20px 0 0;"><a>'.$rsvp->description.'</a></h1>';
				echo '<p>'.$rsvp->details.'.</p>';
				exit;
			} else {
				// Get event info to display here.
				$oauth->fetch("{$this->api_url}/2/events?event_id=".$_SESSION['event']);
				$event = json_decode($oauth->getLastResponse());
				$event = $event->results[0];
				$out  = '<h1 id="site-title" style="padding:20px 0 0;"><a target="_blank" href="'.$event->event_url.'">'.$event->name.'</a></h1>';
				$out .= '<p style="text-align:justify;">'.$event->description.'</p>';
				$out .= '<p><span class="rsvp-count">'.$event->yes_rsvp_count.' '._n('attendee', 'attendees', $event->yes_rsvp_count).'</span></p>';
				if (null !== $event->venue) {
					$venue = $event->venue->name.' '.$event->venue->address_1 . ', ' . $event->venue->city . ', ' . $event->venue->state;
					$out .= "<h3 class='event_location'>Location: <a href='http://maps.google.com/maps?q=$venue+%28".$event->venue->name."%29&z=17' target='_blank'>$venue</a></h3>";
				} else {
					$out .= "<p class='event_location'>Location: TBA</p>";
				}
				$out .= '<h2>'.date('F d, Y @ g:i a',intval($event->time/1000 + $event->utc_offset/1000)).'</h2>';
				
				echo $header . $out;
				$oauth->fetch("{$this->api_url}/rsvps?event_id=".$_SESSION['event']);
				$rsvps = json_decode($oauth->getLastResponse());
				$oauth->fetch("{$this->api_url}/members?relation=self");
				$me = json_decode($oauth->getLastResponse());
				$my_id = $me->results[0]->id;
				foreach ($rsvps->results as $user){
					if ($my_id == $user->member_id){
						echo "<h3 style='padding:20px 0 0; font-weight:normal; font-size:16px'>Your RSVP: <strong>{$user->response}</strong></h3>";
						echo "<p>You can change your RSVP below.</p>";
					}
				}
				
				echo "<h1 style='padding:20px 0 0; font-weight:bold; font-size:22px'>RSVP: </h1>";
				echo "<p style='font-size:.9em'>Please RSVP at meetup.com if you're bringing someone.</p>";
				echo "<a class='button yes' href='{$this->callback_url}&rsvp=yes'>Yes</a>";
				echo "<a class='button no' href='{$this->callback_url}&rsvp=no'>No</a>";
				echo "<p style='clear:both'></p>";
				//echo "<pre>".print_r($event,true)."</pre>";
				exit;  
			}
		} catch(OAuthException $E) {
			echo $header;
			echo "<h1 class='entry-title'>There was an error processing your request. Please try again.</h1>";
			if (WP_DEBUG) echo "<pre>".print_r($E,true)."</pre>";
		}
		unset($_SESSION['state']);
		unset($_SESSION['event']);
		echo "</div> </body> </html>";
	}

}


/**
 * VsMeetSingle extends the widget class to create a single-event widget with RSVP functionality.
 */
class VsMeetSingleWidget extends WP_Widget {
    /** constructor */
    function VsMeetSingleWidget() {
        parent::WP_Widget(false, $name = __('Meetup Single Event','vsmeet_domain'), array('description' => __("Display a single event.",'vsmeet_domain')));	
    }

    /** @see WP_Widget::widget */
    function widget($args, $instance) {		
        extract( $args );
        $title = apply_filters('widget_title', $instance['title']);
        $id = $instance['id'];
        $date_format = $instance['date_format'];
        echo $before_widget;
        if ( $title ) echo $before_title . $title . $after_title;
        if ( $id ) {
    		$vsm = new VsMeetWidget();
    		$html = $vsm->get_single_event($id, $date_format);
    		echo $html;
	    }
        echo $after_widget;
    }

    /** @see WP_Widget::update */
    function update($new_instance, $old_instance) {				
        $instance = $old_instance;
        $instance['title'] = strip_tags($new_instance['title']);
        $instance['id'] = strip_tags($new_instance['id']);
        $instance['date_format'] = $new_instance['date_format'];
        
        return $instance;
    }

    /** @see WP_Widget::form */
    function form($instance) {
        if ( $instance ) {
			$title = esc_attr($instance['title']);
			$id = esc_attr($instance['id']);
            $date_format = esc_attr($instance['date_format']);
        } else {
			$title = '';
			$id = '';
            $date_format = 'F d, Y @ g:i a';
        }
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">
            <?php _e('Title:','vsmeet_domain'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" />
            </label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('id'); ?>">
		    <?php _e('Event ID:','vsmeet_domain'); ?>
		        <input class="widefat" id="<?php echo $this->get_field_id('id'); ?>" name="<?php echo $this->get_field_name('id'); ?>" type="text" value="<?php echo $id; ?>" />
            </label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('date_format'); ?>">
            <?php _e('Date Format:','vsmeet_domain'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('date_format'); ?>" name="<?php echo $this->get_field_name('date_format'); ?>" type="text" value="<?php echo $date_format; ?>" />
            </label>
        </p>
    <?php }
} // class VsMeetSingleWidget


/**
 * VsMeetList extends the widget class to create an event list for a specific meetup group.
 */
class VsMeetListWidget extends WP_Widget {
    /** constructor */
    function VsMeetListWidget() {
        parent::WP_Widget(false, $name = __('Meetup List Event','vsmeet_domain'), array('description' => __("Display a list of events.",'vsmeet_domain')));
    }

    /** @see WP_Widget::widget */
    function widget($args, $instance) {
        extract( $args );
        $title = apply_filters('widget_title', $instance['title']);
        $id = $instance['id']; // meetup ID or URL name
        $limit = intval($instance['limit']);
        $hide_first = $instance['hide_first'];
        $date_format = $instance['date_format'];

        echo $before_widget;
        if ( $title ) echo $before_title . $title . $after_title;
        if ( $id ) {
    		$vsm = new VsMeetWidget();
    		$html = $vsm->get_group_events( $id, $limit, $hide_first, $date_format );
       		echo $html;
	    }
        echo $after_widget;
    }

    /** @see WP_Widget::update */
    function update($new_instance, $old_instance) {
        $instance = $old_instance;
        $instance['title'] = strip_tags($new_instance['title']);
        if ( preg_match('/[a-zA-Z]/', $new_instance['id'] ) )
	        $instance['id'] = sanitize_title( $new_instance['id'] );
	    else
	    	$instance['id'] = str_replace( ' ', '', $new_instance['id'] );
        $instance['limit'] = intval($new_instance['limit']);
        $instance['hide_first'] = $new_instance['hide_first'];
        $instance['date_format'] = $new_instance['date_format'];

        return $instance;
    }

    /** @see WP_Widget::form */
    function form($instance) {
        if ( $instance ) {
			$title = esc_attr($instance['title']);
			$id = esc_attr($instance['id']); // -> it's a name if it contains any a-zA-z, otherwise ID
			$limit = intval($instance['limit']);
            $hide_first = esc_attr($instance['hide_first']);
            $date_format = esc_attr($instance['date_format']);
        } else {
			$title = '';
			$id = '';
			$limit = 5;
            $hide_first = 0;
            $date_format = 'M d, g:ia';
        }
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">
            <?php _e('Title:','vsmeet_domain'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" />
            </label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('id'); ?>">
		    <?php _e('Group ID:','vsmeet_domain'); ?>
		        <input class="widefat" id="<?php echo $this->get_field_id('id'); ?>" name="<?php echo $this->get_field_name('id'); ?>" type="text" value="<?php echo $id; ?>" />
        </label>
        </p>
        <p>
        	<label for="<?php echo $this->get_field_id('limit'); ?>">
            <?php _e('Number of events to show:','vsmeet_domain');?>
                <input id="<?php echo $this->get_field_id('limit'); ?>" name="<?php echo $this->get_field_name('limit'); ?>" type="text" value="<?php echo $limit; ?>" size='3' />
            </label>
		</p>
        <p>
            <label for="<?php echo $this->get_field_id('hide_first'); ?>">
                <input type="checkbox" <?php echo checked($hide_first, 1, false ) ?> class="widefat" id="<?php echo $this->get_field_id('hide_first') ?>" name="<?php echo $this->get_field_name('hide_first') ?>" type="text" value="1" />
                <?php _e('Hide first item','vsmeet_domain'); ?>
            </label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('date_format'); ?>">
            <?php _e('Date Format:','vsmeet_domain'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('date_format'); ?>" name="<?php echo $this->get_field_name('date_format'); ?>" type="text" value="<?php echo $date_format; ?>" />
            </label>
        </p>
    <?php }
} // class VsMeetListWidget

/**
 * VsMeetUserList extends the widget class to create an event list for a specific meetup group.
 */
class VsMeetUserListWidget extends WP_Widget {
    /** constructor */
    function VsMeetUserListWidget() {
        parent::WP_Widget( false, __( 'Meetup User Events', 'vsmeet_domain' ), array( 'description' => __( "Display a list of events for a single user.", 'vsmeet_domain' ), 'classname' => 'widget_meetup_user_list' ) );	
    }

    /** @see WP_Widget::widget */
    function widget($args, $instance) {		
        extract( $args );
        $title = apply_filters('widget_title', $instance['title']);
        $limit = absint($instance['limit']);
        
        echo $before_widget;
        if ( $title ) echo $before_title . $title . $after_title;
		$vsm = new VsMeetWidget();
		$html = $vsm->get_user_events( $limit );
   		echo $html;
        echo $after_widget;
    }

    /** @see WP_Widget::update */
    function update($new_instance, $old_instance) {				
        $instance = $old_instance;
        $instance['title'] = strip_tags($new_instance['title']);
        $instance['limit'] = absint($new_instance['limit']);
        
        // remove caching of old event
        if (!empty($old_instance['id']))
            delete_transient( 'vsmeet_user_events_'.$old_instance['id'] );
        
        return $instance;
    }

    /** @see WP_Widget::form */
    function form($instance) {
        if ( $instance ) {
			$title = esc_attr($instance['title']);
			$limit = absint($instance['limit']);
        } else {
			$title = '';
			$limit = 5;
        }
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">
            <?php _e('Title:','vsmeet_domain'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" />
            </label>
        </p>
        <p>
        	<label for="<?php echo $this->get_field_id('limit'); ?>">
           	<?php _e('Number of events to show:','vsmeet_domain');?>
                <input id="<?php echo $this->get_field_id('limit'); ?>" name="<?php echo $this->get_field_name('limit'); ?>" type="text" value="<?php echo $limit; ?>" size='3' />
            </label>
        </p>
        <p class="description">This widget automatically pulls events from the user who created the API key.</p>
    <?php }
} // class VsMeetUserListWidget


/**
 * VsMeet extends the widget class to display the next event for a specific meetup group.
 */
class VsMeetNextSingleWidget extends WP_Widget {
    /** constructor */
    function VsMeetNextSingleWidget() {
        parent::WP_Widget(false, $name = __('Meetup List Next Event','vsmeet_domain'), array('description' => __("Display the next single events.",'vsmeet_domain')));
    }

    /** @see WP_Widget::widget */
    function widget($args, $instance) {
        extract( $args );
        $title = apply_filters('widget_title', $instance['title']);
        $id = $instance['id']; // meetup ID or URL name
        $date_format = $instance['date_format'];

        echo $before_widget;
        if ( $title ) echo $before_title . $title . $after_title;
        if ( $id ) {
            $vsm = new VsMeetWidget();
            $html = $vsm->get_group_next_event( $id, $date_format );
            echo $html;
        }
        echo $after_widget;
    }

    /** @see WP_Widget::update */
    function update($new_instance, $old_instance) {
        $instance = $old_instance;
        $instance['title'] = strip_tags($new_instance['title']);
        if ( preg_match('/[a-zA-Z]/', $new_instance['id'] ) )
            $instance['id'] = sanitize_title( $new_instance['id'] );
        else
            $instance['id'] = str_replace( ' ', '', $new_instance['id'] );
        $instance['date_format'] = $new_instance['date_format'];

        return $instance;
    }

    /** @see WP_Widget::form */
    function form($instance) {
        if ( $instance ) {
            $title = esc_attr($instance['title']);
            $id = esc_attr($instance['id']); // -> it's a name if it contains any a-zA-z, otherwise ID
            $date_format = esc_attr($instance['date_format']);
        } else {
            $title = '';
            $id = '';
            $date_format = 'F d, Y @ g:i a';
        }
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">
                <?php _e('Title:','vsmeet_domain'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" />
            </label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('id'); ?>">
                <?php _e('Group ID:','vsmeet_domain'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('id'); ?>" name="<?php echo $this->get_field_name('id'); ?>" type="text" value="<?php echo $id; ?>" />
            </label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('date_format'); ?>">
                <?php _e('Date Format:','vsmeet_domain'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('date_format'); ?>" name="<?php echo $this->get_field_name('date_format'); ?>" type="text" value="<?php echo $date_format; ?>" />
            </label>
        </p>
    <?php }
} // class VsMeetNextSingleWidget


/**
 * VsMeetList extends the widget class to create an event list for a a lsit of meetup groups.
 */
class VsMeetGroupsListWidget extends WP_Widget {
    /** constructor */
    function VsMeetGroupsListWidget() {
        parent::WP_Widget(false, $name = __('Meetup Group List Event','vsmeet_domain'), array('description' => __("Display a list of groups' events.",'vsmeet_domain')));
    }

    /** @see WP_Widget::widget */
    function widget($args, $instance) {
        extract( $args );
        $title = apply_filters('widget_title', $instance['title']);
        $ids = $instance['ids']; // meetup IDs or URL names
        $limit = intval($instance['limit']);
        $highlight_first = $instance['highlight_first'];
        $highlight_groups = $instance['highlight_groups'];
        $date_format = $instance['date_format'];

        echo $before_widget;
        if ( $title ) echo $before_title . $title . $after_title;
        if ( $ids ) {
            $vsm = new VsMeetWidget();
            $html = $vsm->get_groups_events( $ids, $limit, $highlight_first, $highlight_groups, $date_format );
            echo $html;
        }
        echo $after_widget;
    }

    /** @see WP_Widget::update */
    function update($new_instance, $old_instance) {
        $instance = $old_instance;
        $instance['title'] = strip_tags($new_instance['title']);
        $instance['ids'] = strtolower(str_replace( ' ', '', $new_instance['ids'] ));
        $instance['limit'] = intval($new_instance['limit']);
        $instance['highlight_first'] = $new_instance['highlight_first'];
        $instance['highlight_groups'] = $new_instance['highlight_groups'];
        $instance['date_format'] = $new_instance['date_format'];

        return $instance;
    }

    /** @see WP_Widget::form */
    function form($instance) {
        if ( $instance ) {
            $title = esc_attr($instance['title']);
            $ids = esc_attr($instance['ids']); // -> it's a name if it contains any a-zA-z, otherwise ID
            $limit = intval($instance['limit']);
            $highlight_first = esc_attr($instance['highlight_first']);
            $highlight_groups = esc_attr($instance['highlight_groups']);
            $date_format = esc_attr($instance['date_format']);
        } else {
            $title = '';
            $ids = '';
            $limit = 10;
            $highlight_first = 1;
			$highlight_groups = '';
            $date_format = 'd/m @ H:i';
        }
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">
                <?php _e('Title:','vsmeet_domain'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" />
            </label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('ids'); ?>">
                <?php _e('Group IDs:','vsmeet_domain'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('ids'); ?>" name="<?php echo $this->get_field_name('ids'); ?>" type="text" value="<?php echo $ids; ?>" />
            </label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('highlight_groups'); ?>">
                <?php _e('Highlight Group IDs:','vsmeet_domain'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('ids'); ?>" name="<?php echo $this->get_field_name('highlight_groups'); ?>" type="text" value="<?php echo $highlight_groups; ?>" />
            </label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('limit'); ?>">
                <?php _e('Number of events to show:','vsmeet_domain');?>
                <input id="<?php echo $this->get_field_id('limit'); ?>" name="<?php echo $this->get_field_name('limit'); ?>" type="text" value="<?php echo $limit; ?>" size='3' />
            </label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('highlight_first'); ?>">
                <input type="checkbox" <?php echo checked($highlight_first, 1, false ) ?> class="widefat" id="<?php echo $this->get_field_id('highlight_first') ?>" name="<?php echo $this->get_field_name('highlight_first') ?>" type="text" value="1" />
                <?php _e('Highlight first item','vsmeet_domain'); ?>
            </label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('date_format'); ?>">
                <?php _e('Date Format:','vsmeet_domain'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('date_format'); ?>" name="<?php echo $this->get_field_name('date_format'); ?>" type="text" value="<?php echo $date_format; ?>" />
            </label>
        </p>
    <?php }
} // class VsMeetGroupsListWidget