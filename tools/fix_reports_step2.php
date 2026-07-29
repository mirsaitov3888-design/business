<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите через PHP CLI.');
}

$root = realpath(dirname(__DIR__));
if (!is_string($root) || !is_file($root . '/app/Repositories/ReportRepository.php')) {
    throw new RuntimeException('Поместите файл в каталог bin проекта.');
}

$path = $root . '/app/Repositories/ReportRepository.php';
$content = file_get_contents($path);
if (!is_string($content)) {
    throw new RuntimeException('Не удалось прочитать ReportRepository.php');
}

$old = <<<'OLD'
            $attributes = [];
            foreach ($child->attributes as $attribute) {
                $attributes[] = $attribute->name;
            }
            foreach ($attributes as $attribute) {
                $child->removeAttribute($attribute);
            }

            if ($tag === 'a') {
                $href = self::safeUrl((string) $child->getAttribute('href'));
                if ($href !== '') {
                    $child->setAttribute('href', $href);
                    $child->setAttribute('rel', 'noopener noreferrer');
                    $child->setAttribute('target', '_blank');
                }
            }
            if ($tag === 'img') {
                $source = (string) $child->getAttribute('src');
                if (!str_starts_with($source, '/uploads/report-media/')) {
                    $parent->removeChild($child);
                    continue;
                }
                $child->setAttribute('src', $source);
                $child->setAttribute('alt', 'Изображение отчёта');
                $child->setAttribute('loading', 'lazy');
            }
OLD;

$new = <<<'NEW'
            $originalHref = $tag === 'a'
                ? (string) $child->getAttribute('href')
                : '';
            $originalSource = $tag === 'img'
                ? (string) $child->getAttribute('src')
                : '';
            $originalAlt = $tag === 'img'
                ? (string) $child->getAttribute('alt')
                : '';

            $attributes = [];
            foreach ($child->attributes as $attribute) {
                $attributes[] = $attribute->name;
            }
            foreach ($attributes as $attribute) {
                $child->removeAttribute($attribute);
            }

            if ($tag === 'a') {
                $href = self::safeUrl($originalHref);
                if ($href !== '') {
                    $child->setAttribute('href', $href);
                    $child->setAttribute('rel', 'noopener noreferrer');
                    $child->setAttribute('target', '_blank');
                }
            }
            if ($tag === 'img') {
                if (!str_starts_with($originalSource, '/uploads/report-media/')) {
                    $parent->removeChild($child);
                    continue;
                }
                $child->setAttribute('src', $originalSource);
                $child->setAttribute(
                    'alt',
                    mb_substr(trim($originalAlt), 0, 300) ?: 'Изображение отчёта'
                );
                $child->setAttribute('loading', 'lazy');
            }
NEW;

if (str_contains($content, $old)) {
    $content = str_replace($old, $new, $content);
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось применить исправление.');
    }
}

if (function_exists('exec')) {
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException(implode("\n", $output));
    }
}

echo "Исправление редактора изображений применено.\n";
