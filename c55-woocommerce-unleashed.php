<?php

/*

Plugin Name: C55 Unleahsed to woocommerce sync
Description: Sync product data from woocommerce to Unleashed.
Version: 1.0.0
Author: Mark Dowton
Author URI: https://www.linkedin.com/in/mark-dowton-03a85a41/
Text Domain: Unleashed to woocommerce
*/

register_shutdown_function(function(){
    $err = error_get_last();
    if ( $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) ) {
        $log_dir = plugin_dir_path(__FILE__) . 'logs/';
        wp_mkdir_p( $log_dir );
        $msg = sprintf(
            "[%s] %s in %s on line %d\n\n",
            date('Y-m-d H:i:s'),
            $err['message'],
            $err['file'],
            $err['line']
        );
        file_put_contents( $log_dir . 'php_shutdown.log', $msg, FILE_APPEND );
    }
});

include_once('src/services/http-services.php');
include_once('src/services/unleashed-services/unleashed-stock.php');
include_once('src/helpers/helpers.php');
include_once('src/helpers/c55_create_variable_product.php');
include_once('src/all-products/c55-all-products.php');
include_once('src/all-customers/c55-all-customers.php');
include_once('src/stock-adjustments/c55-stock-adjustments.php');
include_once('src/woocommerce-products/c55-woocommerce-products.php');


add_action('admin_menu', 'my_admin_menu');
add_action('admin_enqueue_scripts', 'register_my_plugin_scripts');
add_action('admin_enqueue_scripts', 'load_my_plugin_scripts');
add_action('admin_init', 'my_settings_init');

const PRODUCT_GROUP_RETAIL = 'retail';
const PRODUCT_GROUP_SHAKER = 'shaker';
const PRODUCT_GROUP_1KG = '1kg';
const PROUCT_GROUP_250G = '250g';


function my_admin_menu()
{
    add_menu_page(
        __('Sample page', 'my-textdomain'),
        __('Woo-Unleashed', 'my-textdomain'),
        'manage_options',
        'sample-page',
        'my_admin_page_contents',
        'dashicons-schedule',
        3
    );
}


function my_admin_page_contents()
{
?>
    <form method="POST" action="options.php">
        <?php
        my_setting_markup();
        settings_fields('sample-page');
        do_settings_sections('sample-page');
        submit_button();
        ?>
    </form>
<?php
}


function register_my_plugin_scripts()
{
    wp_register_style('my-plugin', plugins_url('./c55-woocommerce-unleashed/assest/css/styles.css'));
    // wp_register_script('my-plugin', plugins_url('ddd/js/plugin.js' ) );
}

function load_my_plugin_scripts($hook)
{
    // Load only on ?page=sample-page
    if ($hook != 'toplevel_page_sample-page') {
        return;
    }
    // Load style & scripts.
    wp_enqueue_style('my-plugin');
    wp_enqueue_script('my-plugin');
}
function my_settings_init()
{

    //     echo "<pre>";
    // print_r(get_post_meta( 835139, 'guid_order', true ));
    //     echo "</pre>";
    // exit();

    add_settings_section(
        'sample_page_setting_section',
        __('Unleashed API Settings', 'my-textdomain'),
        'my_setting_section_callback_function',
        'sample-page'
    );

    add_settings_field(
        'unleashed_api_id',
        __('API Credentials :', 'my-textdomain'),

        'sample-page',
        'sample_page_setting_section'
    );

    register_setting('sample-page', 'unleashed_api_id');
}

// MAIN LOOP
function my_setting_section_callback_function() {
  $productGroup = [PRODUCT_GROUP_RETAIL, PRODUCT_GROUP_SHAKER, PRODUCT_GROUP_1KG, PROUCT_GROUP_250G];
  $array_to_update_meta = array();
  foreach ($productGroup as $group) {
    $model = c55_syncAllProducts($group);
    $array_to_update_meta[] = $model;
  }
  update_option('cron_product_update', $array_to_update_meta);
}

function c55_loop_product_items($item) {
    // foreach ($model as $key => $item) {
        // If they are variation product ....
        if ($item && !empty($item['AttributeSet'])) {
            $attributes = [];
            $parentVariation = '';
            foreach ($item['AttributeSet']['Attributes'] as $key => $attr) {
                if ($attr['Name'] === 'Name') {
                    $parentVariation = strtolower($attr['Value']);
                } else {
                    $attributes[strtolower($attr['Name'])] = [$attr['Value']];
                }
            }

            // Upload the product image
            $attachId = null;
            if (isset($item['ImageUrl'])) {
                // $fileName = preg_replace("/[\s_]/", "-", $item['ProductDescription']);
                // $attachId = c55_upload_product_image($url, $fileName . '.jpg');
                $attachId = $item['ImageUrl'];
            } else {
                $attachId = 'http://go.dev55.com.au/wp-content/uploads/2022/08/placeholder-gourmet-organics-herbs-spices-foods.jpg';
            }

            // Check if SKU already loaded
            $sku = str_replace(" ", "-", $parentVariation);
            $isFound = c55_get_product_by_sku($sku);
            // If not found create tthe variable product
            if ((int)$isFound === 0) {
                $productId = create_product_variable(array(
                    'author'        => '', // optional
                    'title'         => $parentVariation,
                    'regular_price' => '', // product regular price
                    'sale_price'    => '', // product sale price (optional)
                    'stock'         => '', // Set a minimal stock quantity
                    'set_manage_stock' => false,
                    'image_id'      => $attachId, // optional
                    'gallery_ids'   => array(), // optional
                    'sku'           => $sku, // optional
                    'tax_class'     => '', // optional
                    'weight'        => $item['Weight'], // optional
                    // For NEW attributes/values use NAMES (not slugs)
                    'attributes'    => $attributes
                ));
                // create the variations
                $parent_id = $productId; // Or get the variable product id dynamically
                $product_id = $productId;
            } else {
                echo 'Varibale product exists update <br/>';
                c55_updateProductVariable($isFound, $item);
                $product_id = $isFound;
            }
            
            update_post_meta($product_id, 'guid', $item['Guid']);
            update_post_meta($product_id, 'item_unleashed', json_encode($item));

            // Else add tha variations.
            // The variation data
            $variationAtrr = [];
            foreach ($item['AttributeSet']['Attributes'] as $key => $attr) {
                if ($attr['Name'] !== 'Name') {
                    $variationAtrr[strtolower($attr['Name'])] = $attr['Value'];
                }
            }
            // Call to get stock levels by GUID
            $stock_qty = c55_getStockOnHand($item['Guid']);
            $isFoundVariation = c55_get_product_by_sku($item['ProductCode']);

            if ((int)$isFoundVariation === 0) {
                echo 'Creating a variant <br/>';
                $variation_data =  array(
                    'attributes' => $variationAtrr,
                    'sku'           => $item['ProductCode'],
                    'regular_price' => $item['SellPriceTier1']['Value'],
                    'sale_price'    => '',
                    'stock_qty'     => $stock_qty,
                    'manage_stock'  => true,
                    'stock_status'  => ( $stock_qty > 0 ? 'instock' : 'outofstock' ),
                    'image' => $attachId,
                    'weight' => $item['Weight'],
                    'length' => $item['Depth'],
                    'height' => $item['Height'],
                    'width' => $item['Width'],
                );
                if ((int)$isFound !== 0) {
                    $parent_id = $isFound;
                }
                $var_id = create_product_variations($parent_id, $variation_data);
                c55_updateDefaultAttributes($parent_id);
                $sales_price_array = array();
                foreach ($item as $item_inner_key => $item_inner_value) {
                	if(is_array($item_inner_value)) {
                		if(isset($item_inner_value['Value'])) {
                			$sales_price_array[$item_inner_key] = $item_inner_value['Value'];
                		}
                	}
                }
         	   update_post_meta($var_id, 'sales_price_array', json_encode($sales_price_array));
                $variation = new WC_Product_Variation($var_id);
                $variation->set_weight($variation_data['weight']); // weight (reseting)
                $variation->set_length($variation_data['length']);
                $variation->set_height($variation_data['height']);
                $variation->set_width($variation_data['width']);
                $variation->save(); // Save the data
            } else {
                echo $isFoundVariation;
                echo 'variant exist update <br/>';
                $variation_data =  array(
                    'attributes' => $variationAtrr,
                    'sku'           => $item['ProductCode'],
                    'regular_price' => $item['SellPriceTier1']['Value'],
                    'sale_price'    => '',
                    'stock_qty'     => $stock_qty,
                    'manage_stock'  => true,
                    'stock_status'  => ( $stock_qty > 0 ? 'instock' : 'outofstock' ),
                    'image' => $attachId,
                    'weight' => $item['Weight'],
                    'length' => $item['Depth'],
                    'height' => $item['Height'],
                    'width' => $item['Width'],
                );
                $variation = new WC_Product_Variation($isFoundVariation);
                if (!empty($variation_data['sku']))
                    $variation->set_sku($variation_data['sku']);

                // Prices
                if (empty($variation_data['sale_price'])) {
                    $variation->set_price($variation_data['regular_price']);
                } else {
                    $variation->set_price($variation_data['sale_price']);
                    $variation->set_sale_price($variation_data['sale_price']);
                }
                $variation->set_regular_price($variation_data['regular_price']);

                // Stock
                // Force WooCommerce to manage stock on every variation
                $variation->set_manage_stock( true );

                // Always set the quantity (even if zero)
                $variation->set_stock_quantity( (int) $variation_data['stock_qty'] );

                // Explicitly mark status based on whether qty > 0
                if ( $variation_data['stock_qty'] > 0 ) {
                    $variation->set_stock_status( 'instock' );
                } else {
                    $variation->set_stock_status( 'outofstock' );
                }

                // if(isset($variation_data['content'])){
                //     $variation->set_description($variation_data['content']);
                // }
                $variation->save(); // Save the data

                $variation->set_weight($variation_data['weight']); // weight (reseting)
                $variation->set_length($variation_data['length']);
                $variation->set_height($variation_data['height']);
                $variation->set_width($variation_data['width']);

                $variation_id = $variation->get_id(); // Get the variation ID after saving
                if (!empty($variation_data['image'])) {
                    $image_meta_url = '_knawatfibu_url';
                    update_post_meta($variation_id, $image_meta_url, $variation_data['image']);
                }
                $sales_price_array = array();
                foreach ($item as $item_inner_key => $item_inner_value) {
                	if(is_array($item_inner_value)) {
                		if(isset($item_inner_value['Value'])) {
                			$sales_price_array[$item_inner_key] = $item_inner_value['Value'];
                		}
                	}
                }
         	   update_post_meta($variation_id, 'sales_price_array', json_encode($sales_price_array));
            }
        }
    // }
    echo 'Uploaded complete ....';
}
function my_setting_markup()
{
?>
    <section id="section-form">
        <div style="padding: 0 20px 20px 20px;">
            <label for="my-input"><?php _e('Unleashed API ID'); ?>:</label>
            <input style="min-width: 600px;" type="text" id="unleashed_api_id" name="unleashed_api_id" value="<?php echo get_option('unleashed_api_id'); ?>">
        </div>
        <div style="padding: 0 20px 20px 20px;">
            <label for="my-input"><?php _e('Unleashed API Key'); ?>:</label>
            <input style="min-width: 600px;" type="text" id="unleashed_api_key" name="unleashed_api_key" value="<?php echo get_option('unleashed_api_key'); ?>">
        </div>
        <div style="padding: 0 20px 20px 20px;">
            <button id="unleashed_sync" name="unleashed_sync" class="button button-primary">Sync unleashed to woo-comm</button>
        </div>
    </section>
<?php
}

function create_customer_in_unleashed($customer_data) {
    $customer_guid = generate_guid();
    $customer_data['guid'] = $customer_guid;

    $endpoint = 'Customers/'.$customer_guid; // API endpoint for creating customers
    $json_data = json_encode($customer_data);

    // Send the customer data to the remote endpoint
    $response = post_remote_unleashed_url($endpoint, $json_data);

    // Retrieve the GUID from the response and return it
    // $response_data = json_decode($response, true);
    return $customer_guid;
}

add_action('init', 'my_custom_init_function');
function my_custom_init_function() {
    if(isset($_GET['cron_product_cron'])) {
        $update_product_cron_time = get_option("update_product_cron_time");

        if (isset($update_product_cron_time)) {
            $time_diff = time() - $update_product_cron_time;
            $hours_1 = 60 * 15; // 15 mins in seconds

            if ($time_diff > $hours_1) {
                my_setting_section_callback_function(1);
                update_option("update_product_cron_time", time());
                exit();
            }
        } else {
            my_setting_section_callback_function(1);
            update_option("update_product_cron_time", time());
            exit();
        }

        $cron_product_update = get_option('cron_product_update');
        $update_cron_product_update = array();
        $limit = 40;
        $count_limit = 0;
        foreach ($cron_product_update as $cron_product_update_key => $cron_product_update_value) {
            echo count($cron_product_update_value['Items']);
            // echo "<pre>";
            // print_r($cron_product_update_value);
            // echo "</pre>";
            // exit();
            $push_array = array();
            if(isset($cron_product_update_value['Items'])) {
                foreach ($cron_product_update_value['Items'] as $key => $value) {
                    if($limit != $count_limit) {
                        c55_loop_product_items($value);
                        $count_limit++;
                    } else {
                        $push_array[] = $value;
                    }
                }
            }
            $cron_product_update_value['Items'] = $push_array;
            $update_cron_product_update[] = $cron_product_update_value;
            update_option('cron_product_update', $update_cron_product_update);
        }
        exit();
    }

    if(isset($_GET['cron_customer_cron'])) {
        $update_customer_cron_time = get_option("update_customer_cron_time");

        if (isset($update_customer_cron_time)) {
            $time_diff = time() - $update_customer_cron_time;
            $hours_1 = 1 * 60 * 60; // 24 hours in seconds

            if ($time_diff > $hours_1) {
                sync_customers_page_inner_contents(1);
                update_option("update_customer_cron_time", time());
                exit();
            }
        } else {
            sync_customers_page_inner_contents(1);
            update_option("update_customer_cron_time", time());
            exit();
        }
        exit();
        $cron_customer_cron = get_option('cron_customer_update');
        if ($cron_customer_cron['Pagination'] && (int) $cron_customer_cron['Pagination']['NumberOfPages'] > 1 && $cron_customer_cron['Pagination']['PageNumber'] != $cron_customer_cron['Pagination']['NumberOfPages']) {
            $page = $cron_customer_cron['Pagination']['PageNumber'];
            $nextPage = (int) $cron_customer_cron['Pagination']['PageNumber'] + 1;
            // sync_customers_page_inner_contents($nextPage, true);
            sync_customers_page_inner_contents($nextPage, true);
        } else {
            $limit = 40;
            $count_limit = 0;
            $push_array = array();
            if(isset($cron_customer_cron['Items'])) {
                foreach ($cron_customer_cron['Items'] as $key => $value) {
                    if($limit != $count_limit) {
                        c55_loop_customer_items($value);
                        $count_limit++;
                    } else {
                        $push_array[] = $value;
                    }
                }
            }
            $cron_customer_cron['Items'] = $push_array;
            $update_cron_customer_cron[] = $cron_customer_cron;
            update_option('cron_customer_cron', $update_cron_customer_cron);

            exit();
        }

    }
}




   
add_action( 'woocommerce_checkout_order_processed', 'send_data_on_order_place', 10, 1 );
function send_data_on_order_place( $order_id ) {
    $log_dir  = plugin_dir_path( __FILE__ ) . 'logs/';
    wp_mkdir_p( $log_dir );
    $log_file = $log_dir . 'unleashed_errors.log';

    try {
        $order    = wc_get_order( $order_id );
        $post_id  = $order->get_id();

        // only once
        if ( get_post_meta( $post_id, 'guid_order', true ) ) {
            return;
        }

        $order_guid       = generate_guid();
        $customer_id      = $order->get_user_id();
        $customer_guid    = get_user_meta( $customer_id, 'guid', true );
        $comments         = get_order_comments( $order );
        $warehouse        = get_warehouse_data();
        $currency         = get_currency_data();
        $tax              = get_tax_data();            // make sure this returns exactly the tax GUID
        $subtotal         = (float) $order->get_subtotal();
        $tax_total        = (float) $order->get_total_tax();

        // build the lines (with your previous casts / logging)
        $sales_order_lines = [];
        $line_counter     = 1;
        foreach ( $order->get_items() as $item ) {
            $qty        = (int)   $item->get_quantity();
            $line_total = (float) $item->get_total();
            $unit_price = $qty > 0 ? round( $line_total / $qty, 4 ) : 0;

            $sales_order_lines[] = [
                'LineNumber'         => $line_counter++,
                'Product'            => [
                    'Guid'               => get_post_meta( $item->get_product_id(), 'guid', true ) ?: '',
                    'ProductCode'        => $item->get_product()->get_sku(),
                    'ProductDescription' => $item->get_product()->get_name(),
                ],
                'OrderQuantity'      => $qty,
                'UnitPrice'          => $unit_price,
                'DiscountRate'       => 0,
                'LineTotal'          => $line_total,
                'Comments'           => '',
                'AverageLandedPriceAtTimeOfSale' => 10.9135,
                'TaxRate'            => 0,
                'LineTax'            => 0,
                'XeroTaxCode'        => 'EXEMPTOUTPUT',
                'BCUnitPrice'        => $unit_price,
                'BCLineTotal'        => $line_total,
                'BCLineTax'          => 0,
                'XeroSalesAccount'   => 41110,
                'SerialNumbers'      => [],
                'BatchNumbers'       => [],
                'Guid'               => generate_guid(),
            ];
        }

        $converted_order_id = str_pad( $order_id, 8, '0', STR_PAD_LEFT );

        // PAYLOAD: only include the Tax GUID
        $payload = [
            'SalesOrderLines'   => $sales_order_lines,
            'OrderNumber'       => 'SO-' . $converted_order_id,
            'OrderStatus'       => 'Parked',
            'Customer'          => [
                'CustomerCode' => $customer_guid,
                'CustomerName' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'CurrencyId'   => 8,
                'Guid'         => $customer_guid,
            ],
            'Comments'          => $comments,
            'Warehouse'         => $warehouse,
            'Currency'          => $currency,
            'ExchangeRate'      => 1,
            'DiscountRate'      => 0,
            // instead of shoving the whole Tax struct through, just pass its GUID:
            'Tax'               => [ 'Guid' => $tax['Guid'] ],
            // levels above don’t need TaxRate/XeroTaxCode—Unleashed will fill those from the GUID
            'SubTotal'          => $subtotal,
            'TaxTotal'          => $tax_total,
            'Total'             => $subtotal + $tax_total,
            'TotalVolume'       => 0,
            'TotalWeight'       => 0,
            'BCSubTotal'        => $subtotal,
            'BCTaxTotal'        => $tax_total,
            'BCTotal'           => $subtotal + $tax_total,
            'AllocateProduct'   => true,
            'CreatedBy'         => get_option( 'admin_email' ),
            'LastModifiedBy'    => get_option( 'admin_email' ),
            'Guid'              => $order_guid,
        ];

        $json_data = wp_json_encode( $payload );


        // store the GUID so we never re‐send
        update_post_meta( $post_id, 'guid_order', $order_guid );

        file_put_contents( $log_file,
            "[".date('Y-m-d H:i:s')."] REQUEST:\n".$json_data."\n\n",
            FILE_APPEND
        );


        $response = post_remote_unleashed_url( 'SalesOrders/' . $order_guid, $json_data );

        if ( is_wp_error( $response ) ) {
            file_put_contents( $log_file,
                "[".date('Y-m-d H:i:s')."] WP_ERROR: ".$response->get_error_message()."\n\n",
                FILE_APPEND
            );
            return; // stop
        }

        // stringify & log the response
        $repr = is_array($response) ? var_export($response, true) : $response;
        file_put_contents( $log_file,
            "[".date('Y-m-d H:i:s')."] RESPONSE:\n".$repr."\n\n",
            FILE_APPEND
        );

        // if the API returned its own errors array
        if ( is_array($response) && ! empty( $response['Items'] ) ) {
            file_put_contents( $log_file,
                "[".date('Y-m-d H:i:s')."] API_ERRORS:\n".var_export($response['Items'], true)."\n\n",
                FILE_APPEND
            );
        }

    } catch ( Exception $e ) {
        // catch any uncaught exception
        file_put_contents( $log_file,
            "[".date('Y-m-d H:i:s')."] EXCEPTION: ".$e->getMessage()."\n\n",
            FILE_APPEND
        );
    }

    // finally, re-sync your products
    // foreach ( $order->get_items() as $item ) {
        // c55_loop_product_items( c55_updateProductByGuid( get_post_meta($item->get_product_id(),'guid',true) ) );
    // }
}



function generate_guid() {
    if (function_exists('com_create_guid') === true) {
        return trim(com_create_guid(), '{}');
    }

    return sprintf(
        '%04X%04X-%04X-%04X-%04X-%04X%04X%04X',
        mt_rand(0, 65535),
        mt_rand(0, 65535),
        mt_rand(0, 65535),
        mt_rand(16384, 20479),
        mt_rand(32768, 49151),
        mt_rand(0, 65535),
        mt_rand(0, 65535),
        mt_rand(0, 65535)
    );
}

function get_order_comments($order) {
    $comments = "Batch numbers:"; // Customize as needed

    foreach ($order->get_items() as $item) {
        $product_name = $item->get_name();
        $product_batch_number = "123456-01"; // Example batch number, replace with dynamic data
        $comments .= "\n{$product_name}: {$product_batch_number}";
    }

    return $comments;
}

function get_warehouse_data() {
    return [
        "WarehouseCode" => "GOHerbs",
        "WarehouseName" => "GOHerbs",
        "IsDefault" => true,
        "StreetNo" => "HQ GOHerbs",
        "AddressLine1" => "3/8 Stockyard Place",
        "City" => "West Gosford",
        "Region" => "NSW",
        "PostCode" => 2250,
        "PhoneNumber" => "0243237556",
        "MobileNumber" => "0421327767",
        "ContactName" => "Anibal Zarate",
        "Guid" => "ffa99030-326c-4607-8a16-796b599d6e30"
    ];
}

function get_currency_data() {
    return [
        "CurrencyCode" => get_option('woocommerce_currency'),
        "Description" => "Australia, Dollars", // Customize as needed,
        "Guid"=>"f4765315-0d79-4484-986c-8f0ebf9fa2f2"
    ];
}

function get_tax_data() {
    return [
        "TaxCode" => "OUTPUT", // Customize as needed
        "Description" => "GST on Income (10%)", // Customize as needed
        "TaxRate" => 0.1,
        "CanApplyToRevenue" => true,
        "Guid"=>"73e6af76-412f-43e4-a4b0-fc1f30fe6475"
    ];
}

function c55_loop_customer_items($customer_data)
{
    // foreach ($model as $key => $customer_data) {
    $email = $customer_data['Email'];
    if(!empty($email)) {
        $email = "saad_sinpk@yahoo.com";
        $existing_user = get_user_by('email', $email);
        $custom_role = $customer_data['SellPriceTierReference']['Reference'];
        if (!get_role($custom_role)) {
            // Create the "test" role
            add_role($custom_role, $custom_role);
        }

        // Create or update customer based on email existence
        if ($existing_user) {
            echo "Customer exist $email -- updating <br>";
            $user_id = $existing_user->ID;

            // Update existing user data
            $userdata = array(
                'ID' => $user_id,
                'user_email' => $email,
                'first_name' => $customer_data['ContactFirstName'],
                'last_name' => $customer_data['ContactLastName'],
                'role' => $custom_role
            );

            wp_update_user($userdata);
        } else {
            $username = sanitize_user($email, true);
            $password = wp_generate_password();

            $userdata = array(
                'user_login' => $username,
                'user_email' => $email,
                'user_pass' => $password,
                'first_name' => $customer_data['ContactFirstName'],
                'last_name' => $customer_data['ContactLastName'],
                'role' => $custom_role
            );

            $user_id = wp_insert_user($userdata);
            echo "Customer created $email -- updating <br>";

            $headers = array('Content-Type: text/html; charset=UTF-8');
            $message = 'Dear '.$customer_data['ContactFirstName'].' '.$customer_data['ContactLastName'].',<br><br>'
            .'Thank you for choosing Gourmet Organics as your trusted provider of healthy and organic products. We are thrilled to welcome you to our community and assist you on your journey toward a healthier lifestyle.<br><br>'
            .'To access your Gourmet Organics account and start exploring our wide range of delicious, nutritious options, please find below your login details:<br><br>'
            .'Username: '.$username.'<br>'
            .'Password: '.$password.'<br><br>'
            .'We recommend changing your password upon logging in for the first time to ensure the security of your account. Simply follow the instructions provided on our website to update your password to something more memorable to you.';

            wp_mail($email, 'Welcome to Gourmet Organics', $message, $headers);
        }

        // Save additional meta data
        if (!is_wp_error($user_id)) {
            update_user_meta($user_id, 'guid', $customer_data['Guid']);
            update_user_meta($user_id, 'customer_code', $customer_data['CustomerCode']);
            if(isset($customer_data['Currency']['CurrencyCode'])){
                update_user_meta($user_id, 'currency_code', $customer_data['Currency']['CurrencyCode']);
            }

            // Save additional array data as user meta
            foreach ($customer_data as $meta_key => $meta_value) {
                // Skip specific keys
                if (in_array($meta_key, ['Email', 'ContactFirstName', 'ContactLastName', 'Guid', 'CustomerCode'])) {
                    continue;
                }

                // Save arrays as serialized data
                if (is_array($meta_value)) {
                    $meta_value = maybe_serialize($meta_value);
                }

                update_user_meta($user_id, $meta_key, $meta_value);
            }

            // Save address data if it exists
            if (isset($customer_data['Addresses']) && is_array($customer_data['Addresses'])) {
                if(isset($customer_data['Addresses'][0])) {
                    $address_data = $customer_data['Addresses'][0];

			        // Update billing address fields
			        update_user_meta($user_id, 'billing_first_name', $customer_data['ContactFirstName']); // Empty the current billing first name
			        update_user_meta($user_id, 'billing_last_name', $customer_data['ContactLastName']); // Set billing last name as user's last name
			        update_user_meta($user_id, 'billing_address_1', $address_data['StreetAddress']);
			        update_user_meta($user_id, 'billing_address_2', $address_data['StreetAddress2']);
			        update_user_meta($user_id, 'billing_city', $address_data['City']);
			        update_user_meta($user_id, 'billing_state', $address_data['Region']);
			        update_user_meta($user_id, 'billing_postcode', $address_data['PostalCode']);
			        update_user_meta($user_id, 'billing_country', $address_data['Country']);


			        // Update shipping address fields
					update_user_meta($user_id, 'shipping_first_name', $customer_data['ContactFirstName']); // Empty the current shipping first name
					update_user_meta($user_id, 'shipping_last_name', $customer_data['ContactLastName']); // Set shipping last name as user's last name
			        update_user_meta($user_id, 'shipping_address_1', $address_data['StreetAddress']);
			        update_user_meta($user_id, 'shipping_address_2', $address_data['StreetAddress2']);
			        update_user_meta($user_id, 'shipping_city', $address_data['City']);
			        update_user_meta($user_id, 'shipping_state', $address_data['Region']);
			        update_user_meta($user_id, 'shipping_postcode', $address_data['PostalCode']);
			        update_user_meta($user_id, 'shipping_country', $address_data['Country']);
			    }
            }
        }
        exit();
    }
   // }
    echo 'Uploaded complete ....';
}


// Add a new top-level menu page
function add_sync_customers_menu_page() {
    add_menu_page(
        'Sync Customers', // Page title
        'Sync Customers', // Menu title
        'manage_options', // Capability required
        'sync-customers', // Menu slug
        'sync_customers_page_contents', // Callback function to display page content
        'dashicons-groups', // Icon (adjust as needed)
        6 // Position of the menu item (adjust this value to change its position)
    );
}
add_action('admin_menu', 'add_sync_customers_menu_page');

// Callback function to display page content
function sync_customers_page_contents() {
    echo '<h1>Sync Customers</h1>';
    sync_customers_page_inner_contents(1);
}
function sync_customers_page_inner_contents($page = 1, $cron = false) {
    if($cron == true) {
        $old = get_option("cron_customer_update");
        unset($old['paginaiton']);
        $model = c55_syncAllCustomers($page);
        foreach ($old['Items'] as $old_key => $old_value) {
            $model['Items'][] = $old_value;
        }
        update_option("cron_customer_update", $model);
    } else {
        $model = c55_syncAllCustomers($page);
        update_option("cron_customer_update", $model);
    }
}

// Define a function to handle the profile update event
function sync_user_data_to_api($user_id) {
    // Check if the user is logged in and has a customer GUID saved in user meta
    if (is_user_logged_in() && get_user_meta($user_id, 'guid', true)) {
        $fullname = '';
        // Retrieve the customer GUID from user meta
        $customer_guid = get_user_meta($user_id, 'guid', true);
        $customer_CustomerCode = get_user_meta($user_id, 'customer_code', true);
        if(isset($_POST['shipping_first_name'])) {
            if(isset($_POST['shipping_first_name']) AND !empty($_POST['shipping_first_name'])) {
                $fullname .= $_POST['shipping_first_name'].' ';
            }
            if(isset($_POST['shipping_last_name']) AND !empty($_POST['shipping_last_name'])) {
                $fullname .= $_POST['shipping_last_name'];
            }
            if(empty($fullname)) {
                $fullname = 'false';
                $updated_customer_data['CustomerName'] = 'false';
            } else {
                $updated_customer_data['CustomerName'] = $fullname;
            }
            // $_POST['Addresses']['AddressType'] = '';
            $updated_customer_data['Addresses'][] = array(
                "AddressType" => "Shipping",
                "AddressName" => $fullname,
                "StreetAddress" => $_POST['shipping_address_1'],
                "StreetAddress2" => $_POST['shipping_address_2'],
                "Suburb" => "",
                "City" => $_POST['shipping_city'],
                "Region" => $_POST['shipping_state'],
                "Country" => $_POST['shipping_country'],
                "PostalCode" => $_POST['shipping_postcode'],
                "IsDefault" => false,
                "DeliveryInstruction" => ""
            );

        } elseif(isset($_POST['billing_first_name'])) {
	        if(isset($_POST['billing_first_name']) AND !empty($_POST['billing_first_name'])) {
	            $fullname .= $_POST['billing_first_name'].' ';
	        }
	        if(isset($_POST['billing_last_name']) AND !empty($_POST['billing_last_name'])) {
	            $fullname .= $_POST['billing_last_name'];
	        }
	        if(empty($fullname)) {
	        	$fullname = 'false';
	            $updated_customer_data['CustomerName'] = 'false';
	        } else {
	            $updated_customer_data['CustomerName'] = $fullname;
	        }
        	// $_POST['Addresses']['AddressType'] = '';
        	$updated_customer_data['Addresses'][] = array(
			    "AddressType" => "Billing",
			    "AddressName" => $fullname,
			    "StreetAddress" => $_POST['billing_address_1'],
			    "StreetAddress2" => $_POST['billing_address_2'],
			    "Suburb" => "",
			    "City" => $_POST['billing_city'],
			    "Region" => $_POST['billing_state'],
			    "Country" => $_POST['billing_country'],
			    "PostalCode" => $_POST['billing_postcode'],
			    "IsDefault" => false,
			    "DeliveryInstruction" => ""
			);

        }
        $updated_customer_data['Guid'] = $customer_guid;
        $updated_customer_data['CustomerCode'] = $customer_CustomerCode;
        if(isset($_POST['account_first_name'])) {
            $updated_customer_data['ContactFirstName'] = $_POST['account_first_name'];
        }
        if(isset($_POST['account_last_name'])) {
            $updated_customer_data['ContactLastName'] = $_POST['account_last_name'];
        }
        if(isset($_POST['account_email'])) {
            $updated_customer_data['Email'] = $_POST['account_email'];
        }
        if(isset($_POST['account_first_name']) AND !empty($_POST['account_first_name'])) {
            $fullname .= $_POST['account_first_name'].' ';
        }
        if(isset($_POST['ContactLastName']) AND !empty($_POST['ContactLastName'])) {
            $fullname .= $_POST['ContactLastName'];
        }
        if(empty($fullname) || $fullname == 'false') {
            $updated_customer_data['CustomerName'] = 'false';
        } else {
            $updated_customer_data['CustomerName'] = $fullname;
        }

     //    if(isset($_POST['account_email'])) {
	    //     $updated_customer_data['Email'] = $_POST['account_email'];
	    // }

        $json_data = json_encode($updated_customer_data);

        $endpoint = 'Customers/'.$customer_guid; // API endpoint for creating customers
        $response = post_remote_unleashed_url($endpoint, $json_data);
    }
}

// Hook the function to the profile_update event
add_action('profile_update', 'sync_user_data_to_api', 10, 1);
function custom_product_price_html($price, $product) {
    if (is_user_logged_in()) {
        $variation_id = 0;
        $lowest_price = 0;
        $highest_price = 0;

        $user_id = get_current_user_id();
		$SellPriceTierReference = get_user_meta($user_id, "SellPriceTierReference", true);
		$Reference_group = 'none';
		if(!empty($SellPriceTierReference)) {
			$SellPriceTierReference = unserialize($SellPriceTierReference);
			if(isset($SellPriceTierReference['Reference'])) {
				$Reference_group = $SellPriceTierReference['Reference'];
			}
		}
		if($Reference_group != 'none') {
	        if ($product->is_type('variation')) {
	            $variation_id = $product->get_variation_id();

		    	$SellPriceTierReference = get_post_meta($variation_id, "sales_price_array", true);
				$SellPriceTierReferenceArray = array();
		    	if(!empty($SellPriceTierReference)) {
		    		$SellPriceTierReferenceArray = json_decode($SellPriceTierReference);
		    	}
		        $discounted_price = $SellPriceTierReferenceArray->$Reference_group; // 25% discount
		        $price = wc_price($discounted_price);

	        } else {
	            $variations = $product->get_available_variations();

		        $variation_prices = array();
		        foreach ($variations as $variation) {
		            $variation_id = $variation['variation_id'];
		            $variation_price = floatval($variation['display_price']);
		            $variation_prices[$variation_id] = $variation_price;


		        }

		        $lowest_variation_id = array_keys($variation_prices, min($variation_prices))[0];
		        $highest_variation_id = array_keys($variation_prices, max($variation_prices))[0];
		        
		        // Get the variation objects using the variation IDs
		        $lowest_variation = wc_get_product($lowest_variation_id);
		        $highest_variation = wc_get_product($highest_variation_id);
		        
		        // Get the prices of the lowest and highest variations
		        $lowest_price = $lowest_variation->get_id();
		        $highest_price = $highest_variation->get_id();

		    	$SellPriceTierReference_lowest_price = get_post_meta($lowest_price, "sales_price_array", true);
				$SellPriceTierReferenceArray = array();
		    	if(!empty($SellPriceTierReference_lowest_price)) {
		    		$SellPriceTierReferenceArray = json_decode($SellPriceTierReference_lowest_price);
		    	}
		        $discounted_lowest_price = $SellPriceTierReferenceArray->$Reference_group;

		    	$SellPriceTierReference_highest_price = get_post_meta($highest_price, "sales_price_array", true);
				$SellPriceTierReferenceArray = array();
		    	if(!empty($SellPriceTierReference_highest_price)) {
		    		$SellPriceTierReferenceArray = json_decode($SellPriceTierReference_highest_price);
		    	}
		        $discounted_highest_price = $SellPriceTierReferenceArray->$Reference_group;
		        if($discounted_lowest_price != $discounted_highest_price) {
			        $price = wc_price($discounted_lowest_price).' - '.wc_price($discounted_highest_price);
			    } else {
			        $price = wc_price($discounted_lowest_price);
			    }

	        }

	    }

    }
    return $price;
}
add_filter('woocommerce_get_price_html', 'custom_product_price_html', 10, 2);

function custom_checkout_product_price($price, $cart_item, $cart_item_key) {
    if (is_user_logged_in()) {
        $product = $cart_item['data'];
        $variation_id = 0;
        $user_id = get_current_user_id();
		$SellPriceTierReference = get_user_meta($user_id, "SellPriceTierReference", true);
		$Reference_group = 'none';
		if(!empty($SellPriceTierReference)) {
			$SellPriceTierReference = unserialize($SellPriceTierReference);
			if(isset($SellPriceTierReference['Reference'])) {
				$Reference_group = $SellPriceTierReference['Reference'];
			}
		}
		if($Reference_group != 'none') {
	        if ($product->is_type('variation')) {
	            $variation_id = $product->get_variation_id();
	        } else {
	            $variations = $product->get_available_variations();
	            if (!empty($variations)) {
	                $variation_id = $variations[0]['variation_id'];
	            }
	        }
	    	$SellPriceTierReference = get_post_meta($variation_id, "sales_price_array", true);

			$SellPriceTierReferenceArray = array();
	    	if(!empty($SellPriceTierReference)) {
	    		$SellPriceTierReferenceArray = json_decode($SellPriceTierReference);
	    	}
	        $discounted_price = $SellPriceTierReferenceArray->$Reference_group; // 25% discount
	        $price = wc_price($discounted_price);
	    }
    }
    return $price;
}
add_filter('woocommerce_cart_item_price', 'custom_checkout_product_price', 10, 3);

function apply_custom_discount( $cart ) {
    if ( is_user_logged_in() ) {
        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];
	        $variation_id = 0;

	        $user_id = get_current_user_id();
			$SellPriceTierReference = get_user_meta($user_id, "SellPriceTierReference", true);
			$Reference_group = 'none';
			if(!empty($SellPriceTierReference)) {
				$SellPriceTierReference = unserialize($SellPriceTierReference);
				if(isset($SellPriceTierReference['Reference'])) {
					$Reference_group = $SellPriceTierReference['Reference'];
				}
			}

			if($Reference_group != 'none') {
		        if ($product->is_type('variation')) {
		            $variation_id = $product->get_variation_id();
		        } else {
		            $variations = $product->get_available_variations();
		            if (!empty($variations)) {
		                $variation_id = $variations[0]['variation_id'];
		            }
		        }
		    	$SellPriceTierReference = get_post_meta($variation_id, "sales_price_array", true);

				$SellPriceTierReferenceArray = array();
		    	if(!empty($SellPriceTierReference)) {
		    		$SellPriceTierReferenceArray = json_decode($SellPriceTierReference);
		    	}
		        $discounted_price = $SellPriceTierReferenceArray->$Reference_group;
	            $cart_item['data']->set_price( $discounted_price );
	        }
        }
    }
}
add_action( 'woocommerce_before_calculate_totals', 'apply_custom_discount', 10, 1 );

