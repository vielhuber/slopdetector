<?php
declare(strict_types=1);

use vielhuber\slopdetector\Client;
use vielhuber\slopdetector\Pangram;
use vielhuber\slopdetector\Response;
use vielhuber\slopdetector\slopdetector;

final class Test extends \PHPUnit\Framework\TestCase
{
    public function test__lists_available_models(): void
    {
        $client = new FakeClient(new Response(200, '{"models":["default","pangram-4"]}'));
        $detector = new Pangram(apiKey: 'test-key', client: $client);

        $this->assertSame(['default', 'pangram-4'], $detector->listModels());
        $this->assertSame('GET', $client->requests[0]['method']);
        $this->assertSame('https://text.external-api.pangram.com/models', $client->requests[0]['url']);
        $this->assertSame('test-key', $client->requests[0]['headers']['x-api-key']);
    }

    public function test__accepts_model_catalog_without_default_alias(): void
    {
        $client = new FakeClient(new Response(200, '{"models":["pangram-4"]}'));
        $detector = new Pangram(apiKey: 'test-key', client: $client);

        $this->assertSame(['pangram-4'], $detector->listModels());
    }

    public function test__analyzes_text_through_async_task_api(): void
    {
        $client = new FakeClient(
            new Response(200, '{"task_id":"task-1"}'),
            new Response(
                200,
                '{"stage":"STAGE_SUCCESS","fraction_ai":0.2,"fraction_ai_assisted":0.3,"fraction_human":0.5}'
            )
        );
        $detector = new Pangram(apiKey: 'test-key', client: $client);

        $result = $detector->analyzeText(text: 'Example text', model: 'pangram-4');

        $this->assertSame(0.2, $result['fraction_ai']);
        $this->assertSame('POST', $client->requests[0]['method']);
        $this->assertSame('https://text.external-api.pangram.com/task', $client->requests[0]['url']);
        $this->assertSame(
            [
                'text' => 'Example text',
                'model' => 'pangram-4',
                'public_dashboard_link' => false
            ],
            $client->requests[0]['json']
        );
        $this->assertSame('https://text.external-api.pangram.com/task/task-1', $client->requests[1]['url']);
    }

    public function test__checks_plagiarism_without_public_link_side_effects(): void
    {
        $client = new FakeClient(new Response(200, '{"plagiarism_detected":false,"percent_plagiarized":0}'));
        $detector = new Pangram(apiKey: 'test-key', client: $client);

        $result = $detector->checkPlagiarism('Original text');

        $this->assertFalse($result['plagiarism_detected']);
        $this->assertSame('https://plagiarism.api.pangram.com', $client->requests[0]['url']);
        $this->assertSame(['text' => 'Original text'], $client->requests[0]['json']);
    }

    public function test__analyzes_supported_file_as_multipart(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'slopdetector_');
        $this->assertNotFalse($path);
        $pdfPath = $path . '.pdf';
        rename($path, $pdfPath);
        file_put_contents($pdfPath, '%PDF fixture');

        try {
            $client = new FakeClient(new Response(200, '[{"filename":"example.pdf","fraction_human":1}]'));
            $detector = new Pangram(apiKey: 'test-key', client: $client);

            $result = $detector->analyzeFile($pdfPath);

            $this->assertSame('example.pdf', $result['filename']);
            $this->assertSame('https://file-external.api.pangram.com', $client->requests[0]['url']);
            $this->assertInstanceOf(CURLFile::class, $client->requests[0]['multipart']['files']);
            $this->assertSame('false', $client->requests[0]['multipart']['public_dashboard_link']);
        } finally {
            unlink($pdfPath);
        }
    }

    public function test__submits_bulk_items_with_one_explicit_model(): void
    {
        $client = new FakeClient(new Response(202, '{"bulk_id":"bulk-1","status":"queued"}'));
        $detector = new Pangram(apiKey: 'test-key', client: $client);

        $result = $detector->submitBulk(
            items: [['id' => 'one', 'text' => 'First text'], ['id' => 'two', 'text' => 'Second text']],
            model: 'pangram-4'
        );

        $this->assertSame('bulk-1', $result['bulk_id']);
        $this->assertSame('pangram-4', $client->requests[0]['json']['model']);
        $this->assertSame('First text', $client->requests[0]['json']['items'][0]['text']);
    }

    public function test__reads_paginated_bulk_results(): void
    {
        $client = new FakeClient(new Response(200, '{"bulk_id":"bulk-1","offset":100,"limit":25,"items":[]}'));
        $detector = new Pangram(apiKey: 'test-key', client: $client);

        $detector->getBulkResults(bulkId: 'bulk-1', offset: 100, limit: 25);

        $this->assertSame(
            'https://text.external-api.pangram.com/bulk/bulk-1/results?offset=100&limit=25',
            $client->requests[0]['url']
        );
    }

    public function test__rejects_invalid_bulk_item_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bulk item id must be a non-empty string.');

        new Pangram(apiKey: 'test-key', client: new FakeClient())->submitBulk(
            items: [['id' => null, 'text' => 'Text']],
            model: 'default'
        );
    }

    public function test__facade_delegates_to_provider(): void
    {
        $provider = new class implements \vielhuber\slopdetector\Provider {
            public function listModels(): array
            {
                return ['default'];
            }

            public function analyzeText(
                string $text,
                string $model,
                bool $publicDashboardLink = false,
                int $timeout = 300,
                int $pollIntervalMilliseconds = 500
            ): array {
                return ['text' => $text, 'model' => $model];
            }

            public function checkPlagiarism(string $text): array
            {
                return ['text' => $text];
            }

            public function analyzeFile(string $filePath, bool $publicDashboardLink = false, int $timeout = 300): array
            {
                return ['file' => $filePath];
            }

            public function submitBulk(array $items, string $model): array
            {
                return ['items' => $items, 'model' => $model];
            }

            public function getBulkStatus(string $bulkId): array
            {
                return ['bulk_id' => $bulkId];
            }

            public function getBulkResults(string $bulkId, int $offset = 0, int $limit = 100): array
            {
                return ['bulk_id' => $bulkId, 'offset' => $offset, 'limit' => $limit];
            }
        };
        $detector = new slopdetector(providerInstance: $provider);

        $this->assertSame(['default'], $detector->listModels());
        $this->assertSame(
            ['text' => 'Example', 'model' => 'default'],
            $detector->analyzeText(text: 'Example', model: 'default')
        );
    }

    public function test__facade_reads_process_environment(): void
    {
        $previousProvider = $_ENV['PROVIDER'] ?? null;
        $previousApiKey = $_ENV['PANGRAM_API_KEY'] ?? null;
        $_ENV['PROVIDER'] = 'pangram';
        $_ENV['PANGRAM_API_KEY'] = 'environment-key';

        try {
            $this->assertInstanceOf(slopdetector::class, new slopdetector());
        } finally {
            if ($previousProvider === null) {
                unset($_ENV['PROVIDER']);
            } else {
                $_ENV['PROVIDER'] = $previousProvider;
            }
            if ($previousApiKey === null) {
                unset($_ENV['PANGRAM_API_KEY']);
            } else {
                $_ENV['PANGRAM_API_KEY'] = $previousApiKey;
            }
        }
    }
}

final class FakeClient implements Client
{
    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    /** @var Response[] */
    private array $responses;

    public function __construct(Response ...$responses)
    {
        $this->responses = $responses;
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $json = null,
        ?array $multipart = null,
        int $timeout = 10
    ): Response {
        $this->requests[] = compact('method', 'url', 'headers', 'json', 'multipart', 'timeout');
        $response = array_shift($this->responses);
        if (!($response instanceof Response)) {
            throw new RuntimeException('No fake response configured.');
        }
        return $response;
    }
}
