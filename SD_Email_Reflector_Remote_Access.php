<?php
/*                                                                                                                                                                                                                                                             
Plugin Name: SD Email Reflector Remote Access
Plugin URI: http://it.sverigedemokraterna.se
Description: Provides a remote access API to ThreeWP Email Reflector.
Version: 1.0
Author: Sverigedemokraterna IT
Author URI: http://it.sverigedemokraterna.se
Author Email: it@mindreantre.se
*/

if(preg_match('#' . basename(__FILE__) . '#', $_SERVER['PHP_SELF'])) { die('You are not allowed to call this page directly.'); }

require_once('SD_Email_Reflector_Remote_Access_Base.php');
class SD_Email_Reflector_Remote_Access extends SD_Email_Reflector_Remote_Access_Base
{
	public function __construct()
	{
		parent::__construct(__FILE__);
		
		add_filter( 'threewp_email_reflector_admin_edit_get_inputs', array( $this, 'threewp_email_reflector_admin_edit_get_inputs' ) );
		add_filter( 'threewp_email_reflector_admin_edit_get_setting_types', array( $this, 'threewp_email_reflector_admin_edit_get_setting_types' ) );
		add_filter( 'threewp_email_reflector_admin_settings_get_setting_types', array( $this, 'threewp_email_reflector_admin_settings_get_setting_types' ) );
		add_filter( 'threewp_email_reflector_admin_settings_get_inputs', array( $this, 'threewp_email_reflector_admin_settings_get_inputs' ) );
		add_filter( 'threewp_email_reflector_get_access_types', array( &$this, 'threewp_email_reflector_get_access_types' ) );
		add_filter( 'threewp_email_reflector_admin_overview_get_list_info', array( $this, 'threewp_email_reflector_admin_overview_get_list_info' ), 10, 2 );
		add_filter( 'threewp_email_reflector_get_log_types', array( $this, 'threewp_email_reflector_get_log_types' ) );

		add_action( 'wp_loaded', array( &$this, 'wp_loaded' ) );
		add_shortcode( 'sd_email_reflector_remote_access', array( $this, 'shortcode_sd_email_reflector_remote_access' ) );
	}
	
	public function wp_loaded()
	{
		$remote_access_post = apply_filters( 'threewp_email_reflector_get_option', 'remote_access_post' );
		if ( ! $remote_access_post )
			return;

		$result =  $this->shortcode_sd_email_reflector_remote_access();
		if ( $result == '' )
			return;
		
		echo $result;
		exit;
	}
	
	/**
		@brief		Shortcode to enable remote access to the email reflector.
	**/
	public function shortcode_sd_email_reflector_remote_access()
	{
		$s = 'sd_email_reflector_remote_access';
		if ( ! isset( $_POST[ $s ] ) )
			return '';
		
		if ( ! isset( $_POST[ 'commands' ] ) || ! isset( $_POST['key'] ) )
			return '';
		
		$data = array(
			'commands' => $_POST[ 'commands' ],
			'key' => $_POST[ 'key' ],
			'list_id' => $_POST[ 'list_id' ],
		);
		$data = $this->array_to_object( $data );
		$data = $this->handle_call( $data );
		
		$rv = '<' . $s . '>' . serialize( $data ) . '</' . $s . '>';
		return $rv;
	}
	
	private function handle_call( $data )
	{
		require_once( 'SD_Email_Reflector_Remote_Access_Call.php' );
		
		$admin_key = false;
		$found_list_settings = false;
		
		// Is this an admin key?
		$remote_access_keys = apply_filters( 'threewp_email_reflector_get_option', 'remote_access_keys' );
		$remote_access_keys = str_replace( "\r", '', $remote_access_keys );
		$remote_access_keys = array_filter( explode( "\n", $remote_access_keys ) );
		$found_key = false;
		foreach( $remote_access_keys as $key )
		{
			$hashed_key = hash( 'sha512', $key );
			if ( $hashed_key == $data->key )
			{
				$admin_key = true;
				$found_key = $key;
				break;
			}
		}
		
		if ( $found_key === false && $data->list_id != false )
		{
			$list_settings = apply_filters( 'threewp_email_reflector_get_list_settings', $data->list_id );

			$remote_access_keys = $list_settings->remote_access_keys;
			$remote_access_keys = str_replace( "\r", '', $remote_access_keys );
			$remote_access_keys = array_filter( explode( "\n", $remote_access_keys ) );

			foreach( $remote_access_keys as $key )
			{
				$hashed_key = hash( 'sha512', $key );
				if ( $hashed_key == $data->key )
				{
					$found_list_settings = $list_settings;
					$found_key = $key;
					break;
				}
			}
		}
		
		if ( $found_key === false )
		{
			apply_filters( 'threewp_email_reflector_log', 'remote_access', 'Key was not found.' );
			return $data;
		}
		
		$data->found_key = $found_key;

		// We've found an admin key. Decrypt the commands.
		// $iv = substr( hash( 'sha512', $data->found_key ) , 0, 16 ); 
		// @ = Ignore $iv warning.
		$data->commands = @openssl_decrypt( $data->commands, 'aes-256-cbc', $found_key, false );
		$data->commands = unserialize( $data->commands );
		
		if ( ! is_array( $data->commands ) )
		{
			apply_filters( 'threewp_email_reflector_log', 'remote_access', 'Unable to decrypt and unserialize commands.' );
			return $data;
		}
		
		foreach( $data->commands as $command )
		{
			$command->key = $found_key;

			// Admin has access to whichever lists the command wants.
			if ( $admin_key && $command->list_id > 0 )
				$command->list_settings = apply_filters( 'threewp_email_reflector_get_list_settings', $command->list_id );
			else
				$command->list_settings = $found_list_settings;
			
			if ( $admin_key )
				$command->admin = true;
				
			$command->handle();
			unset( $command->list_settings );
			unset( $command->admin );
			unset( $command->key );
		}
		
		// We're done handling the commands. Recrypt!
		// @ = Ignore $iv warning.
		$data->commands = @openssl_encrypt( serialize( $data->commands ), 'aes-256-cbc', $found_key, false );
		unset( $data->key );
		
		return $data;
	}

	public function threewp_email_reflector_admin_edit_get_inputs( $inputs )
	{
		$post = apply_filters( 'threewp_email_reflector_get_option', 'remote_access_post' ); 
		$url = apply_filters( 'threewp_email_reflector_get_option', 'remote_access_url' );
		if ( $url == '' && $post != '1' )
			return array_merge( $inputs, array(
				'remote_access_info' => array(
					'access_type' => 'remote_access',
					'type' => 'rawtext',
					'value' => $this->_( 'The admin has not specified neither a remote access URL or checking of the request. No options can be shown.' ),
				),
			) );
		
		if ( $url == '' )
		{
			$url_text = $this->_( ' and any URL can be used' );
		}
		else
		{
			if ( $post == '1' )
				$url_text = sprintf(
					$this->_( ' and any URL can be used, in addition to <em>%s</em>' ),
					$url
				);
			else
				$url_text = sprintf(
					$this->_( ' and the call must be sent to <em>%s</em>' ),
					$url
				);
		}
		
		return array_merge( $inputs, array(
			'remote_access_info' => array(
				'access_type' => 'remote_access',
				'type' => 'rawtext',
				'value' => sprintf(
					$this->_( 'The list ID is <em>%s</em>%s.' ),
					$_REQUEST[ 'id' ],
					$url_text
				),
			),
			'remote_access_keys' => array(
				'access_type' => 'remote_access',
				'cols' => 60,
				'description' => $this->_( 'Remote access keys used to change this list remotely. One key per line.' ),
				'is_setting' => true,
				'label' => $this->_( 'Keys' ),
				'name' => 'remote_access_keys',
				'rows' => 5,
				'type' => 'textarea',
				'validation' => array( 'empty' => true ),
			),
		) );
	}
	
	public function threewp_email_reflector_admin_edit_get_setting_types( $setting_types )
	{
		return array_merge( $setting_types, array(
			'remote_access' => array(
				'heading' => $this->_( "Remote access" ),
			),
		) );
	}

	public function threewp_email_reflector_admin_settings_get_setting_types( $setting_types )
	{
		return array_merge( $setting_types, array(
			'remote_access' => array(
				'heading' => $this->_( "Remote access" ),
			),
		) );
	}
	
	public function threewp_email_reflector_get_access_types( $access_types )
	{
		$access_types[ 'remote_access' ] = array(
			'editable' => true,
			'label' => $this->_( 'Remote access' ),
			'name' => 'remote_access',
			'title' => $this->_( 'Allow remote access to settings' ),
		);
		return $access_types;
	}
	
	public function threewp_email_reflector_admin_settings_get_inputs( $inputs )
	{
		$inputs = array_merge( $inputs, array(
			'remote_access_text' => array(
				'setting_type' => 'remote_access',
				'type' => 'rawtext',
				'value' => $this->_( 'See the source code documentation for information about using the shortcode - [sd_email_reflector_remote_access] - and access keys.' ),
			),
			'remote_access_post' => array(
				'description' => $this->_( 'Check all Wordpress requests for a remote access call? Else only the shortcode will work.' ),
				'label' => $this->_( 'Check _POST?' ),
				'name' => 'remote_access_post',
				'save' => true,
				'setting_type' => 'remote_access',
				'type' => 'checkbox',
				'value' => '1',
			),
			'remote_access_url' => array(
				'description' => $this->_( 'Optional URL to the post with the remote access shortcode. Either this or the check post option must be set.' ),
				'label' => $this->_( 'Address' ),
				'name' => 'remote_access_url',
				'save' => true,
				'setting_type' => 'remote_access',
				'size' => 50,
				'type' => 'text',
				'validation' => array( 'empty' => true ),
			),
			'remote_access_keys' => array(
				'cols' => 70,
				'description' => $this->_( 'List of admin remote access keys. One key per line.' ),
				'label' => $this->_( 'Remote access keys' ),
				'name' => 'remote_access_keys',
				'rows' => 5,
				'save' => true,
				'setting_type' => 'remote_access',
				'type' => 'textarea',
				'validation' => array( 'empty' => true ),
			),
		) );
		return $inputs;
	}
	
	public function threewp_email_reflector_admin_overview_get_list_info( $info, $list_settings )
	{
		if ( isset( $list_settings->remote_access_latest ) )
			$info[] =  sprintf( $this->_( 'Remotely accessed: %s' ), $list_settings->remote_access_latest );

		return $info;
	}
	
	public function threewp_email_reflector_get_log_types( $log_types )
	{
		return array_merge( $log_types, array(
			'remote_access' => array(
				'label' => $this->_( 'Remote access' )
			),
		) );
	}	
}
$sd_email_reflector_remote_access = new SD_Email_Reflector_Remote_Access();
