<?php

/**
 * Save a new product attribute from his name (slug).
 *
 * @since 3.0.0
 * @param string $name  | The product attribute name (slug).
 * @param string $label | The product attribute label (name).
 */
function save_product_attribute_from_name($name, $label = '', $set = true)
{
    if (!function_exists('get_attribute_id_from_name')) return;

    global $wpdb;

    $label = $label == '' ? ucfirst($name) : $label;
    $attribute_id = get_attribute_id_from_name($name);

    if (empty($attribute_id)) {
        $attribute_id = NULL;
    } else {
        $set = false;
    }
    $args = array(
        'attribute_id'      => $attribute_id,
        'attribute_name'    => $name,
        'attribute_label'   => $label,
        'attribute_type'    => 'select',
        'attribute_orderby' => 'menu_order',
        'attribute_public'  => 0,
    );


    if (empty($attribute_id)) {
        $wpdb->insert("{$wpdb->prefix}woocommerce_attribute_taxonomies", $args);
        set_transient('wc_attribute_taxonomies', false);
    }

    if ($set) {
        $attributes = wc_get_attribute_taxonomies();
        $args['attribute_id'] = get_attribute_id_from_name($name);
        $attributes[] = (object) $args;
        //print_r($attributes);
        set_transient('wc_attribute_taxonomies', $attributes);
    } else {
        return;
    }
}

/**
 * Get the product attribute ID from the name.
 *
 * @since 3.0.0
 * @param string $name | The name (slug).
 */
function get_attribute_id_from_name($name)
{
    global $wpdb;
    $attribute_id = $wpdb->get_col("SELECT attribute_id
    FROM {$wpdb->prefix}woocommerce_attribute_taxonomies
    WHERE attribute_name LIKE '$name'");
    return reset($attribute_id);
}

/**
 * Create a new variable product (with new attributes if they are).
 * (Needed functions:
 *
 * @since 3.0.0
 * @param array $data | The data to insert in the product.
 * Create a variable product
 */

function create_product_variable($data)
{
    if (!function_exists('save_product_attribute_from_name')) return;

    $postname = sanitize_title($data['title']);
    $author = empty($data['author']) ? '1' : $data['author'];
    if(!isset($data['content'])) {
        $data['content'] = '';
    }
    if(!isset($data['content'])) {
        $data['content'] = '';
    }
    if(!isset($data['excerpt'])) {
        $data['excerpt'] = '';
    }
    $post_data = array(
        'post_author'   => $author,
        'post_name'     => $postname,
        'post_title'    => $data['title'],
        'post_content'  => $data['content'],
        'post_excerpt'  => $data['excerpt'],
        'post_status'   => 'publish',
        'ping_status'   => 'closed',
        'post_type'     => 'product',
        'guid'          => home_url('/product/' . $postname . '/'),
    );

    // Creating the product (post data)
    $product_id = wp_insert_post($post_data);

    // Get an instance of the WC_Product_Variable object and save it
    $product = new WC_Product_Variable($product_id);
    $product->save();

    ## ---------------------- Other optional data  ---------------------- ##
    ##     (see WC_Product and WC_Product_Variable setters methods)

    // THE PRICES (No prices yet as we need to create product variations)

    // IMAGES GALLERY
    if (!empty($data['gallery_ids']) && count($data['gallery_ids']) > 0)
        $product->set_gallery_image_ids($data['gallery_ids']);

    // SKU
    if (!empty($data['sku']))
        $product->set_sku($data['sku']);

    // STOCK (stock will be managed in variations)
    $product->set_stock_quantity($data['stock']); // Set a minimal stock quantity
    $product->set_manage_stock($data['set_manage_stock']);
    $product->set_stock_status('');

    // Tax class
    if (empty($data['tax_class']))
        $product->set_tax_class($data['tax_class']);

    // WEIGHT
    if (!empty($data['weight']))
        $product->set_weight($data['weight']); // weight (reseting)
    else
        // $product->set_weight();

    $product->validate_props(); // Check validation

    ## ---------------------- VARIATION ATTRIBUTES ---------------------- ##

    $product_attributes = array();
    // dd($data['attributes']);
    foreach ($data['attributes'] as $key => $terms) {
        $taxonomy = wc_attribute_taxonomy_name($key); // The taxonomy slug
        $attr_label = ucfirst($key); // attribute label name
        $attr_name = (wc_sanitize_taxonomy_name($key)); // attribute slug

        // NEW Attributes: Register and save them
        if (!taxonomy_exists($taxonomy))
            save_product_attribute_from_name($attr_name, $attr_label);
        // var_dump($key);
        // var_dump($terms);
        if (isset($terms[0]) && $terms[0] !== null) {
            $product_attributes[$taxonomy] = array(
                'name'         => $taxonomy,
                'value'        => '',
                'position'     => '',
                'is_visible'   => 0,
                'is_variation' => 1,
                'is_taxonomy'  => 1
            );
        }

        // var_dump($terms);


        foreach ($terms as $value) {
            // var_dump($value);
            if (isset($value) && $value !== null) {
                $term_name = ucfirst($value);
                $term_slug = sanitize_title($value);

                // Check if the Term name exist and if not we create it.
                if (!term_exists($value, $taxonomy))
                    wp_insert_term($term_name, $taxonomy, array('slug' => $term_slug)); // Create the term

                // Set attribute values
                wp_set_post_terms($product_id, $term_name, $taxonomy, true);
            }
        }
    }
    update_post_meta($product_id, '_product_attributes', $product_attributes);
    $product->save(); // Save the data
    return $product_id;
}

function create_product_variations($product_id, $variation_data)
{
    // Get the Variable product object (parent)
    $default = [];
    $product = wc_get_product($product_id);

    $variation_post = array(
        'post_title'  => $product->get_title(),
        'post_name'   => 'product-' . $product_id . '-variation',
        'post_status' => 'publish',
        'post_parent' => $product_id,
        'post_type'   => 'product_variation',
        'guid'        => $product->get_permalink()
    );

    // Creating the product variation
    $variation_id = wp_insert_post($variation_post);

    // Get an instance of the WC_Product_Variation object
    $variation = new WC_Product_Variation($variation_id);

    // Iterating through the variations attributes
    // dd($variation_data['attributes']);
    foreach ($variation_data['attributes'] as $attribute => $term_name) {

        $taxonomy = 'pa_' . $attribute; // The attribute taxonomy

        // If taxonomy doesn't exists we create it (Thanks to Carl F. Corneil)
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy(
                $taxonomy,
                'product_variation',
                array(
                    'hierarchical' => false,
                    'label' => ucfirst($attribute),
                    'query_var' => true,
                    'rewrite' => array('slug' => sanitize_title($attribute)), // The base slug
                ),
            );
        }
        // dd($term_name);
        if (isset($term_name) && $term_name !== null) {
            // Check if the Term name exist and if not we create it.
            if (!term_exists($term_name, $taxonomy))
                wp_insert_term($term_name, $taxonomy); // Create the term

            $term_slug = get_term_by('name', $term_name, $taxonomy)->slug; // Get the term slug

            // Get the post Terms names from the parent variable product.
            $post_term_names =  wp_get_post_terms($product_id, $taxonomy, array('fields' => 'names'));

            // Check if the post term exist and if not we set it in the parent variable product.
            if (!in_array($term_name, $post_term_names))
                wp_set_post_terms($product_id, $term_name, $taxonomy, true);

            // Set/save the attribute data in the product variation
            update_post_meta($variation_id, 'attribute_' . $taxonomy, $term_slug);
            $default[$taxonomy] = $term_slug;
        }
    }

    ## Set/save all other data

    // SKU
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

    // Product Image
    if (!empty($variation_data['image'])) {
        $image_meta_url = '_knawatfibu_url';
        update_post_meta($variation_id, $image_meta_url, $variation_data['image']);
    }
    if(!isset($variation_data['content'])) {
        $variation_data['content'] = '';
    }
    $variation->set_description($variation_data['content']);
    $variation->set_weight(''); // weight (reseting)
    update_post_meta($variation_id, 'guid', $variation_id['Guid']);

    $variation->save(); // Save the data

    return $variation_id;
}

function c55_get_product_by_sku($sku)
{
    return wc_get_product_id_by_sku($sku);
}

function c55_upload_product_image($url, $filename)
{
    $uploaddir = wp_upload_dir();
    $uploadfile = $uploaddir['path'] . '/' . $filename;

    $contents = file_get_contents($url);
    $savefile = fopen($uploadfile, 'w');
    fwrite($savefile, $contents);
    fclose($savefile);

    $wp_filetype = wp_check_filetype(basename($filename), null);

    $attachment = array(
        'post_mime_type' => $wp_filetype['type'],
        'post_title' => $filename,
        'post_content' => '',
        'post_status' => 'inherit'
    );

    $attach_id = wp_insert_attachment($attachment, $uploadfile);

    $imagenew = get_post($attach_id);
    $fullsizepath = get_attached_file($imagenew->ID);
    $attach_data = wp_generate_attachment_metadata($attach_id, $fullsizepath);
    wp_update_attachment_metadata($attach_id, $attach_data);
    return $attach_id;
}

function c55_updateDefaultAttributes($parent_id)
{
    $product = wc_get_product($parent_id);

    if (!count($default_attributes = get_post_meta($product->get_id(), '_default_attributes'))) {
        $new_defaults = array();
        $product_attributes = $product->get_attributes();
        if (count($product_attributes)) {
            foreach ($product_attributes as $key => $attributes) {
                $values = explode(',', $product->get_attribute($key));
                if (isset($values[0]) && !isset($default_attributes[$key])) {
                    $new_defaults[$key] = sanitize_key($values[0]);
                }
            }
            update_post_meta($product->get_id(), '_default_attributes', $new_defaults);
        }
    }
}
