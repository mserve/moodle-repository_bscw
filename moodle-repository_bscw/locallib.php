<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.
/**
 * A helper class to access dropbox resources
 * 
 * @package repository_dropbox
 * @copyright 2016 Martin Schleyer
 * @copyright 2012 Marina Glancy
 * @copyright 2010 Dongsheng Cai
 * @author Martin Schleyer <schleyer@oszimt.de>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined ( 'MOODLE_INTERNAL' ) || die ();
// require_once ($CFG->libdir . '/oauthlib.php');
/**
 * Authentication class to access BSCW API
 * 
 * @package repository_bscw
 * @copyright 2016 Martin Schleyer
 * @copyright 2010 Dongsheng Cai
 * @author Martin Schleyer <schleyer@oszimt.de>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bscw {

    /** @var string BSCW API URL to access the server */
    private $bscw_url = '';
    /** @var string user agent, must be allowed at BSCW level - see conf/config_clientmap.py in BSCW admin documentation */
    private $user_agent = 'XML-RPC for PHP 3.0.0.beta';
    /** @var string key the base64 auth key */
    private $key = '';
    /** @var string root object id of the current bscw user, will be set at construct time */
    private $root_id = '';
    
    /**
     * Constructor for bscw class
     * 
     * @param array $args            
     */
    function __construct($args) {
        $this->key = $args ['key'];
        $this->bscw_url = $args ['bscw_url'];
        if (! empty ( $args ['user_agent'] )) {
            $this->$bscw_url = $args ['user_agent'];
        }
        // set root ID
        $this->root_id = $this->get_root_id ();
    }
    
    
    /**
     * Downloads a file from BSCW and saves it locally
     * 
     * @throws moodle_exception when file could not be downloaded
     * @param string $objectid
     *            path in BSCW
     * @param string $saveas
     *            path to file to save the result
     * @param int $timeout
     *            request timeout in seconds, 0 means no timeout
     * @return array with attributes 'path' and 'objectid'
     */
    public function get_file($objectid, $saveas, $timeout = 0) {
        $result = false;
        if (! ($fp = fopen ( $saveas, 'w' ))) {
            throw new moodle_exception ( 'cannotwritefile', 'error', '', $saveas );
        }
        // here get the file
        $file = $this->call_api ( 'get_document', array (
                $objectid 
        ) );
        if (is_object ( $file )) {
            $result = fwrite ( $fp, $file->scalar );
        }
        fclose ( $fp );
        if ($result === false) {
            unlink ( $saveas );
            // TODO throw new moodle_exception ( 'errorwhiledownload', 'repository', '', $result );
            return false;
        } else {
            return array (
                    'path' => $saveas,
                    'objectid' => $objectid 
            );
        }
    }
    
    /**
     * Returns direct link to BSCW file
     * 
     * @param string $objectid
     *            id in BSCW
     * @return string|null information object or null if request failed with an error
     */
    public function get_file_link($objectid) {
        // Call API to get object attributes
        $data = $this->call_api ( "get_attributes", array (
                $objectid,
                array (
                        '__id__',
                        'special_doc_ref' 
                ) 
        ) );

        // Check if data is ok
        if ($data !== false) {
            // Build URL
            return $this->bscw_url . '/' . $data [0] ['special_doc_ref'];
        }        
        return null;
    }
    
    /**
     * Get file listing from bscw
     * 
     * @param string $path            
     * @return array
     */
    public function get_listing($path = '', $depth = 1) {
        if (empty ( $path )) {
            // We need to get the elements of the root folder
            $path = $this->root_id;
        }
        $data = $this->call_api ( "get_attributes", array (
                $path,
                array (
                        '__id__',
                        'icon_path',
                        'name',
                        'content_size',
                        'reference',
                        'url_link',
                        'special_doc_ref',
                        'may_adddocument',
                        '__class__',
                        'type',
                        'ctime',
                        'size',
                        'content_size' 
                ),
                $depth,
                false 
        ) );
        /* now, go through array and build better one :-) */
        return $this->get_dir_array ( $data );
    }
    
    
    /**
     * Transforms the array returned by BSCW API into flat array
     * with dir/file metadata
     * 
     * @param  array  $dirarray array as received from bscw
     * @param  string $path     trailing path, if any
     * @return array  array without root element, one entry per object
     */
    private function get_dir_array($dirarray, $path = '') {
        // First element is current dir - get length
        $currentobject = array_shift ( $dirarray );
        $direntries = $currentobject ['content_size'];
        $path = $path . "/" . $currentobject ['name'];
        $dirlist = array ();
        // If we have some objects in this path, go through them
        foreach ( $dirarray as $direntry ) {
            $isdir = ($direntry ['__class__'] == "bscw.core.cl_folder.Folder");
            $dirlist [] = array (
                    'name' => $direntry ['name'],
                    'path' => $path . '/' . $direntry ['name'], // fake path
                    'id' => $direntry ['__id__'],
                    'is_dir' => $isdir,
                    'modified' => $direntry ['ctime']->timestamp,
                    'size' => (! $isdir ? $direntry ['size'] : ''),
                    'content_type' => ($isdir ? '' : $direntry ['type']) 
            );
        }
        return $dirlist;
    }
    /**
     * Get the root id of the BSCW user
     * 
     * @return string BSCW id of workspace root
     */
    private function get_root_id() {
        $data = $this->call_api ( 'get_attributes' );
        return $data [0] ['__id__'];
    }
    private function call_api($apicall, $parameters = array()) {
        if (empty ( $this->key )) {
            // throw new moodle_exception ( 'bscwrequestfailed', 'repository', '', 'BSCW auth key not set');
            return false;
        }
        $header = (version_compare ( phpversion (), '5.2.8' )) ? array (
                "Content-Type: text/xml",
                "Authorization: Basic " . $this->key 
        ) : "Content-Type: text/xml\r\nAuthorization: Basic " . $this->key; // Handle changes from 5.2.8 onwards
        $request = xmlrpc_encode_request ( $apicall, $parameters );
        $context = stream_context_create ( array (
                'http' => array (
                        'method' => "POST",
                        'header' => $header,
                        'content' => $request,
                        'user_agent' => $this->user_agent 
                ) 
        ) );
        $file = @file_get_contents ( $this->bscw_url, false, $context );
        // TODO Handle errors of request properly
        if ($file === false) {
            // throw new moodle_exception ( 'bscwrequestfailed', 'repository', '', $request);
            return false;
        }
        $response = xmlrpc_decode ( $file );
        if ($response && is_array ( $response ) && xmlrpc_is_fault ( $response )) {
            // Handle XML RPC error
            // throw new moodle_exception ( 'bscwxmlrpcfault', 'repository', '', $response );
            echo ("XMLRPC is fault");
            return false;
        } else
            return $response;
    }
}
