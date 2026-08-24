<?php

namespace Moxl\Xec\Payload;

use Moxl\Stanza\Stream;
use Moxl\Xec\Action\Register\Get;

class StreamFeatures extends Payload
{
    public function handle(?\SimpleXMLElement $stanza = null, ?\SimpleXMLElement $parent = null)
    {
        if ($stanza->authentication && $stanza->authentication->attributes()->xmlns == 'urn:xmpp:sasl:2') {
            $mechanisms = (array)$stanza->authentication->mechanism;

            $channelBindings = [];

            if ($stanza->{'sasl-channel-binding'} && $stanza->{'sasl-channel-binding'}->attributes()->xmlns == 'urn:xmpp:sasl-cb:0') {
                foreach ($stanza->{'sasl-channel-binding'}->{'channel-binding'} as $channelBinding) {
                    array_push($channelBindings, (string)$channelBinding->attributes()->type);
                }
            }

            if (linker($this->sessionId)->authentication->canAuthenticate()) {
                if (!is_array($mechanisms)) {
                    $mechanisms = [$mechanisms];
                }

                linker($this->sessionId)->authentication->choose($mechanisms, $channelBindings);

                if (linker($this->sessionId)->authentication->hasSelectedMechanism()) {
                    $this->send(Stream::bind2Set(
                        linker($this->sessionId)->authentication->getType(),
                        linker($this->sessionId)->authentication->getResponse(),
                        APP_TITLE . '.' . \generateKey(6)
                    ));
                } else {
                    $this->pack('invalid-mechanism');
                    $this->event('sasl2failure');
                }
            } elseif (
                $stanza->register
                && $stanza->register->attributes()->xmlns == 'http://jabber.org/features/iq-register'
            ) {
                $g = new Get(sessionId: $this->sessionId);
                $g->setTo($stanza->attributes()->from)->request();
            }
        } elseif ($stanza->mechanisms && $stanza->mechanisms->attributes()->xmlns == 'urn:ietf:params:xml:ns:xmpp-sasl') {
            (new SASL(sessionId: $this->sessionId))->handle($stanza->mechanisms, $stanza);
        }
    }
}
