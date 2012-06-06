#! /usr/bin/php
<?php

/**
 * Worker deamon.
 *
 * Usage: taskman_worker.php --id=$(hostname -s)_1 --base=taskman --queue=mytodo --action="echo \"##data##\" >> /tmp/worker.log" [ --sleep=10 ] [ --wait=10 ] [ --type=FIFO|LIFO ]
 */


/**
 * Aide
 */
function usage($code)
{
   // script name
   $script = $_SERVER['argv'][0];

   echo "\n";
   echo "--HELP--\n";
   echo "\n";
   echo "This script creates a worker deamon that will scan a queue in redis.\n";
   echo "When a task data is present, the worker gives it to an action that will do something with it :\n";
   echo "task data replaces the ##data## mask in the parameter '--action' and the action is executed.\n";
   echo "\n";
   echo "Usage :\n";
   echo "-------\n";
   echo "   $script --id=<id> --base=<redis_instance> --queue=<queue> --action=<what_to_do_with_data> [ --wait=<nb_seconds> ] [ --sleep=<nb_seconds> ]\n";
   echo "\n";
   echo "Parameters :\n";
   echo "------------\n";
   echo "\n";
   echo "   --id     (required)  : worker identifier (a good idea is the hostname with an number)\n";
   echo "   --base   (required)  : redis instance name\n";
   echo "   --queue  (required)  : queue name\n";
   echo "   --action (required)  : script to execute with the task data\n";
   echo "   --sleep  (optionnal) : nb seconds to sleep between 2 redis connections, 10 by default\n";
   echo "   --wait   (optionnal) : nb seconds to wait when the queue is empty, 10 by default\n";
   echo "   --type   (optionnal) : how the worker works : FIFO or LIFO, FIFO by default\n";
   echo "\n";
   echo "Examples :\n";
   echo "----------\n";
   echo "\n";
   echo "   $script --id=$(hostname -s)_1 --base=taskman --queue=mytodo --action=\"echo \"##data##\" >> /tmp/worker.log\"\n";
   echo "   $script --id=$(hostname -s)_2 --base=taskman --queue=mytodo --action=\"echo \"##data##\" >> /tmp/worker.log\" --sleep=5 --type=FIFO\n";
   echo "   $script --id=$(hostname -s)_3 --base=taskman --queue=mytodo --action=\"echo \"##data##\" >> /tmp/worker.log\" --sleep=5 --wait=20 --type=LIFO\n";
   echo "\n";
   echo "\n";

   exit($code);
}

require('Class_Redis.php');
require('Class_Taskman_Worker.php');
require('Class_Taskman_Queue.php');

/**
 * Manage parameters from command line
 */
$options = getopt("h", array("help", "base:", "queue:", "id:", "action:", "sleep:", "wait:", "type:"));

// help
if ((count($options) == 0) || isset($options['h']) || isset($options['help']))
{
   usage(255);
}

// required parameters
$required = array('id', 'base', 'queue', 'action');
foreach ($required as $k => $v)
{
   if (!isset($options[$v]))
   {
      echo "\n";
      echo "ERROR : '$v' parameter is required !\n";
      usage(-1);
   }
}

$base      = $options['base'];
$worker_id = $options['id'];
$queue     = $options['queue'];
$action    = $options['action'];

// optionnal parameters
$sleep = 10;
if (isset($options['sleep']))
{
   $sleep  = $options['sleep'];
}

$wait = 10;
if (isset($options['wait']))
{
   $wait  = $options['wait'];
}

$type = "FIFO";
if (isset($options['type']))
{
   $type  = $options['type'];
}

/**
 * Managing a smart while :
 * the worker time-to-live is a random between 1h et 1h10.
 * So, it must be check and started if necessary by a cron job every minutes.
 */
// did not set the max execution time for a php script
set_time_limit(0);

// calculate the worker ttl
$ttl = 60 * 60 * 1;       // 1 hour minimum
$ttl += rand(0, 60 * 10); // adding between 0 et 10 minutes randomly

//@TEST
//$ttl = 60;
//$ttl += rand(0, 60);


// worker end of life
$end_time = time() + $ttl;


/**
 * Worker creation
 */
$worker = new Class_Taskman_Worker($base, $queue, $worker_id, $wait, $sleep, $action, $type);
$worker->setEndTime(date("Y-m-d H:i:s", $end_time));

// Looping until the worker end of life
while (time() < $end_time)
{
   // Reading worker informations stored in redis :
   // like that, we can tune directly the worker by modifying some parameters :
   //    - to modify the time between 2 loops : HSET worker:mytodo:server_1 loop_sleep 2
   //    - to modify the waiting timeout : HSET worker:mytodo:server_1 waiting_timeout 20
   //    - to modify how the worker works : HSET worker:mytodo:server_1 type LIFO
   //    - to kill the worker : HSET worker:mytodo:server_1 end_date ""
   $worker->readWorkerInfo();

   // checking the queue
   $worker->setStatus('WAITING');
   $task = $worker->getTask($worker->getWorkerInfo('WAITING_TIMEOUT'), $worker->getWorkerInfo('TYPE'));

   // getting some data from the queue
   if($task !== false)
   {
      $worker->setStatus('WORKING');

      // calling the action to do something with the data
      $parsed_action = str_replace("##data##", $task, $action);
      system("$parsed_action", $code);
   }

   // sleeping : changin the status only if the worker sleeps
   if ($worker->getWorkerInfo('LOOP_SLEEP') != 0)
   {
      $worker->setStatus('SLEEPING');
      sleep($worker->getWorkerInfo('LOOP_SLEEP'));
   }

   // initializing the worker end of life with the value in redis, like this, we can stop it right now or growing its life
   $end_time = strtotime($worker->getWorkerInfo('END_DATE'));
}


/**
 * Killing the worker to stop it clean
 */
$worker->setStatus('KILLED');
exit(0);
