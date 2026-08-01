<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите через PHP CLI.');
}

function out(string $text = ''): void
{
    fwrite(STDOUT, $text . PHP_EOL);
}

$root = realpath(dirname(__DIR__));

if (
    !is_string($root)
    || !is_file($root . '/index.php')
    || !is_file($root . '/app/bootstrap.php')
) {
    fwrite(STDERR, "Поместите файл в каталог bin проекта.\n");
    exit(1);
}

require $root . '/app/bootstrap.php';

out('YANDEX DIRECT DIAGNOSTIC V2');
out('Project root: ' . $root);
out();

out('[1] Direct-related files and references');
$scanRoots = [
    $root . '/app',
    $root . '/api.php',
    $root . '/index.php',
    $root . '/bin',
];
$directFiles = [];

$inspectFile = static function (string $path) use ($root, &$directFiles): void {
    if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
        return;
    }

    $content = file_get_contents($path);
    $relative = str_starts_with($path, $root . '/')
        ? substr($path, strlen($root) + 1)
        : $path;

    if (
        stripos($relative, 'direct') !== false
        || (is_string($content) && stripos($content, 'YandexDirect') !== false)
        || (is_string($content) && stripos($content, 'Яндекс Директ') !== false)
        || (is_string($content) && stripos($content, 'api.direct.yandex') !== false)
        || (is_string($content) && stripos($content, 'api.direct.yandex.com') !== false)
        || (is_string($content) && stripos($content, 'Client-Login') !== false)
    ) {
        $directFiles[] = $relative;
    }
};

foreach ($scanRoots as $scanRoot) {
    if (is_file($scanRoot)) {
        $inspectFile($scanRoot);
        continue;
    }

    if (!is_dir($scanRoot)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $scanRoot,
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $inspectFile($file->getPathname());
        }
    }
}

$directFiles = array_values(array_unique($directFiles));
sort($directFiles);

foreach ($directFiles as $file) {
    out('- ' . $file);
}

if ($directFiles === []) {
    out('- none');
}

out();
out('[2] Loaded project Direct classes and public methods');
$classes = array_values(array_filter(
    get_declared_classes(),
    static fn(string $class): bool =>
        str_starts_with($class, 'SeoAnalytics\\')
        && stripos($class, 'Direct') !== false
));

foreach ($directFiles as $relative) {
    $path = $root . '/' . $relative;

    if (!is_file($path)) {
        continue;
    }

    $before = get_declared_classes();

    try {
        require_once $path;
    } catch (Throwable $exception) {
        out('- include error in ' . $relative . ': ' . $exception->getMessage());
        continue;
    }

    foreach (array_diff(get_declared_classes(), $before) as $class) {
        if (
            str_starts_with($class, 'SeoAnalytics\\')
            && stripos($class, 'Direct') !== false
        ) {
            $classes[] = $class;
        }
    }
}

$classes = array_values(array_unique($classes));
sort($classes);

foreach ($classes as $class) {
    out('- ' . $class);

    try {
        $reflection = new ReflectionClass($class);
        $methods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn(ReflectionMethod $method): bool =>
                $method->getDeclaringClass()->getName() === $class
        );

        foreach ($methods as $method) {
            $parameters = array_map(
                static fn(ReflectionParameter $parameter): string =>
                    '$' . $parameter->getName(),
                $method->getParameters()
            );
            out('  * ' . $method->getName() . '(' . implode(', ', $parameters) . ')');
        }
    } catch (Throwable $exception) {
        out('  * reflection error: ' . $exception->getMessage());
    }
}

if ($classes === []) {
    out('- none');
}

out();
out('[3] Config file location and matching keys only');
$configCandidates = [
    $root . '/../seo-test-config.php',
    dirname($root) . '/seo-test-config.php',
    dirname($root, 2) . '/seo-test-config.php',
    '/var/www/u0935795/data/seo-test-config.php',
];
$configPath = null;

foreach ($configCandidates as $candidate) {
    $resolved = realpath($candidate);

    if (is_string($resolved) && is_file($resolved)) {
        $configPath = $resolved;
        break;
    }
}

if ($configPath === null) {
    out('- config file not found');
} else {
    out('- config found: ' . $configPath);
    $config = require $configPath;

    if (!is_array($config)) {
        out('- config did not return an array');
    } else {
        $keys = [];
        $walk = static function (array $data, string $prefix = '') use (&$walk, &$keys): void {
            foreach ($data as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

                if (
                    stripos($path, 'direct') !== false
                    || stripos($path, 'client_login') !== false
                    || stripos($path, 'client-login') !== false
                ) {
                    $keys[] = $path;
                }

                if (is_array($value)) {
                    $walk($value, $path);
                }
            }
        };
        $walk($config);
        $keys = array_values(array_unique($keys));
        sort($keys);

        foreach ($keys as $key) {
            out('- ' . $key);
        }

        if ($keys === []) {
            out('- no Direct-related keys');
        }
    }
}

out();
out('[4] Project table columns containing direct/client/login');
try {
    $pdo = \SeoAnalytics\Core\Database::pdo();
    $stmt = $pdo->query('SHOW COLUMNS FROM projects');
    $columns = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $name = (string) ($column['Field'] ?? '');

        if (
            stripos($name, 'direct') !== false
            || stripos($name, 'client') !== false
            || stripos($name, 'login') !== false
        ) {
            $columns[] = $name;
        }
    }

    foreach ($columns as $column) {
        out('- ' . $column);
    }

    if ($columns === []) {
        out('- none');
    }
} catch (Throwable $exception) {
    out('- database inspection error: ' . $exception->getMessage());
}

out();
out('[5] API routes mentioning direct');
$api = file_get_contents($root . '/api.php');

if (is_string($api)) {
    preg_match_all(
        '/action\s*===\s*[\'\"]([^\'\"]*direct[^\'\"]*)[\'\"]/i',
        $api,
        $matches
    );
    $routes = array_values(array_unique($matches[1] ?? []));

    foreach ($routes as $route) {
        out('- ' . $route);
    }

    if ($routes === []) {
        out('- none');
    }
}

out();
out('[6] Direct API endpoint references');
$endpointReferences = [];

foreach ($directFiles as $relative) {
    $path = $root . '/' . $relative;
    $content = is_file($path) ? file_get_contents($path) : false;

    if (!is_string($content)) {
        continue;
    }

    if (stripos($content, 'api.direct.yandex') !== false) {
        $endpointReferences[] = $relative;
    }
}

foreach (array_values(array_unique($endpointReferences)) as $relative) {
    out('- ' . $relative);
}

if ($endpointReferences === []) {
    out('- none');
}

out();
out('SAFE: no token, password or config value was printed.');
