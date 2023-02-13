<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Utopia\CLI\Console;

// TODO: @Meldiron Write more tests (validators mainly)
// TODO: @Meldiron Health API tests
// TODO: Lengthy log test
// TODO: Lengthy body test

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
            'image' => 'openruntimes/php:v3-8.1',
            'workdir' => '/usr/code',
            'commands' => [
                'sh', '-c',
                'tar -zxf /tmp/code.tar.gz -C /usr/code && \
                cd /usr/local/src/ && ./build.sh'
            ]
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsString($response['body']['path']);
        $this->assertIsString($response['body']['errors']);
        $this->assertIsString($response['body']['logs']);
        $this->assertIsFloat($response['body']['duration']);
        $this->assertIsFloat($response['body']['startTime']);
        $this->assertIsInt($response['body']['size']);

        $buildPath = $response['body']['path'];

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

        $response = $this->client->call(Client::METHOD_GET, '/runtimes/test-build-selfdelete', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);

        return [ 'path' => $buildPath ];
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
            'image' => 'openruntimes/php:v3-8.1',
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec/execution');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);

        /** Execute on cold-started runtime */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec/execution', [], [
            'body' => 'test payload',
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
            'image' => 'openruntimes/php:v3-8.1',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec-coldstart', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }

    /**
     *
     * @return array<mixed>
     */
    public function provideScenarios(): array
    {
        return [
            [
                'image' => 'openruntimes/node:v2-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-v2',
                'version' => 'v2',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $this->assertEquals('{"message":"Hello Open Runtimes 👋"}', $response['body']['body']);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                }
            ],
            [
                'image' => 'openruntimes/node:v3-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-empty-object',
                'version' => 'v3',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $this->assertEquals('{}', $response['body']['body']);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                }
            ],
            [
                'image' => 'openruntimes/node:v3-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-empty-array',
                'version' => 'v3',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $this->assertEquals('[]', $response['body']['body']);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                }
            ],
            [
                'image' => 'openruntimes/php:v3-8.1',
                'entrypoint' => 'index.php',
                'folder' => 'php-timeout',
                'version' => 'v3',
                'assertions' => function ($response) {
                    $this->assertEquals(500, $response['headers']['status-code']);
                    $this->assertEquals(500, $response['body']['code']);
                    $this->assertStringContainsString('Operation timed out', $response['body']['message']);
                }
            ]
        ];
    }

    /**
     * @param string $image
     * @param string $entrypoint
     * @param string $folder
     * @param string $version
     * @param callable $assertions
     *
     * @dataProvider provideScenarios
     */
    public function testScenarios(string $image, string $entrypoint, string $folder, string $version, callable $assertions): void
    {
        /** Prepare deployment */
        $stdout = '';
        $stderr = '';
        Console::execute("cd /app/tests/resources/functions/{$folder} && tar --warning=no-file-changed --exclude code.tar.gz -czf code.tar.gz .", '', $stdout, $stderr);

        $this->assertEquals('', $stderr);

        /** Build runtime */
        $params = [
            'runtimeId' => "scenario-build-{$folder}",
            'source' => "/storage/functions/{$folder}/code.tar.gz",
            'destination' => '/storage/builds/test',
            'entrypoint' => $entrypoint,
            'image' => $image,
            'workdir' => '/usr/code',
            'commands' => [
                'sh', '-c',
                'tar -zxf /tmp/code.tar.gz -C /usr/code && \
                cd /usr/local/src/ && ./build.sh'
            ]
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $path = $response['body']['path'];

        /** Execute function */
        $response = $this->client->call(Client::METHOD_POST, "/runtimes/scenario-execute-{$folder}/execution", [], [
            'source' => $path,
            'entrypoint' => $entrypoint,
            'image' => $image,
            'version' => $version
        ]);

        call_user_func($assertions, $response);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, "/runtimes/scenario-execute-{$folder}", [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }

    /**
     *
     * @return array<mixed>
     */
    public function provideCustomRuntimes(): array
    {
        return [
            [ 'folder' => 'php', 'image' => 'openruntimes/php:v3-8.1', 'entrypoint' => 'index.php', 'version' => 'v3' ],
            [ 'folder' => 'node', 'image' => 'openruntimes/node:v3-18.0', 'entrypoint' => 'index.js', 'version' => 'v3' ],
            [ 'folder' => 'deno', 'image' => 'openruntimes/deno:v3-1.24', 'entrypoint' => 'index.ts', 'version' => 'v3' ],
            [ 'folder' => 'python', 'image' => 'openruntimes/python:v3-3.10', 'entrypoint' => 'index.py', 'version' => 'v3' ],
            [ 'folder' => 'ruby', 'image' => 'openruntimes/ruby:v3-3.1', 'entrypoint' => 'index.rb', 'version' => 'v3' ],
            [ 'folder' => 'dart', 'image' => 'openruntimes/dart:v3-2.18', 'entrypoint' => 'lib/index.dart', 'version' => 'v3' ],
            [ 'folder' => 'dotnet', 'image' => 'openruntimes/dotnet:v3-6.0', 'entrypoint' => 'Index.cs', 'version' => 'v3' ],
            // C++, Swift, Kotlin, Java missing on purpose
        ];
    }

    /**
     * @param string $folder
     * @param string $image
     * @param string $entrypoint
     * @param string $version
     *
     * @dataProvider provideCustomRuntimes
     */
    public function testCustomRuntimes(string $folder, string $image, string $entrypoint, string $version): void
    {
        // Prepare tar.gz files
        $stdout = '';
        $stderr = '';
        Console::execute("cd /app/tests/resources/functions/{$folder} && tar --warning=no-file-changed --exclude code.tar.gz -czf code.tar.gz .", '', $stdout, $stderr);

        $this->assertEquals('', $stderr);

        // Build deployment
        $params = [
            'version' => $version,
            'runtimeId' => "custom-build-{$folder}",
            'source' => "/storage/functions/{$folder}/code.tar.gz",
            'destination' => '/storage/builds/test',
            'entrypoint' => $entrypoint,
            'image' => $image,
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
        $this->assertIsString($response['body']['path']);

        $path = $response['body']['path'];

        // Execute function
        $response = $this->client->call(Client::METHOD_POST, "/runtimes/custom-execute-{$folder}/execution", [], [
            'version' => $version,
            'source' => $path,
            'entrypoint' => $entrypoint,
            'image' => $image,
            'timeout' => 60,
            'variables' => [
                'TEST_VARIABLE' => 'Variable secret'
            ],
            'path' => '/my-awesome/path?param=paramValue',
            'headers' => [
                'host' => 'cloud.appwrite.io',
                'x-forwarded-proto' => 'https',
                'content-type' => 'application/json'
            ],
            'body' => \json_encode([
                'id' => '2'
            ])
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $body = $response['body'];
        $this->assertEquals(200, $body['statusCode']);
        $this->assertEmpty($body['errors']);
        $this->assertStringContainsString('Sample Log', $body['logs']);
        $this->assertIsString($body['body']);
        $this->assertNotEmpty($body['body']);
        $response = \json_decode($body['body'], true);
        $this->assertEquals(true, $response['isTest']);
        $this->assertEquals('Hello Open Runtimes 👋', $response['message']);
        $this->assertEquals('Variable secret', $response['variable']);
        $this->assertEquals('https://cloud.appwrite.io/my-awesome/path?param=paramValue', $response['url']);
        $this->assertEquals(1, $response['todo']['userId']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, "/runtimes/custom-execute-{$folder}", [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }
}
