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
    private function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $headerName => $value) {
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

        if (array_key_exists('file', $_FILES)) {
            $browser = (new Browser())->withTimeout(120)
                ->withFollowRedirects(true);

            $filePath = $_FILES['file']['tmp_name'];
            $headers = is_array($upload->headers) ? $upload->headers : [];
            $contentType = $upload->type;

            if (
                !$contentType
                && isset($_FILES['file'])
                && is_array($_FILES['file'])
                && array_key_exists('type', $_FILES['file'])
            ) {
                $contentType = $_FILES['file']['type'];
            }

            if ($contentType && !$this->hasHeader($headers, 'Content-Type')) {
                $headers['Content-Type'] = $contentType;
            }

            $contentLength = @filesize($filePath);
            if ($contentLength !== false && !$this->hasHeader($headers, 'Content-Length')) {
                $headers['Content-Length'] = (string)$contentLength;
            }

            $resource = fopen($filePath, 'rb');
            if ($resource === false) {
                logError(sprintf(
                    'Upload PUT failed for %s (%s): unable to open temporary file',
                    $upload->name,
                    $upload->puturl
                ));

                http_response_code(500);
                return;
            }

            $fileSize = $contentLength !== false ? $contentLength : null;
            $fileUploaded = 0;
            $lastPercentage = -1;

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
                if (!$fileSize) {
                    return;
                }

                $fileUploaded += strlen($data);
                $percentage = min(100, (int)floor($fileUploaded / $fileSize * 100));

                if ($percentage === $lastPercentage) {
                    return;
                }

                $lastPercentage = $percentage;
                $sendProgress($percentage);
            });

            try {
                $response = await($browser->put(
                    $upload->puturl,
                    $headers,
                    body: $file
                ));

                if ($response->getStatusCode() >= 400) {
                    logError(sprintf(
                        'Upload PUT rejected for %s (%s) with status %d',
                        $upload->name,
                        $upload->puturl,
                        $response->getStatusCode()
                    ));
                } else {
                    $upload->uploaded = true;
                    $upload->save();

                    if ($lastPercentage < 100) {
                        $sendProgress(100);
                    }
                }

                http_response_code($response->getStatusCode());
            } catch (\Throwable $e) {
                $statusCode = 406;

                if (method_exists($e, 'getResponse')) {
                    $response = $e->getResponse();

                    if ($response instanceof ResponseInterface) {
                        $statusCode = $response->getStatusCode();
                    }
                }

                logError(sprintf(
                    'Upload PUT failed for %s (%s): %s',
                    $upload->name,
                    $upload->puturl,
                    $e->getMessage()
                ));

                http_response_code($statusCode);
            } finally {
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }
        }
    }
}
