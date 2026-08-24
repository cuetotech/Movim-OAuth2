<?php

namespace Moxl;

use Fabiang\SASL\SASL;

class Authentication
{
    public ?string $username = null;
    public ?string $password = null;
    public ?string $token = null;
    public ?string $jid = null;

    private $_mechanism;
    private ?string $_type;

    public function choose(array $mechanisms, array $channelBindings = [])
    {
        $this->_type = null;
        $this->_mechanism = null;

        if ($this->token !== null) {
            if (in_array('X-OAUTH2', $mechanisms)) {
                $this->_type = 'X-OAUTH2';
            }

            return;
        }

        $choices = ['SCRAM-SHA-1', 'PLAIN'];

        foreach ($choices as $choice) {
            if (in_array($choice, $mechanisms)) {
                $this->_type = $choice;

                $this->_mechanism = SASL::fromString($this->_type)->mechanism([
                    'authcid'  => $this->username,
                    'secret'   => $this->password,
                    'downgrade_protection' => [
                        'allowed_mechanisms'       => $mechanisms,
                        'allowed_channel_bindings' => $channelBindings
                    ],
                ]);

                break;
            }
        }
    }

    public function canAuthenticate(): bool
    {
        return (
            ($this->token !== null && $this->jid !== null)
            || ($this->username !== null && $this->password !== null)
        );
    }

    public function hasSelectedMechanism(): bool
    {
        return $this->_type !== null;
    }

    public function getType(): string
    {
        return $this->_type;
    }

    public function getResponse(): string
    {
        if ($this->_type === 'X-OAUTH2') {
            return "\x00" . $this->username . "\x00" . $this->token;
        }

        return $this->_mechanism->createResponse();
    }

    public function response(): string
    {
        $response = base64_encode($this->getResponse());

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $auth = $dom->createElementNS('urn:ietf:params:xml:ns:xmpp-sasl', 'auth', $response);
        $auth->setAttribute('mechanism', $this->_type);
        $dom->appendChild($auth);

        return $dom->saveXML($dom->documentElement);
    }

    public function challenge($challenge)
    {
        if ($this->_type === 'X-OAUTH2') {
            return '';
        }

        return $this->_mechanism->createResponse($challenge);
    }

    public function clear()
    {
        $this->username = $this->password = $this->token = $this->jid = null;
        $this->_mechanism = null;
        $this->_type = null;
    }
}
