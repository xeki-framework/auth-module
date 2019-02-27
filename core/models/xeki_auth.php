<?php
namespace xeki_auth;
require_once("AuthGroup.php");
require_once("AuthPermission.php");
require_once("AuthUser.php");

/**
 * Class auth-module
 * @package xeki_auth
 * version 1
 */

class xeki_auth
{
    private $encryption_method = 'sha256';

    private $config_params = array();

    private $db_config = "main";

    private $field_identifier = "email";

    private $status = "no_logged";

    private $local_config=[];

    private $sql;
    private $user;

    function get_value_param($key)
    {
        if (!isset($this->config_params[$key])) {
            \xeki\core::fatal_error("ERROR value $key not found check config of xeki_auth");
        }
        return $this->config_params[$key];
    }

    function __construct($config)
    {
        $this->config_params = $config;

        $lifetime = 3600000;
        ini_set('session.name', 'session_id');
        ini_set('session.gc_maxlifetime', $lifetime);
        session_set_cookie_params(time()+$lifetime);

        // set params

        if (isset($config['encryption_method'])) $this->encryption_method = $config['encryption_method'];
        if (isset($config['db_config'])) $this->db_config = $config['db_config'];
        if (isset($config['field_identifier'])) $this->field_identifier = $config['field_identifier'];


        $this->local_config =
        [
            "encryption_method"=>$this->encryption_method,
            "db_config"=>$this->db_config,
            "field_identifier"=>$this->field_identifier,
        ];

        $this->sql = \xeki\module_manager::import_module('db-sql', $this->db_config);
        // Create a new session if necessary

        // TODO handling better this error
        @session_start() or die(); // Don't output anything on invalid cookie forgery attempts


        // check if sk_2 is empty
        if (empty($_COOKIE['sk_2'])){

            $sessionKey = $this->generate_session_key();


            setcookie("sk_2", $sessionKey, time()+$lifetime,\xeki\core::$URL_BASE);
            $_SESSION['sk_2'] = $sessionKey;



//            $res = setcookie("sk_2", $sessionKey, time()+$lifetime, \xeki\core::$URL_BASE, $_SERVER['REQUEST_URI'], 1);
//            d($res);

        }
        else{
            // renew cookie
            setcookie("sk_2", $_COOKIE['sk_2'], time()+$lifetime,\xeki\core::$URL_BASE);
        }




        // Destroy the session completely (including client cookies) if the session keys don't match

        if ($_SESSION['sk_2'] !== $_COOKIE['sk_2']) {
            if(empty($_SESSION['sk_2']) || empty($_COOKIE['sk_2'])){
                // handling error
                if(!empty($_COOKIE['sk_2'] )){
                    // crash
                    $_SESSION['sk_2'] = $_COOKIE['sk_2'];
                    // force re load session
                    if(empty($_SESSION['sk_2'] )) {
                        session_destroy();
                        session_start();
                        $_SESSION['sk_2'] = $_COOKIE['sk_2'];
                    }
                }
                else{
                }
            }
            if(!$this->load_info_from_db()){
//                    $this->logout();
            }
        }

        // recover from db
        if(!empty($_SESSION['xeki_auth']['logged'])){
            if(empty($_SESSION['xeki_auth']['id_user'])) {
                $this->load_info_from_db();
            }
        }



    }

    function load_info_from_db(){

        if(empty($_COOKIE['sk_2']))return false;

        $query = "select * from user_session where sk_2 ='{$_COOKIE['sk_2']}'";

        $info_session = $this->sql->query($query);

        if (count($info_session)>0){
            $info_session = $info_session[0];
            $info = [];
            $info['id']=$info_session['user_id'];
            $_SESSION['xeki_auth']['logged'] = true;
            $_SESSION['xeki_auth']['id_user'] = $info['id'];
            $_SESSION['xeki_auth']['last_view'] = time();
            $_SESSION['user_id'] = $info['id'];

            return $info[0]['user_id'];
        }
        return false;
    }

    static function logout(){
        session_unset();
        session_destroy();
        unset($_COOKIE['PHPSESSID']);
        unset($_COOKIE['session_id']);
        unset($_COOKIE['sk_2']);

        setcookie('PHPSESSID', null, -1); # force for old versions of xeki
        setcookie('session_id', null, -1);
        setcookie('sk_2', null, -1);

        setcookie('PHPSESSID', null, -1,\xeki\core::$URL_BASE); # force for old versions of xeki
        setcookie('session_id', null, -1,\xeki\core::$URL_BASE);
        setcookie('sk_2', null, -1,\xeki\core::$URL_BASE);

        if (isset($_SERVER['HTTP_COOKIE'])) {
            $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
            foreach($cookies as $cookie) {
                $parts = explode('=', $cookie);
                $name = trim($parts[0]);
                setcookie($name, '', time()-1000);
                setcookie($name, '', time()-1000, '/');
                setcookie($name, '', time()-1000, \xeki\core::$URL_BASE);
            }
        }


    }

    function generate_session_key(){
        // Generate a random lowercase alphanumeric string
        $sessionKey = substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyz', 8)), 0, 80);
        $sessionKey = time().$sessionKey;
        return $sessionKey;
    }

    function is_session_started()
    {
        if (is_cli()) {
            if (version_compare(phpversion(), '5.4.0', '>=')) {
                return session_status() === PHP_SESSION_ACTIVE ? TRUE : FALSE;
            } else {
                return session_id() === '' ? FALSE : TRUE;
            }
        }
        return FALSE;
    }


    function create_user($user_identifier,$password,$additional_data){
        if($this->user_exist($user_identifier)){
            return new \xeki\error("user_exist");
        }

        $password = hash($this->encryption_method, $password);
        $data=[
            "{$this->field_identifier}"=>$user_identifier,
            "password"=>$password,
        ];
        $data = array_merge($data,$additional_data);
        $res = $this->sql->insert("auth_user",$data);
        if($res){
            return $res;
        }
        else{
            return new \xeki\error("sql error \$sql->error() for debug");
        }

    }

    function user_exist($user_identifier){
        $this->sql->sanitize($user_identifier);
        $query = "select id from auth_user where {$this->field_identifier} = '$user_identifier'";
        $res = $this->sql->query($query);
        if(count($res)>0){
            return true;
        }
        else{
            return false;
        }
    }

    function remove_user($user_identifier){
        $this->sql->sanitize($user_identifier);
        $this->sql->delete("auth_user"," {$this->field_identifier} = '$user_identifier' ");

    }

    function remove_permission($code){
        $this->sql->sanitize($code);
        $this->sql->delete("auth_permissions"," code = '$code' ");

    }

    function remove_group($code){
        $this->sql->sanitize($code);
        $this->sql->delete("auth_group"," code = '$code' ");

    }

    function create_group($name,$code){
        $this->sql->sanitize($code);
        $data=[
            "name"=>$name,
            "code"=>$code,
        ];
        $this->sql->insert("auth_group",$data);

    }

    function create_permission($name,$code){
        $data=[
            "name"=>$name,
            "code"=>$code,
        ];
        $this->sql->insert("auth_permissions",$data);

    }


    function add_permission_to_group($code_group,$code_permission){
        $group =  $this->get_group($code_group);
        $permission = $this->get_permission($code_permission);
        $data=[
            "group_ref"=>$group->id,
            "permission_ref"=>$permission->id,

        ];
        $this->sql->insert("auth_group_permissions",$data);
    }

    function get_group($code){
        $group = new Group($this->local_config);
        $group->load_code($code);
        return $group;

    }

    function get_permission($code){
        $group = new Permission($this->local_config);
        $group->load_code($code);
        return $group;

    }



    function login_status(){
//        d($_SESSION);
    }

    function login($user_identifier,$password){

        $password = hash($this->encryption_method, $password);
        $this->login_encrypted($user_identifier,$password);
    }



    function login_encrypted($user_identifier,$password)
    {
        $user_identifier = strtolower($user_identifier);
        $user_identifier =$this->sql->sanitize($user_identifier);

        $query = "SELECT * FROM auth_user WHERE {$this->field_identifier} = '$user_identifier'";
        $info = $this->sql->query($query);
        d($info);
        // for check and debug
        // check if exist

        if ($info) if (count($info) > 0) {
            $info = $info[0];
            // check password
            if ($info["password"] != $password) {
                // check name_space
                return false;
            }
        }


        // init user
        $user = new User($this->local_config);
        $user->load_info($info);

        $this->user = $user;

        d($user->get("lastname"));
        d($user->get("email"));
        d($user->get("name"));

        if (!isset($_SESSION['xeki_auth'])) $_SESSION['xeki_auth'] = array();
        $_SESSION['xeki_auth']['logged'] = true;
        $_SESSION['xeki_auth']['id_user'] = $this->user->id;
        $_SESSION['xeki_auth']['last_view'] = time();
//        $_SESSION['xeki_auth']['user_info'] = $this->get_info();

        return true;

    }
    function get_user(){


        return $this->user;
    }




}