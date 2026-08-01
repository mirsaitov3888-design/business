<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите через PHP CLI.');
}

function line(string $text = ''): void
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

line('YANDEX DIRECT DIAGNOSTIC');
line('Project root: ' . $root);
line();

line('[1] Direct-related files');
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $root . '/app',
        FilesystemIterator::SKIP_DOTS
    )
);
$directFiles = [];

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $relative = substr($file->getPathname(), strlen($root) + 1);
    $content = file_get_contents($file->getPathname());

    if (
        stripos($relative, 'direct') !== false
        || (is_string($content) && stripos($content, 'yandex direct') !== false)
        || (is_string($content) && stripos($content, 'api.direct.yandex') !== false)
    ) {
        $directFiles[] = $relative;
    }
}

sort($directFiles);

foreach ($directFiles as $file) {
    line('- ' . $file);
}

if ($directFiles === []) {
    line('- none');
}

line();
line('[2] Loaded Direct classes and public methods');
$classes = array_values(array_filter(
    get_declared_classes(),
    static fn(string $class): bool => stripos($class, 'direct') !== false
));

foreach ($directFiles as $relative) {
    $path = $root . '/' . $relative;
    $before = get_declared_classes();

    try {
        require_once $path;
    } catch (Throwable $exception) {
        line('- include error in ' . $relative . ': ' . $exception->getMessage());
        continue;
    }

    $classes = array_values(array_unique([
        ...$classes,
        ...array_filter(
            array_diff(get_declared_classes(), $before),
            static fn(string $class): bool => stripos($class, 'direct') !== false
        ),
    ]));
}

sort($classes);

foreach ($classes as $class) {
    line('- ' . $class);

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
            line('  * ' . $method->getName() . '(' . implode(', ', $parameters) . ')');
        }
    } catch (Throwable $exception) {
        line('  * reflection error: ' . $exception->getMessage());
    }
}

if ($classes === []) {
    line('- none');
}

line();
line('[3] Config keys only; values are never printed');
$configPath = realpath($root . '/../seo-test-config.php');

if (!is_string($configPath) || !is_file($configPath)) {
    $configPath = realpath(dirname($root) . '/seo-test-config.php');
}

if (is_string($configPath) && is_file($configPath)) {
    $config = require $configPath;

    if (is_array($config)) {
        $keys = [];
        $walk = static function (array $data, string $prefix = '') use (&$walk, &$keys): void {
            foreach ($data as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

                if (
                    stripos($path, 'direct') !== false
                    || stripos($path, 'yandex') !== false
                ) {
                    $keys[] = $path;
                }

                if (is_array($value)) {
                    $walk($value, $path);
                }
            }
        };
        $walk($config);
        sort($keys);

        foreach (array_values(array_unique($keys)) as $key) {
            line('- ' . $key);
        }

        if ($keys === []) {
            line('- none');
        }
    } else {
        line('- config did not return an array');
    }
} else {
    line('- config file not found');
}

line();
line('[4] Project table columns containing direct/client/login');
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
        line('- ' . $column);
    }

    if ($columns === []) {
        line('- none');
    }
} catch (Throwable $exception) {
    line('- database inspection error: ' . $exception->getMessage());
}

line();
line('[5] API routes mentioning direct');
$api = file_get_contents($root . '/api.php');

if (is_string($api)) {
    preg_match_all(
        '/action\s*===\s*[\'\"]([^\'\"]*direct[^\'\"]*)[\'\"]/i',
        $api,
        $matches
    );
    $routes = array_values(array_unique($matches[1] ?? []));

    foreach ($routes as $route) {
        line('- ' . $route);
    }

    if ($routes === []) {
        line('- none');
    }
}

line();
line('SAFE: no token, password or config value was printed.');
