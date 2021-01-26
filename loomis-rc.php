<?php
/*
Plugin Name: Loomis Rate Calculator
Description: Rate shipments via the Loomis rate calculator
Version:	 1.0.4
Author:	  Loomis Express
Author URI:  http://www.loomis-express.com
License:	 GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

TODO: Fail over rates
TODO: Ship to available countries (use the service selection for the time being)
TODO: Max box dimensions per box (like max weight)
TODO: Allow DG shipping. Custom field? Shipping class? Attribute?

Note: When modifying this plugin, please modify the $this->version string (defined in __construct)
		with an indicator that it was modified. For example "1.0.0 (Custom)".
		This will assist any future technical support providers, by letting them know if this is vanilla.
*/

//Block direct access to the plugin
defined('ABSPATH') or die();

define('LOOMIS_RC_RATING_URL', 'https://webservice.loomis-express.com/LShip/services/USSRatingService?wsdl');

/**
 * Check if WooCommerce is active
 */
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	
	/**
	 *
	 */
	function loomis_rate_calculator_init() {
		if ( ! class_exists( 'WC_Loomis_Rate_Calculator' ) ) {
			class WC_Loomis_Rate_Calculator extends WC_Shipping_Method {
				/**
				* Constructor for Loomis Rate Calculator class
				*
				* @access public
				* @return void
				*/
				public function __construct() {
					$this->id				 		= 'loomis_rate_calculator'; // Id for your shipping method. Should be unique.
					$this->method_title	   	= __( 'Loomis Rate Calculator' );  // Title shown in admin
					$this->method_description 	= __( 'Calculate shipping rates using the Loomis rate calculator' ); // Description shown in admin
					$this->title			  		= "Loomis";
					$this->version					= "1.0.4";
					
					$this->init();
				}
 
				/**
				* Init the Loomis Rate Calculator settings
				*
				* @access public
				* @return void
				*/
				function init() {
					// Load the settings API
					$this->init_settings(); // This is part of the settings API. Loads settings you previously init.
					
					
					// Define the defaults - note, this is done here instead of init_form_fields, in case this plugin gets updated with new settings defaults, and the user does not access the administrative area.
					if (count($this->settings) > 0) {
						$this->settings['origin_postal_code'] = preg_replace("/[^A-Za-z0-9]/", '', $this->settings['origin_postal_code']);
						
						if ( (float) $this->settings['default_weight'] <= 0)
						{$this->settings['default_weight'] = 1;}
						
						if ( (float) $this->settings['maximum_weight'] <= 0)
						{
							if (substr(get_option('woocommerce_weight_unit'), 0, 1) == "L") {
								$this->settings['maximum_weight'] = 50;
							}
							else {
								$this->settings['maximum_weight'] = 20;
							}
						}
						
						if ((int) $this->settings['lead_time'] < 0)
						{$this->settings['lead_time'] = 0;}
						
						$this->settings['handling_fee_amount'] = (float) $this->settings['handling_fee_amount'];
						
						if ( ! in_array($this->settings['debug'], array('yes', 'no')))
						{$this->settings['debug'] = 'yes';}
					}
					
					if ( ! isset($this->settings['service_prefix']))
					{$this->settings['service_prefix'] = "Loomis";}

					
					// Set the shipping method names
					$this->services = array(
						'DD' => trim($this->settings['service_prefix'] . " Ground"),
						'DE' => trim($this->settings['service_prefix'] . " Express 18:00"),
						'DN' => trim($this->settings['service_prefix'] . " Express 12:00"),
						'D9' => trim($this->settings['service_prefix'] . " Express 9:00")
					);
					
					// Output the forms for the settings
					$this->init_form_fields(); // This is part of the settings API. Override the method to add your own settings
					
					// Save settings in admin
					add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
				}
				
				/**
				* Initialise Loomis Rate Calculator Settings Form Fields
				* 
				* @access public
				* @return void
				*/
				function init_form_fields() {
					$this->settings['rating_url'] = 'https://webservice.loomis-express.com/LShip/services/USSRatingService?wsdl';
					
					$this->form_fields = array(
						'enabled' => array(
							'title' => 'Enabled',
							'type' => 'select',
							'description' => 'Enable the Loomis shipping method.',
							'default' => 'no',
							'options' => array(
								'yes'=>"Yes", 'no' => 'No'	
							)
						),
						'user_id' => array(
							'title' => 'API User ID',
							'type' => 'text',
							'description' => 'The user ID (email address) for the web services communication.<br />This is the same login you use for logging into www.loomis-express.com',
							'default' => ''
						),
						'password' => array(
							'title' => 'API Password',
							'type' => 'text',
							'description' => 'The password for the web services communication.',
							'default' => ''
						),
						'shipper_num' => array(
							'title' => 'Loomis Account Number',
							'type' => 'text',
							'default' => ''
						),
						'origin_postal_code' => array(
							'title' => 'Origin Postal Code',
							'type' => 'text',
							'description' => 'Enter the postal code that the shipments will be sent from.',
							'default' => ''
						),
						'origin_province' => array(
							'title' => 'Origin Province',
							'type' => 'select',
							'description' => 'Select the province that the shipments will be sent from.',
							'default' => '',
							'options' => array(
								"AB" => "Alberta",
								"BC" => "British Columbia",
								"MB" => "Manitoba",
								"NB" => "New Brunswick",
								"NL" => "Newfoundland and Labrador",
								"NT" => "Northwest Territories",
								"NS" => "Nova Scotia",
								"NU" => "Nunavut",
								"ON" => "Ontario",
								"PE" => "Prince Edward Island",
								"QC" => "Quebec",
								"SK" => "Saskatchewan",
								"YT" => "Yukon"
							)
						),
						'services' => array(
							'title' => 'Services',
							'type' => 'multiselect',
							'description' => 'Select the services that you would like to allow. Hold ctrl to select multiple services.<br />Warning: International service (outside of Canada) is not currently supported. Ensure that you have another shipping method available for non-domestic shipments. Otherwise your international customers will not get a rate calculation.',
							'default' => array('DD', 'DE', 'DN', 'D9'),
							'css' => 'height: ' . (count($this->services) + 2) . 'em;',
							'options' => $this->services
						),
						'maximum_weight' => array(
							'title' => 'Maximum Weight/Box ('.get_option('woocommerce_weight_unit').')',
							'type' => 'text',
							'description' => 'The maximum weight your shipping boxes can hold. This is so the rate calculator can determine how many packages will be shipped.<br />For example, if this value is set to "20", and your customer\'s order weighs "50", then it will be calculated as a 3 piece shipment.<br />Maximum is 150lbs / 68kgs.<br />Note that this also includes dimensional weight.',
							'default' => '50'
						),
						'default_weight' => array(
							'title' => 'Default Weight ('.get_option('woocommerce_weight_unit').')',
							'type' => 'text',
							'description' => 'If a product does not have the weight value defined, what weight would you like to default to?',
							'default' => '1'
						),
						'handling_fee_amount' => array(
							'title' => 'Handling Fee Amount',
							'type' => 'text',
							'description' => 'The amount of handling to apply to each order.',
							'default' => '0'
						),
						'handling_fee_type' => array(
							'title' => 'Handling Fee Type',
							'type' => 'select',
							'description' => 'Select the method to apply the handling fee to the shipping charge. Set the handling fee amount (below) to 0 if you do not want handling applied.',
							'default' => '%',
							'options' => array(
								'%' => '%', '$' => '$'
							)
						),
						'service_prefix' => array(
							'title' => 'Service Name Prefix',
							'type' => 'text',
							'description' => 'The prefix for the service names. For example, if you enter "Loomis", then the shipping services your customer will see will be "Loomis Ground", etc. This can be set to blank as well.',
							'default' => 'Loomis'
						),
						'display_eta' => array(
							'title' => 'Display Expected Delivery Date',
							'type' => 'select',
							'description' => 'Enable this to display the expected delivery date for each shipping method.<br />Note: this only displays for domestic addresses.',
							'default' => 'yes',
							'options' => array(
								'yes' => 'Yes', 'no' => 'No'
							)
						),
						'lead_time' => array(
							'title' => 'Lead Time for Order Processing',
							'type' => 'text',
							'description' => 'Enter the number of days to push the expected delivery date back, for if you do not ship out your orders on the day they are received.',
							'default' => '1'
						),
						/*
						// This option currently does nothing
						'enable_dg' => array(
							'title' => 'Enable DG',
							'type' => 'select',
							'description' => 'Enable this to add a Dangerous Goods charge on any product with an Attribute of "DG" with a value of "yes". Contact Loomis for more information if required.',
							'default' => 'yes',
							'options' => array(
								'yes'=>"Yes", 'no' => 'No'
							)
						),
						*/
						'enable_dv' => array(
							'title' => 'Enable DV',
							'type' => 'select',
							'description' => 'Enable this to include the Declared Value of the products in the rate calculation. The DV value will be set to the total value of the products in the shopping cart.<br />DV represents the liability value if a package is lost or damaged.',
							'default' => 'yes',
							'options' => array(
								'yes'=>"Yes", 'no' => 'No'
							)
						),
						/*
						* This is getting commented out, because the soap_connect() function has the endpoint URL hard coded (so it does not make sense to make the WSDL an option)
						* The WSDL URL ($this->rating_url) is hard coded at the top of the init_form_fields() function.
						'rating_url' => array(
							'title' => 'Rating URL',
							'type' => 'text',
							'description' => 'The WS URL for communication via the rating WS. This does not need to be modified.<br />Default: https://webservice.loomis-express.com/LShip/services/USSRatingService?wsdl',
							'default' => 'https://webservice.loomis-express.com/LShip/services/USSRatingService?wsdl'
						),
						*/
						
						'debug' => array(
							'title' => 'Debug Mode',
							'type' => 'select',
							'description' => 'Enable this to log all rating communication with Loomis Express, as well as display errors on the screen <i>when you are logged in as the wordpress administrator</i>.<br />Warning: this will create log files for every shipment calculation, which may accumulate large file sizes over time, and should thus be left disabled.',
							'default' => 'no',
							'options' => array(
								'yes'=>"Yes", 'no' => 'No'	
							)
						),
						'output_logs' => array(
							'title' => 'Output Logs',
							'type' => 'select',
							'description' => 'Enable this to output log data at the top of the View Cart page. This will only be displayed to users who are logged in as admins, and your customers will not be able to view this.<br />Note 1: This needs to be enabled alongside debug mode.<br />Note 2: Rating data gets cached, and only updates when the plugin settings change, or when the shopping cart changes. If debug data is not being displayed, just click "save" at the bottom of this settings page to force a rate refresh.',
							'default' => 'no',
							'options' => array(
								'yes'=>"Yes", 'no' => 'No'	
							)
						),
					);
				}
				
				
				/**
				* Initialise Loomis Rate Calculator Settings Form Fields
				* 
				* @access public
				* @return void
				*/
				function admin_options() {
					?>
					<h2><?php _e('Loomis Rate Calculator','woocommerce'); ?></h2>
					<table class="form-table">
					<?php $this->generate_settings_html(); ?>
					</table> <?php
				}
 
				/**
				* calculate_shipping function.
				*
				* @access public
				*
				* @param mixed $package
				*
				* @return void
				*/
				public function calculate_shipping( $package = array() ) {
					// Only calculate the shipping if the "enabled" option is true
					if ($this->settings['enabled'] == "no") {
						return;
					}

					// Exit when add_to_cart action invoked, to reduce request times
					if (isset($_REQUEST['wc-ajax']) && $_REQUEST['wc-ajax'] === 'add_to_cart') {
					  return;
					}

					// Prep logging
					$load_time_start = microtime(true);
					$soap_log_name = date('His') . "-RatingSOAP";
					
					//Output the log if needed.
					$output_log = false;
					if ($this->settings['debug'] == "yes" && $this->settings['output_logs'] == "yes" && current_user_can('edit_plugins') === true) {
						$output_log = true;
						?>
						<div class="woocommerce-message">
							<b>Loomis rate Calculator Log File Output:</b><br />
							<a href="#" onclick="document.getElementById('loomis_output_logs_display').style.display='inline'; return false;">
								&bull; Click here to display the log data and error messages
							</a>
							<br />
							<a href="#" onclick="var e = document.getElementById('loomis_output_logs_textbox'); e.innerHTML = document.getElementById('loomis_output_logs_display').textContent.trim(); e.style.display='inline'; e.select(); return false;">
								&bull; Click here to select the log file contents (for copying)
							</a>
							<br /><br />
							<span style="font-size: 0.8em;">
							You are seeing this because the <b>Output Logs</b> setting is enabled in your Loomis Rate Calculator for WooCommerce settings.
						</span>
						</div>
						<textarea id="loomis_output_logs_textbox" style="width: 100%; display: none;" onfocus="this.select();"></textarea>
						<div id="loomis_output_logs_display" style="display: none;">
						<?php
					}
					
					// Output the plugin settings
					$this->loomis_log($soap_log_name, "Plugin version:\n" . print_r($this->version, 1), "append");
					$this->loomis_log($soap_log_name, "Plugin settings:\n" . print_r($this->settings, 1), "append");
					$this->loomis_log($soap_log_name, "Woo Weight Unit:\n" . get_option('woocommerce_weight_unit'), "append");
					$this->loomis_log($soap_log_name, "Woo Dimension Unit:\n" . get_option('woocommerce_dimension_unit'), "append");

					
					// Make sure the Measurement units are in kg/lb and cm/in
					if ( ! in_array(strtolower(get_option('woocommerce_weight_unit')), array("kg", "lb", "lbs", "kgs")) || ! in_array(strtolower(get_option('woocommerce_dimension_unit')), array("cm", "in", "cms", "ins")) ){
						$this->loomis_log($soap_log_name, "Error: Weight Unit and/or Dimension unit are not kg/lb and cm/in. They are:\n" . get_option('woocommerce_weight_unit') . " / " . get_option('woocommerce_dimension_unit'), "append");
						$this->output_error("Error: Weight Unit and/or Dimension unit are not kg/lb and cm/in. Ensure they are set in the WooCommerce Settings, under the Products tab.", __LINE__);
						
						if ($output_log == true)  // Close the log catching div (do this whenever ending this method)
						{print '</div>';} 
						
						return;
					}
					
					
					// Begin the counter to how many rate methods are being displayed.
					$rates_displayed = 0;
					
					// Filter the postal code 
					$package['destination']['postcode'] = preg_replace("/[^A-Za-z0-9]/", '', $package['destination']['postcode']);
					//Define the shipping date
					$shipping_date = $this->get_shipping_date();

					// Get the available services
					$request = array(
						'delivery_country' => $package['destination']['country'],
						'delivery_postal_code' => $package['destination']['postcode'],
						'password' => $this->settings['password'],
						'pickup_postal_code' => $this->settings['origin_postal_code'],
						'shipper_num' => $this->settings['shipper_num'],
						'shipping_date' => $shipping_date,
						'user_id' => $this->settings['user_id']
					);

					$available_services = get_transient(md5('loomisrcservices' . json_encode($request)));

					if (empty($available_services)) {
					    if (!isset($this->loomis)) {
					        // Connect to the SOAP client
					        $this->loomis = $this->soap_connect(LOOMIS_RC_RATING_URL);
					    }
                        // Execute the request
                        $available_services = $this->loomis->getAvailableServices(array('request'=>$request));

                        // Log the request
                        $this->loomis_log($soap_log_name, "getAvailableServices Request:\n" . $this->loomis->__getLastRequest(), "append");
                        $this->loomis_log($soap_log_name, "getAvailableServices Response:\n" . $this->loomis->__getLastResponse(), "append");

                        //Check for errors
                        $error = $this->get_error($available_services);
                        if ($error != "") {
                            $this->output_error($error, __LINE__);
                            return;
                        }

                        $available_services = $available_services->return->getAvailableServicesResult;
					}
					
					if (is_null($available_services))
					{$available_services = array();} // Just make it a blank array to avoid errors about "invalid argument" for the following foreach()
					
					// Get the rate for each service
					foreach ($available_services AS $service) {
						// Determine if this service is allowed
						if ( ! in_array($service->type, $this->settings['services']) )
						{continue;}

						// Get the rate
						$pieces = $this->generate_pieces($package, $service->type);
						$request = $this->build_shipment_request($package, $pieces['packages'], $service->type, $shipping_date, $pieces['units']);

						$rate = get_transient(md5('loomisrc' . json_encode($request)));

						if (is_object($rate)) {
						     $rate = json_decode(json_encode($rate), true);
						}

						if (empty($rate)) {
						    if (!isset($this->loomis)) {
						        // Connect to the SOAP client
					            $this->loomis = $this->soap_connect(LOOMIS_RC_RATING_URL);
						    }
                            $rate_response = $this->loomis->rateShipment(array('request'=>$request));
						
                            // Log the request
                            $this->loomis_log($soap_log_name, "rateShipment Request - Service Type: {$this->services[$service->type]}\n" . $this->loomis->__getLastRequest(), "append");
                            $this->loomis_log($soap_log_name, "rateShipment Response - Service Type: {$this->services[$service->type]}\n" . $this->loomis->__getLastResponse(), "append");

                            // Check for errors, and stop processing if there are any found
                            $error = $this->get_error($rate_response);

                            if ($error != "") {
                                $this->output_error("Service: {$this->services[$service->type]} - $error", __LINE__);
                                continue;
                            }

                            $rate = $rate_response->return->processShipmentResult->shipment;


                            // Convert the rate object to an array for easy cycling
                            $rate = json_decode(json_encode($rate), true);
                            set_transient(md5('loomisrc' . json_encode($request)), $rate, WEEK_IN_SECONDS);
						}
						
						// Determine the label name (service type name, and the ETA)
						$label = $this->services[$service->type];
						
						if ($this->settings['display_eta'] == "yes" && (int) $rate['transit_time'] > 0) {
							// Add any lead time to the transit time
							$transit_days = $rate['transit_time'] + $this->settings['lead_time'];
							
							$label .= " - " . date('M d', strtotime("+" . $transit_days . " weekdays"));
						}
						
						// Calculate the totals
						$total_charge = 0;
						
						// Loop through the "shipment_info_num" to find the TOTAL_CHARGE
						foreach ($rate['shipment_info_num'] AS $shipment_info_num) {
							if ( $shipment_info_num['name'] == 'TOTAL_CHARGE' ) {
								$total_charge += (float) $shipment_info_num['value'];
							}
						}
						
						// Add the rate to the available service methods
						$taxes = array();
						foreach (array('tax_charge_1', 'tax_charge_2') as $index)
						{
							$tax = (float) $rate[$index];
							if ($tax > 0)
							{$taxes[] = $tax;}
						}
						
						// Now remove the taxes from the shipping charge, because the taxes are a seperate charge (calc_tax => per_order in the add_rate() method)
						$total_charge -= $tax;
						
						// Add handling to the charge (not the taxes)
						if ($this->settings['handling_fee_amount'] != 0)
						{
							$log_total = $total_charge; //This is only used to update the log file with the original charge before handling
							
							if ($this->settings['handling_fee_type'] == "$")
							{$total_charge += (float) $this->settings['handling_fee_amount'];}
							
							if ($this->settings['handling_fee_type'] == "%") {
								//Convert to a percent
								$handling = (float) $this->settings['handling_fee_amount'];
								$handling /= 100;
								$handling += 1;
								
								$total_charge *= $handling;
							}
							
							$this->loomis_log($soap_log_name, "Handling charge of {$this->settings['handling_fee_amount']} ({$this->settings['handling_fee_type']}) applied. From \${$log_total} to \${$total_charge}", "append");
						}
						
						$rate_output = array(
							'id' => $rate['service_type'],
							'label' => $label,
							'cost' => $total_charge,
							'taxes' => $taxes,
							'calc_tax' => 'per_order'
						);
						
						$this->loomis_log($soap_log_name, "Rate output for {$this->services[$service->type]}:\n" . print_r($rate_output, 1), "append");
						
						// Register the rate
						$this->add_rate( $rate_output );
						$rates_displayed ++;
					}
					
					//Output the time it took to load the page
					$load_time = microtime(true) - $load_time_start;
					$this->loomis_log($soap_log_name, "Rates generated in:\n" . round($load_time, 4) . " seconds", "append");
					
					//Determine if any rates displayed
					if ($rates_displayed == 0) {
						$this->loomis_log($soap_log_name, "Warning: No rating methods were displayed", "append");
						$this->output_error("Warning: No rating methods were returned for Loomis Express shipping. If you are expecting to see Loomis shipping methods for the shipping destination, make sure there are no other errors, and that the appropriate services are selected in the <b>services</b> section of the Loomis Rate Calculator settings.", __LINE__);
					}
					
					//Close the log file output div
					if ($output_log == true) {
						print '</div>';
					}
				}
				
				
				/**
				 * Build the <shipment> object for the WS request
				 *
				 * @param mixed $package Package object passed from woo commerce
				 * @param array $pieces Array of the pieces
				 * @param string $service Service ID
				 * @param string $shipping_date Date for the shipment to go out (affected by lead time setting)
				 * @param array $units Pass an array with "dim" and "wgt" for the dim and wgt units (in/cm and lb/kg)
				 *
				 * @access public
				 * @return mixed The array to feed into the SOAP request for rating a shipment
				 */
				function build_shipment_request ( $package, $pieces, $service, $shipping_date, $units = array('dim'=>'', 'wgt'=>'') ) {					
					// Build the packages
					// Note: this is now passed as $pieces parameter

					// Build the shipment
					$shipment = array(
						'packages' => $pieces,
						'delivery_address_line_1' => 'WOO FILLER',
						'delivery_city' => 'WOO FILLER',
						'delivery_country' => $package['destination']['country'],
						'delivery_name' => 'WOO FILLER',
						'delivery_postal_code' => $package['destination']['postcode'],
						'delivery_province' => $package['destination']['state'],
						
						'pickup_address_line_1' => 'WOO FILLER',
						'pickup_city' => 'WOO FILLER',
						'pickup_country' => "CA",
						'pickup_name' => 'WOO FILLER',
						'pickup_postal_code' => $this->settings['origin_postal_code'],
						'pickup_province' => $this->settings['origin_province'],
					
						'dimension_unit' => (in_array($units['dim'], array('C', 'I'))) ? $units['wgt'] : get_option('woocommerce_dimension_unit'),
						'reported_weight_unit' => (in_array($units['wgt'], array('K', 'L'))) ? $units['wgt'] : get_option('woocommerce_weight_unit'),
						
						'service_type' => $service,
						'shipper_num' => $this->settings['shipper_num'],
						'shipping_date' => $shipping_date,
						
						'shipment_info_num' => array(
							array(
								'name' => 'DECLARED_VALUE',
								'value' => ($this->settings['enable_dv'] == "yes") ? round(WC()->cart->cart_contents_total, 2) : 0
							)
						)
					);

					// Build the request
					$request = array(
						'password' => $this->settings['password'],
						'user_id' => $this->settings['user_id'],
						
						'shipment' => $shipment
					);
					return $request;
				}
				
				/**
				 * Calculate the number of pieces in the shipment, based on the weight and dimensions
				 *
				 * @param mixed $package
				 * @param string $service Service code for the shipment
				 *
				 * @access public
				 * @return array
				 */
				function generate_pieces ( $package, $service ) {
					$total_weight = 0;
					$total_xc_weight = 0; // Note that "XC" ("eXtra Care") is the same as "Non Standard" - it just means a dimension is too large
					$units = array('dim'=>'', 'wgt'=>'');

					// Prep to log
					if ( ! isset($this->gen_pcs_log) )
					{$this->gen_pcs_log = date('His') . "-generate_pieces";}
					$gen_pcs_log = $this->gen_pcs_log;
					$this->loomis_log($gen_pcs_log, "Generate Pieces for service: {$this->services[$service]}", "append");

					/*
					 * Get the weight of each product.
					 * Use the web services to calculate the dimensional weight,
					 * as well as the appropriate billed weight and XC
					 */
					
					$weights = array();
					$i = 1;
					foreach ($package['contents'] AS $product_data) {
					    /** @var WC_Product $product */
						$product = $product_data['data'];
						
						// Log
						$this->loomis_log($gen_pcs_log, "Calculating product {$i}: {$product->get_title()} (Product ID: {$product_data['product_id']})", "append");

						// Get the dimensions
						/** @var WC_Product $product */
						$length = (float) $product->get_length();
						$width = (float) $product->get_width();
						$height = (float) $product->get_height();
						$weight = (float) $product->get_weight();
						$quantity = (int) $product_data['quantity'];
						
						// Make sure the weight is defined
						if ($weight == 0)
						{$weight = (float) $this->settings['default_weight'];}

						/* Determine the dim weight and unit conversion via a web service call */
						
						// Check if a value for these same dims and weight has already been determined for this service
						if (isset($weights["{$weight}-{$length}-{$width}-{$height}"])){
							$billed_weight = $weights["{$weight}-{$length}-{$width}-{$height}"];
							
							$this->loomis_log($gen_pcs_log, "The weight was determined from the cached value. No WS call was made for this product.", "append");
						}
						// Check if the units are set and the same as the WooCommerce settings, as well as if dims are 0 (if this is the case, then no WS call is required)
						elseif ( substr(get_option('woocommerce_weight_unit'), 0, 1) == $units['wgt'] && ($length == 0 || $width == 0 || $height == 0) ) { 
							$billed_weight = $weight;
							
							$this->loomis_log($gen_pcs_log, "Dimensions were not set, and the calculated weight unit was already determined, so no WS call was required", "append");
						}
						else { //Make the WS call to calculate the dim weight, determine the weight unit, and convert the weight if needed
							// Build packages
							$pieces = array(
								'package_info_num' => array(
									array('name' => 'HEIGHT', 'value' => $height),
									array('name' => 'WIDTH', 'value' => $width),
									array('name' => 'LENGTH', 'value' => $length)
								),
								'reported_weight' => $weight
							);

							// Make the request
							$request = $this->build_shipment_request($package, $pieces, $service, $this->get_shipping_date());
							$rate = get_transient(md5('loomisrcrate' . json_encode($request)));
							if (empty($rate)) {
                                if (!isset($this->loomis)) {
                                    $this->loomis = $this->soap_connect(LOOMIS_RC_RATING_URL);
                                }
    							$rate = $this->loomis->rateShipment(array('request'=>$request));
                                set_transient(md5('loomisrcrate' . json_encode($request)), $rate, WEEK_IN_SECONDS);
							}

							// Check for errors
							$error = $this->get_error($rate);
							if ($error != "") {
								$this->output_error("Service: {$this->services[$service]} - $error", __LINE__);
							}

							// Determine the weight and units (units will always return the same after every request)
							if ($error == "") {
								$rate = $rate->return->processShipmentResult->shipment;
								$billed_weight = $rate->billed_weight;
								$units['wgt'] = $rate->billed_weight_unit;
								$units['dim'] = $rate->dimension_unit;
								$weights["{$weight}-{$length}-{$width}-{$height}"] = $billed_weight; // Cache this so a WS call does not need to be made again
							}
							else {
								$billed_weight = $weight;
							}
						}
						
						// Add the weight of the product to the total weight
						$total_weight += $quantity * $billed_weight;
						
						// If the dimensions made this an XC piece, then add that
						foreach ( $rate->packages[0] AS $tag=>$response ) {
							// We only want the data in the package_info_str object
							if ( $tag != "package_info_str" ) {
								continue;
							}
							
							// We are only looking for the NON_STANDARD value
							if ( $response->name != "NON_STANDARD" ) {
								continue;
							}
							
							if ( $response->value === true) {
								$total_xc_weight += $quantity * $billed_weight;
							}
						}

						// Log the product
						$log_output = array();
						$log_output[] = "Product {$i}: {$product->get_title()} (Product ID: {$product_data['product_id']})";
						$log_output[] = ((float) $product->get_weight() == 0) ? "Weight: {$weight} (defaulted from 0)" : "Weight: {$weight}";
						$log_output[] = "Dims: {$length} x {$width} x {$height}";
						//$log_output[] = "Extra Care (XC): {$rate->packages[0]->xc}";
						$log_output[] = "Price (subtotal): " . $product_data['line_subtotal'];
						$log_output[] = "Calculated weight: {$billed_weight} {$units['wgt']}";
						$log_output[] = "Quantity: {$quantity}";
						$this->loomis_log($gen_pcs_log, implode("\n", $log_output), "append");
						$i ++;
					}

					// If the above population of $total_weight fails (eg WS fails), set it to the cart weight as a fall back
					if ($total_weight == 0) {
						$total_weight = WC()->cart->cart_contents_weight;
					}

					// Determine how many pieces are in the shipment
					// First, does the weight need to be converted to conform with the Loomis rate calculator settings?
					$units['wgt'] = strtoupper($units['wgt']); // Convert to upper case
					if ( strtoupper(substr(get_option('woocommerce_weight_unit'), 0, 1)) != $units['wgt'] ) {
						$converted_weight = $total_weight;
						
						// Convert to lbs
						if ($units['wgt'] == "K") {
							$converted_weight *= 2.2;
							$total_xc_weight *= 2.2;
						}
						
						// Convert to kgs
						if ($units['wgt'] == "L") {
							$converted_weight /= 2.2;
							$total_xc_weight /= 2.2;
						}
						
						//Determine the number of pieces
						$total_pieces = ceil($converted_weight / $this->settings['maximum_weight']);
						
						// Get the ratio of how much "weight" is XC
						$xc_ratio = $total_xc_weight / $converted_weight; 
						
						//Log
						$this->loomis_log($gen_pcs_log, "Total weight of {$total_weight} was converted to {$converted_weight} for determining the number of pieces", "append");
					}
					else { // If it does not need converted
						// Get the number of pieces
						$total_pieces = ceil($total_weight / $this->settings['maximum_weight']);
						
						// Get the ratio of how much "weight" is XC
						$xc_ratio = $total_xc_weight / $total_weight; 
					}
					
					// Determine the number of XC pieces (based on weight ratio)
					if ($total_xc_weight > 0) {
						$xc_pieces = ceil($total_pieces * $xc_ratio); // use that weight ratio to determine how many pieces are XC (rounded)
					}
               
					// Generate the pieces
					$packages = array();
					for ($i=0; $i<$total_pieces; $i++) {
						$packages[$i] = array(
							'package_info_num' => array(

							),
							'reported_weight' => round(($total_weight / $total_pieces), 1)
						);
						
						// Add the XC piece if required
						if (isset($xc_pieces) && $i < $xc_pieces) {
							$packages[$i]['package_info_str'] = array(
								array('name' => 'NON_STANDARD', 'value' => true)
							);
						}
					}
					
					//Output the values
					$output = array("packages"=>$packages, "units"=>$units);
					$this->loomis_log($gen_pcs_log, "Final piece values for service {$this->services[$service]}:\n" . print_r($output, 1), "append");
					return $output;
				}

				/**
				 * Retrieve the error value, if it exists
				 *
				 * @param mixed $response The WS response to check.
				 *
				 * @access public
				 * @return string
				 */
				function get_error ( $response ) {
					//Check if a response was successfully returned
					if ( ! property_exists($response, "return") ) {
						return "No valid web service request was returned. Check the log files to make sure a request was sent, and a response recieved.<br /><br />Make sure the Rating URL value in the Loomis Rate Calculator settings is set correctly.";
					}
					
					//Check if the error is stored in <errors> instead of <error>
					if (property_exists($response->return, "errors"))
					{$error = $response->return->errors;}
					else
					{$error = $response->return->error;}

					$error = explode("|", $error); // Split English and French. Only En will return

					return isset($error[1]) ? $error[1] : '';
				}

				/**
				 * Write a log file with the soap requests
				 *
				 * @param string $name The name of the log file to write to.
				 * @param string $output The text to be put into the log.
				 * @param string $append Whether or not to append to the file, or create a new file. values "new" and "append"
				 *
				 * @access public
				 * @return void
				 */
				function loomis_log ( $name, $output, $append="new" ) {
					// Only write to the log file if the plugin's debug is enabled
					if ($this->settings['debug'] == "no") {
						return;
					}
					
					// Make the log directory if it doesn't exist
					$dir = realpath(dirname(__FILE__, 3)) . "/uploads/loomis-logs";
					
					if ( !file_exists($dir) ) {
						mkdir($dir, 0744);
					}
					
					/*
					 * Always ensure the logs are not visible.
					 * A user may change dir permissions, or clear the log directory (including the .htaccess).
					 * Thus, make sure that any sensitive information written to the logs is hidden
					 */
					if ( ! file_exists("{$dir}/.htaccess") ) {
						file_put_contents("{$dir}/.htaccess", "Order deny,allow\nDeny from all");
					}
					
					// Determine the file name. If the file is to be appended, then do not include the time.
					if ($append == "new") {
						$file_name = date('Ymd-His') . "-{$name}.log";
					}
					else {
						$file_name = date('Ymd') . "-{$name}.log";
					}
					
					// Put the timestamp at the beginning of the output, if required
					if ( file_exists($file_name) || $append == "append" ) {
						$output = "==========\n" . date('F d, Y - H:i:s') . "\n==========\n{$output}\n\n";
					}
					
					// Output
					file_put_contents($dir . '/' . $file_name, $output, FILE_APPEND);
					
					// Output log files to the page
					if ($this->settings['output_logs'] == "yes" && ! is_admin()) // Don't show the logs in an admin page
					{
						// Only output the logs to admins. Note that logs contain sensitive login information for the Loomis API.
						if (current_user_can('edit_plugins') === true)
						{
							print "<pre><b>{$file_name}</b>\n" . htmlentities($output) . '</pre>';
						}
					}
				}

				/**
				 * Get the shipping date, with the lead time, in the correct format
				 *
				 * @access public
				 * @return string
				 */
				function get_shipping_date () {
					// Get the current date, plus lead time specified
					$time = strtotime("+" . ($this->settings['lead_time']) . " weekdays");
					// Return the date in the correct format
					//return date('Y-m-d\T00:00:00.000\Z', $time);
					return date('Ymd', $time);
				}
				
				/**
				 * Output an error on screen
				 *
				 * @param string $error Error message
				 * @param string $line Line number
				 *
				 * @access public
				 * @return void
				 */
				function output_error ( $error, $line ) {
					// Only output the error if the plugin's debug is enabled
					if ($this->settings['debug'] == "no") {
						return;
					}
					
					// Only output the error messages if you are logged in as the admin
					if (current_user_can('edit_plugins') !== true) {
						return;
					}
					
					// Do not display errors in the settings page
					if (is_admin() === true) {
						return;
					}
					
					// Do not display errors about over weight packages for "letter" or "pak" services
					if ( (stripos($error, "letter") !== false || stripos($error, "pak") !== false) && stripos($error, "Package reported weight cannot exceed") !== false ) {
						return;
					}
					?>
					<div class="woocommerce-error">
						<b>Loomis rate calculator error reported from line <?php print $line; ?> (<?php print basename(__FILE__); ?>)</b><br />
						<?php print $error; ?><br /><br />
						<span style="font-size: 0.8em;">
							Disable debugging in the Loomis rate calculator plugin settings to hide error messages.<br />
							For support, please contact the Loomis service desk at: servicedesk@loomis-express.com
						</span>
					</div>
					<?php
				}
				
				/**
				 * Create a SOAP connection
				 *
				 * @param string $url the URL to connect to
				 *
				 * @access public
				 * @return mixed
				 */
				function soap_connect ( $url ) {
					// Don't bother connecting in the admin panel
					if (is_admin()) {
						return new StdClass(); // Return an empty class
					}

					// Check if $url can connect to the wsdl:
					//$url = "http://webservice.loomis-express.com/LShip/services/USSRatingService?wsdl";

					// Try cURL first:
					if (function_exists('curl_version')) {
						$ch = @curl_init($url);
						curl_setopt($ch, CURLOPT_NOBODY, true);
						curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
						curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
						curl_exec($ch);
						$response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
						curl_close($ch);


						//Check if the URL was able to be connected to
						if ($response_code >= 200 && $response_code < 400) { // The URL exists
							$connect = true;
						}
						else {
							$connect = false;
							$this->loomis_log( (date('His') . "-curl_connect"), "curl_init({$url}):\n{$response_code}", "append");
						}
					}
					elseif (@get_headers($url, 1) !== false) { // If cURL fails, try get_headers() as a backup (cURL might be disabled)
						$response_code = get_headers($url, 1);

						if (strpos($response_code[0], '200') || strpos($response_code[1], '200')) {
							$connect = true;
						}
						else {
							$connect = false;
							$this->loomis_log( (date('His') . "-get_headers"), "get_headers({$url}, 1):\n" . print_r(get_headers($url, 1), 1), "append");
						}
					}
					else {
						$connect = false;
						$this->loomis_log( (date('His') . "-connect_error"), "get_headers({$url}):\n" . print_r(get_headers($url), 1), "append");
					}

					// Now connect to the WSDL
					if ($connect == true) {
						// Setup the SOAP client
						$SOAP_OPTIONS = array(
							'soap_version' => SOAP_1_2,
							'exceptions' => false,
							'trace' => 1,
							'cache_wsdl' => WSDL_CACHE_NONE,
							'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
							// For some reason, SoapClient is trying to use the [disabled] http endpoint (instead of the https endpoint) from the WSDL. Therefore, the https endpoint has to be hard coded.
							'location' => 'https://webservice.loomis-express.com/LShip/services/USSRatingService.USSRatingServiceHttpsSoap12Endpoint/'
						);

						// Connect
						$soap_client = new SoapClient($url, $SOAP_OPTIONS);
					}
					else {
						$this->output_error("Unable to connect to the Rating URL:<br /><br />{$url}<br /><br />Make sure this property in your settings is correct", __LINE__);
						$soap_client = new StdClass(); //Create a dummy object
					}
					
					return $soap_client;
				}
			}
		}
	}
 
	add_action( 'woocommerce_shipping_init', 'loomis_rate_calculator_init' );
 
	function add_loomis_shipping_method( $methods ) {
		$methods['add_loomis_shipping_method'] = 'WC_Loomis_Rate_Calculator';
		return $methods;
	}
 
	add_filter( 'woocommerce_shipping_methods', 'add_loomis_shipping_method' );
}
