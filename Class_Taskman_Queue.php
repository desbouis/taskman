<?php
/**
 * This class manages a task queue with Redis.
 *
 * The task datas are stored using a 'list' type.
 * The key name format is : '<prefix><separator><queue_name>', for instance : 'queue:mytodo'
 *
 * This class is used by the Taskman_Worker class.
 *
 * To add a new task data :
 *       $data = "{firstname:'john', name:'doe'}";
 *       $queue = new Class_Taskman_Queue('taskman', 'mytodo');
 *       $nb_tasks = $queue->pushTask($data);
 *
 */

require('Class_Redis.php');

class Class_Taskman_Queue
{

   /**
    * Queue name used in the redis key name
    * @var string
    */
   private $_name;

   /**
    * Prefix used in the redis key name
    * @var string
    */
   private $_prefix = "queue";

   /**
    * Separator used in the redis keys names
    * @var string
    */
   private $_separator = ":";

   /**
    * Redis instance name
    * @var string
    */
   private $_base;

   /**
    * Redis handler
    * @var object
    */
   private $_redis;


   /**
    * Constructor
    */
   public function __construct($base, $queue_name)
   {
      // some tests
      if (is_string($queue_name) === false)
      {
         throw new Exception("The queue name must be a string : '".gettype($queue_name)."'");
      }

      // cleaning
      $queue_name = strtolower(str_replace(" ", "", $queue_name));

      if (!isset($queue_name) || empty($queue_name))
      {
         throw new Exception("The queue name is not set or empty.");
      }

      // the name of the redis key for the queue
      $this->_name = $this->_prefix.$this->_separator.$queue_name;

      $this->_base = $base;

      $redis = new Class_Redis($this->_base);
      $this->_redis = $redis->getHandler();
   }

   /**
    * Queue name getter.
    *
    * @return  string    the queue name
    */
   public function getName()
   {
      return $this->_name;
   }

   /**
    * Push task data in the queue.
    *
    * @param   string   data to store (json for instance)
    *
    * @return  mixed    number of tasks left in the queue on success, false on failure.
    */
   public function pushTask($data)
   {
      // some tests
      if (is_string($data) === false)
      {
         throw new Exception("Task data must be a string : '".gettype($data)."'");
      }

      // push
      $new_size_list = $this->_redis->rPush($this->_name, $data);

      if ($new_size_list === false)
      {
         throw new Exception("Pushing this new task failed : ".print_r($data, true));
      }

      return $new_size_list;
   }

   /**
    * Pull task data from the queue.
    * When pulling, an array is returned : the key '0' is containing the queue name and the key '1' is containing the task data.
    *
    * @param   int      waiting timeout to get data when the queue is empty (in seconds)
    * @param   string   where to pull data (FIFO, LIFO)
    *
    * @return  mixed    the data string on success, false on failure.
    */
   public function pullTask($waiting, $type)
   {
      try
      {
         $data = false;
         switch ($type)
         {
            case 'FIFO' :
               $data = $this->_redis->blPop($this->_name, (int)$waiting);
               break;
            case 'LIFO' :
               $data = $this->_redis->brPop($this->_name, (int)$waiting);
               break;
         }

         if ($data)
         {
            return $data[1];
         }
         else
         {
            return false;
         }
      }
      catch (RedisException $e)
      {
         throw new Exception("Pulling task data failed : ".print_r($e, true));
      }
   }


}
