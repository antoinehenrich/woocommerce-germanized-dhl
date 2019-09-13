<?php

namespace Vendidero\Germanized\DHL\Api;

use Exception;
use PDFMerger\Pdf;
use Vendidero\Germanized\DHL\Package;
use Vendidero\Germanized\DHL\Label;
use Vendidero\Germanized\DHL\ParcelLocator;

defined( 'ABSPATH' ) || exit;

class LabelSoap extends Soap {

    const DHL_MAX_ITEMS = '6';

    const DHL_RETURN_PRODUCT = '07';

    public function __construct( ) {
        try {
            parent::__construct( Package::get_gk_api_url() );
        } catch ( Exception $e ) {
            throw $e;
        }
    }

    public function get_access_token() {
        return $this->get_auth_api()->get_access_token( Package::get_gk_api_user(), Package::get_gk_api_signature() );
    }

    public function test_connection() {
        try {
        	$soap_client = $this->get_access_token();
        	$version     = $soap_client->getVersion();
        	return true;
        } catch( Exception $e ) {
        	return false;
        }
    }

    protected function validate_field( $key, $value ) {
        try {
            switch ( $key ) {
                case 'weight':
                    wc_gzd_dhl_validate_api_field( $value );
                    break;
                case 'hs_code':
                    wc_gzd_dhl_validate_api_field( $value, 'string', 4, 11 );
                    break;
                default:
                    parent::validate_field( $key, $value );
                    break;
            }
        } catch ( Exception $e ) {
            throw $e;
        }
    }

	/**
	 * @param Label $label
	 *
	 * @return mixed
	 * @throws Exception
	 */
    public function get_label( &$label ) {
    	if ( empty( $label->get_number() ) ) {
    		return $this->create_label( $label );
	    } else {
			$soap_request = array(
				'Version'            => array(
					'majorRelease'   => '3',
					'minorRelease'   => '0'
				),
				'shipmentNumber'     => $label->get_number(),
				'labelResponseType'  => 'B64',
			);

		    try {
			    $soap_client = $this->get_access_token();
			    Package::log( '"getLabel" called with: ' . print_r( $soap_request, true ) );

			    $response_body = $soap_client->getLabel( $soap_request );
			    Package::log( 'Response: Successful' );

		    } catch ( Exception $e ) {
			    Package::log( 'Response Error: ' . $e->getMessage() );
			    throw $e;
		    }

		    // Label not found
		    if ( 2000 === $response_body->Status->statusCode ) {
		    	return $this->create_label( $label );
		    } else {
			    return $this->update_label( $label, $response_body->Status, $response_body );
		    }
	    }
    }

    /**
     * @param Label $label
     *
     * @return mixed
     *
     * @throws Exception
     */
    public function create_label( &$label ) {
    	try {
	        $soap_request = $this->get_create_label_request( $label );
            $soap_client  = $this->get_access_token();
            Package::log( '"createShipmentOrder" called with: ' . print_r( $soap_request, true ) );

            $response_body = $soap_client->createShipmentOrder( $soap_request );
            Package::log( 'Response: Successful' );

        } catch ( Exception $e ) {
            Package::log( 'Response Error: ' . $e->getMessage() );
            throw $e;
        }

        return $this->update_label( $label, $response_body->Status, $response_body->CreationState );
    }

	/**
	 * @param Label $label
	 * @param $status
	 * @param $response_body
	 *
	 * @return mixed
	 * @throws Exception
	 */
    protected function update_label( &$label, $status, $response_body ) {
	    if ( 0 !== $status->statusCode ) {
		    if ( isset( $response_body->LabelData->Status ) && isset( $response_body->LabelData->Status->statusMessage ) ) {
			    $messages = (array) $response_body->LabelData->Status->statusMessage;
			    $messages = implode( "\n", array_unique( $messages ) );

			    throw new Exception( $messages );
		    } else {
			    throw new Exception( __( 'There was an error generating the label. Please check your logs.', 'woocommerce-germanized-dhl' ) );
		    }
	    } else {
		    // Give the server 1 second to create the PDF before downloading it
		    // sleep( 1 );
		    try {
			    if ( isset( $response_body->shipmentNumber ) ) {
				    $label->set_number( $response_body->shipmentNumber );
			    }

			    if ( isset( $response_body->returnShipmentNumber ) ) {
				    $label->set_return_number( $response_body->returnShipmentNumber );
			    }

			    if ( ! $filename_label = $label->get_default_filename() ) {
				    $filename_label = wc_gzd_dhl_generate_label_filename( $label, 'label-default' );
			    }

			    if ( $path = wc_gzd_dhl_upload_data( $filename_label, base64_decode( $response_body->LabelData->labelData ) ) ) {
				    $label->set_default_path( $path );
			    }

			    if ( isset( $response_body->LabelData->exportLabelData ) ) {

				    if ( ! $filename_export = $label->get_export_filename() ) {
					    $filename_export = wc_gzd_dhl_generate_label_filename( $label, 'label-export' );
				    }

				    if ( $path = wc_gzd_dhl_upload_data( $filename_export, base64_decode( $response_body->LabelData->exportLabelData ) ) ) {
					    $label->set_export_path( $path );
				    }

				    // Merge files
				    $pdf = new Pdf();
				    $pdf->add( $label->get_default_file() );
				    $pdf->add( $label->get_export_file() );

				    if ( ! $filename_label = $label->get_filename() ) {
					    $filename_label = wc_gzd_dhl_generate_label_filename( $label );
				    }

				    $file = $pdf->output( $filename_label, 'S' );

				    if ( $path = wc_gzd_dhl_upload_data( $filename_label, $file ) ) {
					    $label->set_path( $path );
				    }
			    } else {
					$label->set_path( $path );
			    }

			    do_action( 'woocommerce_gzd_dhl_before_label_api_update', $label );

			    $label->save();

			    do_action( 'woocommerce_gzd_dhl_label_api_updated', $label );

		    } catch( Exception $e ) {
			    throw new Exception( __( 'Error while creating and uploading the label', 'woocommerce-germanized-dhl' ) );
		    }

		    return $label;
	    }
    }

    /**
     * @param Label $label
     *
     * @throws Exception
     */
    protected function delete_label_call( &$label ) {
        $soap_request =	array(
            'Version'          => array(
                'majorRelease' => '3',
                'minorRelease' => '0'
            ),
            'shipmentNumber'   => $label->get_number()
        );

        try {
            Package::log( '"deleteShipmentOrder" called with: ' . print_r( $soap_request, true ) );

            $soap_client   = $this->get_access_token();
            $response_body = $soap_client->deleteShipmentOrder( $soap_request );

            Package::log( 'Response Body: ' . print_r( $response_body, true ) );

        } catch ( Exception $e ) {
            throw $e;
        }

	    do_action( 'woocommerce_gzd_dhl_label_api_before_delete', $label );

	    $label->set_number( '' );
	    $label->set_return_number( '' );

	    if ( $file = $label->get_file() ) {
		    wp_delete_file( $file );
	    }

	    $label->set_path( '' );

	    if ( $file = $label->get_default_file() ) {
		    wp_delete_file( $file );
	    }

	    $label->set_default_path( '' );

	    if ( $file = $label->get_export_file() ) {
		    wp_delete_file( $file );
	    }

	    $label->set_export_path( '' );

	    do_action( 'woocommerce_gzd_dhl_label_api_deleted', $label );

        if ( 0 !== $response_body->Status->statusCode ) {
            throw new Exception( sprintf( __( 'Could not delete label - %s', 'woocommerce-germanized-dhl' ), $response_body->Status->statusMessage ) );
        }

        return $label;
    }

    /**
     * @param Label $label
     *
     * @throws Exception
     */
    public function delete_label( &$label ) {
        try {
        	if ( ! empty( $label->get_number() ) ) {
		        return $this->delete_label_call( $label );
	        }
        } catch ( Exception $e ) {
            throw $e;
        }

        return false;
    }

    protected function get_account_number( $dhl_product ) {
        $product_number = preg_match('!\d+!', $dhl_product, $matches );

        if ( $product_number ) {
            $account_number = Package::get_setting( 'account_number' ) . $matches[0] . Package::get_participation_number( $dhl_product );

            return $account_number;
        } else {
            throw new Exception( __( 'Could not create account number - no product number.', 'woocommerce-germanized-dhl' ) );
        }
    }

    protected function get_return_account_number() {
	    $product_number = self::DHL_RETURN_PRODUCT;
	    $account_number = Package::get_setting( 'account_number' ) . $product_number . Package::get_participation_number( 'return' );

	    return $account_number;
    }

    /**
     * @param Label $label
     * @return array
     *
     * @throws Exception
     */
    protected function get_create_label_request( $label ) {
        $shipment = $label->get_shipment();

        if ( ! $shipment ) {
            throw new Exception( sprintf( __( 'Could not fetch shipment %d.', 'woocommerce-germanized-dhl' ), $label->get_shipment_id() ) );
        }

        $services  = array();
        $bank_data = array();

        foreach( $label->get_services() as $service ) {

            $services[ $service ] = array(
                'active' => 1
            );

            switch ( $service ) {
                case 'AdditionalInsurance':
                    $services[ $service ]['insuranceAmount'] = $shipment->get_total();
                    break;
                case 'IdentCheck':
                    $services[ $service ]['Ident']['surname']     = $shipment->get_first_name();
                    $services[ $service ]['Ident']['givenName']   = $shipment->get_last_name();
                    $services[ $service ]['Ident']['dateOfBirth'] = $label->get_ident_date_of_birth() ? $label->get_ident_date_of_birth()->date( 'Y-m-d' ) : '';
                    $services[ $service ]['Ident']['minimumAge']  = $label->get_ident_min_age();
                    break;
                case 'CashOnDelivery':
                    $services[ $service ]['codAmount'] = $label->get_cod_total();

                    $bank_data_map = array(
                        'bank_holder' => 'accountOwner',
                        'bank_name'   => 'bankName',
                        'bank_iban'   => 'iban',
                        'bank_ref'    => 'note1',
                        'bank_ref_2'  => 'note2',
                        'bank_bic'    => 'bic'
                    );

                    foreach ( $bank_data_map as $key => $value ) {
                        if ( $setting_value = Package::get_setting( $key ) ) {
                            $bank_data[ $value ] = $setting_value;
                        }
                    }
                    break;
                case 'PreferredDay':
                    $services[ $service ]['details'] = $label->get_preferred_day() ? $label->get_preferred_day()->date( 'Y-m-d' ) : '';
                    break;
                case 'PreferredTime':
                    $services[ $service ]['type'] = wc_gzd_dhl_aformat_preferred_api_time( $label->get_preferred_time() );
                    break;
                case 'VisualCheckOfAge':
                    $services[ $service ]['type'] = $label->get_visual_min_age();
                    break;
                case 'PreferredLocation':
                    $services[ $service ]['details'] = $label->get_preferred_location();
                    break;
                case 'PreferredNeighbour':
                    $services[ $service ]['details'] = $label->get_preferred_neighbor();
                    break;
            }
        }

        $dhl_label_body = array(
            'Version'            => array(
                'majorRelease'   => '3',
                'minorRelease'   => '0'
            ),
            'labelResponseType'  => 'B64',
            'ShipmentOrder'      => array (
                'sequenceNumber' => $label->get_shipment_id(),
                'Shipment'       => array(
                    'ShipmentDetails' => array(
                        'product'           => $label->get_dhl_product(),
                        'accountNumber'     => self::get_account_number( $label->get_dhl_product() ),
                        'customerReference' => wc_gzd_dhl_get_label_reference( __( 'Shipment #{shipment_id} to order #{order_id}', 'woocommerce-germanized-dhl' ), array( '{shipment_id}' => $shipment->get_id(), '{order_id}' => $shipment->get_order_id() ) ),
                        'shipmentDate'      => date('Y-m-d' ),
                        'ShipmentItem'      => array(
                            'weightInKG' => wc_get_weight( $shipment->get_weight(), 'kg' ),
	                        'lengthInCM' => wc_get_dimension( $shipment->get_length(), 'cm' ),
                            'widthInCM'  => wc_get_dimension( $shipment->get_width(), 'cm' ),
                            'heightInCM' => wc_get_dimension( $shipment->get_height(), 'cm' ),
                        ),
                        'Service'           => $services,
                        'Notification'      => $label->has_email_notification() ? array( 'recipientEmailAddress' => $shipment->get_email() ) : array(),
                        'BankData'          => array(),
                    ),
                    'Shipper'       => array(
                        'Name'      => array(
                            'name1' => Package::get_setting( 'shipper_company' ) ? Package::get_setting( 'shipper_company' ) : Package::get_setting( 'shipper_name' ),
                            'name2' => Package::get_setting( 'shipper_company' ) ? Package::get_setting( 'shipper_name' ) : '',
                        ),
                        'Address'   => array(
                            'streetName'   => Package::get_setting( 'shipper_street' ),
                            'streetNumber' => Package::get_setting( 'shipper_street_no' ),
                            'zip'          => Package::get_setting( 'shipper_postcode' ),
                            'city'         => Package::get_setting( 'shipper_city' ),
                            'Origin'       => array(
                                'countryISOCode' => Package::get_setting( 'shipper_country' ),
                                'state'          => wc_gzd_dhl_format_label_state( Package::get_setting( 'shipper_state' ), Package::get_setting( 'shipper_country' ) ),
                            )
                        ),
                        'Communication' => array(
                            'phone' => Package::get_setting( 'shipper_phone' ),
                            'email' => Package::get_setting( 'shipper_email' )
                        )
                    ),
                    'Receiver'             => array(
                        'name1'            => $shipment->get_company() ? $shipment->get_company() : $shipment->get_formatted_full_name(),
                        'Address'          => array(
                            'name2'        => $shipment->get_company() ? $shipment->get_formatted_full_name() : '',
                            'streetName'   => $shipment->get_address_street(),
                            'streetNumber' => $shipment->get_address_street_number(),
                            'zip'          => $shipment->get_postcode(),
                            'city'         => $shipment->get_city(),
                            'Origin'       => array(
                                'countryISOCode' => $shipment->get_country(),
                                'state'          => wc_gzd_dhl_format_label_state( $shipment->get_state(), $shipment->get_country() )
                            )
                        ),
                        'Communication' => array(
                            'phone' => $shipment->get_phone(),
                            'email' => $shipment->get_email()
                        )
                    )
                )
            )
        );

        if ( $shipment->send_to_external_pickup( array_keys( wc_gzd_dhl_get_pickup_types() ) ) ) {
            // Address is NOT needed if using a parcel shop
            unset( $dhl_label_body['ShipmentOrder']['Shipment']['Receiver']['Address'] );

            $parcel_shop = array(
                'zip'    => $shipment->get_postcode(),
                'city'   => $shipment->get_city(),
                'Origin' => array(
                    'countryISOCode' => $shipment->get_country(),
                    'state'          => wc_gzd_dhl_format_label_state( $shipment->get_state(), $shipment->get_country() )
                )
            );

            $address_number = filter_var( $shipment->get_address_1(), FILTER_SANITIZE_NUMBER_INT );

            if ( $shipment->send_to_external_pickup( 'packstation' ) ) {
                $parcel_shop['postNumber']        = ParcelLocator::get_postnumber_by_shipment( $shipment );
                $parcel_shop['packstationNumber'] = $address_number;

                $dhl_label_body['ShipmentOrder']['Shipment']['Receiver']['Packstation'] = $parcel_shop;
            }

            if ( $shipment->send_to_external_pickup( 'postoffice' ) || $shipment->send_to_external_pickup( 'parcelshop' ) ) {
                if ( $post_number = ParcelLocator::get_postnumber_by_shipment( $shipment ) ) {
                    $parcel_shop['postNumber'] = $post_number;
                    unset( $dhl_label_body['ShipmentOrder']['Shipment']['Receiver']['Communication']['email'] );
                }

                $parcel_shop['postfilialNumber'] = $address_number;
                $dhl_label_body['ShipmentOrder']['Shipment']['Receiver']['Postfiliale'] = $parcel_shop;
            }
        }

        if ( $label->has_return() ) {
            $dhl_label_body['ShipmentOrder']['Shipment']['ShipmentDetails']['returnShipmentAccountNumber'] = self::get_return_account_number();
            $dhl_label_body['ShipmentOrder']['Shipment']['ShipmentDetails']['returnShipmentReference']     = wc_gzd_dhl_get_label_reference( __( 'Return shipment #{shipment_id} to order #{order_id}', 'woocommerce-germanized-dhl' ), array( '{shipment_id}' => $shipment->get_id(), '{order_id}' => $shipment->get_order_id() ) );

            $dhl_label_body['ShipmentOrder']['Shipment']['ReturnReceiver'] = array(
                'Name' => array(
                    'name1' => $label->get_return_company() ? $label->get_return_company() : $label->get_return_formatted_full_name(),
                    'name2' => $label->get_return_company() ? $label->get_return_formatted_full_name() : ''
                ),
                'Address' => array(
                    'streetName'   => $label->get_return_street(),
                    'streetNumber' => $label->get_return_street_number(),
                    'zip'          => $label->get_return_postcode(),
                    'city'         => $label->get_return_city(),
                    'Origin'       => array(
                        'countryISOCode' => $label->get_return_country(),
                        'state'          => wc_gzd_dhl_format_label_state( $label->get_return_state(), $label->get_return_country() ),
                    )
                ),
                'Communication' => array(
                    'phone' => $label->get_return_phone(),
                    'email' => $label->get_return_email()
                )
            );
        }

        if ( $label->codeable_address_only() ) {
            $dhl_label_body['ShipmentOrder']['PrintOnlyIfCodeable'] = array( 'active' => 1 );
        }

        if ( Package::is_crossborder_shipment( $shipment->get_country() ) ) {

            if ( sizeof( $shipment->get_items() ) > self::DHL_MAX_ITEMS ) {
                throw new Exception( sprintf( __( 'Only %s shipment items can be processed, your shipment has %s', 'woocommerce-germanized-dhl' ), self::DHL_MAX_ITEMS, sizeof( $shipment->get_items() ) ) );
            }

            $customsDetails   = array();
            $item_description = '';

            foreach ( $shipment->get_items() as $key => $item ) {

                $item_description .= ! empty( $item_description ) ? ', ' : '';
                $item_description .= $item->get_name();

                $json_item = array(
                    'description'         => substr( $item->get_name(), 0, 255 ),
                    'countryCodeOrigin'   => $item->get_meta( '_dhl_manufacture_country' ),
                    'customsTariffNumber' => $item->get_meta( '_dhl_hs_code' ),
                    'amount'              => intval( $item->get_quantity() ),
                    'netWeightInKG'       => round( floatval( wc_get_weight( $item->get_weight(), 'kg' ) ), 2 ),
                    'customsValue'        => round( floatval( $item->get_total() ), 2 ),
                );

                array_push($customsDetails, $json_item );
            }

            $item_description = substr( $item_description, 0, 255 );

            $dhl_label_body['ShipmentOrder']['Shipment']['ExportDocument'] = array(
                'invoiceNumber'         => $shipment->get_id(),
                'exportType'            => 'OTHER',
                'exportTypeDescription' => $item_description,
                'termsOfTrade'          => $label->get_duties(),
                'placeOfCommital'       => $shipment->get_country(),
                'ExportDocPosition'     => $customsDetails
            );
        }

        // Unset/remove any items that are empty strings or 0, even if required!
        $this->body_request = $this->walk_recursive_remove( $dhl_label_body );

        // Ensure Export Document is set before adding additional fee
        if ( isset( $this->body_request['ShipmentOrder']['Shipment']['ExportDocument'] ) ) {
            // Additional fees, required and 0 so place after check
            $this->body_request['ShipmentOrder']['Shipment']['ExportDocument']['additionalFee'] = 0;
        }

        // If "Ident-Check" enabled, then ensure both fields are passed even if empty
        if ( $label->has_service( 'IdentCheck' ) ) {
            if ( ! isset( $this->body_request['ShipmentOrder']['Shipment']['ShipmentDetails']['Service']['IdentCheck']['Ident']['minimumAge'] ) ) {
                $this->body_request['ShipmentOrder']['Shipment']['ShipmentDetails']['Service']['IdentCheck']['Ident']['minimumAge'] = '';
            }
            if ( ! isset( $this->body_request['ShipmentOrder']['Shipment']['ShipmentDetails']['Service']['IdentCheck']['Ident']['dateOfBirth'] ) ) {
                $this->body_request['ShipmentOrder']['Shipment']['ShipmentDetails']['Service']['IdentCheck']['Ident']['dateOfBirth'] = '';
            }
        }

        // Ensure 'postNumber' is passed with 'Postfiliale' even if empty
        /*if ( ! isset( $this->body_request['ShipmentOrder']['Shipment']['Receiver']['Postfiliale']['postNumber'] ) ) {
            // Additional fees, required and 0 so place after check
            $this->body_request['ShipmentOrder']['Shipment']['Receiver']['Postfiliale']['postNumber'] = '';
        }*/

        return $this->body_request;
    }
}
