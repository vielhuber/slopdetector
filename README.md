[![build status](https://github.com/vielhuber/slopdetector/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/slopdetector/actions)
[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/slopdetector)](https://github.com/vielhuber/slopdetector/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/slopdetector)](https://github.com/vielhuber/slopdetector/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/slopdetector)](https://github.com/vielhuber/slopdetector/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/slopdetector)](https://packagist.org/packages/vielhuber/slopdetector)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/slopdetector)](https://packagist.org/packages/vielhuber/slopdetector)

# 🍲slopdetector🍲

slopdetector is a provider-based PHP helper and MCP server for AI-text and plagiarism detection.

the first provider is [Pangram](https://www.pangram.com). additional providers can implement the small `Provider` interface without changing the public PHP or MCP API.

slopdetector requires PHP 8.5 or newer.

## installation

```bash
composer require vielhuber/slopdetector
```

## configuration

copy `.env.example` to `.env` or provide the same variables through the process environment:

```dotenv
PROVIDER=pangram
PANGRAM_API_KEY=your_api_key
MCP_TOKEN=
```

the API key is sent only in Pangram's `x-api-key` request header. text and files are transferred to Pangram for analysis. public dashboard links are disabled unless explicitly requested.

## PHP

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use vielhuber\slopdetector\slopdetector;

$detector = slopdetector::create();
$models = $detector->listModels();

$result = $detector->analyzeText(
    text: 'Text to analyze',
    model: $models[0]
);

print_r($result);
```

example response:

```json
{
    "stage": "STAGE_SUCCESS",
    "text": "Text to analyze",
    "version": "3.3.2",
    "headline": "Human Written",
    "prediction": "We believe this is human-written",
    "prediction_short": "Human",
    "fraction_ai": 0,
    "fraction_ai_assisted": 0,
    "fraction_human": 1,
    "num_ai_segments": 0,
    "num_ai_assisted_segments": 0,
    "num_human_segments": 1,
    "windows": [
        {
            "text": "Text to analyze",
            "label": "Human Written",
            "ai_assistance_score": 0.01,
            "confidence": "High",
            "start_index": 0,
            "end_index": 15,
            "word_count": 3,
            "token_length": 3
        }
    ]
}
```

models are discovered dynamically. `analyzeText()` and `submitBulk()` deliberately require an explicit model so the integration remains valid when Pangram makes model selection mandatory after September 30, 2026. Pangram's file endpoint currently uses its server-side default model and therefore has no model argument.

supported file formats are PDF, DOCX, and RTF:

```php
$result = $detector->analyzeFile(filePath: '/path/to/document.pdf');
```

bulk jobs remain asynchronous:

```php
$job = $detector->submitBulk(
    items: [
        ['id' => 'article-1', 'text' => 'First text'],
        ['id' => 'article-2', 'text' => 'Second text']
    ],
    model: $models[0]
);

$status = $detector->getBulkStatus(bulkId: $job['bulk_id']);
$results = $detector->getBulkResults(bulkId: $job['bulk_id'], offset: 0, limit: 100);
```

## providers

providers implement `vielhuber\slopdetector\Provider`. the selected provider is configured through `PROVIDER`; currently supported: `pangram`.

## MCP server

```bash
vendor/bin/mcp-server.php
```

available tools:

- `list_models`
- `analyze_text`
- `check_plagiarism`
- `analyze_file`
- `submit_bulk`
- `get_bulk_status`
- `get_bulk_results`

`analyze_text` hides Pangram's internal submit/poll cycle and returns the completed result. bulk processing exposes separate submit, status, and paginated result tools because jobs can be long-running.

## tests

```bash
composer install
vendor/bin/phpunit
```

the test suite uses a fake transport and does not send texts, files, or API credentials to Pangram.
