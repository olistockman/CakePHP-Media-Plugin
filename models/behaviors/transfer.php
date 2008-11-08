<?php
/**
 * Transfer Behavior File
 * 
 * Copyright (c) $CopyrightYear$ David Persson
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE
 * 
 * PHP version $PHPVersion$
 * CakePHP version $CakePHPVersion$
 * 
 * @category   file transfer
 * @package    attm
 * @subpackage attm.plugins.media.models.behaviors
 * @author     David Persson <davidpersson@qeweurope.org>
 * @copyright  $CopyrightYear$ David Persson <davidpersson@qeweurope.org>
 * @license    http://www.opensource.org/licenses/mit-license.php The MIT License
 * @version    SVN: $Id$
 * @version    Release: $Version$
 * @link       http://cakeforge.org/projects/attm The attm Project
 * @since      media plugin 0.50
 * 
 * @modifiedby   $LastChangedBy$
 * @lastmodified $Date$
 */
App::import('Vendor', 'Media.MimeType');
App::import('Vendor', 'Media.Medium');
App::import('Vendor', 'Media.MediaValidation');
App::import('Vendor', 'Media.TransferValidation');
/**
 * Transfer Behavior Class
 * 
 * Handles file transfers
 * This behavior is triggered by values submitted in the "file" field.
 *
 * @category   file transfer
 * @package    attm
 * @subpackage attm.media.models.behaviors
 * @author     David Persson <davidpersson@qeweurope.org>
 * @copyright  $CopyrightYear$ David Persson <davidpersson@qeweurope.org>
 * @license    http://www.opensource.org/licenses/mit-license.php The MIT License
 * @link       http://cakeforge.org/projects/attm The attm Project
 */
class TransferBehavior extends ModelBehavior {
/**
 * Holds data between function calls keyed by model alias
 * 
 * @var array
 */
	var $runtime = array();
/**
 * Settings keyed by model alias
 *
 * @var array
 */
	var $settings = array();
/**
 * Default settings
 * 
 * createDirectory
 * 	false - Fail on missing directories
 * 	true  - Recursively create missing directories 
 * trustClient
 * 	false -
 * 	true  - Trust the mime type submitted together with an upload
 * destinationFile
 * 	You may use markers here:
 * 	:DS:                    Directory seperator "/" or "\"
 * 	:WWW_ROOT:              Path to webroot of this app
 * 	:APP:                   Path to your app
 * 	:TMP:                   Path to app's tmp directory
 * 	:MEDIA:                 Path to your media root
 * 	:uuid:            	    An uuid generated by String::uuid()
 *	:day:					The current day
 * 	:month:					The current month
 * 	:year:					The current year
 * 	:Model.name:
 *  :Model.alias:
 * 	:Model.xyz:             Where xyz is a field of the submitted record
 * 	:Source.basename:       e.g. logo.png
 * 	:Source.filename:       e.g. logo
 * 	:Source.extension:      e.g. png
 * 	:Source.mimeType:       e.g. image_png
 * 	:Medium.name:           Medium name of the source file (e.g. image)
 * 	:Medium.short:          Short medium name of the source file (e.g. img)
 * 
 * @var array
 */
	var $_defaultSettings = array(
			'createDirectory' 	=> true,
			'trustClient' 		=> false,
			'destinationFile'	=> ':MEDIA:transfer:Medium.short::DS::Source.basename:',
		);
/**
 * Default runtime
 * 
 * @var array
 */		
	var $_defaultRuntime = array(
			'source' 		=> null,
			'temporary'	 	=> null,
			'destination' 	=> null,
			'isReady'	 	=> false,
			'hasPerformed' 	=> false,
			'markers' 		=> array(),
			);
/**
 * Setup
 * 
 * Merges default settings with provided config and sets default validation options
 *
 * @param object $model
 * @param array $config See defaultSettings for configuration options
 * @return void
 */
	function setup(&$model, $config = null) {
		if (!is_array($config)) {
			$this->settings[$model->alias] = $this->_defaultSettings;
		} else {
			$this->settings[$model->alias] = array_merge($this->_defaultSettings, $config);
		}
		$this->runtime[$model->alias] = $this->_defaultRuntime;

		/* Prepare validation */
		if (isset($model->validate['file'])) {
			foreach ($model->validate['file'] as &$rule) {
				$rule['allowEmpty'] = true;
				$rule['required'] = false;
				$rule['last'] = true;
			}
		}
	}
/**
 * Run before any or if validation occurs
 *
 * @param object $model
 * @return bool true
 */
	function beforeValidate(&$model) {
		/*
		 * Try to set source(,temporary) and destination 
		 * enabling validation rules to check transfer
		 */
		if ($this->prepare($model) === false) {
			$model->invalidate('file', 'error'); // preparation error
			return false;
		}

		// ...
		
		return true;
	}
/**
 * Triggers beforeEnter, performs transfer
 * 
 * @param object $model
 * @return bool
 */
	function beforeSave(&$model) {
		/* Integrating prepare */
		$preparation = $this->prepare($model);
		
		if ($preparation === false) {
			/* Malformed-nonblank resource or other error: Implies not ready */
			/* Failing hard here can be circumvented by using provided validations */
			return false;
		}
		if (is_null($preparation)) {
			/* Blank resource or file field not present: Nothing to transfer */
			if(array_key_exists('file', $model->data[$model->alias])) {
				unset($model->data[$model->alias]['file']);
			}
 			return true;
		}

		extract($this->runtime[$model->alias], EXTR_SKIP);
		extract($this->settings[$model->alias], EXTR_SKIP);
	 	
		/*
		 * Transfer is finally launched here because this way 
		 * we're sure that we don't create a zombie record on failure 
		 */
		if (!$this->perform($model)) { /* uses source, etc. from runtime */
			return false;
		}
		
		$model->data[$model->alias]['file'] = $destination['dirname'].DS.$destination['basename'];
		
		return $model->data[$model->alias];
	}		
/**
 * Triggered before beforeValidation and before beforeSave or upon user request
 * 
 * Prepares runtime for being used by the execute method
 * 
 * @param object $model
 * @param string $file Optionally provide a valid transfer resource to be used as source
 * @return mixed true if transfer is ready to be performed, false on error, null if no data was found
 * @todo Selectively fill markers
 */
	function prepare(&$model, $file = null) {
		if (isset($model->data[$model->alias]['file'])) {
			$file = $model->data[$model->alias]['file'];	
		}
		if (empty($file)) {
			return null;
		}
		if ($this->runtime[$model->alias]['hasPerformed']) {
	 		$this->reset($model);
		}
		if ($this->runtime[$model->alias]['isReady']) {
	 		return true; /* Don't do anything */
		}
		
		/* Extract runtime after reset to get default values */
		extract($this->settings[$model->alias], EXTR_SKIP);
		extract($this->runtime[$model->alias], EXTR_SKIP);
		 
		if (TransferValidation::blank($file)) {
	 		/* Set explicitly null enabling allowEmpty in rules act upon emptiness */
			return $model->data[$model->alias]['file'] = null;
		}  		
 		
		/* Letting source and temporary parse resource */
		if ($source = $this->_source($model, $file)) {
			$this->runtime[$model->alias]['source'] = $source;
		} else {
			return false;
		}
		if ($source['type'] !== 'file-local') {
			/* Temporary is allowed to fail silently */
			$temporary = $this->runtime[$model->alias]['temporary'] = $this->_temporary($model, $file);
		} 
		
		/*
		 * Fill Markers to enable substitution in destinationFile 
		 * Add more markers here if you need them in your path
		 */
		$this->_addMarker($model, 'DS', DS);
		$this->_addMarker($model, 'APP', APP);
		$this->_addMarker($model, 'TMP', TMP);
		$this->_addMarker($model, 'WWW_ROOT', WWW_ROOT);
		$this->_addMarker($model, 'MEDIA', MEDIA);

		$this->_addMarker($model, 'uuid', String::uuid());
		$this->_addMarker($model, 'year', date('Y'));
		$this->_addMarker($model, 'month', date('m'));
		$this->_addMarker($model, 'day', date('d'));
				
		$filename = $this->_addMarker($model, 'Source.filename', $source['filename'], true);
		$extension = $this->_addMarker($model, 'Source.extension', $source['extension'], true);
		$this->_addMarker($model, 'Source.basename', empty($extension) ? $filename : $filename . '.' . $extension);
		$this->_addMarker($model, 'Source.mimeType', $source['mimeType'], true);
		$this->_addMarker($model, 'Source.type', $source['type']);

		/* Figure out medium type and map it */
		$this->_addMarker($model, 'Medium.name', strtolower(Medium::name($source['file'], $source['mimeType'])));
		$this->_addMarker($model, 'Medium.short', Medium::short($source['file'], $source['mimeType']));

		if (isset($model->data[$model->alias])) { /* Needed for tableless Models */
			$this->_addMarker($model, $model->alias . '.', $model->data[$model->alias], true);
			$this->_addMarker($model, 'Model.', $model->data[$model->alias], true);
		}
		$this->_addMarker($model, 'Model.name', $model->name);
		$this->_addMarker($model, 'Model.alias', $model->alias);

		/* Work on destination */
		/* destinationFile extracted from settings */
		if ($destination = $this->_destination($model, $this->_replaceMarker($model, $destinationFile))) {
			$this->runtime[$model->alias]['destination'] = $destination;
		} else {
			return false;
		}

		/* Do error checks */
		if ($source == $destination || $temporary == $destination) {
			return false;
		}
		
		$Folder = new Folder($destination['dirname'], $createDirectory);
		if (!$Folder->pwd()) {
			trigger_error('TransferBehavior::prepare - Directory \'' . $destination['dirname'] . '\' could not be created or is not writable. Please check your permissions.', E_USER_WARNING);
			return false;
		}
				
		return $this->runtime[$model->alias]['isReady'] = true; /* Ready ! */ 
	}	
/**
 * Parse data to be used as source
 *
 * @param mixed Path to file in local FS, URL or file-upload array
 * @return mixed Array with parsed results on success, false on error
 * @todo evaluate errors in file uploads
 */
	function _source(&$model, $data) {
		if (TransferValidation::fileUpload($data)) {
			return array_merge($this->info($model, $data), array('error' => $data['error']));
		} else if (MediaValidation::file($data)) {
			return $this->info($model, $data);
		} else if (TransferValidation::url($data, array('scheme' => 'http'))) { 
			/* We currently do only support http */
			return $this->info($model, $data);
		} 
		return false;
	}	
/**
 * Parse data to be used as temporary
 *
 * @param mixed Path to file in local FS or file-upload array
 * @return mixed Array with parsed results on success, false on error
 * @todo evaluate errors in file uploads
 */	
	function _temporary(&$model, $data) {
		if (TransferValidation::fileUpload($data) && TransferValidation::uploadedFile($data['tmp_name'])) {
			return array_merge($this->info($model, $data['tmp_name']), array('error' => $data['error']));
		} else if (MediaValidation::file($data)) {
			return $this->info($model, $data);	
		} 
		return false;
	}	
/**
 * Parse data to be used as destination
 *
 * @param mixed Path to file in local FS
 * @return mixed Array with parsed results on success, false on error
 */	
	function _destination(&$model, $data) {
		if (MediaValidation::file($data , false)) { /* Destination file max not exist yet */
			if (!$data = $this->_alternativeFile($data)) {
				$this->log('Exceeded # of max. tries while finding alt. name for \''.basename($data).'\'');
				return false;
			}
			return $this->info($model, $data);
		} 
		return false;
	}	
/**
 * Performs a transfer
 *
 * @param object $model
 * @param array $source 
 * @param array $temporary
 * @param array $destination
 * @return bool true on success, false on failure
 */
	function perform(&$model) {
		$source      = $this->runtime[$model->alias]['source'];
		$temporary   = $this->runtime[$model->alias]['temporary'];
		$destination = $this->runtime[$model->alias]['destination'];
		
		$typeChain = implode('>>', array($source['type'], $temporary['type'], $destination['type']));
		$fileChain = implode('>>', array($source['file'], $temporary['file'], $destination['file']));

		if ($typeChain === 'file-upload-remote>>uploaded-file-local>>file-local') {
			return $this->runtime[$model->alias]['hasPerformed'] = move_uploaded_file($temporary['file'], $destination['file']);
		} 
		if ($typeChain === 'file-local>>>>file-local') {
			return $this->runtime[$model->alias]['hasPerformed'] = copy($source['file'], $destination['file']);
		}
		if ($typeChain === 'file-local>>file-local>>file-local') {
			return $this->runtime[$model->alias]['hasPerformed'] = copy($source['file'], $temporary['file']) && rename($temporary['file'], $destination['file']);
		}
		if ($source['type'] === 'http-url-remote') {
			if(!class_exists('HttpSocket')) {
				App::import('Core','HttpSocket');
			}
			
			$config = array('method' => 'GET', 'uri' => $source['file']);
			$Socket = new HttpSocket();
			$Socket->request($config);
			
			if (!empty($Socket->error) || $Socket->response['status']['code'] != 200) {
				return $this->runtime[$model->alias]['hasPerformed'] = false;	
			}
		}
		if ($typeChain === 'http-url-remote>>>>file-local') {
			return $this->runtime[$model->alias]['hasPerformed'] = file_put_contents($destination['file'], $Socket->response['body']);
		}
		if($typeChain === 'http-url-remote>>file-local>>file-local') {
			return $this->runtime[$model->alias]['hasPerformed'] = file_put_contents($temporary['file'], $Socket->response['body']) && rename($temporary['file'], $destination['file']);
		}
		return $this->runtime[$model->alias]['hasPerformed'] = false;
	}	
/**
 * Resets runtime property 
 *
 * @param object $model
 * @return void
 */
	function reset(&$model) {
		$this->runtime[$model->alias]['source']       = null;
		$this->runtime[$model->alias]['temporary']    = null;
		$this->runtime[$model->alias]['destination']  = null;
		$this->runtime[$model->alias]['isReady']      = false;
		$this->runtime[$model->alias]['hasPerformed'] = false;
		$this->runtime[$model->alias]['markers']      = array();
	}
/**
 * Convenience method which (if available) returns absolute path to last transferred file
 *
 * @param object $model
 * @return mixed
 */
	function getLastTransferredFile(&$model) {
		extract($this->runtime[$model->alias], EXTR_SKIP);
		
		if($hasPerformed) {
			return $destination['file'];
		}
		return false;
	}
/**
 * Checks if field contains a transferable resource
 *
 * @see TransferBehavior::source
 * 
 * @param object $model
 * @param array $field
 * @return bool
 */
	function checkResource(&$model, $field) {
		return TransferValidation::resource(current($field)); 
	}	
/**
 * Checks if sufficient permissions are set to access the resource
 * Source must be readable, temporary read or writable, destination writable
 *
 * @param object $model
 * @param array $field
 * @return bool
 */
	function checkAccess(&$model, $field) {
		extract($this->runtime[$model->alias]);
		
		if (MediaValidation::file($source['file'], true)) {
			if (!MediaValidation::access($source['file'], 'r')) {
				return false;
			}
		} else {
			if (!MediaValidation::access($source['permission'], 'r')) {
				return false;
			}
		}
		
		if(!empty($temporary)) {
			if (MediaValidation::file($temporary['file'], true)) {
				if (!MediaValidation::access($temporary['file'], 'r')) {
					return false;
				}
			} else if (MediaValidation::folder($temporary['dirname'], true)) {
				if (!MediaValidation::access($temporary['dirname'], 'w')) {
					return false;
				}
			}
		}
		
		if (!MediaValidation::access($destination['dirname'], 'w')) {
			return false;
		}				
		
		return true;		
	}	
/**
 * Checks if resource is located within given locations
 *
 * @param object $model
 * @param array $field
 * @param mixed $allow True or * allows any location, an array containing absolute paths to locations
 * @return bool
 */
	function checkLocation(&$model, $field, $allow = true) {
		extract($this->runtime[$model->alias]);
		$allow = $this->_replaceMarker($model, $allow);
		
		foreach (array('source', 'temporary', 'destination') as $type) {
			if ($type == 'temporary' && empty($$type)) {
				continue(1);
			}
			if ($type == 'source' && ${$type}['type'] == 'file-upload-remote') {
				continue(1);
			}
			if (!MediaValidation::location(${$type}['file'], $allow)) {
				return false;
			}
		}
		
		return true;		
	}
/**
 * Checks if provided or potentially dangerous permissions are set
 *
 * @param object $model
 * @param array $field
 * @param mixed $match True to check for potentially dangerous permissions, a string containing the 4-digit octal value of the permissions to check for an exact match, false to allow any permissions 
 * @return bool
 */
	function checkPermission(&$model, $field, $match = true) {
		extract($this->runtime[$model->alias]);
		
		foreach (array('source', 'temporary') as $type) {
			if ($type == 'temporary' && empty($$type)) {
				continue(1);
			}
			if (!MediaValidation::permission(${$type}['permission'], $match)) {
				return false;
			}
		}
		
		return true;
	}	
/**
 * Checks if resource doesn't exceed provided size
 *
 * Please note that the size will always be checked against 
 * limitations set in php.ini for post_max_size and upload_max_filesize
 * even if $max is set to false
 * 
 * @param object $model
 * @param array $field
 * @param mixed $max String (e.g. 8M) containing maximum allowed size, false allows any size
 * @return bool
 */
	function checkSize(&$model, $field, $max = false) {
		extract($this->runtime[$model->alias]);
		
		foreach (array('source', 'temporary') as $type) {
			if ($type == 'temporary' && empty($$type)) {
				continue(1);
			}
			if (!MediaValidation::size(${$type}['size'], $max)) {
				return false;
			}
		}
		
		return true;
	}	
/**
 * Checks if resource (if it is an image) pixels doesn't exceed provided size
 *
 * Useful in situation where you wan't to prevent running out of memory when
 * the image gets resized later. You can calculate the amount of memory used 
 * like this: width * height * 4 + overhead
 * 
 * @param object $model
 * @param array $field
 * @param mixed $max String (e.g. 40000 or 200x100) containing maximum allowed amount of pixels
 * @return bool
 */
	function checkPixels(&$model, $field, $max = false) {
		extract($this->runtime[$model->alias]);
		
		foreach (array('source', 'temporary') as $type) { /* pixels value is optional */
			if(($type == 'temporary' && empty($$type)) || !isset(${$type}['pixels'])) {
				continue(1);
			}
			if(!MediaValidation::pixels(${$type}['pixels'], $max)) {
				return false;
			}
		}
		
		return true;
	}	
/**
 * Checks if resource has (not) one of given extensions 
 *
 * @param object $model
 * @param array $field
 * @param mixed $deny True or * blocks any extension, an array containing extensions (w/o leading dot) selectively blocks, false blocks no extension
 * @param mixed $allow True or * allows any extension, an array containing extensions (w/o leading dot) selectively allows, false allows no extension
 * @return bool
 */
	function checkExtension(&$model, $field, $deny = false, $allow = true) {
		extract($this->runtime[$model->alias]);
		
		foreach (array('source','temporary','destination') as $type) {
			if (($type == 'temporary' && empty($$type)) || !isset(${$type}['extension'])) {
				continue(1);
			}
			if (!MediaValidation::extension(${$type}['extension'], $deny, $allow)) {
				return false;
			}
		}
		
		return true;
	}	
/**
 * Checks if resource has (not) one of given mime types 
 *
 * @param object $model
 * @param array $field
 * @param mixed $deny True or * blocks any mime type, an array containing mime types selectively blocks, false blocks no mime type
 * @param mixed $allow True or * allows any extension, an array containing extensions selectively allows, false allows no mime type
 * @return bool
 */	
	function checkMimeType(&$model, $field, $deny = false, $allow = true) {
		extract($this->runtime[$model->alias]);
		extract($this->settings[$model->alias], EXTR_SKIP);
		
		foreach(array('source', 'temporary') as $type) {
			/*
			 * Mime types and trustClient setting
			 * 
			 * trust | source   | temporary
			 * ------|----------|----------
			 * true  | xxxx/xxx | xxxx/xxx
			 * ------|----------|----------
			 * false | null     | xxxx/xxx
			 */
			if ($type === 'temporary' && empty($$type)) {
				continue(1); // some transfers dont use a temporary
			}
			if ($type === 'source' && ${$type}['type'] === 'file-upload-remote' && !isset(${$type}['mimeType']) && !$trustClient) {
				continue(1); // see info method
			}
			if (!MediaValidation::mimeType(${$type}['mimeType'], $deny, $allow)) {
				return false;
			}
		}
		
		return true;
	}
/**
 * Gather/Return information about a resource 
 *
 * @param mixed $resource Path to file in local FS, URL or file-upload array
 * @param string $what scheme,host,port,file,mime type,size,permission,dirname,basename,filename,extension or type
 * @return mixed
 * @todo This could become a Medium, too
 */
	function info(&$model, $resource, $what = null) {
		extract($this->settings[$model->alias], EXTR_SKIP);
		
		$defaultResource = array(
							'scheme' 		=> null,
							'host' 			=> null,
							'port' 			=> null,
							'file' 			=> null,
							'mimeType'		=> null,
							'size' 			=> null,
							'pixels'		=> null,
							'permisssion'	=> null,
							'dirname' 		=> null,
							'basename' 		=> null,
							'filename' 		=> null,
							'extension' 	=> null,
							'type' 			=> null,
							);

		/* HTTP Url */
		/* Currently  http is supported only */			
		if (TransferValidation::url($resource, array('scheme' => 'http'))) {
			$resource = array_merge(
							$defaultResource,
							pathinfo(parse_url($resource,PHP_URL_PATH)),
							array(
								'scheme' => parse_url($resource,PHP_URL_SCHEME),
								'host'   => parse_url($resource,PHP_URL_HOST),
								'port'   => parse_url($resource,PHP_URL_PORT),
								'file'   => $resource,
								'type'   => 'http-url-remote',
								)
							);

			if (!class_exists('HttpSocket')) {
				App::import('Core', 'HttpSocket');
			}
			$Socket =& new HttpSocket();
			$Socket->request(array('method' => 'HEAD', 'uri' => $resource['file']));
			
			if (empty($Socket->error) && $Socket->response['status']['code'] == 200) {
				$resource = array_merge(
								$resource,
								array(
									'size'       => $Socket->response['header']['Content-Length'],
									'mimeType'   => $Socket->response['header']['Content-Type'],
									'permission' => '0004'
									)
								);
			}

		/* File */
		} else if (MediaValidation::file($resource, false)) {
			$resource = array_merge(
							$defaultResource,
							pathinfo($resource),
							array(
								'file' => $resource,
								'host' => 'localhost',
								'mimeType' => MimeType::guessType($resource, array('simplify' => true)),
								)
							);

			if (TransferValidation::uploadedFile($resource['file'])) {
				$resource['type'] = 'uploaded-file-local';
			} else {
				$resource['type'] = 'file-local';
			}
				
			if (is_readable($resource['file'])) {
				/*
				 * Because there is not good way to determine if resource is an image
				 * first, we suppress a warning that would be thrown here otherwise.
				 */
				list($width, $height) = @getimagesize($resource['file']);
				
				$resource = array_merge(
								$resource,
								array(
									'size'       => filesize($resource['file']),
									'permission' => substr(sprintf('%o', fileperms($resource['file'])), -4),
									'pixels'     => $width * $height,
									)
								);
			}
		
		/* File Upload */
		} else if (TransferValidation::fileUpload($resource)) {
			$resource = array_merge(
							$defaultResource,
							pathinfo($resource['name']),
							array(
								'file'       => $resource['name'],
								'host'       => env('REMOTE_ADDR'),
								'size'       => $resource['size'],
								'mimeType'   => $trustClient ? MimeType::simplify($resource['type']) : null,
								'permission' => '0004',
								'type'       => 'file-upload-remote',
								)
							);
			
		} else {
			return null;
		}
		
		if (is_null($what)) {
			return $resource;
		} else if (array_key_exists($what, $resource)) {
			return $resource[$what];
		} 
		return null;
	}	
/**
 * Finds an alternative filename for an already existing file
 *
 * @param string $file Absolute path to file in local FS
 * @param int $tries Number of tries
 * @return mixed A string if an alt. name was found, false if number of tries were exceeded
 */
	function _alternativeFile($file, $tries = 100) {
		extract(pathinfo($file), EXTR_SKIP);
		$newFilename = $filename;
		
		$Folder = new Folder($dirname);
		$names = $Folder->find($filename . '.*');
		$names = array_map(create_function('$basename', 'return pathinfo($basename, PATHINFO_FILENAME);'), $names);

		for ($count = 2; in_array($newFilename, $names); $count++) {
			if ($count > $tries) {
				return false;
			}
			
			$newFilename = $filename . '_' . $count;
		}
		
		$new = $dirname . DS . $newFilename;

		if (isset($extension)) {
			$new .= '.' . $extension;
		}
		
		return $new;
	}		
/**
 * Adds and/or overwrites marker(s)/replacement(s)
 *
 * @param object $model 
 * @param string $marker The name of the marker, or the prefix for mapped markers
 * @param string $replacement  String or an array mapping markers to replacements
 */	
	function _addMarker(&$model, $marker, $replacement = null, $slugify = false) {
		if (is_array($replacement)) {
			foreach ($replacement as $subMarker => $subReplacement) {
				if (is_array($subReplacement)) {
					continue(1);
				}
				$this->_addMarker($model, $marker . $subMarker, $subReplacement, $slugify);
			}
			return true;
		} 
		
		if ($slugify) {
			$replacement = strtolower(Inflector::slug($replacement, '_'));
		}
		
		return $this->runtime[$model->alias]['markers'][$marker] = $replacement;
	}
/**
 * Replace with constants and dynamic replacements
 * 
 * @param object $model
 * @param mixed $subject Array holding multiple strings or a single string
 * @param bool $safe Make result safe for e.g. filenames
 * @return string
 */	
	function _replaceMarker(&$model, $subject) {
		if(is_array($subject)) {
			foreach ($subject as $s) {
				$result[] = $this->_replaceMarker($model, $s);
			}
			return $result;
		}
		
		if (!is_string($subject)) {
			return $subject;
		}
		
		$markers = Set::filter($this->runtime[$model->alias]['markers']);
		$subject = String::insert($subject, $markers, array('before' => ':', 'after' => ':', 'clean' => true, 'replacement' => 'unknown_marker'));

		if (strpos($subject, 'unknown_marker') !== false) {
			trigger_error('TransferBehavior::_replaceMarker - Failed to replace all markers of subject \'' . $subject . '\'. Did you setup the Behavior correctly? Check the configuration you provided for TransferBehavior in your \'' . $model->name . '\' model.', E_USER_WARNING);
			return false;
		}
		return $subject;
	}	
}
?>