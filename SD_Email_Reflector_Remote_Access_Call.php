<?php
/**
	@brief		A remote access call to ThreeWP Email Reflector.
	
	Contains one or more {SD_Email_Reflector_Remote_Access_Command}s.
	
	Given an admin-supplied $url and an encryption $key, either a global key or a key specific to a list, the remote access call will send the commands and return the result.
	
	@par		Keys
	
	The key can either be a global key from the global settings of the Email Reflector, or a key from a specific list.
	
	If a list key is used, then the list_id variable must be specified, else Remote Access won't bother looking.
	
	@par		Example: list based access

<pre><code>
try
{
	require_once( 'SD_Email_Reflector_Remote_Access_Call.php' );
	$call = new SD_Email_Reflector_Remote_Access_Call();
	
	//// This key is specified in the settings for list #44.
	$call->key = 't9834hguiernui34ht3hntoiju50234roietjoiawejglaertnaotjheriuthnerkgneraiogjero';
	$call->list_id = 44;
	
	//// The URL comes from the admin.
	$call->url = 'http://emailreflector.sverigedemokraterna.se/remote-access/';

	//// Update list #44. Change the writers setting to just this email address.
	$command = SD_Email_Reflector_Remote_Access_Command::new_update_setting( 44, 'writers', "it@sverigedemokraterna.se" );
	$call->add_command( $command );

	//// Append info@ to the writers setting. Note that adding a newline at the beginning is a good idea, since newlines aren't guaranteed in the settings.
	$command = SD_Email_Reflector_Remote_Access_Command::new_update_setting( 44, 'writers', "\ninfo@sverigedemokraterna.se" );
	$call->add_command( $command );

	//// Remove duplicates using the uniq function.
	$command = SD_Email_Reflector_Remote_Access_Command::new_uniq_setting( 44, 'writers' );
	$call->add_command( $command );

	$call->execute();
}
catch( Exception $e )
{
	echo $e->getMessage();
}
</code></pre>
	
	@par		Example: admin access

<pre><code>

try
{
	require_once( 'SD_Email_Reflector_Remote_Access_Call.php' );
	$call = new SD_Email_Reflector_Remote_Access_Call();
	
	//// This is an admin key and needs no list_id.
	$call->key = 'gfi4o3uthb3u4ytb43jutberkjgbnerjgergjklnwerjkgerg';
	
	//// The URL comes from the admin.
	$call->url = 'http://emailreflector.sverigedemokraterna.se/remote-access/';

	//// Update list #44. Change the readers setting to just this email address.
	$command = SD_Email_Reflector_Remote_Access_Command::new_update_setting( 44, 'readers', "it@sverigedemokraterna.se" );
	$call->add_command( $command );

	//// Append info@ to the writers setting. Note that adding a newline at the beginning is a good idea, since newlines aren't guaranteed in the settings.
	//// Since we're using an admin key we can change whatever lists we feel like.
	$command = SD_Email_Reflector_Remote_Access_Command::new_update_setting( 12, 'writers', "\ninfo@sverigedemokraterna.se" );
	$call->add_command( $command );

	//// Remove duplicate writers from list 5 using the uniq function.
	$command = SD_Email_Reflector_Remote_Access_Command::new_uniq_setting( 5, 'writers' );
	$call->add_command( $command );

	//// Retrieve the queue size.
	//// Save the id (array key) where the command was saved.
	$command = SD_Email_Reflector_Remote_Access_Command::new_get_queue_size();
	$queue_command_id = $call->add_command( $command );

	$call->execute();
	
	//// Retrieve the ID of the queue command using the id we saved previously.
	$queue_command = $call->commands[ $queue_command_id ];
	//// According to the documentation, the queue size is saved in the ->size variable.
	echo "The queue size is: " . $queue_command->size;
}
catch( Exception $e )
{
	echo $e->getMessage();
}
</code></pre>
	
	@version		2012-04-19

	@par	Changelog
	
	@b 2012-04-19	Initial release.
	
**/
class SD_Email_Reflector_Remote_Access_Call
{
	/**
		Array of {SD_Email_Reflector_Remote_Access_Command}s
		@var	$commands
	**/
	public $commands = array();
	
	/**
		Key with which to encrypt this call. This is the same key that is specified in the global remote access settings or in a list.
		@var	$key
	**/
	public $key;
	
	/**
		If the key is from a specific list, which list is it?
		@var	$list_id
	**/
	public $list_id;
	
	/**
		Where to send the remote access request.
		@var	$url
	**/
	public $url;
	
	/**
		@brief		Adds this command to our command store.
		
		Mostly a convenience function. The command is placed under a unique key in the array for easy lookup and retrieval later.

		@param		$command
						A SD_Email_Reflector_Remote_Access_Command object.
		
		@return		The unique int array key under which the command was placed. 
	**/
	public function add_command( $command )
	{
		$rand = rand( 1, PHP_INT_MAX );
		$this->commands[ $rand ] = $command;
		return $rand;
	}
	
	/**
		@brief		Sends the call to the remote access server.
		@throws		Exceptions for: no key, no url.
	**/
	public function execute()
	{
		if ( $this->key == '' )
			throw new Exception( 'No key set. Cannot sign the call.' );
		if ( $this->url == '' )
			throw new Exception( 'No URL set. Cannot send the url anywhere.' );
		
		// @ = Ignore $iv warning.
		// The $iv parameter for openssl_encrypt appeared in 5.3.3.
		// Ignoring the warning ensures that the encryption works between <5.3.3 which doesn't know what an IV is, and >5.3.2 that warns if no IV parameter is specified. *sigh*
		$data = array(
			'commands' => @openssl_encrypt( serialize( $this->commands ), 'aes-256-cbc', $this->key, false ),
			'key' => hash( 'sha512', $this->key ),
			'list_id' => $this->list_id,
			'sd_email_reflector_remote_access' => true,
		);
		
		$ch = curl_init( $this->url );
		curl_setopt_array( $ch, array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $data,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_TIMEOUT => 10,
		) );
		$result = curl_exec( $ch );
		
		$s = 'sd_email_reflector_remote_access';
		
		// Is there a proper reply to be extracted?
		$startpos = strpos( $result, '<' . $s . '>' );
		if ( $startpos === false )
			throw new Exception( 'No reply found.' );
		$endpos = strpos( $result, '</' . $s . '>' );
		if ( $endpos === false )
			throw new Exception( 'No reply found.' );
		
		// Extract the reply
		$s_length = strlen( $s ) + 2;
		$reply = substr( $result, $startpos + $s_length, $endpos - $startpos - $s_length );
		
		// Unserialize
		$reply = unserialize( $reply );
		
		if ( $reply === false )
			throw new Exception( 'Reply incorrectly formed.' );
		
		// @ = Ignore $iv warning.
		$reply->commands = @openssl_decrypt( $reply->commands, 'aes-256-cbc', $this->key, false );
		
		$reply->commands = unserialize( $reply->commands );
		
		if ( $reply->commands === false )
			throw new Exception( 'Unable to read reply. Problem after decryption.' );
		
		$this->commands = $reply->commands;
	}
}

/**
	@brief		Remote Access command.
	
**/
class SD_Email_Reflector_Remote_Access_Command
{
	const command_append_setting	= 'append_setting';
	const command_get_queue_size	= 'get_queue_size';
	const command_get_option		= 'get_option';
	const command_get_setting		= 'get_setting';
	const command_sort_setting		= 'sort_setting';
	const command_update_option		= 'update_option';
	const command_update_setting	= 'update_setting';
	const command_uniq_setting		= 'uniq_setting';
	
	/**
		The command string. Just a string.
		@var	$command
	**/
	public $command;
	
	/**
		An error message, if any. If no errors occurred, it is left empty.
		@var	$error
	**/
	public $error = '';
	
	/**
		When the command has handled by the handle() method.
		
		If this command was not handled, the value remains 0 and $error is probably set to something descriptive.
		@var	$handled
	**/
	public $handled = 0;
	
	/**
		List settings array.
		
		This variable is used by the handle function to handle various list-based commands.

		@var	$list_settings
	**/
	public $list_settings = false;
	
	/**
		@brief		Checks whether the command has admin access.
		@throws		Exception if admin access was not granted.
	**/
	private function check_admin_access()
	{
		if ( $this->admin !== true )
			throw new Exception( 'No admin access for this key.' );
	}

	/**
		@brief		Checks whether the command has access to the requested $this->list_id.
		@throws		Exception if list access was not granted.
	**/
	private function check_list_access()
	{
		if ( ! isset( $this->list_id ) )
			throw new Exception( 'List access check was requested but no list ID was specified.' );
		
		$this->list_id = intval( $this->list_id );

		if ( $this->list_settings == false )
			throw new Exception( 'No access to the requested list: ' . $this->list_id );

		if ( $this->list_id != $this->list_settings->list_id )
			throw new Exception( $this->error = sprintf(
				"List ID's do not match: %s was requested, %s was given.",
				$this->list_id,
				$this->list_settings->list_id
			) );
	}

	/**
		@brief		Tell this command to handle itself.
		
		Will do access checks and then calls whatever handle_* method is associated to this command type.
		
		If the command was handled, the $handled variable is set to the current unix time.
	**/
	public function handle()
	{
		$function = 'handle_' . $this->command;
		try
		{
			$this->$function();
			$this->handled = time();
			apply_filters( 'threewp_email_reflector_log', 'remote_access', 'Handled command ' . $this->command  . ' for key ' . $this->key . '.' );
		}
		catch ( Exception $e )
		{
			apply_filters( 'threewp_email_reflector_log', 'remote_access', 'Could not handle command ' . $this->command  . ' for key ' . $this->key . '. ' . $e->getMessage() );
			$this->error = $e->getMessage();
		} 
	}

	/**
		@brief		Handles appending of a list setting.
		@see		new_append_setting
	**/
	private function handle_append_setting()
	{
		$this->check_list_access();
		$setting = $this->setting;
		$value = $this->list_settings->$setting . $this->value;
		apply_filters( 'threewp_email_reflector_update_list_setting', $this->list_id, $this->setting, $value );
	}
	
	/**
		@brief		Retrieves an option.
		
		The result is placed in $this->value.
		
		@see		new_get_option
	**/
	private function handle_get_option()
	{
		$this->check_admin_access();
		$this->value = apply_filters( 'threewp_email_reflector_get_option', $this->option );
	}
	
	/**
		@brief		Retrieves the send queue size.
		
		The result is placed in $this->size.
		
		@see		new_get_queue_size
	**/
	private function handle_get_queue_size()
	{
		$this->check_admin_access();
		$this->size = apply_filters( 'threewp_email_reflector_get_queue_size', '' );
	}
	
	/**
		@brief		Retrieve a list setting.
		
		The setting value is placed in $this->value.
		
		@see		new_get_setting
	**/
	private function handle_get_setting()
	{
		$setting = $this->setting;
		$this->value = $this->list_settings->$setting;
	}
	
	/**
		@brief		Handles sorting of a list setting.
		@see		new_sort_setting
	**/
	private function handle_sort_setting()
	{
		$this->check_list_access();
		$setting = $this->setting;
		$values = apply_filters( 'threewp_email_reflector_get_list_settings', $this->list_id );
		$value = $values->$setting;
		
		$value = array_filter( explode( "\n", $value ) );
		asort( $value );
		$value = implode( "\n", $value );
		
		apply_filters( 'threewp_email_reflector_update_list_setting', $this->list_id, $this->setting, $value );
	}
	
	/**
		@brief		Handles updating of a ThreeWP global option.
		@see		new_update_option
	**/
	private function handle_update_option()
	{
		$this->check_admin_access();
		apply_filters( 'threewp_email_reflector_update_option', $this->option, $this->value );
	}
	
	/**
		@brief		Handles updating of a list setting.
		@see		new_update_setting
	**/
	private function handle_update_setting()
	{
		$this->check_list_access();
		apply_filters( 'threewp_email_reflector_update_list_setting', $this->list_id, $this->setting, $this->value );
	}
	
	/**
		@brief		Handles uniqueing of a list setting.
		@see		new_uniq_setting
	**/
	private function handle_uniq_setting()
	{
		$this->check_list_access();
		$setting = $this->setting;
		$value = $this->list_settings->$setting;
		
		$value = array_filter( explode( "\n", $value ) );
		$value = array_flip( $value );
		$value = array_flip( $value );
		$value = implode( "\n", $value );
		
		apply_filters( 'threewp_email_reflector_update_list_setting', $this->list_id, $this->setting, $value );
	}
	
	/**
		@brief		Create a command to append a value to list setting.
		@param		$list_id
						ID of list to modify.
		@param		$setting
						Setting to modify.
		@param		$value
						Value to append.
		@return		A new SD_Email_Reflector_Remote_Access_Command.
	**/
	public static function new_append_setting( $list_id, $setting, $value )
	{
		$command = new SD_Email_Reflector_Remote_Access_Command();
		$command->command = self::command_append_setting;
		$command->list_id = $list_id;
		$command->setting = $setting;
		$command->value = $value;
		return $command;
	}
	
	/**
		@brief		Create a command to get an option.
		
		The option value is placed in $this->value.

		@param		$option
						Name of option to get.
		
		@return		A new SD_Email_Reflector_Remote_Access_Command.
	**/
	public static function new_get_option( $option )
	{
		$command = new SD_Email_Reflector_Remote_Access_Command();
		$command->command = self::command_get_option;
		$command->option = $option;
		return $command;
	}
	
	/**
		@brief		Create a command to get the size of the send queue.
		
		The result is placed in $this->size.
		
		@return		A new SD_Email_Reflector_Remote_Access_Command.
	**/
	public static function new_get_queue_size()
	{
		$command = new SD_Email_Reflector_Remote_Access_Command();
		$command->command = self::command_get_queue_size;
		return $command;
	}
	
	/**
		@brief		Create a command to get a list setting.
		
		The setting value is placed in $this->value.

		@param		$list_id
						ID of target list.
		@param		$setting
						Setting to get.
		
		@return		A new SD_Email_Reflector_Remote_Access_Command.
	**/
	public static function new_get_setting( $list_id, $setting )
	{
		$command = new SD_Email_Reflector_Remote_Access_Command();
		$command->command = self::command_get_setting;
		$command->list_id = $list_id;
		$command->setting = $setting;
		return $command;
	}
	
	/**
		@brief		Create a command to sort a list setting.
		
		@param		$list_id
						ID of list to modify.
		@param		$setting
						Setting to sort.
		
		@return		A new SD_Email_Reflector_Remote_Access_Command.
	**/
	public static function new_sort_setting( $list_id, $setting )
	{
		$command = new SD_Email_Reflector_Remote_Access_Command();
		$command->command = self::command_sort_setting;
		$command->list_id = $list_id;
		$command->setting = $setting;
		return $command;
	}

	/**
		@brief		Create a command to update a global option for ThreeWP Email Reflector.

		@param		$option
						Name of option to modify.

		@param		$value
						New value of option.

		@return		A new SD_Email_Reflector_Remote_Access_Command.
	**/
	public static function new_update_option( $option, $value )
	{
		$command = new SD_Email_Reflector_Remote_Access_Command();
		$command->command = self::command_update_option;
		$command->option = $option;
		$command->value = $value;
		return $command;
	}
	
	/**
		@brief		Create a command to update a setting in a list.
		@param		$list_id
						ID of list to modify.
		@param		$setting
						Setting to modify.
		@param		$value
						New value of setting.
		@return		A new SD_Email_Reflector_Remote_Access_Command.
	**/
	public static function new_update_setting( $list_id, $setting, $value )
	{
		$command = new SD_Email_Reflector_Remote_Access_Command();
		$command->command = self::command_update_setting;
		$command->list_id = $list_id;
		$command->setting = $setting;
		$command->value = $value;
		return $command;
	}
	
	/**
		@brief		Create a command to keep only unique lines in a list setting.
		
		When used on a string setting, it will remove duplicate lines.
		
		@param		$list_id
						ID of list to modify.
		@param		$setting
						Setting to uniq.
		
		@return		A new SD_Email_Reflector_Remote_Access_Command.
	**/
	public static function new_uniq_setting( $list_id, $setting )
	{
		$command = new SD_Email_Reflector_Remote_Access_Command();
		$command->command = self::command_uniq_setting;
		$command->list_id = $list_id;
		$command->setting = $setting;
		return $command;
	}
}
