<?php

namespace Moxl\Xec\Payload;

use Moxl\Xec\Action\Jingle\MessageReject;
use Moxl\Xec\Action\Jingle\MessageRetract;

class JinglePropose extends Payload
{
    private function incomingProposalWins(string $incomingId, string $currentId, string $from): bool
    {
        $comparison = strcmp($incomingId, $currentId);

        if ($comparison !== 0) {
            return $comparison < 0;
        }

        // XEP-0353 specifies JID ordering as the final tie breaker if IDs collide.
        return strcmp(bareJid($from), (string)$this->me->id) < 0;
    }

    public function handle(?\SimpleXMLElement $stanza = null, ?\SimpleXMLElement $parent = null)
    {
        $from = (string)$parent->attributes()->from;
        $incomingId = (string)$stanza->attributes()->id;
        $currentCall = linker($this->me->session->id)->currentCall;

        if ($currentCall->isStarted()) {
            $samePeer = $currentCall->isJidInCall($from);

            // XEP-0353 section 4.1: simultaneous, unanswered proposals are
            // resolved by i;octet ordering of the session IDs.
            if ($samePeer && !$currentCall->isAnswered()) {
                if ($this->incomingProposalWins($incomingId, (string)$currentCall->id, $from)) {
                    $retract = new MessageRetract($this->me, $this->me->session->id);
                    $retract->setTo($from)
                        ->setId((string)$currentCall->id)
                        ->setReason('expired')
                        ->setText('Tie-Break')
                        ->setTiebreak(true)
                        ->request();

                    $currentCall->stop((string)$currentCall->jid, (string)$currentCall->id);
                } else {
                    $reject = new MessageReject($this->me, $this->me->session->id);
                    $reject->setTo($from)
                        ->setId($incomingId)
                        ->setReason('expired')
                        ->setText('Tie-Break')
                        ->setTiebreak(true)
                        ->request();
                    return;
                }
            } else {
                // A second call while already engaged is not a tie-break case.
                // Reject it with the XEP-0353 recommended Jingle <busy/> reason.
                $reject = new MessageReject($this->me, $this->me->session->id);
                $reject->setTo($from)
                    ->setId($incomingId)
                    ->request();
                return;
            }
        }

        $withVideo = false;
        foreach ($stanza->xpath('//description/@media') as $attribute) {
            if ((string)$attribute == 'video') $withVideo = true;
        }

        $this->pack([
            'id' => $incomingId,
            'withVideo' => $withVideo
        ], $from);

        $this->deliver();
    }
}
