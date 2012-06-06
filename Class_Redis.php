<?php
/**
 * Classe Redis
 *
 * This class is using the PHP extension 'phpredis' (https://github.com/nicolasff/phpredis).
 *
 */
class Class_Redis
{
   /**
    * Redis host
    * @var string
    */
   CONST CONF_PATH="conf.ini";

   /**
    * Redis host
    * @var string
    */
   private $_host;

   /**
    * Redis port
    * @var string
    */
   private $_port;

   /**
    * Password
    * @var string
    */
   private $_password;

   /**
    * Bd number
    * @var int
    */
   private $_db;

   /**
    * Redis handler
    * @var object
    */
   private $_dbh;

   /**
    * Constructor
    *
    * Connection elements are in a conf file in the section named "[redis:<instance>]"
    *
    */
   public function __construct($instance)
   {
      // some tests
      if (is_string($instance) === false)
      {
         throw new Exception ("The instance name is not a string : '".gettype($instance)."'");
      }

      // cleaning
      $instance = strtolower(str_replace(" ", "", $instance));

      if (!isset($instance) || empty($instance))
      {
         throw new Exception("The instance name is not set or empty.");
      }

      // conf parsing
      if (file_exists(CONF_PATH) === false)
      {
         throw new Exception("The configuration file does not exist : '".CONF_PATH."'");
      }
      $conf = parse_ini_file(CONF_PATH, true);

      if (!is_array($conf['redis:'.$instance]))
      {
         throw new Exception("The connection elements must be in the section '[redis:$instance]'.");
      }

      // getting and setting connection elements
      $this->_host     = $conf['redis:'.$instance]['host'];
      $this->_port     = $conf['redis:'.$instance]['port'];
      $this->_password = $conf['redis:'.$instance]['password'];
      $this->_db       = $conf['redis:'.$instance]['db'];

      // setting the handler and opening the connection
      $this->_dbh = new Redis();
      $this->connect();
   }

   /**
    * Connection
    *
    * We use persistent connection.
    */
   private function connect()
   {
      if ($this->_dbh->pconnect($this->_host, $this->_port) === false)
      {
         throw new Exception("The redis connection failed : host=".$this->_host.", port=".$this->_port);
      }
      if ($this->_dbh->select($this->_db) === false)
      {
         throw new Exception("The redis db selection failed : '".$this->_db."'");
      }
      if ($this->_password != "")
      {
         if ($this->_dbh->auth($this->_password) === false)
         {
            throw new Exception("The redis auth failed.");
         }
      }
   }

   public function getHandler()
   {
      return $this->_dbh;
   }


}
