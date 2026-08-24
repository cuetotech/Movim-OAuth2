<?php

namespace App\Widgets\UploadFile;

use App\Upload;
use Movim\Widget\Base;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Stream\ReadableResourceStream;
use function React\Async\await;

class UploadFile extends Base
{
    /**
     * XEP-0363 only permits Authorization, Cookie and Expires headers from
     * the upload slot. Header names are case-insensitive and CR/LF must not
     * be forwarded.
     */
    private function sanitizeSlotHeaders(array $headers): array
    {
        $allowed = ['authorization', 'cookie', 'expires'];
        $sanitized = [];

        foreach ($headers as $name => $value) {
            $cleanName = str_replace(["\r", "\n"], '', (string)$name);
            if (!in_array(strtolower($cleanName), $allowed, true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$cleanName] = array_map(
                    static fn ($item) => str_replace(["\r", "\n"], '', (string)$item),
                    $value
                );
            } else {
                $sanitized[$cleanName] = str_replace(["\r", "\n"], '', (string)$value);
            }
        }

        return $sanitized;
    }

    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $headerName) {
            if (strcasecmp((string)$headerName, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function display()
    {
        if (!$this->get('f')) return;

        $upload = Upload::findOrFail($this->get('f'));
        if (!$upload) return;

        $json = [
            'func' => 'message',
            'b' => [
                'c' => 'upload',
                'w' => 'Upload',
                'f' => 'ajaxHttpUploadXMPP',
                'p' => [$this->get('f')]
            ]
        ];

        requestAPI('ajax', post: [
            'sid' => $this->me->session->id,
            'json' => rawurlencode(json_encode($json))
        ], await: false);

        if (!array_key_exists($upload->id, $_FILES)) return;

        if ($_FILES[$upload->id]['size'] == 0) {
            $json['b']['f'] = 'ajaxHttpError';
            requestAPI('ajax', post: [
                'sid' => $this->me->session->id,
                'json' => rawurlencode(json_encode($json))
            ], await: false);

            \logError('Uploaded file is empty, check your PHP file upload limit');
            http_response_code(503);
            return;
        }

        // XEP-0363 recommends relatively low PUT slot timeouts around 300s.
        // Streaming is a local reliability choice; the protocol does not
        // mandate how the HTTP request body is buffered.
        $browser = (new Browser())->withTimeout(300)
            ->withFollowRedirects(true);

        $filePath = $_FILES[$upload->id]['tmp_name'];
        $headers = $this->sanitizeSlotHeaders(is_array($upload->headers) ? $upload->headers : []);
        $contentType = $upload->type ?: ($_FILES[$upload->id]['type'] ?? null);
        $contentLength = @filesize($filePath);

        // XEP-0363 requires these headers on the HTTP PUT. They describe the
        // exact slot request and are not slot-provided extension headers.
        if ($contentType && !$this->hasHeader($headers, 'Content-Type')) {
            $headers['Content-Type'] = (string)$contentType;
        }
        if ($contentLength !== false && !$this->hasHeader($headers, 'Content-Length')) {
            $headers['Content-Length'] = (string)$contentLength;
        }

        $resource = fopen($filePath, 'rb');
        if ($resource === false) {
            \logError(sprintf('Upload PUT failed for %s: unable to open temporary file', $upload->name));
            http_response_code(500);
            return;
        }

        $fileUploaded = 0;
        $lastPercentage = -1;
        $fileSize = $contentLength !== false ? (int)$contentLength : null;

        $sendProgress = function (int $percentage) use (&$json) {
            $json['b']['f'] = 'ajaxHttpProgressXMPP';
            $json['b']['p'] = [$percentage];
            requestAPI('ajax', post: [
                'sid' => $this->me->session->id,
                'json' => rawurlencode(json_encode($json))
            ], await: false);
        };

        $file = new ReadableResourceStream($resource);
        $file->on('data', function ($data) use (&$fileUploaded, $fileSize, &$lastPercentage, $sendProgress) {
            if (!$fileSize) return;

            $fileUploaded += strlen($data);
            $percentage = min(100, (int)floor($fileUploaded / $fileSize * 100));
            if ($percentage === $lastPercentage) return;

            $lastPercentage = $percentage;
            $sendProgress($percentage);
        });

        try {
            /** @var ResponseInterface $response */
            $response = await($browser->put($upload->puturl, $headers, body: $file));
            $status = $response->getStatusCode();

            // XEP-0363 specifies 201 Created when the uploaded file is ready.
            if ($status === 201) {
                $upload->uploaded = true;
                $upload->save();
                if ($lastPercentage < 100) $sendProgress(100);
            } else {
                \logError(sprintf(
                    'Upload PUT for %s returned %d; XEP-0363 expects HTTP 201',
                    $upload->name,
                    $status
                ));
            }

            http_response_code($status);
        } catch (\Throwable $e) {
            $status = 406;
            if (method_exists($e, 'getResponse')) {
                $response = $e->getResponse();
                if ($response instanceof ResponseInterface) {
                    $status = $response->getStatusCode();
                }
            }

            \logError(sprintf('Upload PUT failed for %s: %s', $upload->name, $e->getMessage()));
            http_response_code($status);
        } finally {
            if (is_resource($resource)) fclose($resource);
        }
    }
}
