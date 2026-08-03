<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

const UI_HOTFIX_VERSION = '2026.08.03.20';
const UI_HOTFIX_MARKER = 'PORTAL_UI_HOTFIX_V180320';
const UI_HOTFIX_ASSET_VERSION = '2026080320';

function ui20out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function ui20read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }
    return $content;
}

function ui20write(string $path, string $content): void
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

function ui20lint(string $path): void
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

function ui20payload(): array
{
    $encoded = 'eyJqcyI6eyJzaGEyNTYiOiI1N2JiZTE3ZDYwNDNlMGQyNzc4ODY2ZmUyNzdmNGFmNWQ5OWU2NGYzZWRiZWQ0MjQ0NjdhZDNmYTljYjAzNzE4IiwiY29udGVudCI6IkNpOHFJRkJQVWxSQlRGOVZTVjlJVDFSR1NWaGZWakU0TURNeU1DQXFMd29vS0NrZ1BUNGdld29nSUNBZ0ozVnpaU0J6ZEhKcFkzUW5Pd29LSUNBZ0lHTnZibk4wSUZOVVdVeEZYMGxFSUQwZ0ozQnZjblJoYkZWcFNHOTBabWw0VmpJd1UzUjViR1Z6SnpzS0lDQWdJR052Ym5OMElFTlRVeUE5SUdBS0xteHJNaTFqYkdsbGJuUXRjM1J5ZFdOMGRYSmxJQzVpZEc0c0NpNWlNVGt0Ylc5a1lXd2dMbUowYml3S0xteHJNaTFqYkdsbGJuUXRjM1J5ZFdOMGRYSmxJR0oxZEhSdmJsdGtZWFJoTFd4ck1pMWhZM1JwYjI1ZE9tNXZkQ2d1YkdzeUxXTnNhV1Z1ZEMxallYSmtLVHB1YjNRb0xteHJNaTFwWTI5dUxXSjFkSFJ2YmlrNmJtOTBLQzVzYXpJdGJXOWtZV3d0WTJ4dmMyVXBJSHNLSUNBZ0lHRndjR1ZoY21GdVkyVTZJRzV2Ym1VN0NpQWdJQ0JrYVhOd2JHRjVPaUJwYm14cGJtVXRabXhsZURzS0lDQWdJR0ZzYVdkdUxXbDBaVzF6T2lCalpXNTBaWEk3Q2lBZ0lDQnFkWE4wYVdaNUxXTnZiblJsYm5RNklHTmxiblJsY2pzS0lDQWdJRzFwYmkxb1pXbG5hSFE2SURNNGNIZzdDaUFnSUNCd1lXUmthVzVuT2lBNGNIZ2dNVFJ3ZURzS0lDQWdJR0p2Y21SbGNqb2dNWEI0SUhOdmJHbGtJQ05rTUdRM1pUSTdDaUFnSUNCaWIzSmtaWEl0Y21Ga2FYVnpPaUF4TUhCNE93b2dJQ0FnWW1GamEyZHliM1Z1WkRvZ0kyWm1aanNLSUNBZ0lHTnZiRzl5T2lBak16UTBNRFUwT3dvZ0lDQWdabTl1ZERvZ2FXNW9aWEpwZERzS0lDQWdJR1p2Ym5RdGMybDZaVG9nTVROd2VEc0tJQ0FnSUdadmJuUXRkMlZwWjJoME9pQTJOVEE3Q2lBZ0lDQnNhVzVsTFdobGFXZG9kRG9nTVM0eU93b2dJQ0FnZEdWNGRDMWtaV052Y21GMGFXOXVPaUJ1YjI1bE93b2dJQ0FnWTNWeWMyOXlPaUJ3YjJsdWRHVnlPd29nSUNBZ2RISmhibk5wZEdsdmJqb2dZbTl5WkdWeUxXTnZiRzl5SUM0eE5uTWdaV0Z6WlN3Z1ltRmphMmR5YjNWdVpDQXVNVFp6SUdWaGMyVXNJR052Ykc5eUlDNHhObk1nWldGelpTd2dZbTk0TFhOb1lXUnZkeUF1TVRaeklHVmhjMlU3Q24wS0xteHJNaTFqYkdsbGJuUXRjM1J5ZFdOMGRYSmxJQzVpZEc0NmFHOTJaWElzQ2k1aU1Ua3RiVzlrWVd3Z0xtSjBianBvYjNabGNpd0tMbXhyTWkxamJHbGxiblF0YzNSeWRXTjBkWEpsSUdKMWRIUnZibHRrWVhSaExXeHJNaTFoWTNScGIyNWRPbTV2ZENndWJHc3lMV05zYVdWdWRDMWpZWEprS1RwdWIzUW9MbXhyTWkxcFkyOXVMV0oxZEhSdmJpazZibTkwS0M1c2F6SXRiVzlrWVd3dFkyeHZjMlVwT21odmRtVnlJSHNLSUNBZ0lHSnZjbVJsY2kxamIyeHZjam9nSXprNFlUSmlNenNLSUNBZ0lHSmhZMnRuY205MWJtUTZJQ05tT0daaFptTTdDbjBLTG14ck1pMWpiR2xsYm5RdGMzUnlkV04wZFhKbElDNWlkRzR0Y0hKcGJXRnllU3dLTG1JeE9TMXRiMlJoYkNBdVluUnVMWEJ5YVcxaGNua3NDaTVzYXpJdFkyeHBaVzUwTFhOMGNuVmpkSFZ5WlNCaWRYUjBiMjViWkdGMFlTMXNhekl0WVdOMGFXOXVQU0p1WlhjdFkyeHBaVzUwSWwwc0NpNXNhekl0WTJ4cFpXNTBMWE4wY25WamRIVnlaU0JpZFhSMGIyNWJaR0YwWVMxc2F6SXRZV04wYVc5dVBTSnVaWGN0Y0hKdmFtVmpkQ0pkSUhzS0lDQWdJR0p2Y21SbGNpMWpiMnh2Y2pvZ0l6STFOak5sWWlBaGFXMXdiM0owWVc1ME93b2dJQ0FnWW1GamEyZHliM1Z1WkRvZ0l6STFOak5sWWlBaGFXMXdiM0owWVc1ME93b2dJQ0FnWTI5c2IzSTZJQ05tWm1ZZ0lXbHRjRzl5ZEdGdWREc0tJQ0FnSUdKdmVDMXphR0ZrYjNjNklEQWdOSEI0SURFeWNIZ2djbWRpWVNnek55d2dPVGtzSURJek5Td2dMakU0S1RzS2ZRb3ViR3N5TFdOc2FXVnVkQzF6ZEhKMVkzUjFjbVVnTG1KMGJpMXdjbWx0WVhKNU9taHZkbVZ5TEFvdVlqRTVMVzF2WkdGc0lDNWlkRzR0Y0hKcGJXRnllVHBvYjNabGNpd0tMbXhyTWkxamJHbGxiblF0YzNSeWRXTjBkWEpsSUdKMWRIUnZibHRrWVhSaExXeHJNaTFoWTNScGIyNDlJbTVsZHkxamJHbGxiblFpWFRwb2IzWmxjaXdLTG14ck1pMWpiR2xsYm5RdGMzUnlkV04wZFhKbElHSjFkSFJ2Ymx0a1lYUmhMV3hyTWkxaFkzUnBiMjQ5SW01bGR5MXdjbTlxWldOMElsMDZhRzkyWlhJZ2V3b2dJQ0FnWW05eVpHVnlMV052Ykc5eU9pQWpNV1EwWldRNElDRnBiWEJ2Y25SaGJuUTdDaUFnSUNCaVlXTnJaM0p2ZFc1a09pQWpNV1EwWldRNElDRnBiWEJ2Y25SaGJuUTdDbjBLTG14ck1pMWpiR2xsYm5RdGMzUnlkV04wZFhKbElDNWlkRzR0YzJWamIyNWtZWEo1TEFvdVlqRTVMVzF2WkdGc0lDNWlkRzR0YzJWamIyNWtZWEo1SUhzS0lDQWdJR0p2Y21SbGNpMWpiMnh2Y2pvZ0kyUXdaRGRsTWlBaGFXMXdiM0owWVc1ME93b2dJQ0FnWW1GamEyZHliM1Z1WkRvZ0kyWm1aaUFoYVcxd2IzSjBZVzUwT3dvZ0lDQWdZMjlzYjNJNklDTXpORFF3TlRRZ0lXbHRjRzl5ZEdGdWREc0tmUW91YkdzeUxXTnNhV1Z1ZEMxemRISjFZM1IxY21VZ0xtSjBiaTFrWVc1blpYSXRjMjltZENCN0NpQWdJQ0JpYjNKa1pYSXRZMjlzYjNJNklDTm1NbU0yWXpFZ0lXbHRjRzl5ZEdGdWREc0tJQ0FnSUdKaFkydG5jbTkxYm1RNklDTm1abVkwWmpJZ0lXbHRjRzl5ZEdGdWREc0tJQ0FnSUdOdmJHOXlPaUFqWWpReU16RTRJQ0ZwYlhCdmNuUmhiblE3Q24wS0xteHJNaTFqYkdsbGJuUXRjM1J5ZFdOMGRYSmxJR0oxZEhSdmJqcGthWE5oWW14bFpDd0tMbUl4T1MxdGIyUmhiQ0JpZFhSMGIyNDZaR2x6WVdKc1pXUWdld29nSUNBZ2IzQmhZMmwwZVRvZ0xqVTFPd29nSUNBZ1kzVnljMjl5T2lCdWIzUXRZV3hzYjNkbFpEc0tmUW91YkdzeUxXMXZaR0ZzTFdKaFkydGtjbTl3TEFvdVlqRTVMV0poWTJ0a2NtOXdJSHNLSUNBZ0lHOTJaWEp6WTNKdmJHd3RZbVZvWVhacGIzSTZJR052Ym5SaGFXNDdDbjBLTG14ck1pMXRiMlJoYkN3S0xtSXhPUzF0YjJSaGJDQjdDaUFnSUNCcGMyOXNZWFJwYjI0NklHbHpiMnhoZEdVN0NuMEtZRHNLQ2lBZ0lDQm1kVzVqZEdsdmJpQmxibk4xY21WVGRIbHNaWE1vS1NCN0NpQWdJQ0FnSUNBZ2FXWWdLR1J2WTNWdFpXNTBMbWRsZEVWc1pXMWxiblJDZVVsa0tGTlVXVXhGWDBsRUtTa2djbVYwZFhKdU93b2dJQ0FnSUNBZ0lHTnZibk4wSUhOMGVXeGxJRDBnWkc5amRXMWxiblF1WTNKbFlYUmxSV3hsYldWdWRDZ25jM1I1YkdVbktUc0tJQ0FnSUNBZ0lDQnpkSGxzWlM1cFpDQTlJRk5VV1V4RlgwbEVPd29nSUNBZ0lDQWdJSE4wZVd4bExuUmxlSFJEYjI1MFpXNTBJRDBnUTFOVE93b2dJQ0FnSUNBZ0lDaGtiMk4xYldWdWRDNW9aV0ZrSUh4OElHUnZZM1Z0Wlc1MExtUnZZM1Z0Wlc1MFJXeGxiV1Z1ZENrdVlYQndaVzVrUTJocGJHUW9jM1I1YkdVcE93b2dJQ0FnZlFvS0lDQWdJR1oxYm1OMGFXOXVJR2QxWVhKa1RHc3lUVzlrWVd3b2JXOWtZV3dwSUhzS0lDQWdJQ0FnSUNCcFppQW9JU2h0YjJSaGJDQnBibk4wWVc1alpXOW1JRVZzWlcxbGJuUXBJSHg4SUcxdlpHRnNMbVJoZEdGelpYUXVkV2xJYjNSbWFYaFdNakFnUFQwOUlDY3hKeWtnY21WMGRYSnVPd29nSUNBZ0lDQWdJRzF2WkdGc0xtUmhkR0Z6WlhRdWRXbEliM1JtYVhoV01qQWdQU0FuTVNjN0NpQWdJQ0FnSUNBZ2JXOWtZV3d1Y21WdGIzWmxRWFIwY21saWRYUmxLQ2R2Ym1Oc2FXTnJKeWs3Q2lBZ0lDQWdJQ0FnYlc5a1lXd3VZV1JrUlhabGJuUk1hWE4wWlc1bGNpZ25ZMnhwWTJzbkxDQmxkbVZ1ZENBOVBpQjdDaUFnSUNBZ0lDQWdJQ0FnSUdOdmJuTjBJR05zYjNObFEyOXVkSEp2YkNBOUlHVjJaVzUwTG5SaGNtZGxkQzVqYkc5elpYTjBLQ2RiWkdGMFlTMXNhekl0WVdOMGFXOXVQU0pqYkc5elpTMXRiMlJoYkNKZEp5azdDaUFnSUNBZ0lDQWdJQ0FnSUdsbUlDZ2hZMnh2YzJWRGIyNTBjbTlzS1NCN0NpQWdJQ0FnSUNBZ0lDQWdJQ0FnSUNCbGRtVnVkQzV6ZEc5d1VISnZjR0ZuWVhScGIyNG9LVHNLSUNBZ0lDQWdJQ0FnSUNBZ2ZRb2dJQ0FnSUNBZ0lIMHBPd29nSUNBZ2ZRb0tJQ0FnSUdaMWJtTjBhVzl1SUdkMVlYSmtRakU1VFc5a1lXd29iVzlrWVd3cElIc0tJQ0FnSUNBZ0lDQnBaaUFvSVNodGIyUmhiQ0JwYm5OMFlXNWpaVzltSUVWc1pXMWxiblFwSUh4OElHMXZaR0ZzTG1SaGRHRnpaWFF1ZFdsSWIzUm1hWGhXTWpBZ1BUMDlJQ2N4SnlrZ2NtVjBkWEp1T3dvZ0lDQWdJQ0FnSUcxdlpHRnNMbVJoZEdGelpYUXVkV2xJYjNSbWFYaFdNakFnUFNBbk1TYzdDaUFnSUNBZ0lDQWdiVzlrWVd3dVlXUmtSWFpsYm5STWFYTjBaVzVsY2lnblkyeHBZMnNuTENCbGRtVnVkQ0E5UGlCN0NpQWdJQ0FnSUNBZ0lDQWdJR2xtSUNnaFpYWmxiblF1ZEdGeVoyVjBMbU5zYjNObGMzUW9KMXRrWVhSaExXSXhPUzFoWTNScGIyNDlJbU5zYjNObElsMG5LU2tnZXdvZ0lDQWdJQ0FnSUNBZ0lDQWdJQ0FnWlhabGJuUXVjM1J2Y0ZCeWIzQmhaMkYwYVc5dUtDazdDaUFnSUNBZ0lDQWdJQ0FnSUgwS0lDQWdJQ0FnSUNCOUtUc0tJQ0FnSUgwS0NpQWdJQ0JtZFc1amRHbHZiaUJ6WTJGdUtISnZiM1FnUFNCa2IyTjFiV1Z1ZENrZ2V3b2dJQ0FnSUNBZ0lHVnVjM1Z5WlZOMGVXeGxjeWdwT3dvZ0lDQWdJQ0FnSUdsbUlDaHliMjkwSUdsdWMzUmhibU5sYjJZZ1JXeGxiV1Z1ZENBbUppQnliMjkwTG0xaGRHTm9aWE1vSnk1c2F6SXRiVzlrWVd3bktTa2dld29nSUNBZ0lDQWdJQ0FnSUNCbmRXRnlaRXhyTWsxdlpHRnNLSEp2YjNRcE93b2dJQ0FnSUNBZ0lIMEtJQ0FnSUNBZ0lDQnBaaUFvY205dmRDQnBibk4wWVc1alpXOW1JRVZzWlcxbGJuUWdKaVlnY205dmRDNXRZWFJqYUdWektDY3VZakU1TFcxdlpHRnNKeWtwSUhzS0lDQWdJQ0FnSUNBZ0lDQWdaM1ZoY21SQ01UbE5iMlJoYkNoeWIyOTBLVHNLSUNBZ0lDQWdJQ0I5Q2lBZ0lDQWdJQ0FnY205dmRDNXhkV1Z5ZVZObGJHVmpkRzl5UVd4c1B5NG9KeTVzYXpJdGJXOWtZV3duS1M1bWIzSkZZV05vS0dkMVlYSmtUR3N5VFc5a1lXd3BPd29nSUNBZ0lDQWdJSEp2YjNRdWNYVmxjbmxUWld4bFkzUnZja0ZzYkQ4dUtDY3VZakU1TFcxdlpHRnNKeWt1Wm05eVJXRmphQ2huZFdGeVpFSXhPVTF2WkdGc0tUc0tJQ0FnSUgwS0NpQWdJQ0JtZFc1amRHbHZiaUJpYjI5MEtDa2dld29nSUNBZ0lDQWdJSE5qWVc0b1pHOWpkVzFsYm5RcE93b2dJQ0FnSUNBZ0lHTnZibk4wSUc5aWMyVnlkbVZ5SUQwZ2JtVjNJRTExZEdGMGFXOXVUMkp6WlhKMlpYSW9jbVZqYjNKa2N5QTlQaUI3Q2lBZ0lDQWdJQ0FnSUNBZ0lISmxZMjl5WkhNdVptOXlSV0ZqYUNoeVpXTnZjbVFnUFQ0Z2V3b2dJQ0FnSUNBZ0lDQWdJQ0FnSUNBZ2NtVmpiM0prTG1Ga1pHVmtUbTlrWlhNdVptOXlSV0ZqYUNodWIyUmxJRDArSUhzS0lDQWdJQ0FnSUNBZ0lDQWdJQ0FnSUNBZ0lDQnBaaUFvYm05a1pTQnBibk4wWVc1alpXOW1JRVZzWlcxbGJuUXBJSE5qWVc0b2JtOWtaU2s3Q2lBZ0lDQWdJQ0FnSUNBZ0lDQWdJQ0I5S1RzS0lDQWdJQ0FnSUNBZ0lDQWdmU2s3Q2lBZ0lDQWdJQ0FnZlNrN0NpQWdJQ0FnSUNBZ2IySnpaWEoyWlhJdWIySnpaWEoyWlNoa2IyTjFiV1Z1ZEM1aWIyUjVMQ0I3WTJocGJHUk1hWE4wT2lCMGNuVmxMQ0J6ZFdKMGNtVmxPaUIwY25WbGZTazdDaUFnSUNCOUNnb2dJQ0FnYVdZZ0tHUnZZM1Z0Wlc1MExuSmxZV1I1VTNSaGRHVWdQVDA5SUNkc2IyRmthVzVuSnlrZ2V3b2dJQ0FnSUNBZ0lHUnZZM1Z0Wlc1MExtRmtaRVYyWlc1MFRHbHpkR1Z1WlhJb0owUlBUVU52Ym5SbGJuUk1iMkZrWldRbkxDQmliMjkwTENCN2IyNWpaVG9nZEhKMVpYMHBPd29nSUNBZ2ZTQmxiSE5sSUhzS0lDQWdJQ0FnSUNCaWIyOTBLQ2s3Q2lBZ0lDQjlDbjBwS0NrN0NnPT0ifSwiY3NzIjp7InNoYTI1NiI6IjRjMDlmNjA1ZTI4MDliNWU2MWU5OWZlYTcyYjZiNzkyYzE3OGIyMmVmYjI3NzI2ZjI3YzZiOTk0YTA2NzNkOTIiLCJjb250ZW50IjoiQ2k4cUlGQlBVbFJCVEY5VlNWOUlUMVJHU1ZoZlZqRTRNRE15TUNBcUx3b3ViR3N5TFdOc2FXVnVkQzF6ZEhKMVkzUjFjbVVnTG1KMGJpd0tMbUl4T1MxdGIyUmhiQ0F1WW5SdUxBb3ViR3N5TFdOc2FXVnVkQzF6ZEhKMVkzUjFjbVVnWW5WMGRHOXVXMlJoZEdFdGJHc3lMV0ZqZEdsdmJsMDZibTkwS0M1c2F6SXRZMnhwWlc1MExXTmhjbVFwT201dmRDZ3ViR3N5TFdsamIyNHRZblYwZEc5dUtUcHViM1FvTG14ck1pMXRiMlJoYkMxamJHOXpaU2tnZXdvZ0lDQWdZWEJ3WldGeVlXNWpaVG9nYm05dVpUc0tJQ0FnSUdScGMzQnNZWGs2SUdsdWJHbHVaUzFtYkdWNE93b2dJQ0FnWVd4cFoyNHRhWFJsYlhNNklHTmxiblJsY2pzS0lDQWdJR3AxYzNScFpua3RZMjl1ZEdWdWREb2dZMlZ1ZEdWeU93b2dJQ0FnYldsdUxXaGxhV2RvZERvZ016aHdlRHNLSUNBZ0lIQmhaR1JwYm1jNklEaHdlQ0F4TkhCNE93b2dJQ0FnWW05eVpHVnlPaUF4Y0hnZ2MyOXNhV1FnSTJRd1pEZGxNanNLSUNBZ0lHSnZjbVJsY2kxeVlXUnBkWE02SURFd2NIZzdDaUFnSUNCaVlXTnJaM0p2ZFc1a09pQWpabVptT3dvZ0lDQWdZMjlzYjNJNklDTXpORFF3TlRRN0NpQWdJQ0JtYjI1ME9pQnBibWhsY21sME93b2dJQ0FnWm05dWRDMXphWHBsT2lBeE0zQjRPd29nSUNBZ1ptOXVkQzEzWldsbmFIUTZJRFkxTURzS0lDQWdJR3hwYm1VdGFHVnBaMmgwT2lBeExqSTdDaUFnSUNCMFpYaDBMV1JsWTI5eVlYUnBiMjQ2SUc1dmJtVTdDaUFnSUNCamRYSnpiM0k2SUhCdmFXNTBaWEk3Q2lBZ0lDQjBjbUZ1YzJsMGFXOXVPaUJpYjNKa1pYSXRZMjlzYjNJZ0xqRTJjeUJsWVhObExDQmlZV05yWjNKdmRXNWtJQzR4Tm5NZ1pXRnpaU3dnWTI5c2IzSWdMakUyY3lCbFlYTmxMQ0JpYjNndGMyaGhaRzkzSUM0eE5uTWdaV0Z6WlRzS2ZRb3ViR3N5TFdOc2FXVnVkQzF6ZEhKMVkzUjFjbVVnTG1KMGJqcG9iM1psY2l3S0xtSXhPUzF0YjJSaGJDQXVZblJ1T21odmRtVnlMQW91YkdzeUxXTnNhV1Z1ZEMxemRISjFZM1IxY21VZ1luVjBkRzl1VzJSaGRHRXRiR3N5TFdGamRHbHZibDA2Ym05MEtDNXNhekl0WTJ4cFpXNTBMV05oY21RcE9tNXZkQ2d1YkdzeUxXbGpiMjR0WW5WMGRHOXVLVHB1YjNRb0xteHJNaTF0YjJSaGJDMWpiRzl6WlNrNmFHOTJaWElnZXdvZ0lDQWdZbTl5WkdWeUxXTnZiRzl5T2lBak9UaGhNbUl6T3dvZ0lDQWdZbUZqYTJkeWIzVnVaRG9nSTJZNFptRm1ZenNLZlFvdWJHc3lMV05zYVdWdWRDMXpkSEoxWTNSMWNtVWdMbUowYmkxd2NtbHRZWEo1TEFvdVlqRTVMVzF2WkdGc0lDNWlkRzR0Y0hKcGJXRnllU3dLTG14ck1pMWpiR2xsYm5RdGMzUnlkV04wZFhKbElHSjFkSFJ2Ymx0a1lYUmhMV3hyTWkxaFkzUnBiMjQ5SW01bGR5MWpiR2xsYm5RaVhTd0tMbXhyTWkxamJHbGxiblF0YzNSeWRXTjBkWEpsSUdKMWRIUnZibHRrWVhSaExXeHJNaTFoWTNScGIyNDlJbTVsZHkxd2NtOXFaV04wSWwwZ2V3b2dJQ0FnWW05eVpHVnlMV052Ykc5eU9pQWpNalUyTTJWaUlDRnBiWEJ2Y25SaGJuUTdDaUFnSUNCaVlXTnJaM0p2ZFc1a09pQWpNalUyTTJWaUlDRnBiWEJ2Y25SaGJuUTdDaUFnSUNCamIyeHZjam9nSTJabVppQWhhVzF3YjNKMFlXNTBPd29nSUNBZ1ltOTRMWE5vWVdSdmR6b2dNQ0EwY0hnZ01USndlQ0J5WjJKaEtETTNMQ0E1T1N3Z01qTTFMQ0F1TVRncE93cDlDaTVzYXpJdFkyeHBaVzUwTFhOMGNuVmpkSFZ5WlNBdVluUnVMWEJ5YVcxaGNuazZhRzkyWlhJc0NpNWlNVGt0Ylc5a1lXd2dMbUowYmkxd2NtbHRZWEo1T21odmRtVnlMQW91YkdzeUxXTnNhV1Z1ZEMxemRISjFZM1IxY21VZ1luVjBkRzl1VzJSaGRHRXRiR3N5TFdGamRHbHZiajBpYm1WM0xXTnNhV1Z1ZENKZE9taHZkbVZ5TEFvdWJHc3lMV05zYVdWdWRDMXpkSEoxWTNSMWNtVWdZblYwZEc5dVcyUmhkR0V0YkdzeUxXRmpkR2x2YmowaWJtVjNMWEJ5YjJwbFkzUWlYVHBvYjNabGNpQjdDaUFnSUNCaWIzSmtaWEl0WTI5c2IzSTZJQ014WkRSbFpEZ2dJV2x0Y0c5eWRHRnVkRHNLSUNBZ0lHSmhZMnRuY205MWJtUTZJQ014WkRSbFpEZ2dJV2x0Y0c5eWRHRnVkRHNLZlFvdWJHc3lMV05zYVdWdWRDMXpkSEoxWTNSMWNtVWdMbUowYmkxelpXTnZibVJoY25rc0NpNWlNVGt0Ylc5a1lXd2dMbUowYmkxelpXTnZibVJoY25rZ2V3b2dJQ0FnWW05eVpHVnlMV052Ykc5eU9pQWpaREJrTjJVeUlDRnBiWEJ2Y25SaGJuUTdDaUFnSUNCaVlXTnJaM0p2ZFc1a09pQWpabVptSUNGcGJYQnZjblJoYm5RN0NpQWdJQ0JqYjJ4dmNqb2dJek0wTkRBMU5DQWhhVzF3YjNKMFlXNTBPd3A5Q2k1c2F6SXRZMnhwWlc1MExYTjBjblZqZEhWeVpTQXVZblJ1TFdSaGJtZGxjaTF6YjJaMElIc0tJQ0FnSUdKdmNtUmxjaTFqYjJ4dmNqb2dJMll5WXpaak1TQWhhVzF3YjNKMFlXNTBPd29nSUNBZ1ltRmphMmR5YjNWdVpEb2dJMlptWmpSbU1pQWhhVzF3YjNKMFlXNTBPd29nSUNBZ1kyOXNiM0k2SUNOaU5ESXpNVGdnSVdsdGNHOXlkR0Z1ZERzS2ZRb3ViR3N5TFdOc2FXVnVkQzF6ZEhKMVkzUjFjbVVnWW5WMGRHOXVPbVJwYzJGaWJHVmtMQW91WWpFNUxXMXZaR0ZzSUdKMWRIUnZianBrYVhOaFlteGxaQ0I3Q2lBZ0lDQnZjR0ZqYVhSNU9pQXVOVFU3Q2lBZ0lDQmpkWEp6YjNJNklHNXZkQzFoYkd4dmQyVmtPd3A5Q2k1c2F6SXRiVzlrWVd3dFltRmphMlJ5YjNBc0NpNWlNVGt0WW1GamEyUnliM0FnZXdvZ0lDQWdiM1psY25OamNtOXNiQzFpWldoaGRtbHZjam9nWTI5dWRHRnBianNLZlFvdWJHc3lMVzF2WkdGc0xBb3VZakU1TFcxdlpHRnNJSHNLSUNBZ0lHbHpiMnhoZEdsdmJqb2dhWE52YkdGMFpUc0tmUW89In19';
    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || hash('sha256', $decoded) !== '5aaeacca0ae9d0cfb1ca686326cddbc47fb3621a86bb29d699c3ceefcc9dc824') {
        throw new RuntimeException('Повреждён пакет UI-hotfix.');
    }
    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Не удалось декодировать пакет UI-hotfix.');
    }

    $result = [];
    foreach ($payload as $key => $item) {
        $content = base64_decode((string) ($item['content'] ?? ''), true);
        if (!is_string($content) || hash('sha256', $content) !== (string) ($item['sha256'] ?? '')) {
            throw new RuntimeException('Повреждён компонент UI-hotfix: ' . $key);
        }
        $result[$key] = $content;
    }
    return $result;
}

function ui20bustAsset(string $content, string $asset): array
{
    $pattern = '#(' . preg_quote($asset, '#') . ')(?:\?[^"\'\s<>]*)?#i';
    $updated = preg_replace_callback(
        $pattern,
        static fn(array $match): string =>
            $match[1] . '?v=' . UI_HOTFIX_ASSET_VERSION,
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
$appCssPath = $root . '/assets/app.css';

foreach ([$indexPath, $appJsPath, $appCssPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Не найден обязательный файл: ' . $path);
    }
}

$appJsBefore = ui20read($appJsPath);
if (!str_contains($appJsBefore, 'BITRIX_CLIENT_ONBOARDING_V180319')) {
    throw new RuntimeException(
        'Версия ' . UI_HOTFIX_VERSION
        . ' требует установленную 2026.08.03.19.'
    );
}

$components = ui20payload();
$backupDirectory = $root . '/storage/backups/ui-hotfix-v20-'
    . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию UI-hotfix.');
}

$tracked = [
    'index.php',
    'assets/app.js',
    'assets/app.css',
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
    if (!str_contains($appJs, UI_HOTFIX_MARKER)) {
        $appJs = rtrim($appJs) . PHP_EOL . PHP_EOL
            . trim($components['js']) . PHP_EOL;
        ui20write($appJsPath, $appJs);
    }

    $appCss = ui20read($appCssPath);
    if (!str_contains($appCss, UI_HOTFIX_MARKER)) {
        $appCss = rtrim($appCss) . PHP_EOL . PHP_EOL
            . trim($components['css']) . PHP_EOL;
        ui20write($appCssPath, $appCss);
    }

    $index = ui20read($indexPath);
    [$index, $cssCount] = ui20bustAsset($index, 'assets/app.css');
    [$index, $jsCount] = ui20bustAsset($index, 'assets/app.js');
    if ($cssCount <= 0 || $jsCount <= 0) {
        throw new RuntimeException(
            'Не удалось найти ссылки на assets/app.css и assets/app.js в index.php.'
        );
    }
    ui20write($indexPath, $index);

    ui20lint($indexPath);

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
                    'Ошибка JavaScript UI-hotfix: ' . implode("\n", $nodeOutput)
                );
            }
        } else {
            ui20out(
                'Проверка JavaScript через Node.js пропущена: '
                . 'серверная версия Node.js устарела.'
            );
        }
    }

    $finalJs = ui20read($appJsPath);
    $finalCss = ui20read($appCssPath);
    $finalIndex = ui20read($indexPath);

    if (substr_count($finalJs, UI_HOTFIX_MARKER) !== 1) {
        throw new RuntimeException('JavaScript UI-hotfix подключён некорректно.');
    }
    if (substr_count($finalCss, UI_HOTFIX_MARKER) !== 1) {
        throw new RuntimeException('Стили UI-hotfix подключены некорректно.');
    }
    if (!str_contains($finalIndex, 'assets/app.css?v=' . UI_HOTFIX_ASSET_VERSION)) {
        throw new RuntimeException('Версия app.css не обновлена.');
    }
    if (!str_contains($finalIndex, 'assets/app.js?v=' . UI_HOTFIX_ASSET_VERSION)) {
        throw new RuntimeException('Версия app.js не обновлена.');
    }

    ui20out('UI-hotfix установлен.');
    ui20out('- новые стили принудительно загружаются без старого кэша;');
    ui20out('- кнопки раздела клиентов получили единое оформление;');
    ui20out('- клики по полям больше не закрывают модальные окна;');
    ui20out('- закрытие работает по крестику, отмене и фону;');
    ui20out('- app.css и app.js получили версию ' . UI_HOTFIX_ASSET_VERSION . ';');
    ui20out('- резервная копия: ' . $backupDirectory . ';');
} catch (Throwable $exception) {
    foreach ($tracked as $relative) {
        $target = $root . '/' . $relative;
        $backup = $backupDirectory . '/' . $relative;
        if (is_file($backup)) {
            @copy($backup, $target);
        }
    }
    fwrite(STDERR, 'ОШИБКА: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Файлы восстановлены из резервной копии.' . PHP_EOL);
    exit(1);
}
