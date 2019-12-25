<?php
/*
 * Plugin Name: Bigcommerce Import
 * Author: IPS
 * description: Import all Product from Bigcommerce store to Woocommerce
 * version: 1.0
*/

//Require Bigcommerce SDK for API call
require __DIR__.'/vendor/autoload.php';
use Bigcommerce\Api\Client as Bigcommerce;

		
Class BigcommerceImport{
	
	protected static $_instance = null;
	
	/*
	 * Instance for Singleton method
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}
	
	/*
	 * Autoload to run plugin code
	 */
	public function __construct() {
		$this->load_setting();
		$this->load_config();
		//Load required files
		$this->load_files();
		
		// add_action( 'init', array($this,'testsetset'), 6 );
		add_action( 'admin_init', array($this,'ajaxImportCode'), 6 );
		
		//load ajax to verify api is working or not
		add_action( 'wp_ajax_BGverifyAPI', array($this,'verifyAPI') );
		add_action( 'wp_ajax_bigcommerceProducts', array($this,'bigcommerce_total_products') );
		
		//on activate plugin create tables if not exists
		register_activation_hook( __FILE__, array($this,'create_plugin_bigcommerce_tables') );
	}

	/*
	 * @ Load Bigcommerce Config
	 */
	public function load_config(){
		$bigcommerceOptions =  get_option('bigcommerceimport_setting_options');
		if(is_array($bigcommerceOptions)){
			define('BIGCOMMERCE_APP_STORE_HASH', 	$bigcommerceOptions['store_hash']);
			define('BIGCOMMERCE_APP_CLIENT_ID', 	$bigcommerceOptions['client_id']);
			define('BIGCOMMERCE_APP_TOKEN', 		$bigcommerceOptions['auth_token']);
		}
	}
	
	/**
	 * Load Hook for Settings Page
	 */
	public function load_setting(){
		//Include Options page in admin
		include_once(plugin_dir_path( __FILE__ ).'/includes/admin/class.bigcommerce-settings.php');
		$BigcommerceSettingsPage = new BigcommerceSettingsPage();
	}
	
	/**
	 * Load Hook for all Files
	 */
	public function load_files(){
		//woocommmerce create and get products files
		include_once(plugin_dir_path( __FILE__ ).'/includes/classes/class.createProduct.php');
		include_once(plugin_dir_path( __FILE__ ).'/includes/classes/class.getProduct.php');
	}
	
	/*
	 * Bigcommerce API Config
	 */
	public function BGconfig(){
		/* Bigcommerce::configure(array(
			'store_hash' => 'sbcdi4vhgt',
			'client_id'	 => 'l7jl4v5ssdx29jwtjsrbpy4z8i8wxd9',
			'auth_token' => '5ukrwmlro1dr7dwqzd7emy5xu3dhfv7'
		)); */
		
		Bigcommerce::configure(array(
			'store_hash' => BIGCOMMERCE_APP_STORE_HASH,
			'client_id'	 => BIGCOMMERCE_APP_CLIENT_ID,
			'auth_token' => BIGCOMMERCE_APP_TOKEN
		));

		Bigcommerce::verifyPeer(false);
		Bigcommerce::failOnError(true);
	}
	
	/*
	 * Verify API details of the Bigcommerce
	 */
	public function verifyAPI(){
		$response = $this->totalnumber_products();
		echo $response; die;
	}
	
	/**
	 * Get Total number of products
	 */
	public function totalnumber_products(){
		$this->BGconfig();
		try{
			$totalCount = Bigcommerce::getProductsCount();
			$response = array('error'=>0,'total'=>$totalCount);
		}
		catch(Bigcommerce\Api\Error $e) {
			$response = array('error'=>1,'msg'=>$e->getMessage());
		}
		return json_encode($response);
	}
	
	/*
	 * Save total number of products into options (bigcommerce_total_products)
	 */
	public function bigcommerce_total_products(){
		$response = $this->totalnumber_products();
		$response = json_decode($response);
		if($response->error != 1){
			$total_prdcts = (isset($response->total))? $response->total : 0;
			update_option('bigcommerce_total_products',$total_prdcts);
		}
		echo $total_prdcts;
		die;
	}
	
	/**
	 * Load Plugin code
	 */
	public function ajaxImportCode(){

		if(isset($_REQUEST['importbigcommerce']) && $_REQUEST['importbigcommerce'] == 'in091' && isset($_REQUEST['pagenum'])){
			
			//load api config
			$this->BGconfig();
			
			$limit 	 = (isset($_REQUEST['limit']) && $_REQUEST['limit'] !=0)? $_REQUEST['limit'] : 1;
			$pagenum = ($_REQUEST['pagenum'] !=0)? $_REQUEST['pagenum'] : 1;
			$totalProducts 	= get_option('bigcommerce_total_products',true);
			$lastpage		= ($totalProducts!=0)? ($totalProducts/$limit)+1 : 0;
			$productIDs 	= array();
			
			if($totalProducts == 0){
				$errMsg = array('error'=>true,'msg'=>'No Products Found! If not verified API then verify now!');
				echo json_encode($errMsg); die;
			}
			
			//check if last page is current page then stop ajax execution
			if($lastpage == $pagenum){
				$response = array('success'=>'done');
				update_option('bigcommerce_categories_updated','pending'); //reset categories update
				echo json_encode($response); die;
			}

			try {
				/* $product = Bigcommerce::getProduct(279);
				$responseData = array($product); */
				$product = Bigcommerce::getProducts(array('page'=>$pagenum,'limit'=>$limit));
				$responseData = $product;
				
				$productClass = new getProductsfromJsonBG($responseData);
				$products = $productClass->getAllProducts();
				// $this->pre($products); die;
				
				foreach($products as $product_){
					$productsWOO = new createProductsWooBG($product_);
					$productIDs[] = $productsWOO->pID;
				}
				
				$pagenum++;
				
				$returnURL = add_query_arg(array('importbigcommerce' => 'in091','limit' => $limit,'pagenum' => $pagenum),get_admin_url());
				$response = array('success' => true, 'return_url' => $returnURL, 'pr_ids' => implode(',',$productIDs));
				echo json_encode($response);
			} 
			catch(Bigcommerce\Api\Error $error) {
				echo '<b>'.$error->getCode().'</b>: '.$error->getMessage();
			}	
			
			die;
		}
	}
	
	/*
	 * Create Table on activate plugin
	 */
	public function create_plugin_bigcommerce_tables(){
		global $wpdb;
		
		$bg_products 	= "bigcommerce_products";
		$bg_categories 	= "bigcommerce_categories";

		require_once( ABSPATH . '/wp-admin/includes/upgrade.php' );
		
		#Check to see if the table exists already, if not, then create it
		if($wpdb->get_var( "show tables like '$bg_products'" ) != $bg_products) {
			
			$sql = "CREATE TABLE IF NOT EXISTS `{$bg_products}` (
				`id` int(11) NOT NULL,
				`data` text NOT NULL,
				`type` varchar(10) NOT NULL,
				PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=latin1;";
			
			dbDelta($sql);
		}
		
		if($wpdb->get_var( "show tables like '$bg_categories'" ) != $bg_categories) {
			
			$sql = "CREATE TABLE IF NOT EXISTS `{$bg_categories}` (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`parent_id` int(11) NOT NULL,
				`name` varchar(100) NOT NULL,
				`description` text NOT NULL,
				`sort_order` int(11) NOT NULL,
				`is_visible` int(11) NOT NULL,
				`sku` varchar(100) NOT NULL,
				PRIMARY KEY (`id`)
			) ENGINE=InnoDB  DEFAULT CHARSET=latin1";
			
			dbDelta($sql);
		}
	}
	
	/*
	 * Test function to print data
	 */
	public function testsetset(){
		$query = new WP_Query( array( 'post_type' => 'product_variation') );
		if ( $query->have_posts() ) :
			while ( $query->have_posts() ) : $query->the_post(); 
				global $post;
				the_title();
				// $vunique_id = get_post_meta($post->ID,'attribute_pa_size',true);
				$vunique_id = '11.5M';
				update_post_meta($post->ID, '_unique_id', $vunique_id);
			endwhile; 
			wp_reset_postdata();
		endif;
		
		exit;
	}
	
	
	/**
	 * Print Result
	 */
	function pre($var){
		echo '<pre>'.print_r($var,true).'</pre>';
	}
	
}

/*
 * Function to load in all files.
 */
function BigcommerceImportfunc() {
	return BigcommerceImport::instance();
}

// Global for backwards compatibility.
$GLOBALS['bigcommerce_import'] = BigcommerceImportfunc();