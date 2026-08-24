<?php

namespace Moxl\Xec\Payload;

class JingleRinging extends Payload
{
    public function handle(?\SimpleXMLElement $stanza = null, ?\SimpleXMLElement $parent = null)
    {
        $from = (string)$parent->attributes()->from;
        $id = (string)$stanza->attributes()->id;
        $currentCall = linker($this->sessionId)->currentCall;

        if (
            $currentCall
            && $currentCall->isStarted()
            && !$currentCall->isAnswered()
            && $currentCall->hasId($id)
            && $currentCall->isJidInCall($from)
        ) {
            (new \Movim\RPC(user: $this->me, sessionId: $this->sessionId))
                ->call('VisioUtils.onRinging');
        }

        $this->pack($id, $from);
        $this->deliver();
    }
}
