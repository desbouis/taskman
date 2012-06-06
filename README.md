taskman
=======

Taskman is a redis queue management system with flexible worker daemon.
Taskman is written in PHP and uses phpredis (https://github.com/nicolasff/phpredis) PHP extension as Redis client.
It was large inspired by the post "PHP Workers with Redis & Solo" (http://www.justincarmony.com/blog/2012/01/10/php-workers-with-redis-solo/)

With Taskman, you add data in a queue and a worker daemon can pop this queue to give the data to an external script.

Some features
-------------

* FIFO or LIFO queue
* you can launch several workers to go faster
* informations about workers are stored in redis using 'hash' type
* workers has an end date to stop working
* workers can be driven and tuned directly in redis by interacting with their informations

The queue
---------

The task datas are stored using a 'list' type.
The key name format is : 'prefix:queue_name', for instance : 'queue:mytodo'.

This class is used by the Taskman_Worker class.

To add a new task data :

````php
$data = "{firstname:'john', name:'doe'}";
$queue = new Class_Taskman_Queue('taskman', 'mytodo');
$nb_tasks = $queue->pushTask($data);
````

The worker
----------

Workers informations are stored in redis using the 'hash' type.
The key name format is : 'prefix:queue_name:worker_id', for instance : 'worker:mytodo:server_1'.
In this hash, attributes are defined in the class attributes '_workerKeys'.
Informations stored are like this :

````javascript
worker:mytodo:server_1 = {
                          status            = 'STARTED' | 'WAITING' | 'WORKING' | 'SLEEPING' | 'KILLED'
                          status_changedate = '2012-03-10 22:01:23'
                          start_date        = '2012-03-10 21:44:00'
                          end_date          = '2012-03-10 22:47:00'
                          waiting_timeout   = 10
                          loop_sleep        = 10
                          action            = echo "##data##" >> /tmp/worker.log
                          action_cpt        = 2365
                          type              = 'FIFO' | 'LIFO'
                         }
````

You can then tune directly the worker by modifying some parameters with some redis commands :

* to modify the time between 2 loops : HSET worker:mytodo:server_1 loop_sleep 2
* to modify the waiting timeout on an empty queue : HSET worker:mytodo:server_1 waiting_timeout 20
* to modify how the worker works : HSET worker:mytodo:server_1 type LIFO
* to kill the worker : HSET worker:mytodo:server_1 end_date ""

To launch a worker
------------------

````shell
$ taskman_worker.php --id=$(hostname -s)_1 --base=taskman --queue=mytodo --action="echo \"##data##\" >> /tmp/worker.log" [ --sleep=10 ] [ --wait=10 ] [ --type=FIFO|LIFO ]
````

The --base value must be configured in the conf.ini file.

To get help :

````shell
$ taskman_worker.php --help
````





