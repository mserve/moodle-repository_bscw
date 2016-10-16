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
 * A helper class to access bscw resources
 * 
 * @package repository_bscw
 * @copyright  2016 Martin Schleyer {@link http://www.m-serve.de/}
 * @author Martin Schleyer <schleyer@oszimt.de>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined ( 'MOODLE_INTERNAL' ) || die ();
// require_once ($CFG->libdir . '/oauthlib.php');
/**
 * Class to access BSCW API
 * 
 * @package repository_bscw
 * @copyright 2016 Martin Schleyer
 * @author Martin Schleyer <schleyer@oszimt.de>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bscw {
    /** @var string BSCW API URL to access the server */
    private $bscw_url = '';
    /** @var string user agent, must be allowed at BSCW level - see conf/config_clientmap.py in BSCW admin documentation */
    private $user_agent = 'XML-RPC for PHP 3.0.0.beta';
    /** @var string key the base64 auth key */
    private $bscw_key = '';
    /** @var string root object id of the current bscw user, will be set at construct time */
    private $root_id = '';
    /**
     * Constructor for bscw class
     * 
     * @param array $args            
     */
    function __construct($args) {
        global $SESSION;
        $this->bscw_url = $args ['bscw_url'];
        if (! empty ( $args ['bscw_key'] ))
            $this->bscw_key = $args ['bscw_key'];
        if (! empty ( $args ['user_agent'] )) {
            $this->$bscw_url = $args ['user_agent'];
        }
        if (! $SESSION->bscw_id_cache)
            $SESSION->bscw_id_cache = array ();
    }
    /**
     * Checks if root id is set - if not, retrieves once
     */
    private function check_root() {
        if (empty ( $this->root_id ))
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
            throw new moodle_exception ( 'errorwhiledownload', 'repository', '', $result );
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
     * Sets BSCW auth token
     * 
     * @param
     *            string username BSCW usernmae
     * @param
     *            string password BSCW password
     * @return BSCW base64 auth string
     */
    public function authenticate($username, $password) {
        $userkey = base64_encode ( $username . ":" . $password );
        $this->bscw_key = $userkey;
        $this->check_root ();
        return $userkey;
    }
    /**
     * Sets the BSCW auth key and refreshes root id
     * 
     * @param $bscw_key BSCW
     *            auth key
     */
    public function set_key($bscw_key) {
        $this->bscw_key = $bscw_key;
        $this->check_root ();
    }
    /**
     * Checks if properly authenticated,
     * i.e.
     * key is set and is valid
     * 
     * @return boolean true if properly authenticated
     */
    public function is_authenticated() {
        if (! empty ( $this->bscw_key )) {
            $rootid = $this->get_root_id ();
            return ($rootid !== false);
        }
        return false;
    }
    /**
     * Get file listing from bscw
     * 
     * @param string $objectid
     *            object id to list
     * @return array
     */
    public function get_listing($objectid = '', $depth = 1) {
        if (empty ( $objectid ) || $objectid == "/") {
            // We need to get the elements of the root folder
            $objectid = $this->root_id;
        }
        $data = $this->call_api ( "get_attributes", array (
                $objectid,
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
     * @param array $dirarray
     *            array as received from bscw
     * @param string $path
     *            trailing path, if any
     * @return array array without root element, one entry per object
     */
    private function get_dir_array($dirarray, $path = '') {
        // First element is current dir - get length
        $currentobject = array_shift ( $dirarray );
        $direntries = $currentobject ['content_size'];
        if (substr ( $currentobject ['name'], 0, 1 ) == ":")
            $path = "/";
        else
            $path = $path . "/" . $currentobject ['name'];
        $dirlist = array ();
        // If we have some objects in this path, go through them
        foreach ( $dirarray as $direntry ) {
            // Only accept known classes
            if (! array_key_exists ( '__class__', $direntry ))
                continue;
                // Check type
            $isdir = ($direntry ['__class__'] == "bscw.core.cl_folder.Folder");
            $isdoc = ($direntry ['__class__'] == "bscw.core.cl_document.Document");
            // Only accept documents and voders
            if (! ($isdir || $isdoc))
                continue;
            if ($isdir) {
                // Add to cache
                $this->add_to_cache ( $direntry ['__id__'], $direntry ['name'] );
            }
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
     * Generates an array of the object's path, e.g.
     * for Moodles breadcrumb navigation
     * 
     * @param string $objectid
     *            the object id
     * @return array k/v-array with fields 'name' and 'path' for each object,
     */
    public function generate_path_array($objectid = '') {
        if (empty ( $objectid )) {
            // We need to get the elements of the root folder
            $objectid = $this->root_id;
        }
        $data = $this->call_api ( "get_path", array (
                $objectid 
        ) );
        $path = array ();
        foreach ( $data as $level ) {
            $path [] = array (
                    'name' => $this->resolve_id ( $level ),
                    'path' => $level 
            );
        }
        return $path;
    }
    /**
     * Resolves a BSCW object id to its name, tries
     * to use the per-session-cache to reduce API requestes
     * and keep repository more responsive
     * 
     * @param string $objectid
     *            id of the object
     * @return string name of the object
     */
    public function resolve_id($objectid) {
        global $SESSION;
        $curcache = $SESSION->bscw_id_cache;
        if (intval ( $objectid ) > 0 && $curcache [intval ( $objectid )]) {
            return $curcache [$objectid];
        }
        // Resolve ID
        $attr = $this->call_api ( "get_attributes", array (
                $objectid,
                array (
                        '__id__',
                        'name' 
                ) 
        ) );
        $this->add_to_cache ( $attr [0] ['__id__'], $attr [0] ['name'] );
        return $attr [0] ['name'];
    }
    /**
     * Adds an object to cache, if it uses an numeric and thus unique identifier
     * 
     * @param string $objectid
     *            the object id
     * @param string $name
     *            the display name of the object
     */
    private function add_to_cache($objectid, $name) {
        global $SESSION;
        if (intval ( $objectid ) > 0)
            $SESSION->bscw_id_cache [intval ( $objectid )] = $name;
    }
    /**
     * Get the root id of the BSCW user
     * 
     * @return string BSCW id of workspace root
     */
    private function get_root_id() {
        $data = $this->call_api ( 'get_attributes' );
        if ($data === false)
            throw new moodle_exception ( 'bscwrequestfailed', 'repository', '', 'could not find root id' );
        return $data [0] ['__id__'];
    }
    /**
     * Calls the BSCW XML-RPC API
     * 
     * @param string $apicall
     *            name of API function
     * @param array $parameters
     *            array of addtional parameters
     * @return array response of BSCW
     */
    private function call_api($apicall, $parameters = array()) {
        if (empty ( $this->bscw_key )) {
            throw new moodle_exception ( 'bscwrequestfailed', 'repository', '', 'BSCW auth key not set' );
            return false;
        }
        $header = (version_compare ( phpversion (), '5.2.8' )) ? array (
                "Content-Type: text/xml",
                "Authorization: Basic " . $this->bscw_key 
        ) : "Content-Type: text/xml\r\nAuthorization: Basic " . $this->bscw_key; // Handle changes from 5.2.8 onwards
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
            throw new moodle_exception ( 'bscwrequestfailed', 'repository', '', $request );
            return false;
        }
        $response = xmlrpc_decode ( $file, "utf-8" );
        if ($response && is_array ( $response ) && xmlrpc_is_fault ( $response )) {
            // Handle XML RPC error
            throw new moodle_exception ( 'bscwxmlrpcfault', 'repository', '', $response );
            return false;
        } else
            return $response;
    }
}
