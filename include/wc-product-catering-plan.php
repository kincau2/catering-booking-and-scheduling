<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1A) Register the new product type in backend
add_filter( 'product_type_selector', 'register_catering_plan_product_type' );
function register_catering_plan_product_type( $types ) {
    $types['catering_plan'] = __( 'Catering plan', 'catering-booking-and-scheduling' );
    return $types;
}

// 1B) Create a new class extend WC product varible
add_action( 'init', 'create_catering_plan_product_type' );

function create_catering_plan_product_type(){
    class WC_Product_Catering_Plan extends WC_Product_Variable {
        public function __construct( $product ) {

            $this->product_type = 'catering_plan';
            // $this->supports[]   = 'ajax_add_to_cart';
            parent::__construct( $product );

        }

        public function get_type() {
            return 'catering_plan';
        }
    }
}

add_filter( 'woocommerce_data_stores', function( $stores ){
   $stores['product-catering_plan'] = 'WC_Product_Variable_Data_Store_CPT';
   return $stores;
} );

add_filter( 'woocommerce_product_class', 'load_catering_plan_product_class',10,2);
function load_catering_plan_product_class( $php_classname, $product_type ) {
   if ( $product_type == 'catering_plan' ) {
       $php_classname = 'WC_Product_Catering_Plan';
   }
   return $php_classname;
}

add_filter( 'woocommerce_add_to_cart_handler', 'catering_plan_add_to_cart_handler', 10, 2 );
function catering_plan_add_to_cart_handler( $handler, $adding_to_cart ){
    if( $handler == 'catering_plan' ){
        $handler = 'variable';
    }
    return $handler;
}

// 2) Add a new tab "Catering option"
add_filter( 'woocommerce_product_data_tabs', 'catering_plan_product_data_tab' );
function catering_plan_product_data_tab( $tabs ) {
    $tabs['catering_option'] = array(
        'label'    => __( 'Catering option', 'catering-booking-and-scheduling' ),
        'target'   => 'catering_option_product_data',
        'class'    => array( 'show_if_catering_plan' ),
        'priority' => 80
    );
    $tabs[ 'attribute' ][ 'class' ][] = 'show_if_catering_plan';
    $tabs[ 'variations' ][ 'class' ][] = 'show_if_catering_plan';
    $tabs[ 'inventory' ][ 'class' ][] = 'hide_if_catering_plan';
    $tabs[ 'shipping' ][ 'class' ][] = 'hide_if_catering_plan';

    return $tabs;
}

// 3) The actual panel HTML
add_action( 'woocommerce_product_data_panels', 'my_catering_plan_product_data_panel' );
function my_catering_plan_product_data_panel() {
    include CATERING_PLUGIN_DIR . '/template/wc-product-catering-options.php';
}


// 4) Enqueue scripts for select2 & plan-days badges
add_action( 'admin_enqueue_scripts', 'catering_plan_admin_scripts' );
function catering_plan_admin_scripts( $hook ) {
    if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) return;
    if ( 'product' !== get_post_type() ) return;

    // Enqueue select2 if not loaded by WooCommerce:
    wp_enqueue_script('select2', plugins_url('/catering-booking-and-scheduling/lib/select2/select2.min.js'), array('jquery'), '4.0', true );

    // Inline script to init #catering_cat_id as normal multi-select, and #catering_plan_length as "tags"
    wp_add_inline_script('select2', "
    jQuery(document).ready(function($){

        // 1) Initialize select2 for meal categories (simple multi-select)
        $('#catering_cat_id').select2();

    });
    ");
}


add_action('admin_footer', 'catering_plan_type_js');
function catering_plan_type_js(){
    // Only run on the product edit/add screen
    if( 'product' !== get_post_type() ) return;
    ?>
    <script>
    jQuery(document).ready(function($){
        $('#product-type').on('change', function(){
            var ptype = $(this).val();
            // Toggle your show_if_catering_plan
            if(ptype === 'catering_plan'){
                $('.enable_variation').show();
            } else {
                $('.show_if_catering_plan').hide();
            }
        }).change();

        $('.save_attributes').on('click', function(){
          if($('#product-type').val() == 'catering_plan'){
              $('.enable_variation').show();
          }
        });
        
        // New handler: when "add_custom_attribute" button is clicked
        $(document).on('click', '.add_custom_attribute', function(){
            console.log('Catering plan product detected. Hiding variations.');
            if($('#product-type').val() === 'catering_plan'){
                
                setTimeout(function(){
                    $('.enable_variation').show();
                }, 300); // wait 0.3s for the attribute box to render
            }
        });

    });
    </script>
    <?php
}



// 5) Save meta when product is saved
add_action( 'woocommerce_process_product_meta', 'my_save_catering_plan_meta' );
function my_save_catering_plan_meta( $post_id ) {
    // Only do this for 'catering_plan'

    $ptype = isset($_POST['product-type']) ? sanitize_text_field($_POST['product-type']) : '';
    if($ptype !== 'catering_plan') {
        return;
    }

    // 2) Save per‐category pick limits
    $qtys = isset( $_POST['catering_cat_qty'] ) && is_array( $_POST['catering_cat_qty'] )
          ? array_map( 'intval', $_POST['catering_cat_qty'] )
          : [];
    update_post_meta( $post_id, 'catering_cat_qty', maybe_serialize( $qtys ) );

    // Save due date type
    if ( isset( $_POST['catering_type'] ) ) {
        $due_date_type = sanitize_text_field( $_POST['catering_type'] );
        update_post_meta( $post_id, 'catering_type', $due_date_type );
    }

    // Save expiry (in days)
    if ( isset( $_POST['catering_expiry'] ) ) {
        $expiry = intval( $_POST['catering_expiry'] );
        if ( $expiry === 0 ) {
            delete_post_meta( $post_id, 'catering_expiry' );
        } else {
            update_post_meta( $post_id, 'catering_expiry', $expiry );
        }
    }

    // Save soup container setting
    if ( isset( $_POST['catering_soup_container'] ) ) {
        $container = sanitize_text_field( $_POST['catering_soup_container'] );
        if ( $container === '' ) {
            delete_post_meta( $post_id, 'catering_soup_container' );
        } else {
            update_post_meta( $post_id, 'catering_soup_container', $container );
        }
    }

}

// 6) Add to cart for catering plan product =

add_action( "woocommerce_catering_plan_add_to_cart", 'woocommerce_variable_add_to_cart');

add_action('woocommerce_after_product_attribute_settings', 'add_show_if_catering_plan_to_variation_div');
function add_show_if_catering_plan_to_variation_div(){
    ?>
    <script>
    jQuery(document).ready(function($){
        $('.enable_variation.show_if_variable').addClass('show_if_catering_plan');
    });
    </script>
    <?php
}
