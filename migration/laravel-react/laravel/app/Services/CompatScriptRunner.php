<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompatScriptRunner
{
    public function run(Request $request, string $relativePath): Response
    {
        $compatRoot = rtrim((string) config('compat.compat_root'), DIRECTORY_SEPARATOR);
        $relativePath = ltrim(str_replace(['\\', "\0"], ['/', ''], $relativePath), '/');

        $absolutePath = $compatRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (!is_file($absolutePath)) {
            return response('Compatibility script not found: ' . $relativePath, 404);
        }

        // Preserve original superglobals for current PHP process.
        $originalGet = $_GET;
        $originalPost = $_POST;
        $originalFiles = $_FILES;
        $originalServer = $_SERVER;

        $_GET = $request->query();
        $_POST = $request->request->all();
        $_FILES = $request->files->all();

        $_SERVER = array_merge($_SERVER, [
            'REQUEST_METHOD' => strtoupper($request->method()),
            'REQUEST_URI' => '/' . $relativePath,
            'QUERY_STRING' => (string) $request->server('QUERY_STRING', ''),
            'HTTP_X_REQUESTED_WITH' => (string) $request->header('X-Requested-With', ''),
            'HTTP_HOST' => (string) $request->getHost(),
            'REMOTE_ADDR' => (string) $request->ip(),
            'DOCUMENT_ROOT' => $compatRoot,
        ]);

        $cwd = getcwd();
        $statusCode = 200;
        $output = '';

        try {
            chdir(dirname($absolutePath));

            ob_start();
            include $absolutePath;
            $output = (string) ob_get_clean();

            $statusCode = http_response_code() ?: 200;
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $output = 'Compatibility execution error: ' . $e->getMessage();
            $statusCode = 500;
        } finally {
            if (is_string($cwd) && $cwd !== '') {
                chdir($cwd);
            }
            $_GET = $originalGet;
            $_POST = $originalPost;
            $_FILES = $originalFiles;
            $_SERVER = $originalServer;
        }

        $response = response($output, $statusCode);

        // Add deprecation header if this compat path is marked as migrated.
        try {
            $migratedListPath = base_path('../migrated_compat_paths.txt');
            if (is_file($migratedListPath)) {
                $lines = file($migratedListPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $normalized = str_replace('\\', '/', ltrim($relativePath, '/'));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    if (strtolower($line) === strtolower($normalized)) {
                        $response->headers->set('X-Compat-Deprecated', 'true');
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal - don't block compat responses on header logic
        }

        return $response;
    }
}
