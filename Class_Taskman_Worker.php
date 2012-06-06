<?php
/**
 * This class manages workers that scan the queue to get data.
 *
 * Workers informations are stored in redis using the 'hash' type.
 * The key name format is : '<prefix>:<queue_name>:<worker_id>', for instance : 'worker:mytodo:server_1'
 * In this hash, attributes are defined in the class attributes '_workerKeys'.
 * Informations stored are like this :
 *
 *    worker:mytodo:server_1 = {
 *                              status            = 'STARTED' | 'WAITING' | 'WORKING' | 'SLEEPING' | 'KILLED'
 *                              status_changedate = '2012-03-10 22:01:23'
 *                              start_date        = '2012-03-10 21:44:00'
 *                              end_date          = '2012-03-10 22:47:00'
 *                              waiting_timeout   = 10
 *                              loop_sleep        = 10
 *                              action            = echo "##data##" >> /tmp/worker.log
 *                              action_cpt        = 2365
 *                              type              = 'FIFO' | 'LIFO'
 *                             }
 *
 */

require('Class_Redis.php');
require('Class_Taskman_Queue.php');

class Class_Taskman_Worker
{

   /**
    * Worker name used in the redis key name
    * @var string
    */
   private $_worker;

   /**
    * Prefix used in the redis key name
    * @var string
    */
   private $_prefix = "worker";

   /**
    * Separator used in the redis keys names
    * @var string
    */
   private $_separator = ":";

   /**
    * Worker identifier
    * @var string
    */
   private $_workerId;

   /**
    * Attributes used in the worker informations
    * @var array
    */
   private $_workerKeys = array(
                                'STATUS'            => 'status',              // worker status
                                'STATUS_CHANGEDATE' => 'status_changedate',   // change date when the worker wtatus is changing
                                'START_DATE'        => 'start_date',          // worker statup date
                                'END_DATE'          => 'end_date',            // date when the worker must kill itself
                                'WAITING_TIMEOUT'   => 'waiting_timeout',     // time in seconds the worker must wait to get data when the queue is empty
                                'LOOP_SLEEP'        => 'loop_sleep',          // time in seconds the worker sleep between 2 connections to get data
                                'ACTION'            => 'action',              // value of the execution to launch when the worker get data
                                'ACTION_CPT'        => 'action_cpt'           // number of times the value of 'action' is executed
                                'TYPE'              => 'type'                 // how the worker works
                                );

   /**
    * Array to store worker informations read from redis
    * @var array
    */
   private $_workerInfo = array();

   /**
    * Redis handler
    * @var object
    */
   private $_redis;

   /**
    * Queue name used by the worker
    * @var object
    */
   private $_queue;

   /**
    * Time sleeping between 2 redis connections (in seconds)
    * It's possible to modify the value to tune the worker (HSET worker:mytodo:server_1 loop_sleep 0)
    * @var int
    */
   private $_loopSleep;

   /**
    * Waiting timeout to stay connected on the queue when it's empty (in seconds)
    * It's possible to modify the value to tune the worker (HSET worker:mytodo:server_1 waiting_timeout 5)
    * @var int
    */
   private $_waitingTimeout;

   /**
    * Script called by the worker after getting data
    * @var int
    */
   private $_action;

   /**
    * How the worker works (FIFO, LIFO)
    * @var int
    */
   private $_type;

   /**
    * Constructor
    */
   public function __construct($base, $queue, $id, $wait, $sleep, $action, $type)
   {
      // redis connection
      $redis = new Class_Redis($base);

      $this->_redis          = $redis->getHandler();
      $this->_queue          = new Class_Taskman_Queue($base, $queue);
      $this->_workerId       = $id;
      $this->_worker         = $this->_prefix.$this->_separator.$queue.$this->_separator.$this->_workerId;
      $this->_waitingTimeout = $wait;
      $this->_loopSleep      = $sleep;
      $this->_action         = $action;
      $this->_type           = $type;

      // start to store informations about the worker
      $this->setStatus('STARTED');
      $this->setStartTime();
      $this->setWaitingTimeout();
      $this->setLoopSleep();
      $this->setAction();
      $this->setType();
   }


   /**
    * Store in redis the worker status and the change date.
    *
    * Status can have one of these values :
    *
    *       STARTED  : the worker just started but didn't do anything
    *       WAITING  : the worker is connected to redis and is waiting some data
    *       WORKING  : the worker called the action script
    *       SLEEPING : the worker is sleeping between 2 redis connections
    *       KILLED   : the worker is killed
    *
    * The change date format is : "YYYY-MM-DD hh:mm:ss"
    *
    * @param   string  worker status value
    */
   public function setStatus($status)
   {
      $this->_redis->hMset($this->_worker, array(
                                                 $this->_workerKeys['STATUS']            => $status,
                                                 $this->_workerKeys['STATUS_CHANGEDATE'] => date("Y-m-d H:i:s")
                                                 )
                           );
      // if WORKING, incr the action counter
      if ($status == 'WORKING')
      {
         $this->_redis->hIncrBy($this->_worker, $this->_workerKeys['CPT_ACTION'], 1);
      }
      // if STARTED, reset the action counter
      if ($status == 'STARTED')
      {
         $this->_redis->hSet($this->_worker, $this->_workerKeys['CPT_ACTION'], 0);
      }
   }

   /**
    * Store the worker start date.
    * Date format is "YYYY-MM-DD hh:mm:ss".
    */
   public function setStartTime()
   {
      $this->_redis->hSet($this->_worker, $this->_workerKeys['START_DATE'], date("Y-m-d H:i:s"));
   }

   /**
    * Store the date the worker must die.
    *
    * @param   date  date format "YYYY-MM-DD hh:mm:ss"
    */
   public function setEndTime($end)
   {
      $this->_redis->hSet($this->_worker, $this->_workerKeys['END_DATE'], $end);
   }

   /**
    * Store the number of seconds to sleep between 2 actions.
    */
   public function setLoopSleep()
   {
      $this->_redis->hSet($this->_worker, $this->_workerKeys['LOOP_SLEEP'], $this->_loopSleep);
   }

   /**
    * Store the number of seconds to wait whene the queue is empty.
    */
   public function setWaitingTimeout()
   {
      $this->_redis->hSet($this->_worker, $this->_workerKeys['WAITING_TIMEOUT'], $this->_waitingTimeout);
   }

   /**
    * Store the script to execute when the worker gets data.
    */
   public function setAction()
   {
      $this->_redis->hSet($this->_worker, $this->_workerKeys['ACTION'], $this->_action);
   }

   /**
    * Store how the worker works.
    */
   public function setType()
   {
      $this->_redis->hSet($this->_worker, $this->_workerKeys['TYPE'], $this->_type);
   }

   /**
    * Read all the worker informations.
    *
    * @return  array   array representing the redis hash
    */
   public function readWorkerInfo()
   {
      $this->_workerInfo = $this->_redis->hGetAll($this->_worker);
   }

   /**
    * Return one worker information.
    *
    * @param   string   one of the keys form attribut '_workerKeys'.
    *
    * @return  mixed    the value or false if no info
    */
   public function getWorkerInfo($info_key)
   {
      if (isset($this->_workerInfo[$this->_workerKeys[$info_key]]))
      {
         return $this->_workerInfo[$this->_workerKeys[$info_key]];
      }
      else
      {
         return false;
      }
   }

   /**
    * Get a task data form the queue.
    *
    * @param   int         waiting timeout on an empty queue
    * @param   string      how the worker gets data (FIFO, LIFO)
    *
    * @return  string   task data
    */
   public function getTask($wait, $type)
   {
      return $this->_queue->pullTask($wait, $type);
   }


}
