<?php

/*

Plugin Name: C55 Unleahsed to woocommerce sync
Description: Sync product data from woocommerce to Unleashed.
Version: 1.0.0
Author: Mark Dowton
Author URI: https://www.linkedin.com/in/mark-dowton-03a85a41/
Text Domain: Unleashed to woocommerce

*/

include_once('src/services/http-services.php');
include_once('src/services/unleashed-services/unleashed-stock.php');
include_once('src/helpers/helpers.php');
include_once('src/helpers/c55_create_variable_product.php');
include_once('src/all-products/c55-all-products.php');
include_once('src/all-customers/c55-all-customers.php');
include_once('src/stock-adjustments/c55-stock-adjustments.php');
include_once('src/woocommerce-products/c55-woocommerce-products.php');
include_once('src/woocommerce-products/woocommerce_product_hooks.php');


add_action('admin_menu', 'my_admin_menu');
add_action('admin_enqueue_scripts', 'register_my_plugin_scripts');
add_action('admin_enqueue_scripts', 'load_my_plugin_scripts');
add_action('admin_init', 'my_settings_init');
// Hook for CRON
add_action( 'unleashed_cron_hook', 'unleashed_cron_exec' );
if ( ! wp_next_scheduled( 'unleashed_cron_hook' ) ) {
    wp_schedule_event( time(), 'hourly', 'unleashed_cron_hook' );
}
const PRODUCT_GROUP_RETAIL = 'retail';
const PRODUCT_GROUP_SHAKER = 'shaker';
const PRODUCT_GROUP_1KG = '1kg';
const PROUCT_GROUP_250G = '25Og';

function unleashed_cron_exec() {
    $to = 'markdowton007@gmail.com';
    $subject = 'Cron launched';
    $body = 'Cron called';
    $headers = array('Content-Type: text/html; charset=UTF-8');
 
    wp_mail( $to, $subject, $body, $headers );
    // Calls the main loop to execute
    my_setting_section_callback_function();
}

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

  foreach ($productGroup as $group) {
      
    $model = c55_syncAllProducts($group);
    // echo "<pre>";
    // print_r($model);
    // echo "</pre>";
    // exit();
    if ($model) {
        if ($model['Pagination'] && (int) $model['Pagination']['NumberOfPages'] > 1) {
            foreach ($model['Pagination'] as $page) {
                // Need to pass a page param for second call
                if ((int)$page === 1) {
                    c55_loop_product_items($model['Items']);
                    break;
                }
                $nextPage = (int) $model['Pagination']['NumberOfPages'] + 1;
                $model = c55_syncAllProducts($nextPage);
                c55_loop_product_items($model['Items']);
            }
        } else {
            // Single page of results
            c55_loop_product_items($model['Items']);
        }
    }
  }
}

function c55_loop_product_items($model)
{

    foreach ($model as $key => $item) {
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
                    // 'content'       => $item['ProductDescription'],
                    // 'excerpt'       => $item['ProductDescription'],
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
                if (!empty($variation_data['stock_qty'])) {
                    $variation->set_stock_quantity($variation_data['stock_qty']);
                    $variation->set_manage_stock(true);
                    $variation->set_stock_status('');
                } else {
                    $variation->set_manage_stock(false);
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
    }
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
}




   
add_action('woocommerce_new_order', 'send_data_on_order_place', 10, 1);
function send_data_on_order_place($order_id) {
    // Get the order object
    $order_id = wc_get_order($order_id);
    $order_guid = get_post_meta($order_id, "guid_order");
    if(isset($order_guid) AND !empty($order_guid)) {
        $order = wc_get_order($order_id);

        // Generate GUID for the order
        $order_guid = generate_guid();

        // Retrieve dynamic data from the order and WordPress site
        $order_number = $order->get_order_number();
        $customer = $order->get_user();
        $customer_code = $customer->get_id();
        $customer_name = $customer->get_display_name();
        $currency_id = get_option('woocommerce_currency');
        $comments = get_order_comments($order);
        $warehouse = get_warehouse_data();
        $currency = get_currency_data();
        $tax = get_tax_data();
        $subtotal = $order->get_subtotal();
        $tax_total = $order->get_total_tax();
        $total = $order->get_total();
        $customer_id = $order->get_user_id();
        $customer_guid = get_user_meta($customer_id, 'guid', true);

        if(!isset($customer_guid) AND !empty($customer_guid)) {
            $shipping_address = $order->get_address('shipping');
            $customer_data = [
                "Addresses" => [
                    [
                        "AddressType" => "Shipping",
                        "AddressName" => $shipping_address['address_1'],
                        "StreetAddress" => $shipping_address['address_1'],
                        "StreetAddress2" => $shipping_address['address_2'],
                        "Suburb" => $shipping_address['city'],
                        "City" => $shipping_address['city'],
                        "Region" => $shipping_address['state'],
                        "Country" => $shipping_address['country'],
                        "PostalCode" => $shipping_address['postcode'],
                        "IsDefault" => false
                    ]
                ],
                "TaxCode" => "",
                "TaxRate" => null,
                "CustomerCode" => $customer_code,
                "CustomerName" => $customer_name,
                "Currency" => [
                    "CurrencyCode" => $currency_id,
                    "Description" => "Currency description", // Customize as needed
                    "Guid"=>"f4765315-0d79-4484-986c-8f0ebf9fa2f2"
                ],
                "Notes" => null,
                "Taxable" => true,
                // ... include other customer fields as needed
            ];
            $customer_guid = create_customer_in_unleashed($customer_data);
        }
        echo $customer_guid;
        update_user_meta($customer_id, 'guid', $customer_guid);

        // Loop through the order items
        $sales_order_lines = [];
        $line_number_counter = 1;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $product_id = $item->get_product_id();
            $product_guid = get_post_meta($product_id, 'guid', true);
            $product_code = $product->get_sku();
            $product_description = $product->get_name();
            $line_number = $item->get_id();
            $order_quantity = $item->get_quantity();
            $unit_price = $item->get_total() / $order_quantity;
            $line_total = $item->get_total();

            // Generate GUID for the item
            $item_guid = generate_guid();

            // Prepare the sales order line data
            $sales_order_lines[] = [
                "LineNumber" => $line_number_counter,
                "Product" => [
                    "Guid" => $product_guid,
                    "ProductCode" => $product_code,
                    "ProductDescription" => $product_description
                ],
                "OrderQuantity" => $order_quantity,
                "UnitPrice" => $unit_price,
                "DiscountRate" => 0,
                "LineTotal" => floatval($line_total),
                "Comments" => "", // Customize as needed
                "AverageLandedPriceAtTimeOfSale" => 10.913544444444, // Customize as needed
                "TaxRate" => 0,
                "LineTax" => 0,
                "XeroTaxCode" => "EXEMPTOUTPUT", // Customize as needed
                "BCUnitPrice" => $unit_price,
                "BCLineTotal" => floatval($line_total),
                "BCLineTax" => 0,
                "XeroSalesAccount" => 41110, // Customize as needed
                "SerialNumbers" => [],
                "BatchNumbers" => [],
                "Guid" => $item_guid
            ];
            $line_number_counter++;
        }

        $converted_order_id = str_pad($order_id, 8, '0', STR_PAD_LEFT);
        // Prepare the payload
        $data = [
            "SalesOrderLines" => $sales_order_lines,
            "OrderNumber" => "SO-"+$converted_order_id,
            "OrderStatus" => "Parked", // Customize as needed
            "Customer" => [
                "CustomerCode"=>$customer_guid,
                "CustomerName"=>$customer_name,
                "CurrencyId"=>8,
                "Guid"=>$customer_guid
            ],
            "Comments" => $comments,
            "Warehouse" => $warehouse,
            "Currency" => $currency,
            "ExchangeRate" => 1,
            "DiscountRate" => 0,
            "Tax" => $tax,
            "TaxRate" => $tax["TaxRate"],
            "XeroTaxCode" => $tax["TaxCode"],
            "SubTotal" => floatval($subtotal),
            "TaxTotal" => floatval($tax_total),
            "Total" => floatval($subtotal) + floatval($tax_total),
            "TotalVolume" => 0,
            "TotalWeight" => 0,
            "BCSubTotal" => $subtotal,
            "BCTaxTotal" => floatval($tax_total),
            "BCTotal" => floatval($subtotal) + floatval($tax_total),
            "AllocateProduct" => true,
            "CreatedBy" => get_option('admin_email'), // Customize as needed
            "LastModifiedBy" => get_option('admin_email'), // Customize as needed
            "Guid" => $order_guid
        ];
        $json_data = json_encode($data);

        update_post_meta($order_id, "guid_order", $order_guid);
        // Send the data to the remote endpoint
        $endpoint = 'SalesOrders/'.$order_guid; // Customize with your endpoint
        $response = post_remote_unleashed_url($endpoint, $json_data);
    }

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

function c55_loop_customer_items($model)
{
    foreach ($model as $key => $customer_data) {
        $email = $customer_data['Email'];
        if(!empty($email)) {
            $existing_user = get_user_by('email', $email);

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
                    'role' => 'customer'
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
                    'role' => 'customer'
                );

                $user_id = wp_insert_user($userdata);
                echo "Customer created $email -- updating <br>";

                // Send email with login details to the customer
                $message = 'Dear '.$customer_data['ContactFirstName'].' '.$customer_data['ContactLastName'].',<br><br>
                Thank you for choosing Gourmet Organics as your trusted provider of healthy and organic products. We are thrilled to welcome you to our community and assist you on your journey toward a healthier lifestyle.<br><br>
                To access your Gourmet Organics account and start exploring our wide range of delicious, nutritious options, please find below your login details:<br><br>
                Username: '.$username.'<br>
                Password: '.$password.'<br><br>
                We recommend changing your password upon logging in for the first time to ensure the security of your account. Simply follow the instructions provided on our website to update your password to something more memorable to you.';
                wp_mail($email, 'Welcome to Gourmet Organics', $message);
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
        }
   }
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
    // exit();
    sync_customers_page_inner_contents(1);
}
function sync_customers_page_inner_contents($page = 1) {
    $model = c55_syncAllCustomers($page);

    if ($model) {
        if ($model['Pagination'] && (int) $model['Pagination']['NumberOfPages'] > 1) {
            // Need to pass a page param for second call
            $page = $model['Pagination']['PageNumber'];
            if ((int)$page == $model['Pagination']['NumberOfPages']) {
                c55_loop_customer_items($model['Items']);
            } else {
                c55_loop_customer_items($model['Items']);
                $nextPage = (int) $model['Pagination']['PageNumber'] + 1;
                sync_customers_page_inner_contents($nextPage);
            }
        } else {
            // Single page of results
           c55_loop_customer_items($model['Items']);
        }
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

function change_mail_from_address($from_email) {
    return 'noreply@gourmetorganics.com.au'; // Replace with your desired sender email address
}
add_filter('wp_mail_from', 'change_mail_from_address');
