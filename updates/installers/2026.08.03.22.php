<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

const NAVIGATION_STATE_VERSION = '2026.08.03.22';
const NAVIGATION_STATE_MARKER = 'PORTAL_NAVIGATION_STATE_V180322';
const NAVIGATION_STATE_ASSET_VERSION = '2026080322';

function nav22out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function nav22read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }
    return $content;
}

function nav22write(string $path, string $content): void
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать временный файл: ' . $temporary);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось заменить файл: ' . $path);
    }
}

function nav22lint(string $path): void
{
    if (!function_exists('exec')) {
        return;
    }
    $output = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1',
        $output,
        $code
    );
    if ($code !== 0) {
        throw new RuntimeException(
            'Ошибка PHP-синтаксиса в ' . $path . ':\n' . implode("\n", $output)
        );
    }
}

function nav22payload(): string
{
    $encoded = 'Ci8qIFBPUlRBTF9OQVZJR0FUSU9OX1NUQVRFX1YxODAzMjIgKi8KKCgpID0+IHsKICAgICd1c2Ugc3RyaWN0JzsKCiAgICBjb25zdCBTVE9SQUdFX0tFWSA9ICdzZW9BbmFseXRpY3MuYWN0aXZlU2VjdGlvbi52MjInOwogICAgY29uc3QgUkVRVUlSRURfU0VDVElPTlMgPSBbCiAgICAgICAgewogICAgICAgICAgICBzZWN0aW9uOiAncDEtc2FsZXMnLAogICAgICAgICAgICBsYWJlbDogJ9Cf0YDQvtC00LDQttC4INC4INGN0LrQvtC90L7QvNC40LrQsCcsCiAgICAgICAgICAgIGFmdGVyOiAncmVwb3J0cycKICAgICAgICB9CiAgICBdOwogICAgY29uc3QgcmVnaXN0cnkgPSBuZXcgTWFwKCk7CiAgICBsZXQgc2VxdWVuY2UgPSAwOwogICAgbGV0IHNjaGVkdWxlZCA9IGZhbHNlOwogICAgbGV0IHJlc3RvcmluZyA9IGZhbHNlOwoKICAgIGNvbnN0IHFzYSA9IChzZWxlY3Rvciwgcm9vdCA9IGRvY3VtZW50KSA9PgogICAgICAgIEFycmF5LmZyb20ocm9vdC5xdWVyeVNlbGVjdG9yQWxsKHNlbGVjdG9yKSk7CgogICAgZnVuY3Rpb24gbmF2aWdhdGlvblJvb3RzKCkgewogICAgICAgIHJldHVybiBxc2EoJy5zaWRlYmFyLW5hdiwgLnNpZGViYXItbWVudSwgLm5hdi1tZW51LCBhc2lkZSBuYXYsIC5zaWRlYmFyJykKICAgICAgICAgICAgLmZpbHRlcigobm9kZSwgaW5kZXgsIHJvd3MpID0+CiAgICAgICAgICAgICAgICByb3dzLmluZGV4T2Yobm9kZSkgPT09IGluZGV4CiAgICAgICAgICAgICAgICAmJiBub2RlLnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLXNlY3Rpb25dJykKICAgICAgICAgICAgKQogICAgICAgICAgICAuc29ydCgoYSwgYikgPT4KICAgICAgICAgICAgICAgIGIucXVlcnlTZWxlY3RvckFsbCgnW2RhdGEtc2VjdGlvbl0nKS5sZW5ndGgKICAgICAgICAgICAgICAgIC0gYS5xdWVyeVNlbGVjdG9yQWxsKCdbZGF0YS1zZWN0aW9uXScpLmxlbmd0aAogICAgICAgICAgICApOwogICAgfQoKICAgIGZ1bmN0aW9uIG5hdmlnYXRpb25Sb290KCkgewogICAgICAgIHJldHVybiBuYXZpZ2F0aW9uUm9vdHMoKVswXSB8fCBudWxsOwogICAgfQoKICAgIGZ1bmN0aW9uIGlzTmF2aWdhdGlvbkl0ZW0obm9kZSwgcm9vdCA9IG5hdmlnYXRpb25Sb290KCkpIHsKICAgICAgICByZXR1cm4gbm9kZSBpbnN0YW5jZW9mIEVsZW1lbnQKICAgICAgICAgICAgJiYgcm9vdCBpbnN0YW5jZW9mIEVsZW1lbnQKICAgICAgICAgICAgJiYgcm9vdC5jb250YWlucyhub2RlKQogICAgICAgICAgICAmJiBub2RlLm1hdGNoZXMoJ1tkYXRhLXNlY3Rpb25dJyk7CiAgICB9CgogICAgZnVuY3Rpb24gbGFiZWxPZihub2RlKSB7CiAgICAgICAgY29uc3QgdGV4dE5vZGUgPSBub2RlLnF1ZXJ5U2VsZWN0b3IoJy5uYXYtdGV4dCwgW2RhdGEtbmF2LXRleHRdLCBzcGFuOmxhc3QtY2hpbGQnKTsKICAgICAgICByZXR1cm4gU3RyaW5nKCh0ZXh0Tm9kZSB8fCBub2RlKS50ZXh0Q29udGVudCB8fCAnJykudHJpbSgpOwogICAgfQoKICAgIGZ1bmN0aW9uIHNldExhYmVsKG5vZGUsIGxhYmVsKSB7CiAgICAgICAgY29uc3QgdGV4dE5vZGUgPSBub2RlLnF1ZXJ5U2VsZWN0b3IoJy5uYXYtdGV4dCwgW2RhdGEtbmF2LXRleHRdLCBzcGFuOmxhc3QtY2hpbGQnKTsKICAgICAgICBpZiAodGV4dE5vZGUpIHsKICAgICAgICAgICAgdGV4dE5vZGUudGV4dENvbnRlbnQgPSBsYWJlbDsKICAgICAgICB9IGVsc2UgewogICAgICAgICAgICBub2RlLnRleHRDb250ZW50ID0gbGFiZWw7CiAgICAgICAgfQogICAgfQoKICAgIGZ1bmN0aW9uIGNhcHR1cmUobm9kZSkgewogICAgICAgIGNvbnN0IHJvb3QgPSBuYXZpZ2F0aW9uUm9vdCgpOwogICAgICAgIGlmICghaXNOYXZpZ2F0aW9uSXRlbShub2RlLCByb290KSkgcmV0dXJuOwogICAgICAgIGNvbnN0IHNlY3Rpb24gPSBTdHJpbmcobm9kZS5kYXRhc2V0LnNlY3Rpb24gfHwgJycpLnRyaW0oKTsKICAgICAgICBpZiAoIXNlY3Rpb24gfHwgcmVnaXN0cnkuaGFzKHNlY3Rpb24pKSByZXR1cm47CiAgICAgICAgcmVnaXN0cnkuc2V0KHNlY3Rpb24sIHsKICAgICAgICAgICAgc2VjdGlvbiwKICAgICAgICAgICAgbGFiZWw6IGxhYmVsT2Yobm9kZSksCiAgICAgICAgICAgIG5vZGUsCiAgICAgICAgICAgIG9yZGVyOiBzZXF1ZW5jZSsrCiAgICAgICAgfSk7CiAgICB9CgogICAgZnVuY3Rpb24gY2FwdHVyZUN1cnJlbnRNZW51KCkgewogICAgICAgIGNvbnN0IHJvb3QgPSBuYXZpZ2F0aW9uUm9vdCgpOwogICAgICAgIGlmICghcm9vdCkgcmV0dXJuOwogICAgICAgIHFzYSgnW2RhdGEtc2VjdGlvbl0nLCByb290KS5mb3JFYWNoKGNhcHR1cmUpOwogICAgfQoKICAgIGZ1bmN0aW9uIGNsb25lRmFsbGJhY2soc3BlYywgcm9vdCkgewogICAgICAgIGNvbnN0IHJlZmVyZW5jZSA9IHJvb3QucXVlcnlTZWxlY3RvcigKICAgICAgICAgICAgYFtkYXRhLXNlY3Rpb249IiR7Q1NTLmVzY2FwZShzcGVjLmFmdGVyIHx8ICdyZXBvcnRzJyl9Il1gCiAgICAgICAgKTsKICAgICAgICBjb25zdCB0ZW1wbGF0ZSA9IHJlZmVyZW5jZSB8fCByb290LnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLXNlY3Rpb25dJyk7CiAgICAgICAgY29uc3Qgbm9kZSA9IHRlbXBsYXRlCiAgICAgICAgICAgID8gdGVtcGxhdGUuY2xvbmVOb2RlKHRydWUpCiAgICAgICAgICAgIDogZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnYnV0dG9uJyk7CiAgICAgICAgbm9kZS5yZW1vdmVBdHRyaWJ1dGUoJ2lkJyk7CiAgICAgICAgbm9kZS50eXBlID0gJ2J1dHRvbic7CiAgICAgICAgbm9kZS5jbGFzc0xpc3QuYWRkKCduYXYtbGluaycpOwogICAgICAgIG5vZGUuZGF0YXNldC5zZWN0aW9uID0gc3BlYy5zZWN0aW9uOwogICAgICAgIHNldExhYmVsKG5vZGUsIHNwZWMubGFiZWwpOwogICAgICAgIG5vZGUuYWRkRXZlbnRMaXN0ZW5lcignY2xpY2snLCAoKSA9PiB7CiAgICAgICAgICAgIGFjdGl2YXRlU2VjdGlvbihzcGVjLnNlY3Rpb24sIHtyZW1lbWJlcjogdHJ1ZSwgZnJvbUNsaWNrOiB0cnVlfSk7CiAgICAgICAgfSk7CiAgICAgICAgcmV0dXJuIG5vZGU7CiAgICB9CgogICAgZnVuY3Rpb24gZW5zdXJlUmVxdWlyZWRTZWN0aW9ucygpIHsKICAgICAgICBjb25zdCByb290ID0gbmF2aWdhdGlvblJvb3QoKTsKICAgICAgICBpZiAoIXJvb3QpIHJldHVybjsKICAgICAgICBSRVFVSVJFRF9TRUNUSU9OUy5mb3JFYWNoKHNwZWMgPT4gewogICAgICAgICAgICBpZiAocmVnaXN0cnkuaGFzKHNwZWMuc2VjdGlvbikpIHJldHVybjsKICAgICAgICAgICAgY29uc3QgZXhpc3RpbmcgPSByb290LnF1ZXJ5U2VsZWN0b3IoCiAgICAgICAgICAgICAgICBgW2RhdGEtc2VjdGlvbj0iJHtDU1MuZXNjYXBlKHNwZWMuc2VjdGlvbil9Il1gCiAgICAgICAgICAgICk7CiAgICAgICAgICAgIGNvbnN0IG5vZGUgPSBleGlzdGluZyB8fCBjbG9uZUZhbGxiYWNrKHNwZWMsIHJvb3QpOwogICAgICAgICAgICByZWdpc3RyeS5zZXQoc3BlYy5zZWN0aW9uLCB7CiAgICAgICAgICAgICAgICBzZWN0aW9uOiBzcGVjLnNlY3Rpb24sCiAgICAgICAgICAgICAgICBsYWJlbDogc3BlYy5sYWJlbCwKICAgICAgICAgICAgICAgIG5vZGUsCiAgICAgICAgICAgICAgICBvcmRlcjogc2VxdWVuY2UrKwogICAgICAgICAgICB9KTsKICAgICAgICB9KTsKICAgIH0KCiAgICBmdW5jdGlvbiBpbnNlcnRCeU9yZGVyKGVudHJ5LCByb290KSB7CiAgICAgICAgY29uc3Qgcm93cyA9IEFycmF5LmZyb20ocmVnaXN0cnkudmFsdWVzKCkpCiAgICAgICAgICAgIC5zb3J0KChhLCBiKSA9PiBhLm9yZGVyIC0gYi5vcmRlcik7CiAgICAgICAgY29uc3QgcG9zaXRpb24gPSByb3dzLmZpbmRJbmRleChyb3cgPT4gcm93LnNlY3Rpb24gPT09IGVudHJ5LnNlY3Rpb24pOwogICAgICAgIGxldCBwcmV2aW91cyA9IG51bGw7CiAgICAgICAgZm9yIChsZXQgaW5kZXggPSBwb3NpdGlvbiAtIDE7IGluZGV4ID49IDA7IGluZGV4IC09IDEpIHsKICAgICAgICAgICAgY29uc3QgY2FuZGlkYXRlID0gcm93c1tpbmRleF0ubm9kZTsKICAgICAgICAgICAgaWYgKGNhbmRpZGF0ZSBpbnN0YW5jZW9mIEVsZW1lbnQgJiYgY2FuZGlkYXRlLmlzQ29ubmVjdGVkKSB7CiAgICAgICAgICAgICAgICBwcmV2aW91cyA9IGNhbmRpZGF0ZTsKICAgICAgICAgICAgICAgIGJyZWFrOwogICAgICAgICAgICB9CiAgICAgICAgfQogICAgICAgIGlmIChwcmV2aW91cyAmJiBwcmV2aW91cy5wYXJlbnRFbGVtZW50ID09PSByb290KSB7CiAgICAgICAgICAgIHByZXZpb3VzLmluc2VydEFkamFjZW50RWxlbWVudCgnYWZ0ZXJlbmQnLCBlbnRyeS5ub2RlKTsKICAgICAgICB9IGVsc2UgewogICAgICAgICAgICByb290LmFwcGVuZChlbnRyeS5ub2RlKTsKICAgICAgICB9CiAgICB9CgogICAgZnVuY3Rpb24gbm9ybWFsaXplTWVudSgpIHsKICAgICAgICBjb25zdCByb290ID0gbmF2aWdhdGlvblJvb3QoKTsKICAgICAgICBpZiAoIXJvb3QpIHJldHVybjsKICAgICAgICBjYXB0dXJlQ3VycmVudE1lbnUoKTsKICAgICAgICBlbnN1cmVSZXF1aXJlZFNlY3Rpb25zKCk7CgogICAgICAgIHJlZ2lzdHJ5LmZvckVhY2goZW50cnkgPT4gewogICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gcXNhKAogICAgICAgICAgICAgICAgYFtkYXRhLXNlY3Rpb249IiR7Q1NTLmVzY2FwZShlbnRyeS5zZWN0aW9uKX0iXWAsCiAgICAgICAgICAgICAgICByb290CiAgICAgICAgICAgICk7CiAgICAgICAgICAgIGlmICghZW50cnkubm9kZS5pc0Nvbm5lY3RlZCkgewogICAgICAgICAgICAgICAgaWYgKG1hdGNoZXMubGVuZ3RoKSB7CiAgICAgICAgICAgICAgICAgICAgY29uc3QgcmVwbGFjZW1lbnQgPSBtYXRjaGVzWzBdOwogICAgICAgICAgICAgICAgICAgIGlmIChlbnRyeS5zZWN0aW9uID09PSAncDEtc2FsZXMnKSB7CiAgICAgICAgICAgICAgICAgICAgICAgIHJlcGxhY2VtZW50LnJlcGxhY2VXaXRoKGVudHJ5Lm5vZGUpOwogICAgICAgICAgICAgICAgICAgIH0gZWxzZSB7CiAgICAgICAgICAgICAgICAgICAgICAgIGVudHJ5Lm5vZGUgPSByZXBsYWNlbWVudDsKICAgICAgICAgICAgICAgICAgICB9CiAgICAgICAgICAgICAgICB9IGVsc2UgewogICAgICAgICAgICAgICAgICAgIGluc2VydEJ5T3JkZXIoZW50cnksIHJvb3QpOwogICAgICAgICAgICAgICAgfQogICAgICAgICAgICB9CgogICAgICAgICAgICBxc2EoYFtkYXRhLXNlY3Rpb249IiR7Q1NTLmVzY2FwZShlbnRyeS5zZWN0aW9uKX0iXWAsIHJvb3QpCiAgICAgICAgICAgICAgICAuZm9yRWFjaChub2RlID0+IHsKICAgICAgICAgICAgICAgICAgICBpZiAobm9kZSAhPT0gZW50cnkubm9kZSkgbm9kZS5yZW1vdmUoKTsKICAgICAgICAgICAgICAgIH0pOwoKICAgICAgICAgICAgaWYgKGVudHJ5LnNlY3Rpb24gPT09ICdwMS1zYWxlcycpIHsKICAgICAgICAgICAgICAgIHNldExhYmVsKGVudHJ5Lm5vZGUsICfQn9GA0L7QtNCw0LbQuCDQuCDRjdC60L7QvdC+0LzQuNC60LAnKTsKICAgICAgICAgICAgfQogICAgICAgIH0pOwogICAgfQoKICAgIGZ1bmN0aW9uIHZhbGlkU2VjdGlvbihzZWN0aW9uKSB7CiAgICAgICAgcmV0dXJuIHR5cGVvZiBzZWN0aW9uID09PSAnc3RyaW5nJwogICAgICAgICAgICAmJiAvXlthLXowLTlfLV0rJC9pLnRlc3Qoc2VjdGlvbikKICAgICAgICAgICAgJiYgcmVnaXN0cnkuaGFzKHNlY3Rpb24pOwogICAgfQoKICAgIGZ1bmN0aW9uIHNlY3Rpb25Gcm9tSGFzaCgpIHsKICAgICAgICBjb25zdCB2YWx1ZSA9IGRlY29kZVVSSUNvbXBvbmVudChsb2NhdGlvbi5oYXNoLnJlcGxhY2UoL14jLywgJycpKS50cmltKCk7CiAgICAgICAgcmV0dXJuIHZhbGlkU2VjdGlvbih2YWx1ZSkgPyB2YWx1ZSA6ICcnOwogICAgfQoKICAgIGZ1bmN0aW9uIHNlY3Rpb25Gcm9tU3RvcmFnZSgpIHsKICAgICAgICB0cnkgewogICAgICAgICAgICBjb25zdCB2YWx1ZSA9IFN0cmluZyhsb2NhbFN0b3JhZ2UuZ2V0SXRlbShTVE9SQUdFX0tFWSkgfHwgJycpLnRyaW0oKTsKICAgICAgICAgICAgcmV0dXJuIHZhbGlkU2VjdGlvbih2YWx1ZSkgPyB2YWx1ZSA6ICcnOwogICAgICAgIH0gY2F0Y2ggKF8pIHsKICAgICAgICAgICAgcmV0dXJuICcnOwogICAgICAgIH0KICAgIH0KCiAgICBmdW5jdGlvbiBhY3RpdmVTZWN0aW9uRnJvbURvbSgpIHsKICAgICAgICBjb25zdCByb290ID0gbmF2aWdhdGlvblJvb3QoKTsKICAgICAgICBjb25zdCBhY3RpdmVCdXR0b24gPSByb290Py5xdWVyeVNlbGVjdG9yKCdbZGF0YS1zZWN0aW9uXS5hY3RpdmUnKTsKICAgICAgICBpZiAoYWN0aXZlQnV0dG9uPy5kYXRhc2V0LnNlY3Rpb24pIHsKICAgICAgICAgICAgcmV0dXJuIFN0cmluZyhhY3RpdmVCdXR0b24uZGF0YXNldC5zZWN0aW9uKTsKICAgICAgICB9CiAgICAgICAgY29uc3QgYWN0aXZlU2VjdGlvbiA9IGRvY3VtZW50LnF1ZXJ5U2VsZWN0b3IoJy5zZWN0aW9uLmFjdGl2ZVtpZF49InNlY3Rpb24tIl0nKTsKICAgICAgICByZXR1cm4gYWN0aXZlU2VjdGlvbgogICAgICAgICAgICA/IFN0cmluZyhhY3RpdmVTZWN0aW9uLmlkKS5yZXBsYWNlKC9ec2VjdGlvbi0vLCAnJykKICAgICAgICAgICAgOiAnJzsKICAgIH0KCiAgICBmdW5jdGlvbiByZW1lbWJlclNlY3Rpb24oc2VjdGlvbikgewogICAgICAgIGlmICghdmFsaWRTZWN0aW9uKHNlY3Rpb24pKSByZXR1cm47CiAgICAgICAgdHJ5IHsKICAgICAgICAgICAgbG9jYWxTdG9yYWdlLnNldEl0ZW0oU1RPUkFHRV9LRVksIHNlY3Rpb24pOwogICAgICAgIH0gY2F0Y2ggKF8pIHsKICAgICAgICB9CiAgICAgICAgY29uc3QgaGFzaCA9IGAjJHtlbmNvZGVVUklDb21wb25lbnQoc2VjdGlvbil9YDsKICAgICAgICBpZiAobG9jYXRpb24uaGFzaCAhPT0gaGFzaCkgewogICAgICAgICAgICBoaXN0b3J5LnJlcGxhY2VTdGF0ZShoaXN0b3J5LnN0YXRlLCAnJywgaGFzaCk7CiAgICAgICAgfQogICAgfQoKICAgIGZ1bmN0aW9uIGFjdGl2YXRlU2VjdGlvbihzZWN0aW9uLCBvcHRpb25zID0ge30pIHsKICAgICAgICBub3JtYWxpemVNZW51KCk7CiAgICAgICAgaWYgKCF2YWxpZFNlY3Rpb24oc2VjdGlvbikpIHJldHVybiBmYWxzZTsKICAgICAgICBjb25zdCBlbnRyeSA9IHJlZ2lzdHJ5LmdldChzZWN0aW9uKTsKICAgICAgICBjb25zdCBidXR0b24gPSBlbnRyeT8ubm9kZTsKICAgICAgICBpZiAoIShidXR0b24gaW5zdGFuY2VvZiBFbGVtZW50KSkgcmV0dXJuIGZhbHNlOwoKICAgICAgICBpZiAob3B0aW9ucy5yZW1lbWJlciAhPT0gZmFsc2UpIHJlbWVtYmVyU2VjdGlvbihzZWN0aW9uKTsKCiAgICAgICAgaWYgKCFvcHRpb25zLmZyb21DbGljaykgewogICAgICAgICAgICByZXN0b3JpbmcgPSB0cnVlOwogICAgICAgICAgICB0cnkgewogICAgICAgICAgICAgICAgYnV0dG9uLmNsaWNrKCk7CiAgICAgICAgICAgIH0gZmluYWxseSB7CiAgICAgICAgICAgICAgICByZXN0b3JpbmcgPSBmYWxzZTsKICAgICAgICAgICAgfQogICAgICAgIH0KCiAgICAgICAgY29uc3QgdGFyZ2V0ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoYHNlY3Rpb24tJHtzZWN0aW9ufWApOwogICAgICAgIGlmICghdGFyZ2V0Py5jbGFzc0xpc3QuY29udGFpbnMoJ2FjdGl2ZScpKSB7CiAgICAgICAgICAgIHRyeSB7CiAgICAgICAgICAgICAgICBpZiAodHlwZW9mIHdpbmRvdy5zaG93U2VjdGlvbiA9PT0gJ2Z1bmN0aW9uJykgewogICAgICAgICAgICAgICAgICAgIHdpbmRvdy5zaG93U2VjdGlvbihzZWN0aW9uKTsKICAgICAgICAgICAgICAgIH0KICAgICAgICAgICAgfSBjYXRjaCAoXykgewogICAgICAgICAgICB9CiAgICAgICAgfQogICAgICAgIHJldHVybiB0cnVlOwogICAgfQoKICAgIGZ1bmN0aW9uIHJlc3RvcmVTZWN0aW9uKCkgewogICAgICAgIG5vcm1hbGl6ZU1lbnUoKTsKICAgICAgICBjb25zdCBkZXNpcmVkID0gc2VjdGlvbkZyb21IYXNoKCkgfHwgc2VjdGlvbkZyb21TdG9yYWdlKCk7CiAgICAgICAgaWYgKCFkZXNpcmVkKSByZXR1cm47CiAgICAgICAgbGV0IGF0dGVtcHRzID0gMDsKICAgICAgICBjb25zdCBydW4gPSAoKSA9PiB7CiAgICAgICAgICAgIGF0dGVtcHRzICs9IDE7CiAgICAgICAgICAgIG5vcm1hbGl6ZU1lbnUoKTsKICAgICAgICAgICAgaWYgKGFjdGl2YXRlU2VjdGlvbihkZXNpcmVkLCB7cmVtZW1iZXI6IHRydWV9KSB8fCBhdHRlbXB0cyA+PSAyMCkgewogICAgICAgICAgICAgICAgcmV0dXJuOwogICAgICAgICAgICB9CiAgICAgICAgICAgIHNldFRpbWVvdXQocnVuLCA1MCk7CiAgICAgICAgfTsKICAgICAgICBzZXRUaW1lb3V0KHJ1biwgMCk7CiAgICB9CgogICAgZnVuY3Rpb24gdXBkYXRlQml0cml4UHJvamVjdENvdW50KHJvb3QgPSBkb2N1bWVudCkgewogICAgICAgIGNvbnN0IGZvcm0gPSByb290LnF1ZXJ5U2VsZWN0b3I/LignI2IxOU9uYm9hcmRpbmdGb3JtJykKICAgICAgICAgICAgfHwgZG9jdW1lbnQucXVlcnlTZWxlY3RvcignI2IxOU9uYm9hcmRpbmdGb3JtJyk7CiAgICAgICAgaWYgKCFmb3JtKSByZXR1cm47CiAgICAgICAgY29uc3QgaGVhZGluZyA9IHFzYSgnLmIxOS1zZWN0aW9uLWhlYWRpbmcgaDQnLCBmb3JtKQogICAgICAgICAgICAuZmluZChub2RlID0+IC9e0J/RgNC+0LXQutGC0YsgQml0cml4MjQoPzpccypcKFxkK1wpKT8kLy50ZXN0KAogICAgICAgICAgICAgICAgU3RyaW5nKG5vZGUudGV4dENvbnRlbnQgfHwgJycpLnRyaW0oKQogICAgICAgICAgICApKTsKICAgICAgICBpZiAoIWhlYWRpbmcpIHJldHVybjsKICAgICAgICBjb25zdCBzZWxlY3RlZCA9IGZvcm0ucXVlcnlTZWxlY3RvckFsbCgKICAgICAgICAgICAgJ2lucHV0W25hbWU9InByb2plY3RfaWRzW10iXTpjaGVja2VkJwogICAgICAgICkubGVuZ3RoOwogICAgICAgIGhlYWRpbmcudGV4dENvbnRlbnQgPSBg0J/RgNC+0LXQutGC0YsgQml0cml4MjQgKCR7c2VsZWN0ZWR9KWA7CiAgICB9CgogICAgZnVuY3Rpb24gc2NoZWR1bGVOb3JtYWxpemUoKSB7CiAgICAgICAgaWYgKHNjaGVkdWxlZCkgcmV0dXJuOwogICAgICAgIHNjaGVkdWxlZCA9IHRydWU7CiAgICAgICAgcmVxdWVzdEFuaW1hdGlvbkZyYW1lKCgpID0+IHsKICAgICAgICAgICAgc2NoZWR1bGVkID0gZmFsc2U7CiAgICAgICAgICAgIG5vcm1hbGl6ZU1lbnUoKTsKICAgICAgICAgICAgdXBkYXRlQml0cml4UHJvamVjdENvdW50KCk7CiAgICAgICAgfSk7CiAgICB9CgogICAgZnVuY3Rpb24gYmluZEV2ZW50cygpIHsKICAgICAgICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCdjbGljaycsIGV2ZW50ID0+IHsKICAgICAgICAgICAgY29uc3Qgcm9vdCA9IG5hdmlnYXRpb25Sb290KCk7CiAgICAgICAgICAgIGNvbnN0IGJ1dHRvbiA9IGV2ZW50LnRhcmdldC5jbG9zZXN0Py4oJ1tkYXRhLXNlY3Rpb25dJyk7CiAgICAgICAgICAgIGlmICghaXNOYXZpZ2F0aW9uSXRlbShidXR0b24sIHJvb3QpKSByZXR1cm47CiAgICAgICAgICAgIGNvbnN0IHNlY3Rpb24gPSBTdHJpbmcoYnV0dG9uLmRhdGFzZXQuc2VjdGlvbiB8fCAnJyk7CiAgICAgICAgICAgIHJlbWVtYmVyU2VjdGlvbihzZWN0aW9uKTsKICAgICAgICAgICAgaWYgKCFyZXN0b3JpbmcgJiYgc2VjdGlvbiA9PT0gJ3AxLXNhbGVzJyAmJiAhYnV0dG9uLmlzQ29ubmVjdGVkKSB7CiAgICAgICAgICAgICAgICBub3JtYWxpemVNZW51KCk7CiAgICAgICAgICAgIH0KICAgICAgICB9LCB0cnVlKTsKCiAgICAgICAgZG9jdW1lbnQuYWRkRXZlbnRMaXN0ZW5lcignY2hhbmdlJywgZXZlbnQgPT4gewogICAgICAgICAgICBpZiAoZXZlbnQudGFyZ2V0Lm1hdGNoZXM/LignaW5wdXRbbmFtZT0icHJvamVjdF9pZHNbXSJdJykpIHsKICAgICAgICAgICAgICAgIHVwZGF0ZUJpdHJpeFByb2plY3RDb3VudCgpOwogICAgICAgICAgICB9CiAgICAgICAgfSwgdHJ1ZSk7CgogICAgICAgIHdpbmRvdy5hZGRFdmVudExpc3RlbmVyKCdiZWZvcmV1bmxvYWQnLCAoKSA9PiB7CiAgICAgICAgICAgIGNvbnN0IHNlY3Rpb24gPSBhY3RpdmVTZWN0aW9uRnJvbURvbSgpOwogICAgICAgICAgICBpZiAodmFsaWRTZWN0aW9uKHNlY3Rpb24pKSByZW1lbWJlclNlY3Rpb24oc2VjdGlvbik7CiAgICAgICAgfSk7CgogICAgICAgIHdpbmRvdy5hZGRFdmVudExpc3RlbmVyKCdoYXNoY2hhbmdlJywgKCkgPT4gewogICAgICAgICAgICBjb25zdCBzZWN0aW9uID0gc2VjdGlvbkZyb21IYXNoKCk7CiAgICAgICAgICAgIGlmIChzZWN0aW9uKSBhY3RpdmF0ZVNlY3Rpb24oc2VjdGlvbiwge3JlbWVtYmVyOiB0cnVlfSk7CiAgICAgICAgfSk7CiAgICB9CgogICAgZnVuY3Rpb24gYm9vdCgpIHsKICAgICAgICBjYXB0dXJlQ3VycmVudE1lbnUoKTsKICAgICAgICBlbnN1cmVSZXF1aXJlZFNlY3Rpb25zKCk7CiAgICAgICAgbm9ybWFsaXplTWVudSgpOwogICAgICAgIGJpbmRFdmVudHMoKTsKICAgICAgICB1cGRhdGVCaXRyaXhQcm9qZWN0Q291bnQoKTsKCiAgICAgICAgY29uc3Qgb2JzZXJ2ZXIgPSBuZXcgTXV0YXRpb25PYnNlcnZlcihyZWNvcmRzID0+IHsKICAgICAgICAgICAgcmVjb3Jkcy5mb3JFYWNoKHJlY29yZCA9PiB7CiAgICAgICAgICAgICAgICByZWNvcmQuYWRkZWROb2Rlcy5mb3JFYWNoKG5vZGUgPT4gewogICAgICAgICAgICAgICAgICAgIGlmIChub2RlIGluc3RhbmNlb2YgRWxlbWVudCkgewogICAgICAgICAgICAgICAgICAgICAgICBpZiAobm9kZS5tYXRjaGVzKCdbZGF0YS1zZWN0aW9uXScpKSBjYXB0dXJlKG5vZGUpOwogICAgICAgICAgICAgICAgICAgICAgICBub2RlLnF1ZXJ5U2VsZWN0b3JBbGw/LignW2RhdGEtc2VjdGlvbl0nKS5mb3JFYWNoKGNhcHR1cmUpOwogICAgICAgICAgICAgICAgICAgICAgICB1cGRhdGVCaXRyaXhQcm9qZWN0Q291bnQobm9kZSk7CiAgICAgICAgICAgICAgICAgICAgfQogICAgICAgICAgICAgICAgfSk7CiAgICAgICAgICAgIH0pOwogICAgICAgICAgICBzY2hlZHVsZU5vcm1hbGl6ZSgpOwogICAgICAgIH0pOwogICAgICAgIG9ic2VydmVyLm9ic2VydmUoZG9jdW1lbnQuYm9keSwge2NoaWxkTGlzdDogdHJ1ZSwgc3VidHJlZTogdHJ1ZX0pOwoKICAgICAgICByZXN0b3JlU2VjdGlvbigpOwogICAgfQoKICAgIHdpbmRvdy5Qb3J0YWxOYXZpZ2F0aW9uID0gewogICAgICAgIGFjdGl2YXRlOiBzZWN0aW9uID0+IGFjdGl2YXRlU2VjdGlvbihzZWN0aW9uLCB7cmVtZW1iZXI6IHRydWV9KSwKICAgICAgICBjdXJyZW50OiBhY3RpdmVTZWN0aW9uRnJvbURvbSwKICAgICAgICByZWxvYWRDdXJyZW50OiAoKSA9PiB7CiAgICAgICAgICAgIGNvbnN0IHNlY3Rpb24gPSBhY3RpdmVTZWN0aW9uRnJvbURvbSgpOwogICAgICAgICAgICBpZiAodmFsaWRTZWN0aW9uKHNlY3Rpb24pKSByZW1lbWJlclNlY3Rpb24oc2VjdGlvbik7CiAgICAgICAgICAgIGxvY2F0aW9uLnJlbG9hZCgpOwogICAgICAgIH0sCiAgICAgICAgbm9ybWFsaXplOiBub3JtYWxpemVNZW51CiAgICB9OwoKICAgIGlmIChkb2N1bWVudC5yZWFkeVN0YXRlID09PSAnbG9hZGluZycpIHsKICAgICAgICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCdET01Db250ZW50TG9hZGVkJywgYm9vdCwge29uY2U6IHRydWV9KTsKICAgIH0gZWxzZSB7CiAgICAgICAgYm9vdCgpOwogICAgfQp9KSgpOwo=';
    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || hash('sha256', $decoded) !== '3d5a4c621d84c81a8df9640567de05bdd5688f44ea6becf5c0e04ede8057cf6e') {
        throw new RuntimeException('Повреждён пакет навигации.');
    }
    return $decoded;
}

function nav22bustAsset(string $content, string $asset): array
{
    $pattern = '#(' . preg_quote($asset, '#') . ')(?:\?[^"\'\s<>]*)?#i';
    $updated = preg_replace_callback(
        $pattern,
        static fn(array $match): string =>
            $match[1] . '?v=' . NAVIGATION_STATE_ASSET_VERSION,
        $content,
        -1,
        $count
    );
    if (!is_string($updated)) {
        throw new RuntimeException('Не удалось обновить версию ресурса ' . $asset . '.');
    }
    return [$updated, (int) $count];
}
$root = getcwd() ?: '';
$indexPath = $root . '/index.php';
$appJsPath = $root . '/assets/app.js';

foreach ([$indexPath, $appJsPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Не найден обязательный файл: ' . $path);
    }
}

$appJsBefore = nav22read($appJsPath);
if (!str_contains($appJsBefore, 'LK2_MODAL_DIRECT_CLOSE_V21')) {
    throw new RuntimeException(
        'Версия ' . NAVIGATION_STATE_VERSION
        . ' требует установленную 2026.08.03.21.'
    );
}

$navigationJs = nav22payload();
$backupDirectory = $root . '/storage/backups/navigation-state-v22-'
    . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию навигации.');
}

$tracked = [
    'index.php',
    'assets/app.js',
];
foreach ($tracked as $relative) {
    $source = $root . '/' . $relative;
    $destination = $backupDirectory . '/' . $relative;
    $directory = dirname($destination);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог резервной копии.');
    }
    if (!copy($source, $destination)) {
        throw new RuntimeException('Не удалось сохранить резервную копию: ' . $relative);
    }
}

try {
    $appJs = $appJsBefore;
    if (!str_contains($appJs, NAVIGATION_STATE_MARKER)) {
        $appJs = rtrim($appJs) . PHP_EOL . PHP_EOL
            . trim($navigationJs) . PHP_EOL;
        nav22write($appJsPath, $appJs);
    }

    $index = nav22read($indexPath);
    [$index, $cssCount] = nav22bustAsset($index, 'assets/app.css');
    [$index, $jsCount] = nav22bustAsset($index, 'assets/app.js');
    if ($cssCount <= 0 || $jsCount <= 0) {
        throw new RuntimeException(
            'Не удалось найти ссылки на assets/app.css и assets/app.js в index.php.'
        );
    }
    nav22write($indexPath, $index);
    nav22lint($indexPath);

    if (function_exists('exec')) {
        $probeOutput = [];
        $probeCode = 0;
        exec(
            'node -e ' . escapeshellarg(
                "const value = null; value?.x; const y = value ?? 1;"
            ) . ' 2>&1',
            $probeOutput,
            $probeCode
        );
        if ($probeCode === 0) {
            $nodeOutput = [];
            $nodeCode = 0;
            exec(
                'node --check ' . escapeshellarg($appJsPath) . ' 2>&1',
                $nodeOutput,
                $nodeCode
            );
            if ($nodeCode !== 0) {
                throw new RuntimeException(
                    'Ошибка JavaScript навигации: ' . implode("\n", $nodeOutput)
                );
            }
        } else {
            nav22out(
                'Проверка JavaScript через Node.js пропущена: '
                . 'серверная версия Node.js устарела.'
            );
        }
    }

    $finalJs = nav22read($appJsPath);
    $finalIndex = nav22read($indexPath);
    if (substr_count($finalJs, NAVIGATION_STATE_MARKER) !== 1) {
        throw new RuntimeException('Контроллер навигации подключён некорректно.');
    }
    if (!str_contains($finalJs, 'seoAnalytics.activeSection.v22')) {
        throw new RuntimeException('Не подключено сохранение текущего раздела.');
    }
    if (!str_contains($finalJs, 'Проекты Bitrix24 (${selected})')) {
        throw new RuntimeException('Не подключён счётчик выбранных проектов.');
    }
    if (!str_contains($finalJs, "section: 'p1-sales'")) {
        throw new RuntimeException('Не подключена обязательная ссылка продаж и экономики.');
    }
    if (!str_contains($finalIndex, 'assets/app.css?v=' . NAVIGATION_STATE_ASSET_VERSION)) {
        throw new RuntimeException('Версия app.css не обновлена.');
    }
    if (!str_contains($finalIndex, 'assets/app.js?v=' . NAVIGATION_STATE_ASSET_VERSION)) {
        throw new RuntimeException('Версия app.js не обновлена.');
    }

    nav22out('Единая навигация портала установлена.');
    nav22out('- текущий раздел сохраняется после синхронизации и перезагрузки;');
    nav22out('- активный раздел хранится в URL и локальном хранилище браузера;');
    nav22out('- недостающие пункты основного меню восстанавливаются автоматически;');
    nav22out('- дубли пунктов с одинаковым data-section удаляются;');
    nav22out('- «Продажи и экономика» закреплены в едином реестре меню;');
    nav22out('- в заголовке проектов Bitrix24 показывается количество выбранных;');
    nav22out('- резервная копия файлов: ' . $backupDirectory . '.');
} catch (Throwable $exception) {
    foreach ($tracked as $relative) {
        $backup = $backupDirectory . '/' . $relative;
        $target = $root . '/' . $relative;
        if (is_file($backup)) {
            @copy($backup, $target);
        }
    }
    fwrite(STDERR, 'ОШИБКА: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Файлы восстановлены из резервной копии.' . PHP_EOL);
    exit(1);
}
