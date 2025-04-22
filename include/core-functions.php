<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access
}

add_shortcode('debug','display_debug_message');

function display_debug_message(){
  echo "<pre>";
  echo print_r(get_transient('debug'),1);
  echo "</pre>";
}



?>
