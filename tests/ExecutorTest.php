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

    public function testPackFunctions(): void
    {
        // Prepare tar.gz files
        $stdout = '';
        $stderr = '';
        Console::execute('cd /app/tests/resources/functions/php && tar --warning=no-file-changed --exclude code.tar.gz -czf code.tar.gz .', '', $stdout, $stderr);

        $this->assertEquals('', $stderr);
    }

    /**
     * @depends testPackFunctions
     */
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
     * @depends testPackFunctions
     *
     * @return array<string,mixed>
     */
    public function testBuild(): array
    {
        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build',
            'source' => '/storage/functions/php/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v2-8.0',
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
        $this->assertIsInt($response['body']['outputSize']);
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
        // TODO: @Meldiron test timeout execution. Timeout build too?

        $params = [
            'runtimeId' => 'test-exec',
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v2-8.0',
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('ready', $response['body']['status']);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec/execution');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);
        $this->assertEquals('completed', $response['body']['status']);
        $this->assertEquals('log1', $response['body']['stdout']);
        $this->assertIsString($response['body']['stderr']);
        $this->assertEmpty($response['body']['stderr']);
        $this->assertIsFloat($response['body']['duration']);
        $this->assertEquals('{"payload":"","variable":"","unicode":"Unicode magic: êä"}', $response['body']['response']);

        /** Execute on cold-started runtime */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec/execution', [], [
            'payload' => 'test payload',
            'variables' => [
                'customVariable' => 'mySecret'
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('{"payload":"test payload","variable":"mySecret","unicode":"Unicode magic: êä"}', $response['body']['response']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);

        /** Execute on new runtime */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/execution', [], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v2-8.0',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec-coldstart', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }
}
