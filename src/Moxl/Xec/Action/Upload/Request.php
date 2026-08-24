<?php

namespace Moxl\Xec\Action\Upload;

use Moxl\Xec\Action;
use Moxl\Stanza\Upload;

class Request extends Action
{
    protected $_id;
    protected $_to;
    protected $_name;
    protected $_size;
    protected $_type;

    private const AUTHORIZED_HEADERS = ['authorization', 'cookie', 'expires'];
    private const CANONICAL_HEADERS = [
        'authorization' => 'Authorization',
        'cookie' => 'Cookie',
        'expires' => 'Expires',
    ];

    public function request()
    {
        $this->store();
        $this->iq(Upload::request($this->_name, $this->_size, $this->_type), to: $this->_to, type: 'get');
    }

    public function handle(?\SimpleXMLElement $stanza = null, ?\SimpleXMLElement $parent = null)
    {
        if ($stanza->slot) {
            $params = [
                'id' => $this->_id,
                'get' => (string)$stanza->slot->get->attributes()->url,
                'put' => (string)$stanza->slot->put->attributes()->url,
                'headers' => null
            ];

            if ($stanza->slot->put->header) {
                $headers = [];

                foreach ($stanza->slot->put->header as $header) {
                    // XEP-0363 requires case-insensitive allow-listing, newline
                    // stripping for both names and values, and preservation of
                    // repeated values for the same header in their original order.
                    $name = str_replace(["\n", "\r"], '', (string)$header->attributes()->name);
                    $lowerName = strtolower($name);

                    if (!in_array($lowerName, self::AUTHORIZED_HEADERS, true)) {
                        continue;
                    }

                    $canonicalName = self::CANONICAL_HEADERS[$lowerName];
                    $value = str_replace(["\n", "\r"], '', (string)$header);

                    if (!array_key_exists($canonicalName, $headers)) {
                        $headers[$canonicalName] = $value;
                    } elseif (is_array($headers[$canonicalName])) {
                        $headers[$canonicalName][] = $value;
                    } else {
                        $headers[$canonicalName] = [$headers[$canonicalName], $value];
                    }
                }

                $params['headers'] = $headers;
            }

            $this->pack($params);
            $this->deliver();
        }
    }

    public function error(string $errorId, ?string $message = null)
    {
        $this->pack($this->_to);
        $this->deliver();
    }

    public function errorFileTooLarge($error)
    {
        $this->pack($this->_to);
        $this->deliver();
    }

    // the client exceeded a quota
    public function errorResourceConstraint($error)
    {
        $this->pack($this->_to);
        $this->deliver();
    }

    public function errorNotAllowed($error)
    {
        $this->pack($this->_to);
        $this->deliver();
    }
}
