<?php
/**
 * Css Tidy Medium Adapter File
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
 * @category   media handling
 * @package    attm
 * @subpackage attm.plugins.media.libs.medium.adapter
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
/**
 * Css Tidy Medium Adapter Class
 * 
 * @category   media handling
 * @package    attm
 * @subpackage attm.plugins.media.libs.medium.adapter
 * @author     David Persson <davidpersson@qeweurope.org>
 * @copyright  $CopyrightYear$ David Persson <davidpersson@qeweurope.org>
 * @license    http://www.opensource.org/licenses/mit-license.php The MIT License
 * @link       http://cakeforge.org/projects/attm The attm Project
 * @link       http://csstidy.sourceforge.net/
 */
class CssTidyMediumAdapter extends MediumAdapter {

	var $require = array(
						'mimeTypes' => array('text/css'),
						'extensions' => array('ctype'),
						'imports' => array(array('type' => 'Vendor','name'=> 'csstidy','file' => 'csstidy/class.csstidy.php')),
						);

	var $_template = 'high_compression'; // or: highest_compression
	
	function initialize(&$Medium) {
		if (!isset($Medium->contents['raw']) && isset($Medium->file)) {
			return $Medium->contents['raw'] = file_get_contents($Medium->file);
		}
		return true;
	}
	
	function store(&$Medium, $file) {
		return file_put_contents($Medium->contents['raw'], $file);
	}
	
	function compress(&$Medium) {
		$Tidy = new csstidy() ;
		$Tidy->load_template($this->_template); 
		$Tidy->parse($Medium->contents['raw']);
		
		if ($compressed = $Tidy->print->plain()) {
			$Medium->content['raw'] = $compressed;
			return true;		
		}
		return false;
	}
}
?>