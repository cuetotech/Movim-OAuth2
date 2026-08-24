<?php

namespace Moxl\Xec\Payload;

class SASL extends Payload
{
    public function handle(?\SimpleXMLElement $stanza = null, ?\SimpleXMLElement $parent = null)
    {
        $mechanisms = (array)$stanza->mechanism;

        /*
         * Weird behaviour on old eJabberd servers, fixed on the new versions
         * see https://github.com/processone/ejabberd/commit/2d748115
         */
        if (
            isset($parent->starttls)
            && isset($parent->starttls->required)
        ) {
            return;
        }

        if (linker($this->sessionId)->authentication?->canAuthenticate()) {
            if (!is_array($mechanisms)) {
                $mechanisms = [$mechanisms];
            }

            linker($this->sessionId)->authentication->choose($mechanisms);

            if (linker($this->sessionId)->authentication->hasSelectedMechanism()) {
                linker($this->sessionId)->writeXMPP(linker($this->sessionId)->authentication->response());
            } else {
                $this->pack('invalid-mechanism');
                $this->event('saslfailure');
            }
        }
    }
}
