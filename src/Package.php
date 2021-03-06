<?php

namespace Vendidero\Germanized\DHL;

use DateTime;
use DateTimeZone;
use Exception;
use Vendidero\Germanized\DHL\Api\Paket;
use Vendidero\Germanized\DHL\ShippingProvider\MethodDeutschePost;
use Vendidero\Germanized\DHL\ShippingProvider\MethodDHL;
use Vendidero\Germanized\DHL\Api\Internetmarke;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Main package class.
 */
class Package {

    /**
     * Version.
     *
     * @var string
     */
    const VERSION = '1.4.5';

    public static $upload_dir_suffix = '';

	// These are all considered domestic by DHL
	protected static $us_territories = array( 'US', 'GU', 'AS', 'PR', 'UM', 'VI' );

	protected static $holidays = array();

	protected static $api = null;

	protected static $im_api = null;

	protected static $method_settings = null;

	protected static $dp_method_settings = null;
	
	protected static $iso = null;

    /**
     * Init the package - load the REST API Server class.
     */
    public static function init() {

    	if ( ! self::has_dependencies() ) {
    		return;
	    }

        self::define_tables();
        self::maybe_set_upload_dir();

	    // Add shipping provider
	    add_filter( 'woocommerce_gzd_shipping_provider_class_names', array( __CLASS__, 'add_shipping_provider_class_name' ), 10, 1 );
	    add_action( 'woocommerce_gzd_admin_settings_before_save_dhl', array( __CLASS__, 'before_update_settings' ), 10, 2 );

	    // Password Settings
	    add_filter( 'woocommerce_admin_settings_sanitize_option_woocommerce_gzd_dhl_api_sandbox_password', array( __CLASS__, 'sanitize_password_field' ), 10, 3 );
	    add_filter( 'woocommerce_admin_settings_sanitize_option_woocommerce_gzd_dhl_api_password', array( __CLASS__, 'sanitize_password_field' ), 10, 3 );
	    add_filter( 'woocommerce_admin_settings_sanitize_option_woocommerce_gzd_dhl_im_api_password', array( __CLASS__, 'sanitize_password_field' ), 10, 3 );

	    add_filter( 'woocommerce_admin_settings_sanitize_option_woocommerce_gzd_dhl_api_username', array( __CLASS__, 'sanitize_user_field' ), 10, 3 );
	    add_filter( 'woocommerce_admin_settings_sanitize_option_woocommerce_gzd_dhl_api_sandbox_username', array( __CLASS__, 'sanitize_user_field' ), 10, 3 );
	    add_filter( 'woocommerce_admin_settings_sanitize_option_woocommerce_gzd_dhl_im_api_username', array( __CLASS__, 'sanitize_user_field' ), 10, 3 );

	    if ( self::is_enabled() ) {
	        if ( self::has_load_dependencies() ) {
		        self::init_hooks();
            } else {
	            add_action( 'admin_notices', array( __CLASS__, 'load_dependencies_notice' ) );
            }
        }

        self::includes();
    }

    public static function load_dependencies_notice() {
    	?>
	    <div class="notice notice-error error">
		    <p><?php printf( _x( 'To enable communication between your shop and DHL, the PHP <a href="%s">SOAPClient</a> is required. Please contact your host and make sure that SOAPClient is <a href="%s">installed</a>.', 'dhl', 'woocommerce-germanize-dhl' ), 'https://www.php.net/manual/class.soapclient.php', admin_url( 'admin.php?page=wc-status' ) ); ?></p>
	    </div>
	    <?php
    }

    public static function has_dependencies() {
    	return ( class_exists( 'WooCommerce' ) && class_exists( '\Vendidero\Germanized\Shipments\Package' ) && self::base_country_is_supported() && apply_filters( 'woocommerce_gzd_dhl_enabled', true ) );
    }

    public static function has_load_dependencies() {
    	return ( ! class_exists( 'SoapClient' ) ? false : true );
    }

    public static function base_country_is_supported() {
    	return in_array( self::get_base_country(), self::get_supported_countries() );
    }

    public static function get_supported_countries() {
    	return array( 'DE' );
    }

    public static function base_country_supports( $type = 'services' ) {
		$base_country = self::get_base_country();

		if ( 'services' === $type || 'returns' === $type || 'pickup' === $type ) {
			return 'DE' === $base_country;
		}

		return false;
    }

    public static function get_date_de_timezone( $format = 'Y-m-d', $reset_timezone = true ) {
    	try {
		    // Get existing timezone to reset afterwards
		    $current_timzone = date_default_timezone_get();

		    // Always set and get DE timezone and check against it.
		    date_default_timezone_set( 'Europe/Berlin' );

		    $tz_obj       = new DateTimeZone(  'Europe/Berlin' );
		    $current_date = new DateTime( "now", $tz_obj );

		    $date_formatted = $current_date->format( $format );

		    if ( $reset_timezone ) {
			    // Reset timezone to not affect any other plugins
			    date_default_timezone_set( $current_timzone );
		    }

		    return $date_formatted;
	    } catch( Exception $e ) {
    		return date( $format );
	    }
    }

	public static function get_holidays( $country = 'DE' ) {
		if ( empty( self::$holidays ) ) {
			self::$holidays = include self::get_path() . '/i18n/holidays.php';
		}

		$holidays = self::$holidays;

		if ( ! empty( $country ) ) {
			$holidays = array_key_exists( $country, self::$holidays ) ? self::$holidays[ $country ] : array();
		}

		/**
		 * Filter to adjust dates regarded as holidays for a certain country.
		 *
		 * @param array  $holidays Array containing dates in Y-m-d format.
		 * @param string $country The country as ISO code.
		 *
		 * @since 3.0.0
		 * @package Vendidero/Germanized/DHL
		 */
		return apply_filters( 'woocommerce_gzd_dhl_holidays', $holidays, $country );
	}

    /**
     * Register custom tables within $wpdb object.
     */
    private static function define_tables() {
        global $wpdb;

        // List of tables without prefixes.
        $tables = array(
            'gzd_dhl_labelmeta'           => 'woocommerce_gzd_dhl_labelmeta',
            'gzd_dhl_labels'              => 'woocommerce_gzd_dhl_labels',
            'gzd_dhl_im_products'         => 'woocommerce_gzd_dhl_im_products',
            'gzd_dhl_im_product_services' => 'woocommerce_gzd_dhl_im_product_services',
        );

        foreach ( $tables as $name => $table ) {
            $wpdb->$name    = $wpdb->prefix . $table;
            $wpdb->tables[] = $table;
        }
    }

    public static function maybe_set_upload_dir() {
        // Create a dir suffix
        if ( ! get_option( 'woocommerce_gzd_dhl_upload_dir_suffix', false ) ) {
            self::$upload_dir_suffix = substr( self::generate_key(), 0, 10 );
            update_option( 'woocommerce_gzd_dhl_upload_dir_suffix', self::$upload_dir_suffix );
        } else {
            self::$upload_dir_suffix = get_option( 'woocommerce_gzd_dhl_upload_dir_suffix' );
        }
    }

    public static function is_enabled() {
    	return ( self::is_dhl_enabled() || self::is_internetmarke_enabled() );
    }

	public static function is_dhl_enabled() {
		return ( 'yes' === self::get_setting( 'enable' ) );
	}
    
    public static function get_country_iso_alpha3( $country_code ) {
	    if ( empty( self::$iso ) ) {
		    self::$iso = include self::get_path() . '/i18n/iso.php';
	    }

	    $iso = self::$iso;

    	if ( isset( $iso[ $country_code ] ) ) {
    		return $iso[ $country_code ];
	    }

    	return $country_code;
    }

    private static function includes() {
        include_once self::get_path() . '/includes/wc-gzd-dhl-core-functions.php';

        if ( self::is_enabled() ) {
	        if ( is_admin() ) {
		        Admin\Admin::init();
	        }

	        if ( ParcelLocator::is_enabled() ) {
		        ParcelLocator::init();
	        }

	        /**
	         * Additional services are only available for DHL products
	         */
	        if ( self::is_dhl_enabled() && ParcelServices::is_enabled() ) {
	        	ParcelServices::init();
	        }

	        Ajax::init();
	        ShipmentLabelWatcher::init();
	        LabelWatcher::init();
	        Automation::init();
        }
    }

    public static function init_hooks() {
	    add_filter( 'woocommerce_data_stores', array( __CLASS__, 'register_data_stores' ), 10, 1 );

	    add_filter( 'woocommerce_gzd_shipping_provider_method_admin_settings', array( __CLASS__, 'add_shipping_provider_settings' ), 10, 1 );
		add_filter( 'woocommerce_gzd_shipping_provider_method_clean_settings', array( __CLASS__, 'clean_shipping_provider_settings' ), 10, 2 );

	    // Filter templates
	    add_filter( 'woocommerce_gzd_default_plugin_template', array( __CLASS__, 'filter_templates' ), 10, 3 );

	    // Maybe force street number during checkout
	    add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'maybe_force_street_number' ), 10, 2 );
    }

	public static function sanitize_password_field( $value, $option, $raw_value ) {
		$value = is_null( $raw_value ) ? '' : addslashes( $raw_value );

		return trim( $value );
	}

	public static function sanitize_user_field( $value, $option, $raw_value ) {
		return strtolower( wc_clean( $value ) );
	}

	/**
	 * @param array     $data
	 * @param WP_Error $errors
	 */
    public static function maybe_force_street_number( $data, $errors ) {
    	if ( 'yes' === self::get_setting( 'label_checkout_validate_street_number_address' ) ) {
		    if ( function_exists( 'wc_gzd_split_shipment_street' ) && ( $method = wc_gzd_dhl_get_current_shipping_method() ) ) {
			    if ( $method->is_dhl_enabled() || $method->is_deutsche_post_enabled() ) {
				    if ( isset( $data['shipping_country'], $data['shipping_address_1'] ) && ! empty( $data['shipping_country'] ) ) {
					    // Do only check street numbers for inner EU.
					    if ( ! self::is_crossborder_shipment( $data['shipping_country'] ) ) {
						    $parts = wc_gzd_split_shipment_street( $data['shipping_address_1'] );

						    if ( empty( $parts['number'] ) ) {
							    $errors->add( 'shipping', _x( 'Please check the street field and make sure to provide a valid street number.', 'dhl', 'woocommerce-germanized-dhl' ) );
						    }
					    }
				    }
			    }
		    }
	    }
    }

	public static function filter_templates( $path, $template_name ) {

		if ( file_exists( self::get_path() . '/templates/' . $template_name ) ) {
			$path = self::get_path() . '/templates/' . $template_name;
		}

		return $path;
	}

	public static function add_shipping_provider_settings( $settings ) {
		return array_merge( $settings, self::get_method_settings() );
	}

	public static function clean_shipping_provider_settings( $p_settings, $method ) {
		$shipping_provider_settings = self::get_method_settings();
		$shipping_provider          = isset( $p_settings['shipping_provider'] ) ? $p_settings['shipping_provider'] : '';

		foreach( $p_settings as $setting => $value ) {
			if ( array_key_exists( $setting, $shipping_provider_settings ) ) {
				if ( substr( $setting, 0, strlen( $shipping_provider ) + 1 ) !== $shipping_provider . '_' ) {
					unset( $p_settings[ $setting ] );
				} elseif ( self::get_setting( $setting ) === $value ) {
					unset( $p_settings[ $setting ] );
				} elseif( ''  === $value ) {
					unset( $p_settings[ $setting ] );
				}
 			}
		}

		return $p_settings;
	}

	public static function get_method_settings() {
    	if ( is_null( self::$method_settings ) ) {
    		self::$method_settings = include Package::get_path() . '/includes/admin/views/settings-shipping-method.php';
	    }

    	return self::$method_settings;
	}

	public static function eur_to_cents( $price ) {
		return round( $price * 100 );
	}

	public static function cents_to_eur( $price ) {
		return $price > 0 ? $price / 100 : 0;
	}

	public static function add_shipping_provider_class_name( $class_names ) {
		$class_names['dhl']           = '\Vendidero\Germanized\DHL\ShippingProvider\DHL';
		$class_names['deutsche_post'] = '\Vendidero\Germanized\DHL\ShippingProvider\DeutschePost';

		return $class_names;
	}

    public static function install() {
	    self::includes();
	    Install::install();
    }

	public static function install_integration() {
    	self::install();
	}

	public static function is_integration() {
		return class_exists( 'WooCommerce_Germanized' ) ? true : false;
	}

	public static function register_data_stores( $stores ) {
        $stores['dhl-label'] = 'Vendidero\Germanized\DHL\DataStores\Label';

        return $stores;
    }

    public static function get_api() {
		if ( is_null( self::$api ) ) {
			self::$api = new Paket( self::get_base_country() );
		}

		return self::$api;
    }

	public static function get_internetmarke_api() {
		if ( is_null( self::$im_api ) && self::is_internetmarke_enabled() ) {
			self::$im_api = new Internetmarke();
		}

		return self::$im_api;
	}

    /**
     * Return the version of the package.
     *
     * @return string
     */
    public static function get_version() {
        return self::VERSION;
    }

    /**
     * Return the path to the package.
     *
     * @return string
     */
    public static function get_path() {
        return dirname( __DIR__ );
    }

    public static function get_template_path() {
	    return 'woocommerce-germanized/';
    }

    /**
     * Return the path to the package.
     *
     * @return string
     */
    public static function get_url() {
        return plugins_url( '', __DIR__ );
    }

	public static function get_assets_url() {
		return self::get_url() . '/assets';
	}

	public static function is_debug_mode() {
		return ( defined( 'WC_GZD_DHL_DEBUG' ) && WC_GZD_DHL_DEBUG ) || 'yes' === get_option( "woocommerce_gzd_dhl_sandbox_mode" );
	}

	public static function enable_logging() {
		return ( defined( 'WC_GZD_DHL_LOG_ENABLE' ) && WC_GZD_DHL_LOG_ENABLE ) || self::is_debug_mode();
	}

    private static function define_constant( $name, $value ) {
        if ( ! defined( $name ) ) {
            define( $name, $value );
        }
    }

    public static function get_app_id() {
        return 'woo_germanized_2';
    }

    public static function get_app_token() {
        return '8KdXFjxwY0I1oOEo28Jk997tS5Rkky';
    }

    public static function get_geschaeftskunden_portal_url() {
    	return 'https://www.dhl-geschaeftskundenportal.de';
    }

    public static function get_internetmarke_main_url() {
        return 'https://internetmarke.deutschepost.de/OneClickForAppV3?wsdl';
    }

    public static function get_warenpost_international_rest_url() {
        return 'https://api.deutschepost.com';
    }

    public static function get_internetmarke_products_url() {
        return 'https://prodws.deutschepost.de:8443/ProdWSProvider_1_1/prodws?wsdl';
    }

    public static function get_internetmarke_refund_url() {
        return 'https://internetmarke.deutschepost.de/OneClickForRefund?wsdl';
    }

    public static function get_internetmarke_partner_id() {
    	return 'AVHGE';
    }

    public static function get_internetmarke_token() {
    	return 'l4e0TNkqpGKd0YIhyuz3m4vzEek1iZY4';
    }

    public static function get_internetmarke_key_phase() {
    	return 1;
    }

    public static function get_internetmarke_product_username() {
    	return 'vendidero';
    }

	public static function get_internetmarke_product_password() {
		return 'A&5%bk?dx8';
	}

	public static function get_internetmarke_product_mandant_id() {
		return 'VENDIDERO';
	}

	public static function is_internetmarke_enabled() {
    	return 'yes' === self::get_setting( 'internetmarke_enable' );
	}

	public static function get_internetmarke_username() {
		if ( self::is_debug_mode() && defined( 'WC_GZD_DHL_IM_SANDBOX_USER' ) ) {
			return WC_GZD_DHL_IM_SANDBOX_USER;
		} else {
			return self::get_setting( 'im_api_username' );
		}
	}

	public static function get_internetmarke_warenpost_int_ekp() {
		$ekp = self::get_setting( 'internetmarke_warenpost_int_ekp' );

		if ( empty( $ekp ) ) {
			$ekp = '0000012207';
		}

		return $ekp;
	}

	/**
     * The Warenpost International API (necessary for customs forms)
     * needs separate Sandbox credentials. In live mode the Portokasse credentials are being used.
     *
	 * @return string
	 */
	public static function get_internetmarke_warenpost_int_username() {
		if ( self::is_debug_mode() && defined( 'WC_GZD_DHL_IM_WP_SANDBOX_USER' ) ) {
			return WC_GZD_DHL_IM_WP_SANDBOX_USER;
		} else {
			return self::get_setting( 'im_api_username' );
		}
	}

	public static function get_internetmarke_password() {
		if ( self::is_debug_mode() && defined( 'WC_GZD_DHL_IM_SANDBOX_PASSWORD' ) ) {
			return WC_GZD_DHL_IM_SANDBOX_PASSWORD;
		} else {
			return self::get_setting( 'im_api_password' );
		}
	}

	public static function get_internetmarke_warenpost_int_password() {
		if ( self::is_debug_mode() && defined( 'WC_GZD_DHL_IM_WP_SANDBOX_PASSWORD' ) ) {
			return WC_GZD_DHL_IM_WP_SANDBOX_PASSWORD;
		} else {
			return self::get_setting( 'im_api_password' );
		}
	}

	/**
	 * CIG Authentication (basic auth) user. In Sandbox mode use Developer ID and password of entwickler.dhl.de
	 *
	 * @return mixed|string|void
	 */
    public static function get_cig_user() {
    	$debug_user = defined( 'WC_GZD_DHL_SANDBOX_USER' ) ? WC_GZD_DHL_SANDBOX_USER : self::get_setting( 'api_sandbox_username' );
		$debug_user = strtolower( $debug_user );

        return self::is_debug_mode() ? $debug_user : self::get_app_id();
    }

	/**
	 * CIG Authentication (basic auth) password. In Sandbox mode use Developer ID and password of entwickler.dhl.de
	 *
	 * @return mixed|string|void
	 */
    public static function get_cig_password() {
	    $debug_pwd = defined( 'WC_GZD_DHL_SANDBOX_PASSWORD' ) ? WC_GZD_DHL_SANDBOX_PASSWORD : self::get_setting( 'api_sandbox_password' );

        return self::is_debug_mode() ? $debug_pwd : self::get_app_token();
    }

	/**
	 * GK Auth user
	 *
	 * @return mixed|string|void
	 */
    public static function get_gk_api_user() {
	    $user = self::is_debug_mode() ? '2222222222_01' : self::get_setting( 'api_username' );

	    return strtolower( $user );
    }

	/**
	 * GK Auth password
	 *
	 * @return mixed|string|void
	 */
	public static function get_gk_api_signature() {
		return self::is_debug_mode() ? 'pass' : self::get_setting( 'api_password' );
	}

	/**
	 * Retoure Auth user
	 *
	 * @return mixed|string|void
	 */
	public static function get_retoure_api_user() {
		$user = self::is_debug_mode() ? '2222222222_Customer' : self::get_setting( 'api_username' );

		return strtolower( $user );
	}

	public static function get_return_receivers() {
		$receiver = self::get_setting( 'retoure_receiver_ids' );

		if ( ! empty( $receiver ) ) {
			return (array) $receiver;
		} else {
			return array();
		}
	}

	public static function get_return_receiver_by_slug( $slug ) {
		$receivers         = self::get_return_receivers();

		if ( array_key_exists( sanitize_key( $slug ), $receivers ) ) {
			return $receivers[ $slug ];
		}

		return false;
	}

	public static function get_return_receiver_by_country( $country ) {
		$receivers         = self::get_return_receivers();
		$country_receiver  = false;
		$fallback_receiver = false;

		foreach( $receivers as $receiver ) {

			if ( ! $fallback_receiver && empty( $receiver['country'] ) ) {
				$fallback_receiver = $receiver;
			}

			if ( $receiver['country'] === $country ) {
				$country_receiver = $receiver;
			}
		}

		if ( ! $country_receiver ) {
			$country_receiver = $fallback_receiver;
		}

		/**
		 * Returns the DHL retoure receiver id for a certain country.
		 *
		 * @param array  $receiver The receiver to be used for the retoure.
		 * @param string $country The country code of the retoure.
		 *
		 * @since 3.0.0
		 * @package Vendidero/Germanized/DHL
		 */
		return apply_filters( 'woocommerce_gzd_dhl_retoure_receiver', $country_receiver, $country );
	}

	/**
	 * Retoure Auth signature
	 *
	 * @return mixed|string|void
	 */
	public static function get_retoure_api_signature() {
		return self::is_debug_mode() ? 'uBQbZ62!ZiBiVVbhc' : self::get_setting( 'api_password' );
	}

    public static function get_cig_url() {
        return self::is_debug_mode() ? 'https://cig.dhl.de/services/sandbox/soap' : 'https://cig.dhl.de/services/production/soap';
    }

    public static function get_rest_url() {
        return self::is_debug_mode() ? 'https://cig.dhl.de/services/sandbox/rest' : 'https://cig.dhl.de/services/production/rest';
    }

    public static function get_gk_api_url() {
	    return self::is_debug_mode() ? 'https://cig.dhl.de/cig-wsdls/com/dpdhl/wsdl/geschaeftskundenversand-api/3.1/geschaeftskundenversand-api-3.1.wsdl' : 'https://cig.dhl.de/cig-wsdls/com/dpdhl/wsdl/geschaeftskundenversand-api/3.1/geschaeftskundenversand-api-3.1.wsdl';
    }

	public static function get_parcel_finder_api_url() {
		return self::is_debug_mode() ? 'https://cig.dhl.de/cig-wsdls/com/dpdhl/wsdl/standortsuche-api/1.1/standortsuche-api-1.1.wsdl' : 'https://cig.dhl.de/cig-wsdls/com/dpdhl/wsdl/standortsuche-api/1.1/standortsuche-api-1.1.wsdl';
	}

    public static function get_business_portal_url() {
        return 'https://www.dhl-geschaeftskundenportal.de';
    }

    /**
     * Generate a unique key.
     *
     * @return string
     */
    protected static function generate_key() {
        $key       = array( ABSPATH, time() );
        $constants = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'SECRET_KEY' );

        foreach ( $constants as $constant ) {
            if ( defined( $constant ) ) {
                $key[] = constant( $constant );
            }
        }

        shuffle( $key );

        return md5( serialize( $key ) );
    }

    public static function get_upload_dir_suffix() {
        return self::$upload_dir_suffix;
    }

    public static function get_upload_dir() {

        self::set_upload_dir_filter();
        $upload_dir = wp_upload_dir();
        self::unset_upload_dir_filter();

	    /**
	     * Filter to adjust the DHL label upload directory. By default
	     * DHL labels are stored in a custom directory under wp-content/uploads.
	     *
	     * @param array $upload_dir Array containing `wp_upload_dir` data.
	     *
	     * @since 3.0.0
	     * @package Vendidero/Germanized/DHL
	     */
        return apply_filters( 'woocommerce_gzd_dhl_upload_dir', $upload_dir );
    }

    public static function get_relative_upload_dir( $path ) {

        self::set_upload_dir_filter();
        $path = _wp_relative_upload_path( $path );
        self::unset_upload_dir_filter();

	    /**
	     * Filter to retrieve the DHL label relative upload path.
	     *
	     * @param array $path Relative path.
	     *
	     * @since 3.0.0
	     * @package Vendidero/Germanized/DHL
	     */
        return apply_filters( 'woocommerce_gzd_dhl_relative_upload_dir', $path );
    }

    public static function set_upload_dir_filter() {
        add_filter( 'upload_dir', array( __CLASS__, "filter_upload_dir" ), 150, 1 );
    }

    public static function get_file_by_path( $file ) {
	    // If the file is relative, prepend upload dir.
	    if ( $file && 0 !== strpos( $file, '/' ) && ( ( $uploads = Package::get_upload_dir() ) && false === $uploads['error'] ) ) {
		    $file = $uploads['basedir'] . "/$file";

		    return $file;
	    } else {
		    return $file;
	    }
    }

    public static function get_wsdl_file( $wsdl_link ) {
        if ( self::is_debug_mode() ) {
            return $wsdl_link;
        }

	    $main_file       = basename( $wsdl_link );
	    $required_files  = array( $main_file );

	    // Some WSDLs may require multiple files
	    if ( strpos( $wsdl_link, 'geschaeftskundenversand-api' ) !== false ) {
		    $required_files = array(
			    $main_file,
			    str_replace( '.wsdl', '-schema-cis_base.xsd', $main_file ),
			    str_replace( '.wsdl', '-schema-bcs_base.xsd', $main_file ),
		    );
	    }

	    $file_link       = $wsdl_link;
	    $transient       = 'wc_gzd_dhl_wsdl_' . sanitize_key( $main_file );
	    $new_file_name   = $main_file;
	    $files_exist     = true;
	    $is_zip          = false;

	    // Renew files every 14 days
	    $transient_valid = DAY_IN_SECONDS * 14;

	    if ( sizeof( $required_files ) > 1 ) {
	    	$file_link     = str_replace( '.wsdl', '.zip', $file_link );
	    	$new_file_name = str_replace( '.wsdl', '.zip', $new_file_name );
	    	$is_zip        = true;
	    }

	    /**
	     * Check if all required files exist locally
	     */
	    foreach( $required_files as $file ) {
		    $inner_transient = 'wc_gzd_dhl_wsdl_' . sanitize_key( $file );
		    $file_path       = get_transient( $inner_transient );

		    if ( $file_path ) {
			    $file_path = self::get_file_by_path( $file_path );
		    }

		    if ( ! $file_path || ! file_exists( $file_path ) ) {
			    $files_exist = false;
		    }
	    }

	    $file_path = get_transient( $transient );

	    /**
	     * This filter may be used to force loading an alternate (local) WSDL file
	     * for a certain API endpoint. By default we are trying to locally store necessary files
	     * to reduce API calls. Transients/files are renewed once per day.
	     *
	     * @param boolean|string $alternate_file In case an alternate file should be used this must be the absolute path.
	     * @param string         $wsdl_link The link to the original WSDL file.
	     *
	     * @since 3.1.2
	     * @package Vendidero/Germanized/DHL
	     */
	    $alternate_file = apply_filters( 'woocommerce_gzd_dhl_alternate_wsdl_file', false, $wsdl_link );

	    if ( ( $files_exist && $file_path ) || $alternate_file ) {
		    $wsdl_link = $alternate_file ? $alternate_file : self::get_file_by_path( $file_path );
	    } else {

	    	if ( ! function_exists( 'download_url' ) ) {
				include_once( ABSPATH . 'wp-admin/includes/file.php' );
		    }

		    if ( function_exists( 'download_url' ) && function_exists( 'unzip_file' ) ) {
			    /**
			     * Some URLs like https://prodws.deutschepost.de:8443/ProdWSProvider_1_1/prodws?wsdl might
                 * be rejected due to not using standard SSL ports, e.g.: 8443. Allow them anyway.
			     */
		        add_filter( 'http_request_args', function( $args, $url ) use ( $file_link ) {
		            if ( $url === $file_link ) {
		                $args['reject_unsafe_urls'] = false;
                    }

		            return $args;
                }, 10, 2 );

			    $tmp_file = download_url( $file_link, 1500 );

			    if ( ! is_wp_error( $tmp_file ) ) {

				    $uploads    = Package::get_upload_dir();
				    $new_file   = $uploads['path'] . "/$new_file_name";
				    $has_copied = @copy( $tmp_file, $new_file );

				    if ( $has_copied ) {

				    	if ( $is_zip ) {
						    global $wp_filesystem;

						    if ( ! $wp_filesystem ) {
							    WP_Filesystem();
						    }

						    $unzipfile = unzip_file( $new_file, $uploads['path'] );

						    if ( ! is_wp_error( $unzipfile ) ) {
							    $files_exist   = true;
							    $new_wsdl_link = false;

							    foreach( $required_files as $file ) {
								    $transient = 'wc_gzd_dhl_wsdl_' . sanitize_key( $file );
								    $file_path = $uploads['path'] . "/$file";

								    if ( file_exists( $file_path ) ) {
									    set_transient( $transient, self::get_relative_upload_dir( $file_path ), $transient_valid );

									    if ( $file === $main_file ) {
										    $new_wsdl_link = $file_path;
									    }
								    } else {
									    $files_exist = false;
								    }
							    }

							    if ( $files_exist && $new_wsdl_link ) {
								    $wsdl_link = $new_wsdl_link;
							    }
						    }

						    @unlink( $new_file );
					    } else {
						    $transient = 'wc_gzd_dhl_wsdl_' . sanitize_key( $main_file );
						    $file_path = $uploads['path'] . "/$main_file";

						    if ( file_exists( $file_path ) ) {
							    set_transient( $transient, self::get_relative_upload_dir( $file_path ), $transient_valid );
							    $wsdl_link = $file_path;
						    }
					    }

					    @unlink( $tmp_file );
				    }
			    }
		    }
	    }

	    return $wsdl_link;
    }

    public static function unset_upload_dir_filter() {
        remove_filter( 'upload_dir', array( __CLASS__, "filter_upload_dir" ), 150 );
    }

    public static function filter_upload_dir( $args ) {
        $upload_base = trailingslashit( $args['basedir'] );
        $upload_url  = trailingslashit( $args['baseurl'] );

	    /**
	     * Filter to adjust the DHL label upload path. By default
	     * DHL labels are stored in a custom directory under wp-content/uploads.
	     *
	     * @param string $path Path to the upload directory.
	     *
	     * @since 3.0.0
	     * @package Vendidero/Germanized/DHL
	     */
        $args['basedir'] = apply_filters( 'woocommerce_gzd_dhl_upload_path', $upload_base . 'wc-gzd-dhl-' . self::get_upload_dir_suffix() );
	    /**
	     * Filter to adjust the DHL label upload URL. By default
	     * DHL labels are stored in a custom directory under wp-content/uploads.
	     *
	     * @param string $url URL to the upload directory.
	     *
	     * @since 3.0.0
	     * @package Vendidero/Germanized/DHL
	     */
        $args['baseurl'] = apply_filters( 'woocommerce_gzd_dhl_upload_url', $upload_url . 'wc-gzd-dhl-' . self::get_upload_dir_suffix() );

        $args['path'] = $args['basedir'] . $args['subdir'];
        $args['url']  = $args['baseurl'] . $args['subdir'];

        return $args;
    }

    public static function get_participation_number( $product ) {
    	return self::get_setting( 'participation_' . $product );
    }

	/**
	 * @param $name
	 * @param bool|MethodDHL|MethodDeutschePost $method
	 *
	 * @return mixed|void
	 */
    public static function get_setting( $name, $method = false ) {
	    $is_dp = false;

    	if ( substr( $name, 0, 4 ) === 'dhl_' ) {
    		$name = substr( $name, 4 );
	    } elseif( substr( $name, 0, 14 ) === 'deutsche_post_' ) {
		    $name  = substr( $name, 14 );
    		$is_dp = true;
	    }

    	if ( self::is_debug_mode() ) {
			if( 'api_username' === $name ) {
				$name = 'api_sandbox_username';
			} elseif( 'api_password' === $name ) {
				$name = 'api_sandbox_password';
			} elseif( 'account_number' === $name ) {
				return '2222222222';
			}
	    }

    	if ( $method ) {
    		if ( $method->has_option( $name ) ) {
    			return $method->get_option( $name );
		    }
	    }

    	if ( ! $is_dp ) {
		    $value = get_option( "woocommerce_gzd_dhl_{$name}" );
	    } else {
		    $value = get_option( "woocommerce_gzd_deutsche_post_{$name}" );
	    }

    	if ( ! empty( $value ) && strpos( $name, 'password' ) !== false ) {
    		return stripslashes( $value );
	    }

    	return $value;
    }

    public static function log( $message, $type = 'info' ) {
        $logger         = wc_get_logger();
        $enable_logging = self::enable_logging() ? true : false;

        if ( ! $logger ) {
            return false;
        }

	    /**
	     * Filter that allows adjusting whether to enable or disable
	     * logging for the DHL package (e.g. API requests).
	     *
	     * @param boolean $enable_logging True if logging should be enabled. False otherwise.
	     *
	     * @package Vendidero/Germanized/DHL
	     */
        if ( ! apply_filters( 'woocommerce_gzd_dhl_enable_logging', $enable_logging ) ) {
        	return false;
        }

        if ( ! is_callable( array( $logger, $type ) ) ) {
            $type = 'info';
        }

        $logger->{$type}( $message, array( 'source' => 'woocommerce-germanized-dhl' ) );
    }

    public static function get_available_countries() {
	    return array( 'DE' => _x(  'Germany', 'dhl', 'woocommerce-germanized-dhl' ) );
    }

    public static function get_base_country() {
	    $base_location       = wc_get_base_location();
	    $base_country        = $base_location['country'];
	    $sender_base_country = Package::get_setting( 'shipper_country' );

	    if ( ! empty( $sender_base_country ) ) {
	    	$base_country = $sender_base_country;
	    }

	    /**
	     * Filter to adjust the DHL base country.
	     *
	     * @param string $country The country as ISO code.
	     *
	     * @since 3.0.0
	     * @package Vendidero/Germanized/DHL
	     */
	    return apply_filters( 'woocommerce_gzd_dhl_base_country', $base_country );
    }

    public static function get_us_territories() {
    	return self::$us_territories;
    }

	/**
	 * Function return whether the sender and receiver country is the same territory
	 */
	public static function is_shipping_domestic( $country_receiver ) {
		// If base is US territory
		if ( in_array( self::get_base_country(), self::get_us_territories() ) ) {
			// ...and destination is US territory, then it is "domestic"
			if ( in_array( $country_receiver, self::get_us_territories() ) ) {
				return true;
			} else {
				return false;
			}
		} elseif ( $country_receiver == self::get_base_country() ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Check if it is an EU shipment
	 */
	public static function is_eu_shipment( $country_receiver ) {
		if ( self::is_shipping_domestic( $country_receiver ) ) {
			return false;
		}

        if ( in_array( $country_receiver, self::get_eu_countries() ) ) {
            return true;
        } else {
            return false;
        }
	}

	protected static function get_eu_countries() {
	    $countries = WC()->countries->get_european_union_countries();

	    if ( in_array( 'GB', $countries ) ) {
	        $countries = array_diff( $countries, array( 'GB' ) );
	    }

	    return $countries;
	}

	/**
	 * Function return whether the sender and receiver country is "crossborder" i.e. needs CUSTOMS declarations (outside EU)
	 */
	public static function is_crossborder_shipment( $country_receiver ) {
		if ( self::is_shipping_domestic( $country_receiver ) ) {
			return false;
		}

		// Is sender country in EU...
		if ( in_array( self::get_base_country(), self::get_eu_countries() ) ) {
			// ... and receiver country is in EU means NOT crossborder!
			if ( in_array( $country_receiver, self::get_eu_countries() ) ) {
				return false;
			} else {
				return true;
			}
		} else {
			return true;
		}
	}

	public static function before_update_settings( $settings, $current_section = '' ) {

		if ( ! empty( $current_section ) ) {
			return;
		}

		$currently_enabled = self::get_setting( 'enable' ) === 'yes';

		if ( ! $currently_enabled && isset( $_POST['woocommerce_gzd_dhl_enable'] ) && ! empty( $_POST['woocommerce_gzd_dhl_enable'] ) ) {

			if ( $provider = wc_gzd_get_shipping_provider( 'dhl' ) ) {
				$default_provider = wc_gzd_get_default_shipping_provider();

				if ( empty( $default_provider ) ) {
					update_option( 'woocommerce_gzd_shipments_default_shipping_provider', 'dhl' );
				}

				/**
				 * This action is documented in woocommerce-germanized-shipments/src/ShippingProvider.php
				 */
				do_action( 'woocommerce_gzd_shipping_provider_activated', $provider );
			}
		} elseif ( $currently_enabled && ! isset( $_POST['woocommerce_gzd_dhl_enable'] ) ) {
			if ( $provider = wc_gzd_get_shipping_provider( 'dhl' ) ) {
				/**
				 * This action is documented in woocommerce-germanized-shipments/src/ShippingProvider.php
				 */
				do_action( 'woocommerce_gzd_shipping_provider_deactivated', $provider );
			}
		}
	}
}