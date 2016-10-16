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
 * This plugin is used to access files from users bscw workspace
 * 
 * @since Moodle 3.1
 * @package repository_bscw
 * @copyright 2016 Martin Schleyer {@link http://www.m-serve.de/}
 * @copyright 2012 Marina Glancy
 * @copyright 2010 Dongsheng Cai {@link http://dongsheng.org}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once ($CFG->dirroot . '/repository/lib.php');
require_once (dirname ( __FILE__ ) . '/locallib.php');
/**
 * Repository to access bscw files
 * 
 * @package repository_bscw
 * @copyright 2016 Martin Schleyer {@link http://www.m-serve.de/}
 * @copyright 2010 Dongsheng Cai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_bscw extends repository {
    /** @var bscw the instance of bscw client */
    private $bscw;
    /** @var array files */
    public $files;
    /** @var bool flag of login status */
    public $logged = false;
    /** @var int maximum size of file to cache in moodle filepool */
    public $cachelimit = null;
    /** @var int cached file ttl */
    private $cachedfilettl = null;
    /** @var string URL to the bscw server */
    private $bscw_url;
    /**
     * Constructor of bscw plugin
     * 
     * @param int $repositoryid            
     * @param stdClass $context            
     * @param array $options            
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
        global $CFG;
        $options ['page'] = optional_param ( 'p', 1, PARAM_INT );
        parent::__construct ( $repositoryid, $context, $options );
        $this->setting = 'bscw_';
        $this->bscw_url = $this->get_option ( 'bscw_url' );
        // one day
        $this->cachedfilettl = 60 * 60 * 24;
        // Generate key
        // 1. Check if user has stored key
        // -> if yes - use
        // -> if no - login?!
        if (! empty ( $this->key )) {
            $this->logged = true;
        }
        $callbackurl = new moodle_url ( $CFG->wwwroot . '/repository/repository_callback.php', array (
                'callback' => 'yes',
                'repo_id' => $repositoryid 
        ) );
        $args = array (
                'bscw_url' => $this->bscw_url,
                'key' => $this->key 
        );
        $this->bscw = new bscw ( $args );
    }
    /**
     * Get bscw workspace listing
     * 
     * @param string $path            
     * @param int $page            
     * @return array
     */
    public function get_listing($path = '', $page = '1') {
        global $OUTPUT;
        if (empty ( $path ) || $path == '/') {
            $path = '/';
        } else {
            $path = file_correct_filepath ( $path );
        }
        $encoded_path = str_replace ( "%2F", "/", rawurlencode ( $path ) );
        $list = array ();
        $list ['list'] = array ();
        $list ['manage'] = $this->bscw_url;
        $list ['dynload'] = true;
        $list ['nosearch'] = true;
        $list ['message'] = get_string ( 'logoutdesc', 'repository_bscw' );
        // process breadcrumb trail
        $list ['path'] = array (
                array (
                        'name' => get_string ( 'bscw', 'repository_bscw' ),
                        'path' => '/' 
                ) 
        );
        $result = $this->bscw->get_listing ( $encoded_path, 1 );
        if (! is_object ( $result ) || empty ( $result )) {
            return $list;
        }
        if (empty ( $result->path )) {
            $current_path = '/';
        } else {
            $current_path = file_correct_filepath ( $result->path );
        }
        $trail = '';
        if (! empty ( $path )) {
            $parts = explode ( '/', $path );
            if (count ( $parts ) > 1) {
                foreach ( $parts as $part ) {
                    if (! empty ( $part )) {
                        $trail .= ('/' . $part);
                        $list ['path'] [] = array (
                                'name' => $part,
                                'path' => $trail 
                        );
                    }
                }
            } else {
                $list ['path'] [] = array (
                        'name' => $path,
                        'path' => $path 
                );
            }
        }
        if (! empty ( $result->error )) {
            // reset access key
            set_user_preference ( $this->setting . '_key', '' );
            throw new repository_exception ( 'repositoryerror', 'repository', '', $result->error );
        }
        if (empty ( $result->contents ) or ! is_array ( $result->contents )) {
            return $list;
        }
        $files = $result->contents;
        $dirslist = array ();
        $fileslist = array ();
        foreach ( $files as $file ) {
            if ($file->is_dir) {
                $dirslist [] = array (
                        'title' => $file->name,
                        'path' => $file->id,
                        'date' => strtotime ( $file->modified ),
                        'thumbnail' => $OUTPUT->pix_url ( file_folder_icon ( 64 ) )->out ( false ),
                        'thumbnail_height' => 64,
                        'thumbnail_width' => 64,
                        'children' => array () 
                );
            } else {
                $fileslist [] = array (
                        'title' => $file->name,
                        'source' => $file->id,
                        'size' => $file->bytes,
                        'date' => $file->modified,
                        'thumbnail' => $OUTPUT->pix_url ( file_mimetype_icon ( $file->type, 64 ) )->out ( false ),
                        'thumbnail_height' => 64,
                        'thumbnail_width' => 64 
                );
            }
        }
        $fileslist = array_filter ( $fileslist, array (
                $this,
                'filter' 
        ) );
        $list ['list'] = array_merge ( $dirslist, array_values ( $fileslist ) );
        return $list;
    }
    public function print_login() { // From repository_alfresco
        if ($this->options ['ajax']) {
            $user_field = new stdClass ();
            $user_field->label = get_string ( 'username', 'repository_bscw' ) . ': ';
            $user_field->id = 'bscw_username';
            $user_field->type = 'text';
            $user_field->name = 'bscw_username';
            // TODO check how to add the read only field?
            if ($this->get_option ( 'bscw_forcemoodlename' )) {
                global $USER;
                $user_field->value = $USER->username;
                $user_field->attributes = array (
                        'readonly' => true 
                );
            }
            $passwd_field = new stdClass ();
            $passwd_field->label = get_string ( 'password', 'repository_bscw' ) . ': ';
            $passwd_field->id = 'bscw_password';
            $passwd_field->type = 'password';
            $passwd_field->name = 'bscw_password';
            $ret = array ();
            $ret ['login'] = array (
                    $user_field,
                    $passwd_field 
            );
            return $ret;
        } else { // Non-AJAX login form - directly output the form elements
            echo '<table>';
            echo '<tr><td><label>' . get_string ( 'username', 'repository_bscw' ) . '</label></td>';
            echo '<td><input type="text" name="bscw_username" /></td></tr>';
            echo '<tr><td><label>' . get_string ( 'password', 'repository_bscw' ) . '</label></td>';
            echo '<td><input type="password" name="bscw_password" /></td></tr>';
            echo '</table>';
            echo '<input type="submit" value="Enter" />';
        }
    }
    /**
     * Logout from bscw
     * 
     * @return array
     */
    public function logout() {
        set_user_preference ( $this->setting . '_key', '' );
        $this->key = '';
        return $this->print_login ();
    }
    /**
     * Set bscw option
     * 
     * @param array $options            
     * @return mixed
     */
    public function set_option($options = array()) {
        if (! empty ( $options ['bscw_url'] )) {
            set_config ( 'bscw_url', trim ( $options ['bscw_url'] ), 'bscw' );
        }
        if (! empty ( $options ['bscw_forcemoodlename'] )) {
            set_config ( 'bscw_forcemoodlename', $options ['bscw_forcemoodlename'], 'bscw' );
        }
        unset ( $options ['bscw_url'] );
        unset ( $options ['bscw_forcemoodlename'] );
        $ret = parent::set_option ( $options );
        return $ret;
    }
    /**
     * Get bscw options
     * 
     * @param string $config            
     * @return mixed
     */
    public function get_option($config = '') {
        if ($config === 'bscw_url') {
            return trim ( get_config ( 'bscw', 'bscw_cachelimit' ) );
        } elseif ($config === 'bscw_forcemoodlename') {
            return get_config ( 'bscw', 'bscw_forcemoodlename' );
        } else {
            $options = parent::get_option ();
            $options ['bscw_url'] = trim ( get_config ( 'bscw', 'bscw_url' ) );
            $options ['bscw_forcemoodlename'] = get_config ( 'bscw', 'bscw_forcemoodlename' );
        }
        return $options;
    }
    /**
     * Downloads a file from external repository and saves it in temp dir
     * 
     * @throws moodle_exception when file could not be downloaded
     * @param string $objectid
     *            the content of files.reference field or result of
     *            function {@link repository_bscw::get_file_reference()}
     * @param string $saveas
     *            filename (without path) to save the downloaded file in the
     *            temporary directory, if omitted or file already exists the new filename will be generated
     * @return array with elements:
     *         path: internal location of the file
     *         objectid: ID of the source (from parameters)
     */
    public function get_file($objectid, $saveas = '') {
        global $CFG;
        $saveas = $this->prepare_file ( $saveas );
        return $this->bscw->get_file ( $objectid, $saveas );
        throw new moodle_exception ( 'cannotdownload', 'repository' );
    }
    
    /**
     * Add Plugin settings input to Moodle form
     * 
     * @param moodleform $mform
     *            Moodle form (passed by reference)
     * @param string $classname
     *            repository class name
     */
    public static function type_config_form($mform, $classname = 'repository') {
        global $CFG;
        parent::type_config_form ( $mform );
        $url = get_config ( 'bscw', 'bscw_url' );
        $forcemoodlename = get_config ( 'bscw', 'bscw_forcemoodlename' );
        if (empty ( $url )) {
            $url = '';
        }
        if (empty ( $forcemoodlename )) {
            $forcemoodlename = false;
        }
        $strrequired = get_string ( 'required' );
        $mform->addElement ( 'text', 'bscw_url', get_string ( 'url', 'repository_bscw' ), array (
                'value' => $url,
                'size' => '40' 
        ) );
        $mform->setType ( 'bscw_url', PARAM_RAW_TRIMMED );
        $mform->addRule ( 'bscw_url', $strrequired, 'required', null, 'client' );
        $mform->addElement ( 'checkbox', 'bscw_forcemoodlename', get_string ( 'forcemoodlename', 'repository_bscw' ), ($forcemoodlename ? array (
                'checked' => 'checked' 
        ) : array ()) );
        $mform->setType ( 'bscw_forcemoodlename', PARAM_BOOL );
        $str_getkey = get_string ( 'instruction', 'repository_bscw' );
        $mform->addElement ( 'static', null, '', $str_getkey );
    }
    /**
     * Option names of bscw plugin
     * 
     * @return array
     */
    public static function get_type_option_names() {
        return array (
                'bscw_url',
                'bscw_forcemoodlename',
                'pluginname' 
        );
    }
    /**
     * BSCW plugin supports all kinds of files
     * 
     * @return array
     */
    public function supported_filetypes() {
        return '*';
    }
    /**
     * User can use the external link to BSCW
     * 
     * @return int
     */
    public function supported_returntypes() {
        return FILE_INTERNAL | FILE_EXTERNAL;
    }
    /**
     * Return the source information
     * 
     * @param string $source            
     * @return string
     */
    public function get_file_source_info($source) {
        global $USER;
        // TODO generate full URL to object
        return 'BSCW (' . fullname ( $USER ) . '): ' . $source;
    }
    /**
     * Return file URL, i.e.
     * add the BSCW url to the internal id.
     * 
     * @param string $url
     *            the url of file
     * @return string
     */
    public function get_link($url) {
        return $url;
    }
}

