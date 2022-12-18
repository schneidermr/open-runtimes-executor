<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Utopia\CLI\Console;

// TODO: @Meldiron Write more tests (validators mainly)
// TODO: @Meldiron Health API tests

final class ExecutorTest extends TestCase
{
    protected Client $client;

    protected string $key;

    /**
     * @var string
     */
    protected $endpoint = 'http://exc1/v1';

    protected function setUp(): void
    {
        $this->client = new Client();

        $this->key = 'executor-secret-key';

        $this->client
            ->setEndpoint($this->endpoint)
            ->addHeader('Content-Type', 'application/json')
            ->setKey($this->key);
    }

    public function testErrors(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/unknown', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Not Found', $response['body']['message']);

        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, count($response['body']));

        $response = $this->client->call(Client::METHOD_GET, '/runtimes/id', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Runtime not found', $response['body']['message']);

        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Runtime not found', $response['body']['message']);

        $this->client->setKey('');
        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('Missing executor key', $response['body']['message']);
        $this->client->setKey($this->key);
    }

    /**
     * @return array<string,mixed>
     */
    public function testBuild(): array
    {
        $stdout = '';
        $stderr = '';
        Console::execute('cd /app/tests/resources/functions/php && tar --warning=no-file-changed --exclude code.tar.gz -czf code.tar.gz .', '', $stdout, $stderr);

        $this->assertEquals('', $stderr);

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build',
            'source' => '/storage/functions/php/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v2-8.1',
            'workdir' => '/usr/code',
            'commands' => [
                'sh', '-c',
                'tar -zxf /tmp/code.tar.gz -C /usr/code && \
                cd /usr/local/src/ && ./build.sh'
            ]
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('ready', $response['body']['status']);
        $this->assertIsString($response['body']['outputPath']);
        $this->assertIsString($response['body']['stderr']);
        $this->assertIsString($response['body']['stdout']);
        $this->assertIsFloat($response['body']['duration']);
        $this->assertIsInt($response['body']['startTimeUnix']);
        $this->assertIsInt($response['body']['endTimeUnix']);

        $outputPath = $response['body']['outputPath'];

        /** List runtimes */
        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, count($response['body']));
        $this->assertEquals('test-build', $response['body'][0]['name']);

        /** Get runtime */
        $response = $this->client->call(Client::METHOD_GET, '/runtimes/test-build', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('test-build', $response['body']['name']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-build', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);

        /** Delete non existent runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-build', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Runtime not found', $response['body']['message']);

        /** Self-deleting build */
        $params['runtimeId'] = 'test-build-selfdelete';
        $params['remove'] = true;

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('ready', $response['body']['status']);

        $response = $this->client->call(Client::METHOD_GET, '/runtimes/test-build-selfdelete', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);

        return [ 'path' => $outputPath ];
    }

    /**
     * @depends testBuild
     *
     * @param array<string,mixed> $data
     */
    public function testExecute(array $data): void
    {
        $params = [
            'runtimeId' => 'test-exec',
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v2-8.1',
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('ready', $response['body']['status']);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec/execution');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);
        $this->assertEquals('completed', $response['body']['status']);

        /** Execute on cold-started runtime */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec/execution', [], [
            'payload' => 'test payload',
            'variables' => [
                'customVariable' => 'mySecret'
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);

        /** Execute on new runtime */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/execution', [], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v2-8.1',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec-coldstart', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }

    /**
     * @return array<string,mixed>
     */
    public function testTimeoutBuild(): array
    {
        $stdout = '';
        $stderr = '';
        Console::execute('cd /app/tests/resources/functions/php-timeout && tar --warning=no-file-changed --exclude code.tar.gz -czf code.tar.gz .', '', $stdout, $stderr);

        $this->assertEquals('', $stderr);

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build',
            'source' => '/storage/functions/php-timeout/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v2-8.1',
            'workdir' => '/usr/code',
            'commands' => [
                'sh', '-c',
                'tar -zxf /tmp/code.tar.gz -C /usr/code && \
                cd /usr/local/src/ && ./build.sh'
            ]
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('ready', $response['body']['status']);

        $outputPath = $response['body']['outputPath'];

        return [ 'path' => $outputPath ];
    }

    /**
     * @depends testTimeoutBuild
     *
     * @param array<string,mixed> $data
     */
    public function testTimeoutExecute(array $data): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-timeout/execution', [], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v2-8.1',
        ]);


        $this->assertEquals(500, $response['headers']['status-code']);
        $this->assertEquals(500, $response['body']['code']);
        $this->assertStringContainsString('Operation timed out', $response['body']['message']);
    }

    /**
     *
     * @return array<mixed>
     */
    public function provideCustomRuntimes(): array
    {
        return [
            [ ['folder' => 'php', 'image' => 'openruntimes/php:v2-8.1', 'entrypoint' => 'index.php'] ],
            [ ['folder' => 'node', 'image' => 'openruntimes/node:v2-18.0', 'entrypoint' => 'index.js'] ],
            [ ['folder' => 'deno', 'image' => 'openruntimes/deno:v2-1.24', 'entrypoint' => 'index.ts'] ],
            [ ['folder' => 'python', 'image' => 'openruntimes/python:v2-3.9', 'entrypoint' => 'index.py'] ],
            [ ['folder' => 'ruby', 'image' => 'openruntimes/ruby:v2-3.1', 'entrypoint' => 'index.rb'] ],
            [ ['folder' => 'cpp', 'image' => 'openruntimes/cpp:v2-17', 'entrypoint' => 'index.cc'] ],
            [ ['folder' => 'dart', 'image' => 'openruntimes/dart:v2-2.17', 'entrypoint' => 'lib/index.dart'] ],
            [ ['folder' => 'dotnet', 'image' => 'openruntimes/dotnet:v2-6.0', 'entrypoint' => 'Index.cs'] ],
            // Swift, Kotlin, Java missing on purpose
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @dataProvider provideCustomRuntimes
     */
    public function testCustomRuntimes(array $data): void
    {
        // Prepare tar.gz files
        $stdout = '';
        $stderr = '';
        Console::execute("cd /app/tests/resources/functions/{$data['folder']} && tar --warning=no-file-changed --exclude code.tar.gz -czf code.tar.gz .", '', $stdout, $stderr);

        $this->assertEquals('', $stderr);

        // Build deployment
        $params = [
            'runtimeId' => "custom-build-{$data['folder']}",
            'source' => "/storage/functions/{$data['folder']}/code.tar.gz",
            'destination' => '/storage/builds/test',
            'entrypoint' => $data['entrypoint'],
            'image' => $data['image'],
            'workdir' => '/usr/code',
            'timeout' => 60,
            'commands' => [
                'sh', '-c',
                'tar -zxf /tmp/code.tar.gz -C /usr/code && \
                cd /usr/local/src/ && ./build.sh'
            ]
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('ready', $response['body']['status']);
        $this->assertIsString($response['body']['outputPath']);

        $outputPath = $response['body']['outputPath'];

        // Execute function
        $response = $this->client->call(Client::METHOD_POST, "/runtimes/custom-execute-{$data['folder']}/execution", [
            'content-type' => 'application/json',
        ], [
            'source' => $outputPath,
            'entrypoint' => $data['entrypoint'],
            'image' => $data['image'],
            'timeout' => 60,
            'variables' => [
                'test-variable' => 'Variable secret'
            ],
            'payload' => \json_encode([
                'id' => '2'
            ])
        ]);

        if ($response['headers']['status-code'] !== 200 || $response['body']['status'] !== 'completed') {
            \var_dump($data);
            \var_dump($response);
            \var_dump('---');
        }

        $this->assertEquals(200, $response['headers']['status-code']);
        $body = $response['body'];
        $this->assertEquals('completed', $body['status']);
        $this->assertEquals(200, $body['statusCode']);
        $this->assertEmpty($body['stderr']);
        $this->assertStringContainsString('Sample Log', $body['stdout']);
        $this->assertIsString($body['response']);
        $this->assertNotEmpty($body['response']);
        $response = \json_decode($body['response'], true);
        $this->assertEquals(true, $response['isTest']);
        $this->assertEquals('Hello Open Runtimes 👋', $response['message']);
        $this->assertEquals('Variable secret', $response['variable']);
        $this->assertEquals(1, $response['todo']['userId']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, "/runtimes/custom-execute-{$data['folder']}", [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }
}
