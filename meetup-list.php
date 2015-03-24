<?php
global $events;

echo '<ul class="meetup_list">';
$count = 0;
foreach ( $events as $event ) {
    if ($hide_first && ++$count == 1)
        continue;

    printf(
        '<li><a href="%1$s">%2$s</a>; %3$s</li>',
        esc_url($event->event_url),
        strip_tags($event->name),
        date($date_format, intval($event->time / 1000 + $event->utc_offset / 1000))
    );
}
echo '</ul>';
