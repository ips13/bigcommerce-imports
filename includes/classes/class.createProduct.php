<?php
/*
 * Class to Create the Woocommerce products from the Bigcommerce API 
 */
class createProductsWooBG{
	
	public $type = 'simple';
	public $product_data;
	public $pID; //product ID
	public $featured_image;
	public $product_gallery;
	
	
	//Autoload function
	public function __construct($productData){
		// add_action('init',array($this,'init'),10);
		$this->product_data = $productData;
		$this->createProduct();  
	}
	
	/*
	 * Create Product into store and saved data.
	 * Create products type @Varible and @Simple
	 */
	public function createProduct(){
		
		$post = array( // Set up the basic post data to insert for our product
			'post_author'  => 1,
			'post_content' => $this->product_data['description'],
			'post_status'  => 'publish',
			'post_title'   => $this->product_data['name'],
			'post_parent'  => '',
			'post_type'    => 'product'
		);
		
		$product_ID = 0;
		$bg_pid = $this->product_data['bg_pid'];
		
		//get product by bigcommerce id
		if(!$this->isEmpty($bg_pid)){
			$product_ID = $this->get_product_id_by_bgc($bg_pid);
		}
		
		//get product by SKU
		if($product_ID == 0){
			$product_ID = (!$this->isEmpty($this->product_data['sku']))? wc_get_product_id_by_sku($this->product_data['sku']) : 0;
		}
		
		//post slug
		$p_slug = sanitize_title($this->product_data['name']);
		
      	//if sku is empty or "-"
		//get product by slug
		if($product_ID == 0){
			$product_ID = $this->get_productid_by_slug($p_slug);
		}
		
		if($product_ID == 0){
			// Insert the post returning the new post id
			$product_ID = wp_insert_post($post); 
		}
		else{
			$post['ID'] = $product_ID;
			$post['post_name'] = $p_slug;
			wp_update_post($post);
		}
		
		// Insert the post returning the new post id
		$this->pID = $product_ID; 

		// If there is no post id something has gone wrong so don't proceed
		if (!$product_ID){
			return false;
		}
		
		// Set its SKU
		update_post_meta($product_ID, '_sku', $this->product_data['sku']); 
		
		// Set the product to visible, if not it won't show on the front end
		update_post_meta($product_ID,'_visibility','visible'); 
		
		// Set bigcommerce product id
		update_post_meta($product_ID,'bg_pid',$bg_pid); 
		
		//brand name
		update_post_meta($product_ID,'brand',$this->product_data['brand']); 
		update_post_meta($product_ID,'brand_id',$this->product_data['brand_id']); 
		
		//product source
		update_post_meta($product_ID,'psource','bigcommerce'); 
		
		$this->setProductType();
		$this->createCatsAndTags();
		
		//check if the product is variable
		if($this->isVariable()){
			
			// Add attributes passing the new post id, attributes & variations
			$available_attributes = explode(',',$this->product_data['available_attributes']);
			$this->insert_attributes($available_attributes, $this->product_data['variations']); 
			
			// Insert variations passing the new post id & variations   
			$this->insert_variations($this->product_data['variations']);
		}
		else{
			// Set it to a variable product type
			wp_set_object_terms($this->pID, 'simple', 'product_type'); 
			
			// Set the product to visible, if not it won't show on the front end
			update_post_meta($product_ID,'_price',$this->product_data['price']);
			if(!empty($this->product_data['sale_price'])){
				update_post_meta($product_ID,'_sale_price',$this->product_data['sale_price']);
			}
			update_post_meta($product_ID,'_regular_price',$this->product_data['price']); 
			update_post_meta($product_ID,'_manage_stock','yes'); 
			update_post_meta($product_ID,'_weight',$this->product_data['weight']); 
			update_post_meta($product_ID,'_stock',$this->product_data['qty']); 
		}
		
		//import all images for the product
		$this->importImages();
		
		// Get the product and save
		$productsave = wc_get_product( $product_ID );
		$productsave->save();
		
	}
  
  	/*
     * Check if value is empty
     */
  	public function isEmpty($val){
      	if(trim($val) == '-' || trim($val == '') || trim($val == 0)){
        	return true;
        }
      	return false;
    }
	
	/*
	 * Set current product type
	 */
	public function setProductType(){
		if($this->product_data['type'] == 'variable'){
			$this->type = 'variable';
			wp_set_object_terms($this->pID, 'variable', 'product_type'); // Set it to a variable product type
		}
	}
	
	/*
	 * Check if current product is variable or simple
	 */
	public function isVariable(){
		if($this->type == 'variable'){
			return true;
		}
		return false;
	}
	
	/*
	 * Create Tags and Categories for current product
	 */
	public function createCatsAndTags(){
		// Set up its categories and Tags
		wp_set_object_terms($this->pID, $this->product_data['categories'], 'product_cat');
		
		if(isset($this->product_data['tags'])){
			wp_set_object_terms($this->pID, explode(',',$this->product_data['tags']), 'product_tag');
		}
	}


	/*
	 * Insert attributes for current product.
	 */
	public function insert_attributes($available_attributes, $variations){
		$post_id = $this->pID;
		// Go through each attribute
		foreach ($available_attributes as $attribute){
			//attribute slug
			$attr_slug = $this->generate_attrSlug($attribute);
			//create new attribute taxonomy if not exists
			$create_attribute = $this->process_add_attribute(array('attribute_name' => $attr_slug, 'attribute_label' => ucfirst($attribute), 'attribute_type' => 'select', 'attribute_orderby' => 'menu_order', 'attribute_public' => false));
			
			$values = array(); // Set up an array to store the current attributes values.

			// Loop each variation in the file
			foreach ($variations as $variation){
				
				// Get the keys for the current variations attributes
				$attribute_keys = array_keys($variation['attributes']); 

				// Loop through each key
				foreach ($attribute_keys as $key){
					// If this attributes key is the top level attribute add the value to the $values array
					if ($key === $attr_slug){
						$values[] = $variation['attributes'][$key];
					}
				}
			}

			// Essentially we want to end up with something like this for each attribute:
			// $values would contain: array('small', 'medium', 'medium', 'large');

			$values = array_unique($values); // Filter out duplicate values

			// Store the values to the attribute on the new post, for example without variables:
			// wp_set_object_terms(6, 'small', 'pa_size');
			
			$var_taxonomy_ids = wp_set_object_terms($post_id,$values,'pa_'.$attr_slug);
			
			if (is_wp_error($var_taxonomy_ids)) {
				die($var_taxonomy_ids->get_error_message());
			}
		}

		$product_attributes_data = array(); // Setup array to hold our product attributes data

		// Loop round each attribute
		foreach ($available_attributes as $attribute){
			//attribute slug
			$attr_slug = $this->generate_attrSlug($attribute);
			// Set this attributes array to a key to using the prefix 'pa'
			$product_attributes_data['pa_'.$attr_slug] = array(

				'name'         	=> 'pa_'.$attr_slug,
				'value'        	=> '',
				'position'	   	=> '0',
				'is_visible'  	=> '1',
				'is_variation' 	=> '1',
				'is_taxonomy'  	=> '1'

			);
		}
		
		// Attach the above array to the new posts meta data key '_product_attributes'
		update_post_meta($post_id, '_product_attributes', $product_attributes_data); 

	}

	/*
	 * Insert Variations for current product.
	 */
	public function insert_variations($variations){
		
		$post_id = $this->pID;
		
		foreach ($variations as $index => $variation){
			
			
			$variation_post = array( // Setup the post data for the variation
				'post_title'  => 'Variation #'.$index.' of '.count($variations).' for product#'. $post_id,
				'post_name'   => 'product-'.$post_id.'-variation-'.$index,
				'post_status' => 'publish',
				'post_parent' => $post_id,
				'post_type'   => 'product_variation',
				'menu_order'  => $variation['position'],
				'guid'        => home_url() . '/?product_variation=product-' . $post_id . '-variation-' . $index
			);

			$metaQuery = array(array('key' => '_unique_id','value' => $variation['unique_id'],'compare' => '='));
			$variation_post_id = $this->VariationExists($metaQuery,$post_id);
			
			if($variation_post_id == 0){
				$variation_post_id = wp_insert_post($variation_post); // Insert the variation
			}

			//update variation menu order
			wp_update_post(array('ID'=>$variation_post_id,'menu_order'=>$variation['position']));
			
			$vunique_id = '';
			
			// Loop through the variations attributes
			foreach ($variation['attributes'] as $attribute => $value){
				
				$attribute = sanitize_title($attribute);
				// We need to insert the slug not the name into the variation post meta
				$attribute_term = get_term_by('name', $value, 'pa_'.$attribute); 

				// Again without variables: update_post_meta(25, 'attribute_pa_size', 'small')
				update_post_meta($variation_post_id, 'attribute_pa_'.$attribute, $attribute_term->slug);
				
				$vunique_id .= $value;
			}

			update_post_meta($variation_post_id, '_unique_id', $variation['unique_id']);
			update_post_meta($variation_post_id, '_value', $variation['value']);
			update_post_meta($variation_post_id, '_manage_stock', $variation['manage_stock']);
			update_post_meta($variation_post_id, '_stock', $variation['qty']);
			update_post_meta($variation_post_id, '_price', $variation['price']);
			update_post_meta($variation_post_id, '_regular_price', $variation['price']);
			if(!empty($variation['sale_price'])){
				update_post_meta($variation_post_id,'_sale_price',$variation['sale_price']);
			}
			update_post_meta($variation_post_id, '_weight', $variation['weight']);
		}
	}
	
	/*
	 * Check if Variation is already exists or not.
	 * @return VariationID or 0
	 */
	public function VariationExists($metaQuery,$parentID){
		$variationEx = new WP_Query( array( 'post_type' => 'product_variation','meta_query' => $metaQuery,'post_parent' => $parentID) );
		if($variationEx->post_count > 0){
			return $variationEx->posts[0]->ID;
		}
		return 0;
	}
	
	/*
	 * Import current product images into wordpress
	 */
	public function importImages(){

		//single file
		if(!empty($this->product_data['image'])){
			$featureImage = explode('?',$this->product_data['image']);
			$featureImage = $featureImage[0];
			$this->featured_image = $featureImage;
			
			if(!empty($this->featured_image)){
				$this->save_featured_image();
			}
		}
		
		//product Gallery
		$productGallery = $this->product_data['images'];
		$productGalleryImgs = array();
		if(isset($productGallery) && is_array($productGallery)){
			foreach($productGallery as $productGalleryImage){
				$productGImgSrc = explode('?',$productGalleryImage);
				$productGImgSrc = $productGImgSrc[0];
				array_push($productGalleryImgs,$productGImgSrc);
			}
		}
		$this->product_gallery = $productGalleryImgs;
		
		if(sizeof($this->product_gallery) > 0){
			$this->save_product_gallery();
		}
	}
	
	
	/*
	 * Save product featured image
	 */
	public function save_featured_image(){
		
		$imageID = false;
		$imgName = pathinfo($this->featured_image);
		$imageExists = $this->checkIfImageExists($imgName['filename']);
		
		if($imageExists){
			$imageID = $imageExists;
		}
		elseif($this->is_valid_url($this->featured_image)){
			$imageID = $this->save_image_with_url($this->featured_image);
		}
		
		if ($imageID)
			set_post_thumbnail( $this->pID, $imageID );	
	}

	
	/*
	 * Save product Gallery Images
	 */
	public function save_product_gallery(){	
	
		$post_id = $this->pID;
		$images = $this->product_gallery;
		$gallery = (isset($gallery))? array() : false;
		foreach ($images as $image) {
			
			$imgName = pathinfo($image);
			$imageExists = $this->checkIfImageExists($imgName['filename']);
			
			if($imageExists){
				$imageID = $imageExists;
			}
			elseif($this->is_valid_url($image)){
				$imageID = $this->save_image_with_url($image);
			}
			
			if ($imageID)
				$gallery[] = $imageID;
		}
		if ($gallery) {
			$meta_value = implode(',', $gallery);
			update_post_meta($post_id, '_product_image_gallery', $meta_value);
		}
		else{
			update_post_meta($post_id, '_product_image_gallery', '');
			// delete_post_meta($post_id, '_product_image_gallery');
		}
		
	}
	
	
	/*
	 * Download image and assigned to products
	 */
	function save_image_with_url($url) {
		
		$tmp = download_url( $url , 10 );
		$post_id = $this->pID;
		$desc = "";
		$file_array = array();
		$id = false;
	
		// Set variables for storage
		// fix file filename for query strings
		@preg_match('/[^\?]+\.(jpg|jpe|jpeg|gif|png)/i', $url, $matches);
		if (!$matches) {
			return $id;			
		}
		
		$file_array['name'] = basename($matches[0]);
		$file_array['tmp_name'] = $tmp;
		$desc = $file_array['name'];
		
		// If error storing temporarily, unlink
		if ( is_wp_error( $tmp ) ) {
			@unlink($file_array['tmp_name']);
			$file_array['tmp_name'] = '';
			return $id;
		}
	
		// do the validation and storage stuff
		$id = media_handle_sideload( $file_array, $post_id, $desc );
		
		if(is_wp_error($id)){
			echo $id->get_error_message(); exit;
		}
	
		// If error storing permanently, unlink
		if ( is_wp_error($id) ) {
			@unlink($file_array['tmp_name']);
			return $id;
		}
		
		return $id;
	}

	
	/*
	 * Check if Image already added
	 */
	public function checkIfImageExists($image){
		
		global $wpdb;

		/* use  get_posts to retreive image instead of query direct!*/
		
		//set up the args
		$args = array(
            'numberposts'	=> 1,
            'orderby'		=> 'post_date',
			'order'			=> 'DESC',
            'post_type'		=> 'attachment',
            'post_mime_type'=> 'image',
            'post_status' =>'any',
		    'meta_query' => array(
		        array(
		            'key' => '_wp_attached_file',
		            'value' => sanitize_file_name($image),
		            'compare' => 'LIKE'
		        )
		    )
		);
		//get the images
        $images = get_posts($args);

        if (!empty($images)) {
        //we found a match, return it!
	        return (int)$images[0]->ID;
        } else {
        //no image found with the same name, return false
	        return false;
        }
		
	}

	/*
	 * @helper
	 * Check if given url is valid!
	 */
	public function is_valid_url($url){
		// alternative way to check for a valid url
		if  (filter_var($url, FILTER_VALIDATE_URL) === FALSE) return false; else return true;

	}

	/*
	 * Add new attribute taxonomy if not exists
	 * if has error @return WP_Error object
	 */
	public function process_add_attribute($attribute){
		global $wpdb;

		if (empty($attribute['attribute_type'])) { $attribute['attribute_type'] = 'text';}
		if (empty($attribute['attribute_orderby'])) { $attribute['attribute_orderby'] = 'menu_order';}
		if (empty($attribute['attribute_public'])) { $attribute['attribute_public'] = 0;}

		if ( empty( $attribute['attribute_name'] ) || empty( $attribute['attribute_label'] ) ) {
			return new WP_Error( 'error', __( 'Please, provide an attribute name and slug.', 'woocommerce' ) );
		} 
		elseif ( ( $valid_attribute_name = $this->valid_attribute_name_f( $attribute['attribute_name'] ) ) && is_wp_error( $valid_attribute_name ) ) {
			return $valid_attribute_name;
		} 
		elseif ( taxonomy_exists( wc_attribute_taxonomy_name( $attribute['attribute_name'] ) ) ) {
			return false;
			// return new WP_Error( 'error', sprintf( __( 'Slug "%s" is already in use. Change it, please.', 'woocommerce' ), sanitize_title( $attribute['attribute_name'] ) ) );
		}

		$wpdb->insert( $wpdb->prefix . 'woocommerce_attribute_taxonomies', $attribute );

		do_action( 'woocommerce_attribute_added', $wpdb->insert_id, $attribute );
		
		//register taxonomy
		$name  = 'pa_'.$attribute['attribute_name'];
		$label = $attribute['attribute_name'];
		$taxonomy_data  = array(
			'hierarchical'          => false,
			'update_count_callback' => '_update_post_term_count',
			'labels'                => array(
					'name'              => sprintf( _x( 'Product %s', 'Product Attribute', 'woocommerce' ), $label ),
					'singular_name'     => $label,
					'search_items'      => sprintf( __( 'Search %s', 'woocommerce' ), $label ),
					'all_items'         => sprintf( __( 'All %s', 'woocommerce' ), $label ),
					'parent_item'       => sprintf( __( 'Parent %s', 'woocommerce' ), $label ),
					'parent_item_colon' => sprintf( __( 'Parent %s:', 'woocommerce' ), $label ),
					'edit_item'         => sprintf( __( 'Edit %s', 'woocommerce' ), $label ),
					'update_item'       => sprintf( __( 'Update %s', 'woocommerce' ), $label ),
					'add_new_item'      => sprintf( __( 'Add new %s', 'woocommerce' ), $label ),
					'new_item_name'     => sprintf( __( 'New %s', 'woocommerce' ), $label ),
					'not_found'         => sprintf( __( 'No &quot;%s&quot; found', 'woocommerce' ), $label ),
				),
			'show_ui'            => true,
			'show_in_quick_edit' => false,
			'show_in_menu'       => false,
			'meta_box_cb'        => false,
			'query_var'          => 1,
			'rewrite'            => false,
			'sort'               => false,
			'public'             => 1,
			'show_in_nav_menus'  => 1,
			'capabilities'       => array(
				'manage_terms' => 'manage_product_terms',
				'edit_terms'   => 'edit_product_terms',
				'delete_terms' => 'delete_product_terms',
				'assign_terms' => 'assign_product_terms',
			),
		);
		register_taxonomy($name,array('product'),$taxonomy_data);
		 
		flush_rewrite_rules();
		delete_transient( 'wc_attribute_taxonomies' );
		
		return true;
	}

	/**
	 * Valid attribute name
	 */
	public function valid_attribute_name_f( $attribute_name ) {
		
		if ( strlen( $attribute_name ) >= 28 ){
			return new WP_Error( 'error', sprintf( __( 'Slug "%s" is too long (28 characters max). Shorten it, please.', 'woocommerce' ), sanitize_title( $attribute_name ) ) );
		} 
		elseif(wc_check_if_attribute_name_is_reserved($attribute_name)){
			return new WP_Error( 'error', sprintf( __( 'Slug "%s" is not allowed because it is a reserved term. Change it, please.', 'woocommerce' ), sanitize_title( $attribute_name ) ) );
		}

		return true;
	}
	
	/*
	 * Get Product ID by bigcommerce product id
	 */
	public function get_product_id_by_bgc($bg_pid){
		global $wpdb;
		$productID = $wpdb->get_var( "SELECT p.`ID` from wp_posts p LEFT JOIN wp_postmeta pm ON p.`ID`=pm.`post_id` where pm.`meta_key` = 'bg_pid' AND pm.`meta_value` = {$bg_pid}" );
		if(!empty($productID) && $productID != 0){
			return $productID;
		} else{			
			return 0;
		}
	}
	
	/*
	 * Get Product ID by Slug
	 */
	public function get_productid_by_slug($slug) {
		$product = get_page_by_path( $slug, OBJECT, 'product' );
		if($product) {
			return $product->ID;
		} else {
			return 0;
		}
	}
	
	/**
	* Generate Attribute Slug
	*/
	public function generate_attrSlug($title){	
		return sanitize_title($title);
	}
	
	/**
	 * Print Result
	 */
	function pre($var){
		echo '<pre>'.print_r($var,true).'</pre>';
	}
}