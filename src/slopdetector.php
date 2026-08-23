<?php
declare(strict_types=1);

namespace vielhuber\slopdetector;

use vielhuber\simplemcp\Attributes\McpTool;
use vielhuber\simplemcp\Attributes\Schema;

final class ApiException extends \RuntimeException {}

final class TransportException extends \RuntimeException {}

final readonly class Response
{
    public function __construct(public int $statusCode, public string $body) {}
}

interface Client
{
    /**
     * Send one HTTP request while keeping provider code transport-independent.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     * @param array<string, mixed>|null $multipart
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $json = null,
        ?array $multipart = null,
        int $timeout = 10
    ): Response;
}

final class CurlClient implements Client
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $json = null,
        ?array $multipart = null,
        int $timeout = 10
    ): Response {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException('Could not initialize the HTTP client.');
        }

        $requestHeaders = [];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeout)),
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_HTTPHEADER => $requestHeaders
        ];
        if ($json !== null) {
            try {
                $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $exception) {
                throw new TransportException('Could not encode the request payload.', previous: $exception);
            }
        }
        if ($multipart !== null) {
            $options[CURLOPT_POSTFIELDS] = $multipart;
        }

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        if ($body === false) {
            throw new TransportException('HTTP request failed: ' . curl_error($handle));
        }

        return new Response(statusCode: (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), body: $body);
    }
}

interface Provider
{
    /** @return string[] */
    public function listModels(): array;

    public function analyzeText(
        string $text,
        string $model = '',
        bool $publicDashboardLink = false,
        int $timeout = 300,
        int $pollIntervalMilliseconds = 500
    ): array;

    public function checkPlagiarism(string $text): array;

    public function analyzeFile(string $filePath, bool $publicDashboardLink = false, int $timeout = 300): array;

    public function submitBulk(array $items, string $model = ''): array;

    public function getBulkStatus(string $bulkId): array;

    public function getBulkResults(string $bulkId, int $offset = 0, int $limit = 100): array;
}

final class Pangram implements Provider
{
    private const TEXT_API_URL = 'https://text.external-api.pangram.com';
    private const FILE_API_URL = 'https://file-external.api.pangram.com';
    private const PLAGIARISM_API_URL = 'https://plagiarism.api.pangram.com';
    private const SUCCESS_STAGE = 'STAGE_SUCCESS';
    // the other catalog entry classifies only english; on any other language
    // it reports every text as human, which is no signal at all
    private const DEFAULT_MODEL = 'pangram-4';
    private const FAILED_STAGE = 'STAGE_FAILED';

    private Client $client;
    private readonly string $apiKey;

    public function __construct(string $apiKey, ?Client $client = null)
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Pangram API key is required.');
        }
        $this->apiKey = $apiKey;
        $this->client = $client ?? new CurlClient();
    }

    public function listModels(): array
    {
        $response = $this->requestJson(method: 'GET', url: self::TEXT_API_URL . '/models');
        $models = $response['models'] ?? null;
        if (!is_array($models) || $models === []) {
            throw new ApiException('Pangram returned an invalid model catalog.');
        }
        foreach ($models as $model) {
            if (!is_string($model) || trim($model) === '' || trim($model) !== $model) {
                throw new ApiException('Pangram returned an invalid model catalog.');
            }
        }
        if (count($models) !== count(array_unique($models))) {
            throw new ApiException('Pangram returned an invalid model catalog.');
        }
        return array_values($models);
    }

    public function analyzeText(
        string $text,
        string $model = '',
        bool $publicDashboardLink = false,
        int $timeout = 300,
        int $pollIntervalMilliseconds = 500
    ): array {
        if (trim($text) === '') {
            throw new \InvalidArgumentException('Text must not be empty.');
        }
        $model = trim($model) !== '' ? trim($model) : self::DEFAULT_MODEL;
        if ($timeout < 1) {
            throw new \InvalidArgumentException('Timeout must be at least one second.');
        }
        if ($pollIntervalMilliseconds < 100) {
            throw new \InvalidArgumentException('Poll interval must be at least 100 milliseconds.');
        }

        $deadline = microtime(true) + $timeout;
        $submission = $this->requestJson(
            method: 'POST',
            url: self::TEXT_API_URL . '/task',
            json: [
                'text' => $text,
                'model' => $model,
                'public_dashboard_link' => $publicDashboardLink
            ],
            timeout: min(10, $timeout)
        );
        $taskId = $submission['task_id'] ?? null;
        if (!is_string($taskId) || $taskId === '') {
            throw new ApiException('Pangram task response does not contain a task_id.');
        }

        while (microtime(true) < $deadline) {
            try {
                $result = $this->requestJson(
                    method: 'GET',
                    url: self::TEXT_API_URL . '/task/' . rawurlencode($taskId),
                    timeout: max(1, min(10, (int) ceil($deadline - microtime(true))))
                );
            } catch (TransportException $exception) {
                if (microtime(true) >= $deadline) {
                    throw new ApiException(
                        'Pangram task timed out after ' . $timeout . ' seconds.',
                        previous: $exception
                    );
                }
                usleep($pollIntervalMilliseconds * 1000);
                continue;
            }

            $stage = $result['stage'] ?? null;
            if ($stage === self::SUCCESS_STAGE) {
                return $result;
            }
            if ($stage === self::FAILED_STAGE) {
                $message = $result['headline'] ?? ($result['detail'] ?? 'Task failed.');
                throw new ApiException('Pangram task failed: ' . (string) $message);
            }
            if (!is_string($stage) || $stage === '') {
                throw new ApiException('Pangram task response does not contain a stage.');
            }
            usleep($pollIntervalMilliseconds * 1000);
        }

        throw new ApiException('Pangram task timed out after ' . $timeout . ' seconds.');
    }

    public function checkPlagiarism(string $text): array
    {
        if (trim($text) === '') {
            throw new \InvalidArgumentException('Text must not be empty.');
        }
        return $this->requestJson(method: 'POST', url: self::PLAGIARISM_API_URL, json: ['text' => $text], timeout: 90);
    }

    public function analyzeFile(string $filePath, bool $publicDashboardLink = false, int $timeout = 300): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException('File does not exist or is not readable: ' . $filePath);
        }
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'rtf' => 'application/rtf',
            default => throw new \InvalidArgumentException('Only PDF, DOCX, and RTF files are supported.')
        };
        if ($timeout < 1) {
            throw new \InvalidArgumentException('Timeout must be at least one second.');
        }

        $response = $this->requestJson(
            method: 'POST',
            url: self::FILE_API_URL,
            multipart: [
                'files' => new \CURLFile($filePath, $mimeType, basename($filePath)),
                'public_dashboard_link' => $publicDashboardLink ? 'true' : 'false'
            ],
            timeout: $timeout
        );
        if (!array_is_list($response) || !isset($response[0]) || !is_array($response[0])) {
            throw new ApiException('Pangram returned an invalid file analysis response.');
        }
        return $response[0];
    }

    public function submitBulk(array $items, string $model = ''): array
    {
        if ($items === []) {
            throw new \InvalidArgumentException('Bulk items must not be empty.');
        }
        $model = trim($model) !== '' ? trim($model) : self::DEFAULT_MODEL;

        $allTexts = true;
        $allObjects = true;
        foreach ($items as $item) {
            $allTexts = $allTexts && is_string($item) && trim($item) !== '';
            $allObjects =
                $allObjects &&
                is_array($item) &&
                isset($item['text']) &&
                is_string($item['text']) &&
                trim($item['text']) !== '';
            if (is_array($item) && array_diff(array_keys($item), ['id', 'text']) !== []) {
                throw new \InvalidArgumentException('Bulk item objects may only contain id and text.');
            }
            if (is_array($item) && array_key_exists('id', $item)) {
                if (!is_string($item['id']) || trim($item['id']) === '') {
                    throw new \InvalidArgumentException('Bulk item id must be a non-empty string.');
                }
            }
        }
        if (!$allTexts && !$allObjects) {
            throw new \InvalidArgumentException(
                'Bulk items must be either text strings or objects with text and optional id.'
            );
        }

        return $this->requestJson(
            method: 'POST',
            url: self::TEXT_API_URL . '/bulk',
            json: [$allTexts ? 'text' : 'items' => $items, 'model' => $model],
            expectedStatusCodes: [202],
            // pangram rejects a bulk submission without it; derived from the payload
            // so a repeated call for the same items does not create a second job
            idempotencyKey: hash('sha256', json_encode([$items, $model]))
        );
    }

    public function getBulkStatus(string $bulkId): array
    {
        $bulkId = trim($bulkId);
        if ($bulkId === '') {
            throw new \InvalidArgumentException('Bulk ID must not be empty.');
        }
        return $this->requestJson(method: 'GET', url: self::TEXT_API_URL . '/bulk/' . rawurlencode($bulkId));
    }

    public function getBulkResults(string $bulkId, int $offset = 0, int $limit = 100): array
    {
        $bulkId = trim($bulkId);
        if ($bulkId === '') {
            throw new \InvalidArgumentException('Bulk ID must not be empty.');
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset must not be negative.');
        }
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Limit must be between 1 and 1000.');
        }
        return $this->requestJson(
            method: 'GET',
            url: self::TEXT_API_URL .
                '/bulk/' .
                rawurlencode($bulkId) .
                '/results?' .
                http_build_query([
                    'offset' => $offset,
                    'limit' => $limit
                ])
        );
    }

    /**
     * Decode and validate one Pangram response without altering its provider-specific payload.
     *
     * @param array<string, mixed>|null $json
     * @param array<string, mixed>|null $multipart
     * @param int[] $expectedStatusCodes
     */
    private function requestJson(
        string $method,
        string $url,
        ?array $json = null,
        ?array $multipart = null,
        int $timeout = 10,
        array $expectedStatusCodes = [200],
        ?string $idempotencyKey = null
    ): array {
        $headers = ['x-api-key' => $this->apiKey];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->client->request(
            method: $method,
            url: $url,
            headers: $headers,
            json: $json,
            multipart: $multipart,
            timeout: $timeout
        );
        if (!in_array($response->statusCode, $expectedStatusCodes, true)) {
            throw new ApiException(
                'Pangram API returned HTTP ' . $response->statusCode . ': ' . substr($response->body, 0, 1000)
            );
        }
        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ApiException('Pangram API returned invalid JSON.', previous: $exception);
        }
        if (!is_array($data)) {
            throw new ApiException('Pangram API returned an invalid response.');
        }
        if (isset($data['error'])) {
            $error = is_scalar($data['error'])
                ? (string) $data['error']
                : json_encode($data['error'], JSON_UNESCAPED_UNICODE);
            throw new ApiException('Pangram API returned an error: ' . ($error === false ? 'Unknown error.' : $error));
        }
        return $data;
    }
}

final class slopdetector
{
    private Provider $provider;

    public function __construct(?string $provider = null, ?string $apiKey = null, ?Provider $providerInstance = null)
    {
        if ($providerInstance !== null) {
            $this->provider = $providerInstance;
            return;
        }

        $workingDirectory = getcwd();
        foreach (
            array_unique([is_string($workingDirectory) ? $workingDirectory : '', dirname(__DIR__)])
            as $directory
        ) {
            if ($directory !== '' && is_file($directory . '/.env')) {
                \Dotenv\Dotenv::createImmutable($directory)->safeLoad();
            }
        }

        $configuredProvider = $_ENV['PROVIDER'] ?? ($_SERVER['PROVIDER'] ?? getenv('PROVIDER'));
        $provider = strtolower(trim($provider ?? (is_string($configuredProvider) ? $configuredProvider : 'pangram')));
        if ($provider === '') {
            $provider = 'pangram';
        }
        $configuredApiKey = $_ENV['PANGRAM_API_KEY'] ?? ($_SERVER['PANGRAM_API_KEY'] ?? getenv('PANGRAM_API_KEY'));
        $apiKey = $apiKey ?? (is_string($configuredApiKey) ? $configuredApiKey : '');
        $this->provider = match ($provider) {
            'pangram' => new Pangram(apiKey: $apiKey),
            default => throw new \InvalidArgumentException('Unsupported slopdetector provider: ' . $provider)
        };
    }

    public static function create(?string $provider = null, ?string $apiKey = null): self
    {
        return new self(provider: $provider, apiKey: $apiKey);
    }

    #[
        McpTool(
            name: 'list_models',
            description: 'Lists the Pangram detection models available to the configured API key.'
        )
    ]
    public function listModels(): array
    {
        return $this->provider->listModels();
    }

    #[
        McpTool(
            name: 'analyze_text',
            description: 'Analyzes text for AI-generated, AI-assisted, and human-written segments.'
        )
    ]
    public function analyzeText(
        string $text,
        string $model = '',
        bool $publicDashboardLink = false,
        int $timeout = 300,
        int $pollIntervalMilliseconds = 500
    ): array {
        return $this->provider->analyzeText(
            text: $text,
            model: $model,
            publicDashboardLink: $publicDashboardLink,
            timeout: $timeout,
            pollIntervalMilliseconds: $pollIntervalMilliseconds
        );
    }

    #[
        McpTool(
            name: 'check_plagiarism',
            description: 'Checks text for plagiarism and returns matching sources and passages.'
        )
    ]
    public function checkPlagiarism(string $text): array
    {
        return $this->provider->checkPlagiarism(text: $text);
    }

    #[
        McpTool(
            name: 'analyze_file',
            description: 'Analyzes one local PDF, DOCX, or RTF file using Pangram text extraction.'
        )
    ]
    public function analyzeFile(string $filePath, bool $publicDashboardLink = false, int $timeout = 300): array
    {
        return $this->provider->analyzeFile(
            filePath: $filePath,
            publicDashboardLink: $publicDashboardLink,
            timeout: $timeout
        );
    }

    #[McpTool(name: 'submit_bulk', description: 'Submits multiple texts as one asynchronous Pangram bulk job.')]
    public function submitBulk(
        #[
            Schema(
                definition: [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'oneOf' => [
                            ['type' => 'string', 'minLength' => 1],
                            [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'text' => ['type' => 'string', 'minLength' => 1]
                                ],
                                'required' => ['text'],
                                'additionalProperties' => false
                            ]
                        ]
                    ]
                ]
            )
        ]
        array $items,
        string $model = ''
    ): array {
        return $this->provider->submitBulk(items: $items, model: $model);
    }

    #[
        McpTool(
            name: 'get_bulk_status',
            description: 'Returns status and counters for an asynchronous Pangram bulk job.'
        )
    ]
    public function getBulkStatus(string $bulkId): array
    {
        return $this->provider->getBulkStatus(bulkId: $bulkId);
    }

    #[McpTool(name: 'get_bulk_results', description: 'Returns one paginated result page for a Pangram bulk job.')]
    public function getBulkResults(string $bulkId, int $offset = 0, int $limit = 100): array
    {
        return $this->provider->getBulkResults(bulkId: $bulkId, offset: $offset, limit: $limit);
    }
}
