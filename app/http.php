<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Runtimes\Runtimes;
use OpenRuntimes\Executor\Validator\TCP;
use OpenRuntimes\Executor\Usage;
use Swoole\Process;
use Swoole\Runtime;
use Swoole\Table;
use Swoole\Timer;
use Utopia\CLI\Console;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Storage;
use Utopia\System\System;
use Utopia\DSN\DSN;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Http\Http;
use Utopia\Http\Request;
use Utopia\Http\Response;
use Utopia\Http\Route;
use Utopia\Http\Validator\Assoc;
use Utopia\Http\Validator\Boolean;
use Utopia\Http\Validator\FloatValidator;
use Utopia\Http\Validator\Integer;
use Utopia\Http\Validator\Text;
use Utopia\Http\Validator\WhiteList;
use Utopia\Registry\Registry;

use function Swoole\Coroutine\batch;
use function Swoole\Coroutine\run;

// Unlimited memory limit to handle as many coroutines/requests as possible
ini_set('memory_limit', '-1');

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

Http::setMode((string)Http::getEnv('OPR_EXECUTOR_ENV', Http::MODE_TYPE_PRODUCTION));

// Setup Registry
$register = new Registry();

/**
 * Create logger for cloud logging
 */
$register->set('logger', function () {
    $providerName = Http::getEnv('OPR_EXECUTOR_LOGGING_PROVIDER', '');
    $providerConfig = Http::getEnv('OPR_EXECUTOR_LOGGING_CONFIG', '');
    $logger = null;

    if (!empty($providerName) && !empty($providerConfig) && Logger::hasProvider($providerName)) {
        $adapter = match ($providerName) {
            'sentry' => new Sentry($providerConfig),
            'raygun' => new Raygun($providerConfig),
            'logowl' => new LogOwl($providerConfig),
            'appsignal' => new AppSignal($providerConfig),
            default => throw new Exception('Provider "' . $providerName . '" not supported.')
        };

        $logger = new Logger($adapter);
    }

    return $logger;
});

/**
 * Create orchestration
 */
$register->set('orchestration', function () {
    $dockerUser = (string)Http::getEnv('OPR_EXECUTOR_DOCKER_HUB_USERNAME', '');
    $dockerPass = (string)Http::getEnv('OPR_EXECUTOR_DOCKER_HUB_PASSWORD', '');
    $orchestration = new Orchestration(new DockerCLI($dockerUser, $dockerPass));

    return $orchestration;
});

/**
 * Create a Swoole table to store runtime information
 */
$register->set('activeRuntimes', function () {
    $table = new Table(4096);

    $table->column('created', Table::TYPE_FLOAT);
    $table->column('updated', Table::TYPE_FLOAT);
    $table->column('name', Table::TYPE_STRING, 1024);
    $table->column('hostname', Table::TYPE_STRING, 1024);
    $table->column('status', Table::TYPE_STRING, 256);
    $table->column('key', Table::TYPE_STRING, 1024);
    $table->column('listening', Table::TYPE_INT, 1);
    $table->create();

    return $table;
});

/**
 * Create a Swoole table of usage stats (separate for host and containers)
 */
$register->set('statsContainers', function () {
    $table = new Table(4096);

    $table->column('usage', Table::TYPE_FLOAT, 8);
    $table->create();

    return $table;
});

$register->set('statsHost', function () {
    $table = new Table(4096);

    $table->column('usage', Table::TYPE_FLOAT, 8);
    $table->create();

    return $table;
});


/** Set Resources */
Http::setResource('log', fn () => new Log());
Http::setResource('register', fn () => $register);
Http::setResource('orchestration', fn (Registry $register) => $register->get('orchestration'), ['register']);
Http::setResource('activeRuntimes', fn (Registry $register) => $register->get('activeRuntimes'), ['register']);
Http::setResource('logger', fn (Registry $register) => $register->get('logger'), ['register']);
Http::setResource('statsContainers', fn (Registry $register) => $register->get('statsContainers'), ['register']);
Http::setResource('statsHost', fn (Registry $register) => $register->get('statsHost'), ['register']);

function logError(Log $log, Throwable $error, string $action, Logger $logger = null, Route $route = null): void
{
    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());

    if ($logger && ($error->getCode() === 500 || $error->getCode() === 0)) {
        $version = (string)Http::getEnv('OPR_EXECUTOR_VERSION', '');
        if (empty($version)) {
            $version = 'UNKNOWN';
        }

        $log->setNamespace("executor");
        $log->setServer(\gethostname() !== false ? \gethostname() : null);
        $log->setVersion($version);
        $log->setType(Log::TYPE_ERROR);
        $log->setMessage($error->getMessage());

        if ($route) {
            $log->addTag('method', $route->getMethod());
            $log->addTag('url', $route->getPath());
        }

        $log->addTag('code', \strval($error->getCode()));
        $log->addTag('verboseType', get_class($error));

        $log->addExtra('file', $error->getFile());
        $log->addExtra('line', $error->getLine());
        $log->addExtra('trace', $error->getTraceAsString());
        // TODO: @Meldiron Uncomment, was warning: Undefined array key "file" in Sentry.php on line 68
        // $log->addExtra('detailedTrace', $error->getTrace());

        $log->setAction($action);

        $log->setEnvironment(Http::isProduction() ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        $responseCode = $logger->addLog($log);
        Console::info('Executor log pushed with status code: ' . $responseCode);
    }
}

function getStorageDevice(string $root): Device
{
    $connection = Http::getEnv('OPR_EXECUTOR_CONNECTION_STORAGE', '');

    if (!empty($connection)) {
        $acl = 'private';
        $device = Storage::DEVICE_LOCAL;
        $accessKey = '';
        $accessSecret = '';
        $bucket = '';
        $region = '';

        try {
            $dsn = new DSN($connection);
            $device = $dsn->getScheme();
            $accessKey = $dsn->getUser() ?? '';
            $accessSecret = $dsn->getPassword() ?? '';
            $bucket = $dsn->getPath() ?? '';
            $region = $dsn->getParam('region');
        } catch (\Exception $e) {
            Console::warning($e->getMessage() . 'Invalid DSN. Defaulting to Local device.');
        }

        switch ($device) {
            case Storage::DEVICE_S3:
                return new S3($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case STORAGE::DEVICE_DO_SPACES:
                return new DOSpaces($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_BACKBLAZE:
                return new Backblaze($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LINODE:
                return new Linode($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_WASABI:
                return new Wasabi($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
        }
    } else {
        switch (strtolower(Http::getEnv('OPR_EXECUTOR_STORAGE_DEVICE', Storage::DEVICE_LOCAL) ?? '')) {
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
            case Storage::DEVICE_S3:
                $s3AccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_ACCESS_KEY', '') ?? '';
                $s3SecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_SECRET', '') ?? '';
                $s3Region = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_REGION', '') ?? '';
                $s3Bucket = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_BUCKET', '') ?? '';
                $s3Acl = 'private';
                return new S3($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
            case Storage::DEVICE_DO_SPACES:
                $doSpacesAccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_ACCESS_KEY', '') ?? '';
                $doSpacesSecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_SECRET', '') ?? '';
                $doSpacesRegion = Http::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_REGION', '') ?? '';
                $doSpacesBucket = Http::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_BUCKET', '') ?? '';
                $doSpacesAcl = 'private';
                return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
            case Storage::DEVICE_BACKBLAZE:
                $backblazeAccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_ACCESS_KEY', '') ?? '';
                $backblazeSecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_SECRET', '') ?? '';
                $backblazeRegion = Http::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_REGION', '') ?? '';
                $backblazeBucket = Http::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_BUCKET', '') ?? '';
                $backblazeAcl = 'private';
                return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
            case Storage::DEVICE_LINODE:
                $linodeAccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_LINODE_ACCESS_KEY', '') ?? '';
                $linodeSecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_LINODE_SECRET', '') ?? '';
                $linodeRegion = Http::getEnv('OPR_EXECUTOR_STORAGE_LINODE_REGION', '') ?? '';
                $linodeBucket = Http::getEnv('OPR_EXECUTOR_STORAGE_LINODE_BUCKET', '') ?? '';
                $linodeAcl = 'private';
                return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
            case Storage::DEVICE_WASABI:
                $wasabiAccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_WASABI_ACCESS_KEY', '') ?? '';
                $wasabiSecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_WASABI_SECRET', '') ?? '';
                $wasabiRegion = Http::getEnv('OPR_EXECUTOR_STORAGE_WASABI_REGION', '') ?? '';
                $wasabiBucket = Http::getEnv('OPR_EXECUTOR_STORAGE_WASABI_BUCKET', '') ?? '';
                $wasabiAcl = 'private';
                return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
        }
    }
}


/**
 * @param array<string> $networks
 *
 * @return array<string>
 */
function createNetworks(Orchestration $orchestration, array $networks): array
{
    $image = Http::getEnv('OPR_EXECUTOR_IMAGE', '');
    $containers = $orchestration->list(['label' => "openruntimes-image=$image"]);

    if (count($containers) < 1) {
        $containerName = '';
        Console::warning('No matching executor found. Networks will be created but the executor will need to be connected manually.');
    } else {
        $containerName = $containers[0]->getName();
        Console::success('Found matching executor. Networks will be created and the executor will be connected automatically.');
    }

    $jobs = [];
    $createdNetworks = [];
    foreach ($networks as $network) {
        $jobs[] = function () use ($orchestration, $network, $containerName, &$createdNetworks) {
            if (!$orchestration->networkExists($network)) {
                try {
                    $orchestration->createNetwork($network, false);
                    if (!empty($containerName)) {
                        $orchestration->networkConnect($containerName, $network);
                    }
                    Console::success("Created network: $network");
                    $createdNetworks[] = $network;
                } catch (Exception $e) {
                    Console::error("Failed to create network $network: " . $e->getMessage());
                }
            } else {
                Console::info("Network $network already exists");
                $createdNetworks[] = $network;
            }
        };
    }

    batch($jobs);
    return $createdNetworks;
}

/**
 * @param array<string> $networks
 */
function cleanUp(Orchestration $orchestration, Table $activeRuntimes, array $networks = []): void
{
    Console::log('Cleaning up containers and networks...');

    $functionsToRemove = $orchestration->list(['label' => 'openruntimes-executor=' . System::getHostname()]);

    if (\count($functionsToRemove) === 0) {
        Console::info('No containers found to clean up.');
    }

    $jobsRuntimes = [];
    foreach ($functionsToRemove as $container) {
        $jobsRuntimes[] = function () use ($container, $activeRuntimes, $orchestration) {
            try {
                $orchestration->remove($container->getId(), true);

                $activeRuntimeId = $container->getName();

                if (!$activeRuntimes->exists($activeRuntimeId)) {
                    $activeRuntimes->del($activeRuntimeId);
                }

                Console::success('Removed container ' . $container->getName());
            } catch (\Throwable $th) {
                Console::error('Failed to remove container: ' . $container->getName());
                Console::error($th);
            }
        };
    }
    batch($jobsRuntimes);

    $jobsNetworks = [];
    foreach ($networks as $network) {
        $jobsNetworks[] = function () use ($orchestration, $network) {
            try {
                $orchestration->removeNetwork($network);
                Console::success("Removed network: $network");
            } catch (Exception $e) {
                Console::error("Failed to remove network $network: " . $e->getMessage());
            }
        };
    }
    batch($jobsNetworks);

    Console::success('Cleanup finished.');
}

Http::get('/v1/runtimes/:runtimeId/logs')
    ->desc("Get live stream of logs of a runtime")
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.')
    ->param('timeout', '600', new Text(16), 'Maximum logs timeout.', true)
    ->inject('response')
    ->inject('log')
    ->action(function (string $runtimeId, string $timeoutStr, Response $response, Log $log) {
        $timeout = \intval($timeoutStr);

        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $response->sendHeader('Content-Type', 'text/event-stream');
        $response->sendHeader('Cache-Control', 'no-cache');

        // Wait for runtime
        for ($i = 0; $i < 10; $i++) {
            $output = '';
            $code = Console::execute('docker container inspect ' . \escapeshellarg($runtimeName), '', $output);
            if ($code === 0) {
                break;
            }

            if ($i === 9) {
                $runtimeIdTokens = explode("-", $runtimeName);
                $executorId = $runtimeIdTokens[0];
                $functionId = $runtimeIdTokens[1];
                $deploymentId = $runtimeIdTokens[2];
                $log->addTag('executorId', $executorId);
                $log->addTag('functionId', $functionId);
                $log->addTag('deploymentId', $deploymentId);
                throw new Exception('Runtime not ready. Container not found.', 500);
            }

            \usleep(500000);
        }

        /**
         * @var mixed $logsChunk
         */
        $logsChunk = '';

        /**
         * @var mixed $logsProcess
         */
        $logsProcess = null;

        $streamInterval = 1000; // 1 second
        $timerId = Timer::tick($streamInterval, function () use (&$logsProcess, &$logsChunk, $response) {
            if (empty($logsChunk)) {
                return;
            }

            $write = $response->write($logsChunk);
            $logsChunk = '';

            if (!$write) {
                if (!empty($logsProcess)) {
                    \proc_terminate($logsProcess, 9);
                }
            }
        });

        $output = '';
        Console::execute('docker exec ' . \escapeshellarg($runtimeName) . ' tail -F /var/tmp/logs.txt', '', $output, $timeout, function (string $outputChunk, mixed $process) use (&$logsChunk, &$logsProcess) {
            $logsProcess = $process;

            if (!empty($outputChunk)) {
                $logsChunk .= $outputChunk;
            }
        });

        Timer::clear($timerId);

        $response->end();
    });

Http::post('/v1/runtimes')
    ->desc("Create a new runtime server")
    ->param('runtimeId', '', new Text(64), 'Unique runtime ID.')
    ->param('image', '', new Text(128), 'Base image name of the runtime.')
    ->param('entrypoint', '', new Text(256), 'Entrypoint of the code file.', true)
    ->param('source', '', new Text(0), 'Path to source files.', true)
    ->param('destination', '', new Text(0), 'Destination folder to store runtime files into.', true)
    ->param('variables', [], new Assoc(), 'Environment variables passed into runtime.', true)
    ->param('runtimeEntrypoint', '', new Text(1024, 0), 'Commands to run when creating a container. Maximum of 100 commands are allowed, each 1024 characters long.', true)
    ->param('command', '', new Text(1024), 'Commands to run after container is created. Maximum of 100 commands are allowed, each 1024 characters long.', true)
    ->param('timeout', 600, new Integer(), 'Commands execution time in seconds.', true)
    ->param('remove', false, new Boolean(), 'Remove a runtime after execution.', true)
    ->param('cpus', 1.0, new FloatValidator(), 'Container CPU.', true)
    ->param('memory', 512, new Integer(), 'Comtainer RAM memory.', true)
    ->param('version', 'v3', new WhiteList(['v2', 'v3']), 'Runtime Open Runtime version.', true)
    ->inject('networks')
    ->inject('orchestration')
    ->inject('activeRuntimes')
    ->inject('response')
    ->inject('log')
    ->action(function (string $runtimeId, string $image, string $entrypoint, string $source, string $destination, array $variables, string $runtimeEntrypoint, string $command, int $timeout, bool $remove, float $cpus, int $memory, string $version, array $networks, Orchestration $orchestration, Table $activeRuntimes, Response $response, Log $log) {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $runtimeHostname = \uniqid();

        $log->addTag('version', $version);
        $log->addTag('runtimeId', $runtimeName);

        if ($activeRuntimes->exists($runtimeName)) {
            if ($activeRuntimes->get($runtimeName)['status'] == 'pending') {
                throw new \Exception('A runtime with the same ID is already being created. Attempt a execution soon.', 409);
            }

            throw new Exception('Runtime already exists.', 409);
        }

        $container = [];
        $output = '';
        $startTime = \microtime(true);

        $secret = \bin2hex(\random_bytes(16));

        $activeRuntimes->set($runtimeName, [
            'listening' => 0,
            'name' => $runtimeName,
            'hostname' => $runtimeHostname,
            'created' => $startTime,
            'updated' => $startTime,
            'status' => 'pending',
            'key' => $secret,
        ]);

        /**
         * Temporary file paths in the executor
         */
        $tmpFolder = "tmp/$runtimeName/";
        $tmpSource = "/{$tmpFolder}src/code.tar.gz";
        $tmpBuild = "/{$tmpFolder}builds/code.tar.gz";

        $sourceDevice = getStorageDevice("/");
        $localDevice = new Local();

        try {
            /**
             * Copy code files from source to a temporary location on the executor
             */
            if (!empty($source)) {
                if (!$sourceDevice->transfer($source, $tmpSource, $localDevice)) {
                    throw new Exception('Failed to copy source code to temporary directory', 500);
                };
            }

            /**
             * Create the mount folder
             */
            if (!$localDevice->createDirectory(\dirname($tmpBuild))) {
                throw new Exception("Failed to create temporary directory", 500);
            }

            /**
             * Create container
             */
            $variables = \array_merge(
                $variables,
                match ($version) {
                    'v2' => [
                        'INTERNAL_RUNTIME_KEY' => $secret,
                        'INTERNAL_RUNTIME_ENTRYPOINT' => $entrypoint,
                        'INERNAL_EXECUTOR_HOSTNAME' => System::getHostname()
                    ],
                    'v3' => [
                        'OPEN_RUNTIMES_SECRET' => $secret,
                        'OPEN_RUNTIMES_ENTRYPOINT' => $entrypoint,
                        'OPEN_RUNTIMES_HOSTNAME' => System::getHostname()
                    ]
                }
            );

            $variables = array_map(fn ($v) => strval($v), $variables);
            $orchestration
                ->setCpus($cpus)
                ->setMemory($memory);

            $runtimeEntrypointCommands = [];

            if (empty($runtimeEntrypoint)) {
                if ($version === 'v2' && empty($command)) {
                    $runtimeEntrypointCommands = [];
                } else {
                    $runtimeEntrypointCommands = ['tail', '-f', '/dev/null'];
                }
            } else {
                $runtimeEntrypointCommands = ['sh', '-c', $runtimeEntrypoint];
            }

            $codeMountPath = $version === 'v2' ? '/usr/code' : '/mnt/code';
            $workdir = $version === 'v2' ? '/usr/code' : '';

            $network = $networks[array_rand($networks)];

            /** Keep the container alive if we have commands to be executed */
            $containerId = $orchestration->run(
                image: $image,
                name: $runtimeName,
                hostname: $runtimeHostname,
                vars: $variables,
                command: $runtimeEntrypointCommands,
                labels: [
                    'openruntimes-executor' => System::getHostname(),
                    'openruntimes-runtime-id' => $runtimeId
                ],
                volumes: [
                    \dirname($tmpSource) . ':/tmp:rw',
                    \dirname($tmpBuild) . ':' . $codeMountPath . ':rw'
                ],
                network: $network,
                workdir: $workdir
            );

            if (empty($containerId)) {
                throw new Exception('Failed to create runtime', 500);
            }

            /**
             * Execute any commands if they were provided
             */
            if (!empty($command)) {
                $commands = [
                    'sh',
                    '-c',
                    'touch /var/tmp/logs.txt && (' . $command . ') >> /var/tmp/logs.txt 2>&1 && cat /var/tmp/logs.txt'
                ];

                try {
                    $status = $orchestration->execute(
                        name: $runtimeName,
                        command: $commands,
                        output: $output,
                        timeout: $timeout
                    );

                    if (!$status) {
                        throw new Exception('Failed to create runtime: ' . $output, 400);
                    }
                } catch (Throwable $err) {
                    throw new Exception($err->getMessage(), 400);
                }
            }

            /**
             * Move built code to expected build directory
             */
            if (!empty($destination)) {
                // Check if the build was successful by checking if file exists
                if (!$localDevice->exists($tmpBuild)) {
                    throw new Exception('Something went wrong when starting runtime.', 500);
                }

                $size = $localDevice->getFileSize($tmpBuild);
                $container['size'] = $size;

                $destinationDevice = getStorageDevice($destination);
                $path = $destinationDevice->getPath(\uniqid() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));


                if (!$localDevice->transfer($tmpBuild, $path, $destinationDevice)) {
                    throw new Exception('Failed to move built code to storage', 500);
                };

                $container['path'] = $path;
            }

            if ($output === '') {
                $output = 'Runtime created successfully!';
            }

            $endTime = \microtime(true);
            $duration = $endTime - $startTime;

            $container = array_merge($container, [
                'output' => \mb_strcut($output, 0, 1000000), // Limit to 1MB
                'startTime' => $startTime,
                'duration' => $duration,
            ]);

            $activeRuntime = $activeRuntimes->get($runtimeName);
            $activeRuntime['updated'] = \microtime(true);
            $activeRuntime['status'] = 'Up ' . \round($duration, 2) . 's';
            $activeRuntimes->set($runtimeName, $activeRuntime);
        } catch (Throwable $th) {
            $error = $th->getMessage() . $output;

            // Extract as much logs as we can
            try {
                $logs = '';
                $status = $orchestration->execute(
                    name: $runtimeName,
                    command: ['sh', '-c', 'cat /var/tmp/logs.txt'],
                    output: $logs,
                    timeout: 15
                );

                if (!empty($logs)) {
                    $error = $th->getMessage() . $logs;
                }
            } catch (Throwable $err) {
                // Ignore, use fallback error message
            }

            if ($remove) {
                \sleep(2); // Allow time to read logs
            }

            $localDevice->deletePath($tmpFolder);

            // Silently try to kill container
            try {
                $orchestration->remove($runtimeName, true);
            } catch (Throwable $th) {
            }

            $activeRuntimes->del($runtimeName);

            throw new Exception($error, $th->getCode() ?: 500);
        }

        // Container cleanup
        if ($remove) {
            \sleep(2); // Allow time to read logs

            $localDevice->deletePath($tmpFolder);

            // Silently try to kill container
            try {
                $orchestration->remove($runtimeName, true);
            } catch (Throwable $th) {
            }

            $activeRuntimes->del($runtimeName);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($container);
    });

Http::get('/v1/runtimes')
    ->desc("List currently active runtimes")
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function (Table $activeRuntimes, Response $response) {
        $runtimes = [];

        foreach ($activeRuntimes as $runtime) {
            $runtimes[] = $runtime;
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($runtimes);
    });

Http::get('/v1/runtimes/:runtimeId')
    ->desc("Get a runtime by its ID")
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.')
    ->inject('activeRuntimes')
    ->inject('response')
    ->inject('log')
    ->action(function (string $runtimeId, Table $activeRuntimes, Response $response, Log $log) {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $log->addTag('runtimeId', $runtimeName);

        if (!$activeRuntimes->exists($runtimeName)) {
            throw new Exception('Runtime not found', 404);
        }

        $runtime = $activeRuntimes->get($runtimeName);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($runtime);
    });

Http::delete('/v1/runtimes/:runtimeId')
    ->desc('Delete a runtime')
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.', false)
    ->inject('orchestration')
    ->inject('activeRuntimes')
    ->inject('response')
    ->inject('log')
    ->action(function (string $runtimeId, Orchestration $orchestration, Table $activeRuntimes, Response $response, Log $log) {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $log->addTag('runtimeId', $runtimeName);

        if (!$activeRuntimes->exists($runtimeName)) {
            throw new Exception('Runtime not found', 404);
        }

        $orchestration->remove($runtimeName, true);
        $activeRuntimes->del($runtimeName);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->send();
    });

Http::post('/v1/runtimes/:runtimeId/executions')
    ->alias('/v1/runtimes/:runtimeId/execution')
    ->desc('Create an execution')
    // Execution-related
    ->param('runtimeId', '', new Text(64), 'The runtimeID to execute.')
    ->param('body', '', new Text(20971520), 'Data to be forwarded to the function, this is user specified.', true)
    ->param('path', '/', new Text(2048), 'Path from which execution comes.', true)
    ->param('method', 'GET', new Whitelist(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true), 'Path from which execution comes.', true)
    ->param('headers', [], new Assoc(), 'Headers passed into runtime.', true)
    ->param('timeout', 15, new Integer(), 'Function maximum execution time in seconds.', true)
    // Runtime-related
    ->param('image', '', new Text(128), 'Base image name of the runtime.', true)
    ->param('source', '', new Text(0), 'Path to source files.', true)
    ->param('entrypoint', '', new Text(256), 'Entrypoint of the code file.', true)
    ->param('variables', [], new Assoc(), 'Environment variables passed into runtime.', true)
    ->param('cpus', 1.0, new FloatValidator(), 'Container CPU.', true)
    ->param('memory', 512, new Integer(), 'Container RAM memory.', true)
    ->param('version', 'v3', new WhiteList(['v2', 'v3']), 'Runtime Open Runtime version.', true)
    ->param('runtimeEntrypoint', '', new Text(1024, 0), 'Commands to run when creating a container. Maximum of 100 commands are allowed, each 1024 characters long.', true)
    ->inject('activeRuntimes')
    ->inject('response')
    ->inject('log')
    ->action(
        function (string $runtimeId, ?string $payload, string $path, string $method, array $headers, int $timeout, string $image, string $source, string $entrypoint, array $variables, float $cpus, int $memory, string $version, string $runtimeEntrypoint, Table $activeRuntimes, Response $response, Log $log) {
            if (empty($payload)) {
                $payload = '';
            }

            $runtimeName = System::getHostname() . '-' . $runtimeId;

            $log->addTag('version', $version);
            $log->addTag('runtimeId', $runtimeName);

            $variables = \array_merge($variables, [
                'INERNAL_EXECUTOR_HOSTNAME' => System::getHostname()
            ]);

            $prepareStart = \microtime(true);

            // Prepare runtime
            if (!$activeRuntimes->exists($runtimeName)) {
                if (empty($image) || empty($source) || empty($entrypoint)) {
                    throw new Exception('Runtime not found. Please start it first or provide runtime-related parameters.', 401);
                }

                // Prepare request to executor
                $sendCreateRuntimeRequest = function () use ($runtimeId, $image, $source, $entrypoint, $variables, $cpus, $memory, $version, $runtimeEntrypoint) {
                    $statusCode = 0;
                    $errNo = -1;
                    $executorResponse = '';

                    $ch = \curl_init();

                    $body = \json_encode([
                        'runtimeId' => $runtimeId,
                        'image' => $image,
                        'source' => $source,
                        'entrypoint' => $entrypoint,
                        'variables' => $variables,
                        'cpus' => $cpus,
                        'memory' => $memory,
                        'version' => $version,
                        'runtimeEntrypoint' => $runtimeEntrypoint
                    ]);

                    \curl_setopt($ch, CURLOPT_URL, "http://localhost/v1/runtimes");
                    \curl_setopt($ch, CURLOPT_POST, true);
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

                    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Content-Length: ' . \strlen($body ?: ''),
                        'authorization: Bearer ' . Http::getEnv('OPR_EXECUTOR_SECRET', '')
                    ]);

                    $executorResponse = \curl_exec($ch);

                    $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    $error = \curl_error($ch);

                    $errNo = \curl_errno($ch);

                    \curl_close($ch);

                    return [
                        'errNo' => $errNo,
                        'error' => $error,
                        'statusCode' => $statusCode,
                        'executorResponse' => $executorResponse
                    ];
                };

                // Prepare runtime
                while (true) {
                    // If timeout is passed, stop and return error
                    if (\microtime(true) - $prepareStart >= $timeout) {
                        throw new Exception('Function timed out during preparation.', 400);
                    }

                    ['errNo' => $errNo, 'error' => $error, 'statusCode' => $statusCode, 'executorResponse' => $executorResponse] = \call_user_func($sendCreateRuntimeRequest);

                    if ($errNo === 0) {
                        $body = \json_decode($executorResponse, true);

                        if ($statusCode >= 500) {
                            $error = $body['message'];
                        // Continues to retry logic
                        } elseif ($statusCode >= 400) {
                            $error = $body['message'];
                            throw new Exception('An internal curl error has occurred while starting runtime! Error Msg: ' . $error, 500);
                        } else {
                            break;
                        }
                    } elseif ($errNo !== 111) { // Connection Refused - see https://openswoole.com/docs/swoole-error-code
                        throw new Exception('An internal curl error has occurred while starting runtime! Error Msg: ' . $error, 500);
                    }

                    // Wait 0.5s and check again
                    \usleep(500000);
                }
            }

            // Lower timeout by time it took to prepare container
            $timeout -= (\microtime(true) - $prepareStart);

            // Update swoole table
            $runtime = $activeRuntimes->get($runtimeName) ?? [];
            $runtime['updated'] = \time();
            $activeRuntimes->set($runtimeName, $runtime);

            // Ensure runtime started
            $launchStart = \microtime(true);
            while (true) {
                // If timeout is passed, stop and return error
                if (\microtime(true) - $launchStart >= $timeout) {
                    throw new Exception('Function timed out during launch.', 400);
                }

                if ($activeRuntimes->get($runtimeName)['status'] !== 'pending') {
                    break;
                }

                // Wait 0.5s and check again
                \usleep(500000);
            }

            // Lower timeout by time it took to launch container
            $timeout -= (\microtime(true) - $launchStart);

            // Ensure we have secret
            $runtime = $activeRuntimes->get($runtimeName);
            $hostname = $runtime['hostname'];
            $secret = $runtime['key'];
            if (empty($secret)) {
                throw new Exception('Runtime secret not found. Please re-create the runtime.', 500);
            }

            $executeV2 = function () use ($variables, $payload, $secret, $hostname, $timeout): array {
                $statusCode = 0;
                $errNo = -1;
                $executorResponse = '';

                $ch = \curl_init();

                $body = \json_encode([
                    'variables' => $variables,
                    'payload' => $payload,
                    'headers' => []
                ], JSON_FORCE_OBJECT);

                \curl_setopt($ch, CURLOPT_URL, "http://" . $hostname . ":3000/");
                \curl_setopt($ch, CURLOPT_POST, true);
                \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

                \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . \strlen($body ?: ''),
                    'x-internal-challenge: ' . $secret,
                    'host: null'
                ]);

                $executorResponse = \curl_exec($ch);

                $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

                $error = \curl_error($ch);

                $errNo = \curl_errno($ch);

                \curl_close($ch);

                if ($errNo !== 0) {
                    return [
                        'errNo' => $errNo,
                        'error' => $error,
                        'statusCode' => $statusCode,
                        'body' => '',
                        'logs' => '',
                        'errors' => '',
                        'headers' => []
                    ];
                }

                // Extract response
                $executorResponse = json_decode(\strval($executorResponse), false);

                $res = $executorResponse->response ?? '';
                if (is_array($res)) {
                    $res = json_encode($res, JSON_UNESCAPED_UNICODE);
                } elseif (is_object($res)) {
                    $res = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
                }

                $stderr = $executorResponse->stderr ?? '';
                $stdout = $executorResponse->stdout ?? '';

                return [
                    'errNo' => $errNo,
                    'error' => $error,
                    'statusCode' => $statusCode,
                    'body' => $res,
                    'logs' => $stdout,
                    'errors' => $stderr,
                    'headers' => []
                ];
            };

            $executeV3 = function () use ($path, $method, $headers, $payload, $secret, $hostname, $timeout): array {
                $statusCode = 0;
                $errNo = -1;
                $executorResponse = '';

                $ch = \curl_init();

                $body = $payload;

                $responseHeaders = [];

                \curl_setopt($ch, CURLOPT_URL, "http://" . $hostname . ":3000" . $path);
                \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                \curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
                    $len = strlen($header);
                    $header = explode(':', $header, 2);
                    if (count($header) < 2) { // ignore invalid headers
                        return $len;
                    }

                    $key = strtolower(trim($header[0]));
                    $responseHeaders[$key] = trim($header[1]);

                    if (\in_array($key, ['x-open-runtimes-logs', 'x-open-runtimes-errors'])) {
                        $responseHeaders[$key] = \urldecode($responseHeaders[$key]);
                    }

                    return $len;
                });
                \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout + 5); // Gives extra 5s after safe timeout to recieve response
                \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

                $headers['x-open-runtimes-secret'] = $secret;
                $headers['x-open-runtimes-timeout'] = \max(\intval($timeout), 1);
                $headersArr = [];
                foreach ($headers as $key => $value) {
                    $headersArr[] = $key . ': ' . $value;
                }

                \curl_setopt($ch, CURLOPT_HEADEROPT, CURLHEADER_UNIFIED);
                \curl_setopt($ch, CURLOPT_HTTPHEADER, $headersArr);

                $executorResponse = \curl_exec($ch);

                $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

                $error = \curl_error($ch);

                $errNo = \curl_errno($ch);

                \curl_close($ch);

                if ($errNo !== 0) {
                    return [
                        'errNo' => $errNo,
                        'error' => $error,
                        'statusCode' => $statusCode,
                        'body' => '',
                        'logs' => '',
                        'errors' => '',
                        'headers' => $responseHeaders
                    ];
                }

                $stdout = $responseHeaders['x-open-runtimes-logs'] ?? '';
                $stderr = $responseHeaders['x-open-runtimes-errors'] ?? '';

                $outputHeaders = [];
                foreach ($responseHeaders as $key => $value) {
                    if (\str_starts_with($key, 'x-open-runtimes-')) {
                        continue;
                    }

                    $outputHeaders[$key] = $value;
                }

                return [
                    'errNo' => $errNo,
                    'error' => $error,
                    'statusCode' => $statusCode,
                    'body' => $executorResponse,
                    'logs' => $stdout,
                    'errors' => $stderr,
                    'headers' => $outputHeaders
                ];
            };

            // From here we calculate billable duration of execution
            $startTime = \microtime(true);

            $listening = $runtime['listening'];

            if (empty($listening)) {
                // Wait for cold-start to finish (app listening on port)
                $pingStart = \microtime(true);
                $validator = new TCP();
                while (true) {
                    // If timeout is passed, stop and return error
                    if (\microtime(true) - $pingStart >= $timeout) {
                        throw new Exception('Function timed out during cold start.', 400);
                    }

                    $online = $validator->isValid($hostname . ':' . 3000);
                    if ($online) {
                        break;
                    }

                    // Wait 0.5s and check again
                    \usleep(500000);
                }

                // Update swoole table
                $runtime = $activeRuntimes->get($runtimeName);
                $runtime['listening'] = 1;
                $activeRuntimes->set($runtimeName, $runtime);

                // Lower timeout by time it took to cold-start
                $timeout -= (\microtime(true) - $pingStart);
            }

            // Execute function
            $executionRequest = $version === 'v3' ? $executeV3 : $executeV2;
            $executionResponse = \call_user_func($executionRequest);

            // Error occured
            if ($executionResponse['errNo'] !== 0) {
                // Unknown protocol error code, but also means parsing issue
                // As patch, we consider this too big entry for headers (logs&errors)
                if ($executionResponse['errNo'] === 7102) {
                    throw new Exception('Invalid response. This usually means too large logs or errors. Please avoid logging files or lengthy strings.', 500);
                }

                // Intended timeout error for v2 functions
                if ($executionResponse['errNo'] === 110 && $version === 'v2') {
                    throw new Exception($executionResponse['error'], 400);
                }

                // Unknown error
                throw new Exception('Internal curl errors has occurred within the executor! Error Number: ' . $executionResponse['errNo'] . '. Error Msg: ' . $executionResponse['error'], 500);
            }

            // Successful execution

            ['statusCode' => $statusCode, 'body' => $body, 'logs' => $logs, 'errors' => $errors, 'headers' => $headers] = $executionResponse;

            $endTime = \microtime(true);
            $duration = $endTime - $startTime;

            $header['x-open-runtimes-encoding'] = 'original';
            $execution = [
                'statusCode' => $statusCode,
                'headers' => $headers,
                'body' => $body,
                'logs' => \mb_strcut($logs, 0, 1000000), // Limit to 1MB
                'errors' => \mb_strcut($errors, 0, 1000000), // Limit to 1MB
                'duration' => $duration,
                'startTime' => $startTime,
            ];

            $executionString = \json_encode($execution, JSON_UNESCAPED_UNICODE);
            if (!$executionString) {
                $execution['body'] = \base64_encode($body);
                $execution['headers']['x-open-runtimes-encoding'] = 'base64';
                $executionString = \json_encode($execution, JSON_UNESCAPED_UNICODE);
            }

            // Update swoole table
            $runtime = $activeRuntimes->get($runtimeName);
            $runtime['updated'] = \microtime(true);
            $activeRuntimes->set($runtimeName, $runtime);

            // Finish request
            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->setContentType(Response::CONTENT_TYPE_JSON, Response::CHARSET_UTF8)
                ->send((string)$executionString);
        }
    );

Http::get('/v1/health')
    ->desc("Get health status of host machine and runtimes.")
    ->inject('statsHost')
    ->inject('statsContainers')
    ->inject('response')
    ->action(function (Table $statsHost, Table $statsContainers, Response $response) {
        $output = [
            'status' => 'pass',
            'runtimes' => []
        ];

        $hostUsage = $statsHost->get('host', 'usage') ?? null;
        $output['usage'] = $hostUsage;

        foreach ($statsContainers as $hostname => $stat) {
            $output['runtimes'][$hostname] = [
                'status' => 'pass',
                'usage' => $stat['usage'] ?? null
            ];
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($output);
    });

/** Set callbacks */
Http::error()
    ->inject('route')
    ->inject('error')
    ->inject('logger')
    ->inject('response')
    ->inject('log')
    ->action(function (?Route $route, Throwable $error, ?Logger $logger, Response $response, Log $log) {
        logError($log, $error, "httpError", $logger, $route);

        $version = Http::getEnv('OPR_EXECUTOR_VERSION', 'UNKNOWN');
        $message = $error->getMessage();
        $file = $error->getFile();
        $line = $error->getLine();
        $trace = $error->getTrace();

        switch ($error->getCode()) {
            case 400: // Error allowed publicly
            case 401: // Error allowed publicly
            case 402: // Error allowed publicly
            case 403: // Error allowed publicly
            case 404: // Error allowed publicly
            case 406: // Error allowed publicly
            case 409: // Error allowed publicly
            case 412: // Error allowed publicly
            case 425: // Error allowed publicly
            case 429: // Error allowed publicly
            case 501: // Error allowed publicly
            case 503: // Error allowed publicly
                $code = $error->getCode();
                break;
            default:
                $code = 500; // All other errors get the generic 500 server error status code
        }

        $output = Http::isDevelopment() ? [
            'message' => $message,
            'code' => $code,
            'file' => $file,
            'line' => $line,
            'trace' => \json_encode($trace, JSON_UNESCAPED_UNICODE) === false ? [] : $trace, // check for failing encode
            'version' => $version
        ] : [
            'message' => $message,
            'code' => $code,
            'version' => $version
        ];

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode($code);

        $response->json($output);
    });

Http::init()
    ->inject('request')
    ->action(function (Request $request) {
        $secretKey = \explode(' ', $request->getHeader('authorization', ''))[1] ?? '';
        if (empty($secretKey) || $secretKey !== Http::getEnv('OPR_EXECUTOR_SECRET', '')) {
            throw new Exception('Missing executor key', 401);
        }
    });

run(function () use ($register) {
    $orchestration = $register->get('orchestration');
    $statsContainers = $register->get('statsContainers');
    $activeRuntimes = $register->get('activeRuntimes');
    $statsHost = $register->get('statsHost');

    $networks = explode(',', Http::getEnv('OPR_EXECUTOR_NETWORK') ?? 'executor_runtimes');
    /**
     * Remove residual runtimes and networks
     */
    Console::info('Removing orphan runtimes and networks...');
    cleanUp($orchestration, $activeRuntimes, $networks);
    Console::success("Orphan runtimes and networks removal finished.");

    // TODO: Remove all /tmp folders starting with System::hostname() -

    /**
     * Create and store Docker Bridge networks used for communication between executor and runtimes
     */
    Console::info('Creating networks...');
    $createdNetworks = createNetworks($orchestration, $networks);
    Http::setResource('networks', fn () => $createdNetworks);

    /**
     * Warmup: make sure images are ready to run fast 🚀
     */
    $allowList = empty(Http::getEnv('OPR_EXECUTOR_RUNTIMES')) ? [] : \explode(',', Http::getEnv('OPR_EXECUTOR_RUNTIMES'));

    $runtimeVersions = \explode(',', Http::getEnv('OPR_EXECUTOR_RUNTIME_VERSIONS', 'v3') ?? 'v3');
    foreach ($runtimeVersions as $runtimeVersion) {
        Console::success("Pulling $runtimeVersion images...");
        $runtimes = new Runtimes($runtimeVersion); // TODO: @Meldiron Make part of open runtimes
        $runtimes = $runtimes->getAll(true, $allowList);
        $callables = [];
        foreach ($runtimes as $runtime) {
            $callables[] = function () use ($runtime, $orchestration) {
                Console::log('Warming up ' . $runtime['name'] . ' ' . $runtime['version'] . ' environment...');
                $response = $orchestration->pull($runtime['image']);
                if ($response) {
                    Console::info("Successfully Warmed up {$runtime['name']} {$runtime['version']}!");
                } else {
                    Console::warning("Failed to Warmup {$runtime['name']} {$runtime['version']}!");
                }
            };
        }

        batch($callables);
    }

    Console::success("Image pulling finished.");

    /**
     * Run a maintenance worker every X seconds to remove inactive runtimes
     */
    Console::info('Starting maintenance interval...');
    $interval = (int)Http::getEnv('OPR_EXECUTOR_MAINTENANCE_INTERVAL', '3600'); // In seconds
    Timer::tick($interval * 1000, function () use ($orchestration, $activeRuntimes) {
        Console::info("Running maintenance task ...");
        // Stop idling runtimes
        foreach ($activeRuntimes as $runtimeName => $runtime) {
            $inactiveThreshold = \time() - \intval(Http::getEnv('OPR_EXECUTOR_INACTIVE_TRESHOLD', '60'));
            if ($runtime['updated'] < $inactiveThreshold) {
                go(function () use ($runtimeName, $runtime, $orchestration, $activeRuntimes) {
                    try {
                        $orchestration->remove($runtime['name'], true);
                        Console::success("Successfully removed {$runtime['name']}");
                    } catch (\Throwable $th) {
                        Console::error('Inactive Runtime deletion failed: ' . $th->getMessage());
                    } finally {
                        $activeRuntimes->del($runtimeName);
                    }
                });
            }
        }
        // Clear leftover build folders
        $localDevice = new Local();
        $tmpPath = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;
        $entries = $localDevice->getFiles($tmpPath);
        $prefix = $tmpPath . System::getHostname() . '-';
        foreach ($entries as $entry) {
            if (\str_starts_with($entry, $prefix)) {
                $isActive = false;

                foreach ($activeRuntimes as $runtimeName => $runtime) {
                    if (\str_ends_with($entry, $runtimeName)) {
                        $isActive = true;
                        break;
                    }
                }

                if (!$isActive) {
                    $localDevice->deletePath($entry);
                }
            }
        }

        Console::success("Maintanance task finished.");
    });

    Console::success('Maintenance interval started.');

    /**
     * Get usage stats every X seconds to update swoole table
     */
    Console::info('Starting stats interval...');
    function getStats(Table $statsHost, Table $statsContainers, Orchestration $orchestration, bool $recursive = false): void
    {
        // Get usage stats
        $usage = new Usage($orchestration);
        $usage->run();

        // Update host usage stats
        if ($usage->getHostUsage() !== null) {
            $oldStat = $statsHost->get('host', 'usage') ?? null;

            if ($oldStat === null) {
                $stat = $usage->getHostUsage();
            } else {
                $stat = ($oldStat + $usage->getHostUsage()) / 2;
            }

            $statsHost->set('host', ['usage' => $stat]);
        }

        // Update runtime usage stats
        foreach ($usage->getRuntimesUsage() as $runtime => $usageStat) {
            $oldStat = $statsContainers->get($runtime, 'usage') ?? null;

            if ($oldStat === null) {
                $stat = $usageStat;
            } else {
                $stat = ($oldStat + $usageStat) / 2;
            }

            $statsContainers->set($runtime, ['usage' => $stat]);
        }

        // Delete gone runtimes
        $runtimes = \array_keys($usage->getRuntimesUsage());
        foreach ($statsContainers as $hostname => $stat) {
            if (!(\in_array($hostname, $runtimes))) {
                $statsContainers->delete($hostname);
            }
        }

        if ($recursive) {
            Timer::after(1000, fn () => getStats($statsHost, $statsContainers, $orchestration, $recursive));
        }
    }

    // Load initial stats in blocking way
    getStats($statsHost, $statsContainers, $orchestration);

    // Setup infinite recurssion in non-blocking way
    \go(function () use ($statsHost, $statsContainers, $orchestration) {
        getStats($statsHost, $statsContainers, $orchestration, true);
    });

    Console::success('Stats interval started.');

    $server = new Server('0.0.0.0', '80');
    $http = new Http($server, 'UTC');

    Console::success('Executor is ready.');

    Process::signal(SIGINT, fn () => cleanUp($orchestration, $activeRuntimes, $createdNetworks));
    Process::signal(SIGQUIT, fn () => cleanUp($orchestration, $activeRuntimes, $createdNetworks));
    Process::signal(SIGKILL, fn () => cleanUp($orchestration, $activeRuntimes, $createdNetworks));
    Process::signal(SIGTERM, fn () => cleanUp($orchestration, $activeRuntimes, $createdNetworks));

    $http->start();
});
