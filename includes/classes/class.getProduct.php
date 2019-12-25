<?php
/*
 * Class to Get the all products from API and make a new array to map the fields for woocommerce import.
 */
use Bigcommerce\Api\Client as Bigcommerce;
class getProductsfromJsonBG{
	
	public $product = array();
	public $products = array();
	public $productJson;
	public $cProduct;
	
	public $currentOptions;
	public $cproductSkus;
	public $allCategories;
	
	//Autoload function
	public function __construct($productJson){
		//current product
		$this->productJson = $productJson;
		$this->getProducts();
	}
	
	//Get all Products
	private function getProducts(){
		unset($this->product);
		foreach($this->productJson as $product){
			$this->cProduct = $product;
			$this->init();
			array_push($this->products,$this->product);
		}
	}
	
	
	/*
	 * Get the data for the products and stored into the array
	 * Call the Variations, Tags, Categories, Attrubutes for Simple and Variable Product.
	 */
	private function init(){
		$cProduct = $this->cProduct;
		$this->product['name'] 			= $cProduct->name;
		$this->product['sku'] 			= ($cProduct->sku != '-')? $cProduct->sku : '';
		$this->product['description'] 	= $cProduct->description;
		$this->product['product_type'] 	= '';
		$this->product['slug'] 			= $cProduct->custom_url;
		$this->product['tags'] 			= '';
		$this->product['price'] 		= $cProduct->price;
		$this->product['bg_pid'] 		= $cProduct->id;	//bigcommerce product id
		
		//set brand name
		if($cProduct->brand_id != 0){
			$this->product['brand'] 		= $cProduct->id;
			$this->product['brand_id'] 		= $cProduct->brand_id;
		}
		
		if($cProduct->sale_price != 0){
			$this->product['sale_price'] = $cProduct->sale_price;
		}
		
		$product_Skus = $cProduct->skus();
		if(!empty($product_Skus)){
			$this->cproductSkus = $product_Skus;
		}
		
		$this->saveProductsInTable();
		$this->insertAllCategories();
		$this->getCategories();
		$this->getAvailableAttributes();
		$this->getVariations();
		$this->getImages();
	}
	
	//save product in table
	public function saveProductsInTable(){
		global $wpdb;
		$cProduct = $this->cProduct;
		$cProductArr = $this->objectToArray($cProduct); //convert object into array
		$productSaved = $wpdb->get_var( "SELECT id FROM `bigcommerce_products` where id={$cProduct->id}" );
		if(!$productSaved){
			$wpdb->insert(
				'bigcommerce_products',
				array(
					'id'	=> $cProduct->id,
					'data'  => serialize($cProductArr),
					'type'  => empty($cProduct->option_set)? 'simple' : 'variable'
				)
			);
		}
		else{
			$wpdb->update(
				'bigcommerce_products',
				array(
					'data'  => serialize($cProductArr),
					'type'  => empty($cProduct->option_set)? 'simple' : 'variable'
				),
				array('id' => $cProduct->id)
			);
		}
	}
	
	//change object to array
	function objectToArray($object) {
		$reflectionClass = new ReflectionClass(get_class($object));
		$array = array();
		foreach ($reflectionClass->getProperties() as $property) {
			$property->setAccessible(true);
			$array[$property->getName()] = $property->getValue($object);
			$property->setAccessible(false);
		}
		return $array;
	}
	
	/*
	 * Get all attributes for Variable products
	 */
	private function getAvailableAttributes(){
		$avaAttr = '';
		$cProduct = $this->cProduct;
		$currentOptions = $cProduct->option_set_options();
		
		//check values set then it has product variations
		if(!empty($currentOptions)){
			$this->currentOptions = $currentOptions;
			foreach($currentOptions as $p_options){
				if(is_array($p_options->values)){
					$avaAttr .= trim($p_options->display_name).',';
				}
			}
		}
		
		$avaAttr = rtrim($avaAttr,',');
		$this->product['available_attributes'] = $avaAttr;	
	}

	
	/*
	 * Get all Variations for Variable products
	 */
	private function getVariations(){
		$cProduct = $this->cProduct;
		//Simple Product
		if(empty($this->product['available_attributes'])){
			unset($this->product['available_attributes']);
			$this->product['qty'] 	 = ($cProduct->inventory_level > 0)? $cProduct->inventory_level : 0;
			$this->product['weight'] = $cProduct->weight;
			$this->product['width']  = $cProduct->width;
			$this->product['height'] = $cProduct->height;
			$this->product['depth']  = $cProduct->depth;
			$this->product['type'] 	 = 'simple';
		}
		else{
			//Variable Product
			
			//get All attributes
			$attributes = array();
			$attributesArray = array();
			foreach($this->currentOptions as $currentOption){
				
				foreach($currentOption->values as $p_variation){
					
					$varPrice = $cProduct->price;
					$varSalePrice = false;
					if($cProduct->sale_price != 0){
						$varSalePrice = $cProduct->sale_price;
					}
				
					//included for (Size,Material,Color)
					$attributesArray[$this->generate_attrSlug($currentOption->display_name)] = $p_variation->label;
					
					$attributes[] = array(
						'attributes' 	=> $attributesArray,
						'price'			=> $varPrice,
						'sale_price'	=> $varSalePrice,
						'weight'		=> $cProduct->weight,
						'sku'			=> $this->productSkuByVariationId($p_variation->option_value_id),
						'position'		=> $p_variation->sort_order,
						'manage_stock'	=> 'yes',
						'qty'			=> ($cProduct->inventory_level > 0)? $cProduct->inventory_level : 0,
						'unique_id'		=> $p_variation->option_value_id,
						'value'			=> $p_variation->value
					);
				}
				
			}
			
			/* $product['variations'] = array(
				array('attributes' => array('size' => 'Small','color'=>'Red'),'price'=>'8.00')
			); */
			
			$this->product['variations'] = $attributes;
			$this->product['type'] = 'variable';
		}
	}
	
	//get sku for variation by option_value_id
	public function productSkuByVariationId($oid){
		if(is_array($this->cproductSkus)){
			foreach($this->cproductSkus as $skuData){
				if($skuData->options[0]->option_value_id == $oid){
					return $skuData->sku;
				}
			}
		}
	}
	
	/**
	* Generate Attribute Slug
	*/
	public function generate_attrSlug($title){	
		return sanitize_title($title);
	}
	
	/**
	* Insert Categories if not exists
	*/
	public function insertAllCategories(){
		global $wpdb;
		
		//skip if already updated
		$bg_catUpdated = get_option('bigcommerce_categories_updated',true);
		if($bg_catUpdated == 'updated'){
			return;
		}
		
		//insert all categories first time.
		$allCategories = $this->allCategories = Bigcommerce::getCategories();
		// $this->pre($allCategories);
		
		foreach($allCategories as $category){
			
			$catExist = $wpdb->get_var( "SELECT id FROM `bigcommerce_categories` where id={$category->id}" );
			
			if(!$catExist){
				$wpdb->insert(
					'bigcommerce_categories',
					array(
						'id' => $category->id, 
						'parent_id' => $category->parent_id,
						'name' => $category->name,
						'description' => $category->description,
						'sort_order' => $category->sort_order,
						'is_visible' => $category->is_visible,
						'sku' => sanitize_title($category->name)
					)
				);
			}
			else{
				$wpdb->update( 
					'bigcommerce_categories',
					array(
						'parent_id' => $category->parent_id,
						'name' => $category->name,
						'description' => $category->description,
						'sort_order' => $category->sort_order,
						'is_visible' => $category->is_visible,
						'sku' => sanitize_title($category->name)
					),
					array('id' => $category->id)
				);
			}
		}
		
		//set bigcommerce categories updated
		update_option('bigcommerce_categories_updated','updated');
	}
	
	/*
	 * Get all Categories for products
	 */
	public function getCategories(){
		global $wpdb;
		$cProduct 	= $this->cProduct;
		$catIDs 	= implode(',',$cProduct->categories);
		$catresult 	= $wpdb->get_results ( "SELECT `name` FROM bigcommerce_categories where id IN ({$catIDs})",ARRAY_A );
		
		$allcats = array();
		foreach($catresult as $cat){
			$allcats[] = $cat['name'];
		}
		$this->product['categories']  = $allcats;
	}	
	
	/*
	 * Get all Images for products
	 */
	public function getImages(){
		$cProduct = $this->cProduct;
		$primary_image_id = $cProduct->primary_image->id;
		$this->product['image'] 	= $cProduct->primary_image->zoom_url;
		
		if(!empty($cProduct->images)){
			$productImages = array();
			
			$allImages = $cProduct->images();
			
			foreach($allImages as $imagesA){
				//remove first image because it is already set as featured images
				if($primary_image_id == $imagesA->id){ continue; }
				$productImages[] = $imagesA->zoom_url;
				
			}
			
			$this->product['images'] = !empty($productImages)? $productImages : '';
		}
		else{
			$this->product['images'] = '';
		}
	}	
	
	/*
	 * Get the last product from the array.
	 */
	public function getLastProduct(){
		return $this->product;
	}
	
	/*
	 * Get all Products function to call directly
	 */
	public function getAllProducts(){
		return $this->products;
	}

	/**
	 * Print Result
	 */
	function pre($var){
		echo '<pre>'.print_r($var,true).'</pre>';
	}
}
