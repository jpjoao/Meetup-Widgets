<?php
global $events;
global $_meetup_widget;

echo '<ul class="meetup_list">';
$count = 0;
foreach ( $events as $event ) {

    if (++$count > $limit)
        break;

    if (in_array(strtolower($event->group->urlname), $highlight_groups))
    {
        echo  '<li class="highlight">';
    }
    else
    {
        echo '<li>';
    }

    if ($highlight_first && $count == 1)
    {
        if ( isset( $event->time ) ) {
            $date = date( $date_format, intval( $event->time/1000 + $event->utc_offset/1000 ) );
        } else {
            $date = apply_filters( 'vsm_no_date_text', '' );
        }
        $event_url = $event->event_url;
        $event_name = strip_tags($event->name);
        $event_description = wp_trim_words( strip_tags( $event->description ), 20 );
        $event_rsvp = absint( $event->yes_rsvp_count ) .' '. _n( $_meetup_widget[get_locale()]['attendee'], $_meetup_widget[get_locale()]['attendees'], $event->yes_rsvp_count );
        echo '
        <h3 class="event-title"><a href="' . $event_url . '">' . $event_name . '</a></h3>';
        if ( ! empty( $date ) )
        {
            echo '<p class="event-date">' . $date , '</p>';
        }
        echo '
        <p class="event-summary">' . $event_description . '</p>
        <p class="event-rsvp"><span class="rsvp-count">' . $event_rsvp . '</span> ';
        $rsvp = $_meetup_widget[get_locale()]['rsvp'];
        if ( !empty($options['vs_meetup_key']) && !empty($options['vs_meetup_secret']) && class_exists('OAuth')) {
            echo "<span class='rsvp-add'><a href='#' onclick='javascript:window.open(\"{$event->callback_url}&event=$id\",\"authenticate\",\"width=400,height=600\");'>$rsvp</a></span>";
        } else {
            echo "<span class='rsvp-add'><a href='{$event->event_url}'>$rsvp</a></span>";
        }
        echo '</p>';

        $location = $_meetup_widget[get_locale()]['location'];
        if ( isset( $event->venue ) ) {
            $venue = $event->venue->name.' '.$event->venue->address_1 . ', ' . $event->venue->city . ', ' . $event->venue->state;
            echo "<p class='event-location'>$location: <a href='http://maps.google.com/maps?q=$venue+%28".$event->venue->name."%29&z=17'>$venue</a></p>";
        } else {
            $tba = $_meetup_widget[get_locale()]['tba'];
            $venue = apply_filters( 'vsm_no_location_text', "$location: $tba" );
            if ( ! empty( $venue ) ){
                echo "<p class='event-location'>$venue</p>";
            }
        }
    }
    else
    {
        printf(
            '<a href="%1$s">%2$s</a>; %3$s',
            esc_url($event->event_url),
            strip_tags($event->name),
            date($date_format, intval($event->time / 1000 + $event->utc_offset / 1000))
        );
    }
    echo '</li>';
}

echo '<li class="desc_phpsp"><span></span> Eventos do PHPSP.</li>';
echo '<li class="desc_partner"><span></span> Eventos de parceiros.</li>';
echo '</ul>';
