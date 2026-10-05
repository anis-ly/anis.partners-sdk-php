<?php

declare(strict_types=1);

/** Generates the PHP error enum from the catalogue's public representations. */
function generateErrorCodeSource(string $cataloguePath): string
{
    $json = file_get_contents($cataloguePath);
    if ($json === false) {
        throw new RuntimeException('The error catalogue could not be read.');
    }
    $catalogue = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($catalogue) || !is_array($catalogue['representations'] ?? null)) {
        throw new UnexpectedValueException('The error catalogue has no representations list.');
    }

    $representations = array_values(array_filter(
        $catalogue['representations'],
        static fn(mixed $representation): bool => is_array($representation)
            && ($representation['publicDocumentation'] ?? false) === true
            && is_string($representation['publicCode'] ?? null)
            && preg_match('/\A[a-z][a-z0-9_]*\z/D', $representation['publicCode']) === 1,
    ));
    usort($representations, static fn(array $left, array $right): int => strcmp($left['publicCode'], $right['publicCode']));

    $codes = [];
    foreach ($representations as $representation) {
        $codes[$representation['publicCode']] ??= (bool) ($representation['retryable'] ?? false);
    }
    if ($codes === []) {
        throw new UnexpectedValueException('The error catalogue has no public codes.');
    }

    $cases = [];
    $retryable = [];
    foreach ($codes as $wireCode => $isRetryable) {
        $name = str_replace(' ', '', ucwords(str_replace('_', ' ', $wireCode)));
        if ($name === 'Unknown') {
            throw new UnexpectedValueException('The catalogue cannot replace the reserved Unknown error code.');
        }
        $cases[] = "    case {$name} = '{$wireCode}';";
        if ($isRetryable) {
            $retryable[] = "            self::{$name} => true,";
        }
    }

    return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Anis\\Partners\\Errors;\n\n"
        . "/** @generated from contracts/error-catalogue.json by tools/generate-errors.php; do not edit. */\n"
        . "enum ErrorCode: string\n{\n    case Unknown = 'unknown';\n"
        . implode("\n", $cases) . "\n\n"
        . "    /** Resolves wire values leniently so a future code does not break response handling. */\n"
        . "    public static function parse(?string \$value): self\n    {\n        if (\$value === null) {\n            return self::Unknown;\n        }\n\n        return self::tryFrom(\$value) ?? self::Unknown;\n    }\n\n"
        . "    /** Reports only catalogue-marked retryable values; unknown codes remain conservative. */\n"
        . "    public function isRetryable(): bool\n    {\n        return match (\$this) {\n"
        . implode("\n", $retryable) . "\n            default => false,\n        };\n    }\n}\n";
}

$scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptPath) && realpath($scriptPath) === __FILE__) {
    $root = dirname(__DIR__);
    $output = $root . '/src/Errors/ErrorCode.php';
    $source = generateErrorCodeSource($root . '/contracts/error-catalogue.json');
    if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0777, true) && !is_dir(dirname($output))) {
        throw new RuntimeException('The generated ErrorCode.php directory could not be created.');
    }
    if (file_put_contents($output, $source) === false) {
        throw new RuntimeException('The generated ErrorCode.php could not be written.');
    }
    preg_match_all('/^    case \\w+ = /m', $source, $matches);
    fwrite(STDOUT, 'generated ' . (count($matches[0]) - 1) . " public codes -> src/Errors/ErrorCode.php\n");
}
