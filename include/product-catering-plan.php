<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register new product type "Catering Plan" into product type selector.
 */
add_filter( 'product_type_selector', 'add_catering_plan_product_type' );
function add_catering_plan_product_type( $types ) {
    // Add our new product type with key "catering_plan".
    $types['catering_plan'] = __( 'Catering Plan', 'catering-booking-and-scheduling' );
    return $types;
}

/**
 * Define custom product class for Catering Plan.
 * This class extends WC_Product_Simple so it keeps all the simple product functionality.
 */

 add_action( 'init', 'create_catering_plan_product_type' );

 function create_catering_plan_product_type(){
     class WC_Product_Catering_Plan extends WC_Product {
         public function get_type() {
             return 'catering_plan';
         }
     }
 }

/**
 * Ensure WooCommerce loads our custom product class when a product is of type catering_plan.
 */
add_filter( 'woocommerce_product_class', 'load_catering_plan_product_class', 10, 2 );
function load_catering_plan_product_class( $classname, $product_type ) {
    if ( 'catering_plan' === $product_type ) {
        $classname = 'WC_Product_Catering_Plan';
    }
    return $classname;
}

/**
 * Add the custom "Catering Option" tab.
 * This tab will appear next to the default WooCommerce product data tabs.
 */
add_filter( 'woocommerce_product_data_tabs', 'add_catering_option_product_data_tab' );
function add_catering_option_product_data_tab( $tabs ) {
    // We add the tab only for our custom type by adding the class "show_if_catering_plan".
    $tabs['catering_option'] = array(
        'label'    => __( 'Catering Option', 'catering-booking-and-scheduling' ),
        'target'   => 'catering_product_options',
        'class'    => array( 'show_if_catering_plan' ),
        'priority' => 21,
    );
    return $tabs;
}

/**
 * Add content for the "Catering Option" tab.
 */
add_action( 'woocommerce_product_data_panels', 'add_catering_option_product_data_panel' );
function add_catering_option_product_data_panel() {
    ?>
    <div id="catering_product_options" class="panel woocommerce_options_panel hidden">
        <div class="options_group">
          <p class="form-field _include_meal_category_field ">
            <label for="_include_meal_category"><abbr title="Stock Keeping Unit"><?php echo esc_html( __( "Meal category", "catering-booking-and-scheduling" ) ) ?></abbr></label>
            <input type="text" class="short" style="" name="_include_meal_category" id="_include_meal_category" value="" placeholder=""> </p>
        </div>
    </div>
    <?php
}

add_action( 'woocommerce_product_data_panels', 'catering_plan_product_type_show_price' );

function catering_plan_product_type_show_price() {
   wc_enqueue_js( "
      $(document.body).on('woocommerce-product-type-change',function(event,type){
         if (type=='catering_plan') {
            $('.general_tab').show();
            $('.pricing').show();
            $('.inventory_tab').show();
            $('.inventory_sold_individually').show();
            $('._sold_individually_field').show();
            $('.shipping_options').hide();
         }
      });
   " );
   global $product_object;
   if ( $product_object && 'catering_plan' === $product_object->get_type() ) {
      wc_enqueue_js( "
         $('.general_tab').show();
         $('.pricing').show();
         $('.inventory_tab').show();
         $('.inventory_sold_individually').show();
         $('._sold_individually_field').show();
         $('.shipping_options').hide();
      " );
   }
}

?>
